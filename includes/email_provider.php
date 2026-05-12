<?php
/**
 * Per-restaurant email provider routing (Premium feature: custom_from_email).
 *
 * - Šifrirano shrani credentials (Mailgun API key, SMTP geslo) v `email_settings_enc`
 *   z AES-256-GCM in APP_SECRET ključem iz config.php.
 * - SMTP klient (vendored, brez Composer) — Gmail, Outlook 365, Zoho, Plus.si, etc.
 * - Mailgun custom domain — restavracija doda svojo Mailgun domeno + API ključ.
 *
 * Public:
 *   email_encrypt(array)              -> string
 *   email_decrypt(string)             -> array|null
 *   get_restaurant_email_config(...)  -> ?array
 *   email_send_via_smtp(...)          -> bool
 *   email_send_via_mailgun_custom(...) -> bool
 */

if (!defined('APP_SECRET')) {
    // Fallback: izpelje "deterministični" key iz drugih konstant — boljši kot ne-šifrirano.
    define('APP_SECRET', hash('sha256', (defined('STRIPE_SECRET_KEY') ? STRIPE_SECRET_KEY : '') . (defined('MAILGUN_API_KEY') ? MAILGUN_API_KEY : '')));
}

// ─── Šifriranje credentialov ───────────────────────────────────
function email_encrypt(array $data): string {
    $plain = json_encode($data, JSON_UNESCAPED_UNICODE);
    $key   = hash('sha256', APP_SECRET, true);
    $iv    = random_bytes(12);
    $tag   = '';
    $cipher = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    if ($cipher === false) return '';
    return base64_encode($iv . $tag . $cipher);
}

function email_decrypt(?string $enc): ?array {
    if (!$enc) return null;
    $raw = base64_decode($enc, true);
    if ($raw === false || strlen($raw) < 28) return null;
    $iv     = substr($raw, 0, 12);
    $tag    = substr($raw, 12, 16);
    $cipher = substr($raw, 28);
    $key    = hash('sha256', APP_SECRET, true);
    $plain  = openssl_decrypt($cipher, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    if ($plain === false) return null;
    $data = json_decode($plain, true);
    return is_array($data) ? $data : null;
}

// ─── Naloži per-restaurant email config (z preverjanjem premium) ────────
function get_restaurant_email_config(PDO $pdo, int $restId): ?array {
    require_once __DIR__ . '/plans.php';
    $stmt = $pdo->prepare("SELECT owner_id, email_provider, email_settings_enc, email_from_name, email_from_address, email_verified_at FROM restaurants WHERE id = ?");
    $stmt->execute([$restId]);
    $row = $stmt->fetch();
    if (!$row) return null;
    if (($row['email_provider'] ?? 'default') === 'default') return null;
    if (!user_has_feature($pdo, (int)$row['owner_id'], 'custom_from_email')) return null;

    $creds = email_decrypt($row['email_settings_enc'] ?? null);
    if (!$creds) return null;

    return [
        'provider'    => $row['email_provider'],
        'from_name'   => $row['email_from_name'] ?: null,
        'from_email'  => $row['email_from_address'] ?: null,
        'verified_at' => $row['email_verified_at'] ?: null,
        'creds'       => $creds, // ['domain','api_key'] ALI ['host','port','user','pass','secure']
    ];
}

// ─── Sestavi RFC822 From header ────────────────────────────────────
function _email_from_header(array $config): string {
    $name  = $config['from_name'] ?? '';
    $email = $config['from_email'] ?? '';
    if (!$email) return '';
    if ($name) return '"' . str_replace('"', '', $name) . '" <' . $email . '>';
    return $email;
}

// ─── Mailgun custom domain (HTTP API) ─────────────────────────────────
function email_send_via_mailgun_custom(array $config, string $to, string $subject, string $html, string $text = ''): bool {
    $creds  = $config['creds'] ?? [];
    $domain = $creds['domain']  ?? '';
    $apiKey = $creds['api_key'] ?? '';
    $region = $creds['region']  ?? 'eu'; // eu | us
    if (!$domain || !$apiKey) { error_log('Mailgun custom: missing domain/api_key.'); return false; }

    $host = $region === 'us' ? 'api.mailgun.net' : 'api.eu.mailgun.net';
    $url  = 'https://' . $host . '/v3/' . $domain . '/messages';
    $from = _email_from_header($config) ?: 'noreply@' . $domain;

    $data = [
        'from'    => $from,
        'to'      => $to,
        'subject' => $subject,
        'html'    => $html,
    ];
    if ($text) $data['text'] = $text;

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD        => 'api:' . $apiKey,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $data,
        CURLOPT_TIMEOUT        => 10,
    ]);
    $resp = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($err)  { error_log('Mailgun custom curl: ' . $err); return false; }
    if ($code !== 200) { error_log('Mailgun custom HTTP ' . $code . ': ' . $resp); return false; }
    return true;
}

// ─── SMTP klient (vendored, brez Composer) ─────────────────────────────
function email_send_via_smtp(array $config, string $to, string $subject, string $html, string $text = ''): bool {
    $creds = $config['creds'] ?? [];
    $host  = $creds['host']   ?? '';
    $port  = (int)($creds['port'] ?? 587);
    $user  = $creds['user']   ?? '';
    $pass  = $creds['pass']   ?? '';
    $secure = $creds['secure'] ?? 'tls'; // 'tls' (STARTTLS), 'ssl' (SMTPS), 'none'
    $from  = $config['from_email'] ?: $user;
    $fromName = $config['from_name'] ?? '';
    if (!$host || !$user || !$pass || !$from) { error_log('SMTP: missing host/user/pass/from.'); return false; }

    $proto = $secure === 'ssl' ? 'ssl://' : '';
    $errno = 0; $errstr = '';
    $sock = @stream_socket_client($proto . $host . ':' . $port, $errno, $errstr, 15, STREAM_CLIENT_CONNECT);
    if (!$sock) { error_log("SMTP connect $host:$port: $errstr"); return false; }
    stream_set_timeout($sock, 15);

    $read = function() use ($sock) {
        $data = '';
        while (($line = fgets($sock, 1024)) !== false) {
            $data .= $line;
            if (preg_match('/^\d{3} /', $line)) break;
        }
        return $data;
    };
    $write = function($cmd) use ($sock) { fwrite($sock, $cmd . "\r\n"); };
    $expect = function($code, $resp) {
        $first = strtok($resp, "\n");
        if (substr(ltrim($first), 0, 3) !== $code) {
            error_log("SMTP unexpected response (expected $code): $resp");
            return false;
        }
        return true;
    };

    $resp = $read();              if (!$expect('220', $resp)) { fclose($sock); return false; }
    $write('EHLO ' . ($_SERVER['SERVER_NAME'] ?? 'localhost'));
    $resp = $read();              if (!$expect('250', $resp)) { fclose($sock); return false; }

    if ($secure === 'tls') {
        $write('STARTTLS');
        $resp = $read();          if (!$expect('220', $resp)) { fclose($sock); return false; }
        if (!stream_socket_enable_crypto($sock, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            error_log('SMTP STARTTLS handshake failed');
            fclose($sock); return false;
        }
        $write('EHLO ' . ($_SERVER['SERVER_NAME'] ?? 'localhost'));
        $resp = $read();          if (!$expect('250', $resp)) { fclose($sock); return false; }
    }

    // AUTH LOGIN
    $write('AUTH LOGIN');
    $resp = $read();              if (!$expect('334', $resp)) { fclose($sock); return false; }
    $write(base64_encode($user));
    $resp = $read();              if (!$expect('334', $resp)) { fclose($sock); return false; }
    $write(base64_encode($pass));
    $resp = $read();              if (!$expect('235', $resp)) { fclose($sock); return false; }

    $write('MAIL FROM: <' . $from . '>');
    $resp = $read();              if (!$expect('250', $resp)) { fclose($sock); return false; }
    $write('RCPT TO: <' . $to . '>');
    $resp = $read();              if (!$expect('250', $resp)) { fclose($sock); return false; }
    $write('DATA');
    $resp = $read();              if (!$expect('354', $resp)) { fclose($sock); return false; }

    // Sestavi MIME multipart/alternative
    $boundary = 'rzb_' . bin2hex(random_bytes(8));
    $fromHdr  = $fromName ? '"' . str_replace('"', '', $fromName) . '" <' . $from . '>' : $from;
    $headers  = [
        'From: ' . $fromHdr,
        'To: ' . $to,
        'Subject: =?UTF-8?B?' . base64_encode($subject) . '?=',
        'MIME-Version: 1.0',
        'Date: ' . date('r'),
        'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
    ];
    $body  = implode("\r\n", $headers) . "\r\n\r\n";
    if ($text) {
        $body .= '--' . $boundary . "\r\n";
        $body .= "Content-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n";
        $body .= chunk_split(base64_encode($text)) . "\r\n";
    }
    $body .= '--' . $boundary . "\r\n";
    $body .= "Content-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n";
    $body .= chunk_split(base64_encode($html)) . "\r\n";
    $body .= '--' . $boundary . "--\r\n";

    // RFC5321: dot-stuffing (vrstice ki se začnejo s "." dobijo dodatno "..")
    $body = preg_replace('/^\./m', '..', $body);

    fwrite($sock, $body . "\r\n.\r\n");
    $resp = $read();              if (!$expect('250', $resp)) { fclose($sock); return false; }

    $write('QUIT');
    @fclose($sock);
    return true;
}

// ─── Glavna routing funkcija ──────────────────────────────────────
function email_send_via_provider(array $config, string $to, string $subject, string $html, string $text = ''): bool {
    if (($config['provider'] ?? '') === 'mailgun') return email_send_via_mailgun_custom($config, $to, $subject, $html, $text);
    if (($config['provider'] ?? '') === 'smtp')    return email_send_via_smtp($config, $to, $subject, $html, $text);
    return false;
}
