<?php
/**
 * AI Help Chat endpoint.
 * - POST ?action=send  → pošlje uporabnikovo sporočilo, vrne AI odgovor
 *                       (in shrani oba v help_chat_messages)
 * - GET  ?action=history&conversation_id=N → zgodovina pogovora
 * - GET  ?action=list → seznam pogovorov za trenutnega uporabnika
 *
 * Model: claude-haiku-4-5-20251001 (najceneje + dovolj sposobno).
 * Prompt caching: sistemski promt + knowledge base sta cachana (ephemeral, 5min).
 *
 * Cene (USD/MTok):
 *   - input:        $1.00
 *   - output:       $5.00
 *   - cache write:  $1.25 (1.25x input)
 *   - cache read:   $0.10 (0.10x input)
 */

require_once '../includes/auth_check.php';
require_once '../includes/db.php';
require_once '../includes/functions.php';
require_once '../includes/help_chat_knowledge.php';

header('Content-Type: application/json; charset=utf-8');

const HELP_CHAT_MODEL          = 'claude-haiku-4-5-20251001';
const HELP_CHAT_MAX_HISTORY    = 20;          // koliko zadnjih sporočil pošljemo modelu
const HELP_CHAT_MAX_TOKENS_OUT = 1024;
const HELP_CHAT_API_URL        = 'https://api.anthropic.com/v1/messages';
const HELP_CHAT_API_VERSION    = '2023-06-01';

// Cene v USD per million tokenov (Haiku 4.5).
const HELP_CHAT_PRICE_INPUT_MTK         = 1.00;
const HELP_CHAT_PRICE_OUTPUT_MTK        = 5.00;
const HELP_CHAT_PRICE_CACHE_WRITE_MTK   = 1.25;
const HELP_CHAT_PRICE_CACHE_READ_MTK    = 0.10;

// ─── Rate limiti (anti-zloraba) ────────────────────────────────────────
// Dnevna kapaciteta po paketih (število user sporočil/dan).
const HELP_CHAT_DAILY_LIMIT = [
    'trial'    => 15,
    'basic'    => 15,
    'advanced' => 30,
    'premium'  => 60,
];
// Burst: max sporočil v X-sekundnem oknu (proti scriptanim spam napadom).
const HELP_CHAT_BURST_LIMIT     = 10;
const HELP_CHAT_BURST_WINDOW_S  = 60;
// Cost cap per uporabnik/dan (varnostna mreža za primer dolgih sporočil ali model anomalij).
const HELP_CHAT_DAILY_COST_USD  = 0.50;

$session = require_auth(); // tudi user lahko, ne le admin
$pdo     = getDB();
$method  = $_SERVER['REQUEST_METHOD'];
$action  = $_GET['action'] ?? '';

function _hc_calc_cost(int $in, int $out, int $cw, int $cr): float {
    $cost  = ($in  * HELP_CHAT_PRICE_INPUT_MTK)        / 1_000_000;
    $cost += ($out * HELP_CHAT_PRICE_OUTPUT_MTK)       / 1_000_000;
    $cost += ($cw  * HELP_CHAT_PRICE_CACHE_WRITE_MTK)  / 1_000_000;
    $cost += ($cr  * HELP_CHAT_PRICE_CACHE_READ_MTK)   / 1_000_000;
    return round($cost, 6);
}

// ─── Rate limit helper ────────────────────────────────────────────────
// Preveri kvoto za uporabnika; vrne:
//   ['ok'=>true,  'limit'=>N, 'used'=>K, 'remaining'=>N-K, 'cost_today'=>F]
//   ['ok'=>false, 'reason'=>'daily_limit'|'cost_limit'|'burst', 'retry_in_s'=>S, ...]
function _hc_check_quota(PDO $pdo, int $userId, string $role): array {
    // Superadmin nima limita (interni testing).
    if ($role === 'superadmin') {
        return ['ok' => true, 'limit' => -1, 'used' => 0, 'remaining' => -1, 'cost_today' => 0.0];
    }

    // Plan-based dnevni limit
    require_once __DIR__ . '/../includes/plans.php';
    $sub  = get_active_subscription($pdo, $userId);
    $slug = $sub['plan_slug'] ?? 'basic';
    $dailyLimit = HELP_CHAT_DAILY_LIMIT[$slug] ?? HELP_CHAT_DAILY_LIMIT['basic'];

    // Štetje user sporočil + cost summary za dnešnji dan
    $stmt = $pdo->prepare("
        SELECT
            SUM(CASE WHEN m.role = 'user' THEN 1 ELSE 0 END)            AS user_msgs,
            COALESCE(SUM(m.cost_usd), 0)                                AS cost_today
        FROM help_chat_messages m
        JOIN help_chat_conversations c ON m.conversation_id = c.id
        WHERE c.user_id = ?
          AND m.created_at >= CURDATE()
          AND m.created_at <  CURDATE() + INTERVAL 1 DAY
    ");
    $stmt->execute([$userId]);
    $row = $stmt->fetch();
    $usedToday  = (int)($row['user_msgs']  ?? 0);
    $costToday  = (float)($row['cost_today'] ?? 0);
    $secsUntilMidnight = strtotime('tomorrow 00:00:00') - time();

    if ($usedToday >= $dailyLimit) {
        return [
            'ok'         => false,
            'reason'     => 'daily_limit',
            'limit'      => $dailyLimit,
            'used'       => $usedToday,
            'retry_in_s' => $secsUntilMidnight,
        ];
    }
    if ($costToday >= HELP_CHAT_DAILY_COST_USD) {
        return [
            'ok'         => false,
            'reason'     => 'cost_limit',
            'cost_today' => $costToday,
            'retry_in_s' => $secsUntilMidnight,
        ];
    }

    // Burst preverjanje (zadnjih HELP_CHAT_BURST_WINDOW_S sekund)
    $bstmt = $pdo->prepare("
        SELECT COUNT(*) FROM help_chat_messages m
        JOIN help_chat_conversations c ON m.conversation_id = c.id
        WHERE c.user_id = ?
          AND m.role = 'user'
          AND m.created_at > NOW() - INTERVAL ? SECOND
    ");
    $bstmt->execute([$userId, HELP_CHAT_BURST_WINDOW_S]);
    $burstCnt = (int)$bstmt->fetchColumn();
    if ($burstCnt >= HELP_CHAT_BURST_LIMIT) {
        return [
            'ok'         => false,
            'reason'     => 'burst',
            'retry_in_s' => HELP_CHAT_BURST_WINDOW_S,
        ];
    }

    return [
        'ok'         => true,
        'limit'      => $dailyLimit,
        'used'       => $usedToday,
        'remaining'  => $dailyLimit - $usedToday,
        'cost_today' => round($costToday, 4),
    ];
}

// Lokalizirana sporočila za kvoto (fallback na SL).
function _hc_quota_message(string $reason, string $lang, array $ctx = []): string {
    $msgs = [
        'sl' => [
            'daily_limit' => 'Dosegli ste dnevno omejitev za Mio (' . ($ctx['limit'] ?? '?') . ' vprašanj). Vrnete se jutri.',
            'cost_limit'  => 'Dosegli ste dnevno omejitev za Mio. Vrnete se jutri.',
            'burst'       => 'Preveč vprašanj v kratkem času. Počakajte 1 minuto, prosim.',
        ],
        'en' => [
            'daily_limit' => 'You have reached the daily Mia limit (' . ($ctx['limit'] ?? '?') . ' questions). Try again tomorrow.',
            'cost_limit'  => 'You have reached the daily Mia limit. Try again tomorrow.',
            'burst'       => 'Too many questions in a short time. Please wait 1 minute.',
        ],
        'de' => [
            'daily_limit' => 'Sie haben das tägliche Mia-Limit erreicht (' . ($ctx['limit'] ?? '?') . ' Fragen). Versuchen Sie es morgen erneut.',
            'cost_limit'  => 'Sie haben das tägliche Mia-Limit erreicht. Versuchen Sie es morgen erneut.',
            'burst'       => 'Zu viele Anfragen in kurzer Zeit. Bitte warten Sie 1 Minute.',
        ],
        'it' => [
            'daily_limit' => 'Hai raggiunto il limite giornaliero di Mia (' . ($ctx['limit'] ?? '?') . ' domande). Riprova domani.',
            'cost_limit'  => 'Hai raggiunto il limite giornaliero di Mia. Riprova domani.',
            'burst'       => 'Troppe domande in poco tempo. Attendi 1 minuto.',
        ],
        'fr' => [
            'daily_limit' => 'Vous avez atteint la limite quotidienne de Mia (' . ($ctx['limit'] ?? '?') . ' questions). Réessayez demain.',
            'cost_limit'  => 'Vous avez atteint la limite quotidienne de Mia. Réessayez demain.',
            'burst'       => 'Trop de questions en peu de temps. Attendez 1 minute.',
        ],
        'hr' => [
            'daily_limit' => 'Dosegli ste dnevni limit za Miu (' . ($ctx['limit'] ?? '?') . ' pitanja). Pokušajte ponovno sutra.',
            'cost_limit'  => 'Dosegli ste dnevni limit za Miu. Pokušajte ponovno sutra.',
            'burst'       => 'Previše pitanja u kratkom vremenu. Pričekajte 1 minutu.',
        ],
        'es' => [
            'daily_limit' => 'Ha alcanzado el límite diario de Mia (' . ($ctx['limit'] ?? '?') . ' preguntas). Vuelva a intentarlo mañana.',
            'cost_limit'  => 'Ha alcanzado el límite diario de Mia. Vuelva a intentarlo mañana.',
            'burst'       => 'Demasiadas preguntas en poco tiempo. Espere 1 minuto.',
        ],
        'pt' => [
            'daily_limit' => 'Atingiu o limite diário da Mia (' . ($ctx['limit'] ?? '?') . ' perguntas). Tente novamente amanhã.',
            'cost_limit'  => 'Atingiu o limite diário da Mia. Tente novamente amanhã.',
            'burst'       => 'Demasiadas perguntas em pouco tempo. Aguarde 1 minuto.',
        ],
    ];
    if (!isset($msgs[$lang])) $lang = 'sl';
    return $msgs[$lang][$reason] ?? $msgs[$lang]['daily_limit'];
}

function _hc_call_api(array $messages, string $userLang, ?int $currentRestId = null): array {
    if (!defined('ANTHROPIC_API_KEY') || !ANTHROPIC_API_KEY) {
        throw new RuntimeException('ANTHROPIC_API_KEY ni nastavljen.');
    }
    $kb = help_chat_knowledge_text($currentRestId);
    // Sistemski promt z cache_control za prompt caching.
    $payload = [
        'model'      => HELP_CHAT_MODEL,
        'max_tokens' => HELP_CHAT_MAX_TOKENS_OUT,
        'system'     => [
            [
                'type'          => 'text',
                'text'          => $kb,
                'cache_control' => ['type' => 'ephemeral'],
            ],
        ],
        'messages'   => $messages,
    ];

    $ch = curl_init(HELP_CHAT_API_URL);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER     => [
            'x-api-key: ' . ANTHROPIC_API_KEY,
            'anthropic-version: ' . HELP_CHAT_API_VERSION,
            'content-type: application/json',
        ],
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_CONNECTTIMEOUT => 10,
    ]);
    $t0   = microtime(true);
    $resp = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    $latencyMs = (int)round((microtime(true) - $t0) * 1000);

    if ($resp === false) throw new RuntimeException('cURL napaka: ' . $err);
    $json = json_decode($resp, true);
    if ($code >= 400 || !is_array($json)) {
        $msg = $json['error']['message'] ?? ('HTTP ' . $code);
        throw new RuntimeException('Anthropic API: ' . $msg);
    }
    $text = $json['content'][0]['text'] ?? '';
    if ($text === '') throw new RuntimeException('AI odgovor je prazen.');

    $usage = $json['usage'] ?? [];
    return [
        'text'                => $text,
        'input_tokens'        => (int)($usage['input_tokens']                ?? 0),
        'output_tokens'       => (int)($usage['output_tokens']               ?? 0),
        'cache_create_tokens' => (int)($usage['cache_creation_input_tokens'] ?? 0),
        'cache_read_tokens'   => (int)($usage['cache_read_input_tokens']     ?? 0),
        'latency_ms'          => $latencyMs,
    ];
}

function _hc_user_owns_conversation(PDO $pdo, array $session, int $convId): bool {
    if ($session['role'] === 'superadmin') return true;
    $stmt = $pdo->prepare("SELECT user_id FROM help_chat_conversations WHERE id=?");
    $stmt->execute([$convId]);
    $owner = $stmt->fetchColumn();
    return $owner !== false && (int)$owner === (int)$session['user_id'];
}

// ─── GET ?action=quota ────────────────────────────────────────────────────────
// Vrne trenutno stanje kvote uporabnika (za prikaz "X/Y vprašanj danes" v UI).
if ($method === 'GET' && $action === 'quota') {
    $q = _hc_check_quota($pdo, (int)$session['user_id'], $session['role'] ?? '');
    json_response(true, $q);
}

// ─── GET ?action=list ─────────────────────────────────────────────────────────
if ($method === 'GET' && $action === 'list') {
    $stmt = $pdo->prepare("
        SELECT id, title, message_count, total_cost_usd, created_at, last_message_at
        FROM help_chat_conversations
        WHERE user_id = ?
        ORDER BY COALESCE(last_message_at, created_at) DESC
        LIMIT 30
    ");
    $stmt->execute([$session['user_id']]);
    json_response(true, $stmt->fetchAll());
}

// ─── GET ?action=history&conversation_id=N ────────────────────────────────────
if ($method === 'GET' && $action === 'history') {
    $convId = (int)($_GET['conversation_id'] ?? 0);
    if (!$convId) json_response(false, null, 'conversation_id manjka.', 400);
    if (!_hc_user_owns_conversation($pdo, $session, $convId)) {
        json_response(false, null, 'Dostop zavrnjen.', 403);
    }
    $stmt = $pdo->prepare("
        SELECT role, content, created_at, refused
        FROM help_chat_messages
        WHERE conversation_id = ?
        ORDER BY id ASC
    ");
    $stmt->execute([$convId]);
    json_response(true, [
        'conversation_id' => $convId,
        'messages'        => $stmt->fetchAll(),
    ]);
}

// ─── POST ?action=send ────────────────────────────────────────────────────────
if ($method === 'POST' && $action === 'send') {
    $body    = get_body();
    $message = trim((string)($body['message'] ?? ''));
    $convId  = isset($body['conversation_id']) ? (int)$body['conversation_id'] : 0;

    if ($message === '')      json_response(false, null, 'Sporočilo je prazno.', 400);
    if (mb_strlen($message) > 400) json_response(false, null, 'Sporočilo je predolgo (max 400 znakov).', 400);

    // Določi/ustvari pogovor
    $userLang = !empty($_COOKIE['rzlang']) ? (string)$_COOKIE['rzlang'] : 'sl';

    // ── Rate limit check (PRED kakršnimkoli DB inserts ali API call-om) ──
    $quota = _hc_check_quota($pdo, (int)$session['user_id'], $session['role'] ?? '');
    if (!$quota['ok']) {
        $msg = _hc_quota_message($quota['reason'], $userLang, $quota);
        json_response(false, [
            'reason'     => $quota['reason'],
            'retry_in_s' => $quota['retry_in_s'] ?? 0,
            'limit'      => $quota['limit']      ?? null,
            'used'       => $quota['used']       ?? null,
        ], $msg, 429);
    }
    if ($convId > 0) {
        if (!_hc_user_owns_conversation($pdo, $session, $convId)) {
            json_response(false, null, 'Dostop zavrnjen.', 403);
        }
    } else {
        $title = mb_substr($message, 0, 80);
        $pdo->prepare("
            INSERT INTO help_chat_conversations
                (user_id, restaurant_id, user_name, user_email, user_role, user_lang, title, model, last_message_at)
            VALUES (?,?,?,?,?,?,?,?,NOW())
        ")->execute([
            $session['user_id'] ?? null,
            !empty($session['restaurant_id']) ? (int)$session['restaurant_id'] : null,
            $session['full_name'] ?? null,
            $session['email']     ?? null,
            $session['role']      ?? null,
            $userLang,
            $title,
            HELP_CHAT_MODEL,
        ]);
        $convId = (int)$pdo->lastInsertId();
    }

    // Naloži zadnjih N sporočil za kontekst
    $hist = $pdo->prepare("
        SELECT role, content
        FROM (
            SELECT id, role, content
            FROM help_chat_messages
            WHERE conversation_id = ?
            ORDER BY id DESC
            LIMIT " . (int)HELP_CHAT_MAX_HISTORY . "
        ) sub
        ORDER BY id ASC
    ");
    $hist->execute([$convId]);
    $historyMessages = array_map(function ($m) {
        return ['role' => $m['role'], 'content' => $m['content']];
    }, $hist->fetchAll());

    // Dodaj trenutno user sporočilo (kot zadnji turn)
    $apiMessages = $historyMessages;
    $apiMessages[] = ['role' => 'user', 'content' => $message];

    // Shrani user sporočilo zdaj — tudi če AI klic propade, uporabnik vidi svoj input.
    $pdo->prepare("INSERT INTO help_chat_messages (conversation_id, role, content) VALUES (?, 'user', ?)")
        ->execute([$convId, $message]);

    // AI klic — Mia prejme ID trenutno izbrane restavracije, da linki gredo direktno na edit page.
    $currentRestId = null;
    if (!empty($_SESSION['restaurant_id'])) {
        $currentRestId = (int)$_SESSION['restaurant_id'];
    } elseif (!empty($session['user_id']) && ($session['role'] ?? '') === 'admin') {
        // Admin brez session.restaurant_id — vzemi prvo restavracijo, ki jo lasti.
        $rstmt = $pdo->prepare("SELECT restaurant_id FROM restaurant_admins WHERE user_id = ? ORDER BY restaurant_id ASC LIMIT 1");
        $rstmt->execute([$session['user_id']]);
        $rid = (int)$rstmt->fetchColumn();
        if ($rid) $currentRestId = $rid;
    }
    try {
        $r = _hc_call_api($apiMessages, $userLang, $currentRestId);
    } catch (Throwable $e) {
        error_log('help_chat AI error: ' . $e->getMessage());
        json_response(false, ['conversation_id' => $convId], 'AI ni dosegljiv. Poskusite znova.', 502);
    }

    $cost = _hc_calc_cost(
        $r['input_tokens'], $r['output_tokens'],
        $r['cache_create_tokens'], $r['cache_read_tokens']
    );
    $refused = preg_match('/(can only help|samo z vprašanji|samo s vprašanji|nur Fragen zur Rezble|solo con preguntas|seulement aux questions|samo s pitanjima|apenas com perguntas|posso aiutarla solo)/iu', $r['text']) ? 1 : 0;

    // Shrani assistant sporočilo z usage podatki
    $pdo->prepare("
        INSERT INTO help_chat_messages
            (conversation_id, role, content, input_tokens, output_tokens, cache_create_tokens, cache_read_tokens, cost_usd, latency_ms, refused)
        VALUES (?, 'assistant', ?, ?, ?, ?, ?, ?, ?, ?)
    ")->execute([
        $convId, $r['text'],
        $r['input_tokens'], $r['output_tokens'],
        $r['cache_create_tokens'], $r['cache_read_tokens'],
        $cost, $r['latency_ms'], $refused,
    ]);

    // Posodobi pogovor (kumulativni totals + last_message_at)
    $pdo->prepare("
        UPDATE help_chat_conversations
        SET message_count            = message_count + 2,
            total_input_tokens       = total_input_tokens       + ?,
            total_output_tokens      = total_output_tokens      + ?,
            total_cache_create_tokens= total_cache_create_tokens+ ?,
            total_cache_read_tokens  = total_cache_read_tokens  + ?,
            total_cost_usd           = total_cost_usd           + ?,
            last_message_at          = NOW()
        WHERE id = ?
    ")->execute([
        $r['input_tokens'], $r['output_tokens'],
        $r['cache_create_tokens'], $r['cache_read_tokens'],
        $cost, $convId,
    ]);

    json_response(true, [
        'conversation_id' => $convId,
        'reply'           => $r['text'],
        'usage'           => [
            'input_tokens'        => $r['input_tokens'],
            'output_tokens'       => $r['output_tokens'],
            'cache_create_tokens' => $r['cache_create_tokens'],
            'cache_read_tokens'   => $r['cache_read_tokens'],
            'cost_usd'            => $cost,
            'latency_ms'          => $r['latency_ms'],
        ],
    ]);
}

// ─── POST ?action=delete_conversation&conversation_id=N ───────────────────────
if ($method === 'POST' && $action === 'delete_conversation') {
    $body   = get_body();
    $convId = (int)($body['conversation_id'] ?? 0);
    if (!$convId) json_response(false, null, 'conversation_id manjka.', 400);
    if (!_hc_user_owns_conversation($pdo, $session, $convId)) {
        json_response(false, null, 'Dostop zavrnjen.', 403);
    }
    $pdo->prepare("DELETE FROM help_chat_conversations WHERE id=?")->execute([$convId]);
    json_response(true, null);
}

json_response(false, null, 'Metoda ali akcija nista podprti.', 405);
