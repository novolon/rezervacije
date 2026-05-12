<?php
/**
 * Superadmin: pregled AI Help Chat zgodovine + tokeni / stroški per pogovor.
 */
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/lang.php';

if (!is_logged_in()) redirect_to_login();
if ($_SESSION['role'] !== 'superadmin') {
    header('Location: ' . BASE_PATH . '/pages/main.php');
    exit;
}

$pdo = getDB();

// Detail view če je conversation_id v URL-ju
$detailId = isset($_GET['conv']) ? (int)$_GET['conv'] : 0;
$detail   = null;
$detailMessages = [];
if ($detailId) {
    $stmt = $pdo->prepare("
        SELECT c.*, u.full_name AS user_full_name, u.email AS user_real_email
        FROM help_chat_conversations c
        LEFT JOIN users u ON c.user_id = u.id
        WHERE c.id = ?
    ");
    $stmt->execute([$detailId]);
    $detail = $stmt->fetch();
    if ($detail) {
        $mStmt = $pdo->prepare("
            SELECT id, role, content, input_tokens, output_tokens,
                   cache_create_tokens, cache_read_tokens, cost_usd, latency_ms, refused, created_at
            FROM help_chat_messages
            WHERE conversation_id = ?
            ORDER BY id ASC
        ");
        $mStmt->execute([$detailId]);
        $detailMessages = $mStmt->fetchAll();
    }
}

// Seznam (z agregati)
$stmt = $pdo->query("
    SELECT
        c.id, c.user_id, c.user_name, c.user_email, c.user_role, c.user_lang,
        c.title, c.message_count, c.total_input_tokens, c.total_output_tokens,
        c.total_cache_create_tokens, c.total_cache_read_tokens, c.total_cost_usd,
        c.model, c.created_at, c.last_message_at,
        u.full_name AS user_full_name, u.email AS user_real_email,
        r.name AS restaurant_name
    FROM help_chat_conversations c
    LEFT JOIN users u       ON c.user_id = u.id
    LEFT JOIN restaurants r ON c.restaurant_id = r.id
    ORDER BY COALESCE(c.last_message_at, c.created_at) DESC
    LIMIT 200
");
$conversations = $stmt->fetchAll();

// Globalni totali
$tot = $pdo->query("
    SELECT
        COUNT(*) AS conv_count,
        COALESCE(SUM(total_input_tokens),0)        AS sum_in,
        COALESCE(SUM(total_output_tokens),0)       AS sum_out,
        COALESCE(SUM(total_cache_create_tokens),0) AS sum_cw,
        COALESCE(SUM(total_cache_read_tokens),0)   AS sum_cr,
        COALESCE(SUM(total_cost_usd),0)            AS sum_cost,
        COALESCE(SUM(message_count),0)             AS sum_msgs
    FROM help_chat_conversations
")->fetch();

function _hc_eur(float $usd): string {
    // Približna konverzija (vizualno – API-jev pravi tečaj ne potrebujemo).
    $eur = $usd * 0.92;
    return number_format($eur, 4, '.', '') . ' €';
}
function _hc_fmt_tokens(int $n): string {
    if ($n >= 1000) return number_format($n / 1000, 1, '.', '') . 'k';
    return (string)$n;
}
?><!DOCTYPE html>
<html lang="<?= get_lang() ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="icon" type="image/svg+xml" href="<?= BASE_PATH ?>/assets/images/icon.svg">
<title>AI Help Chat – Superadmin – <?= htmlspecialchars(APP_NAME, ENT_QUOTES) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= BASE_PATH ?>/assets/css/tokens.css">
<link rel="stylesheet" href="<?= BASE_PATH ?>/assets/css/rezble.css?v=<?= @filemtime(__DIR__ . '/../assets/css/rezble.css') ?>">
<style>
body{font-family:var(--font),system-ui;background:var(--bg-soft,#fafaf7);color:var(--ink,#111827);margin:0}
.hc-wrap{max-width:1280px;margin:0 auto;padding:24px}
.hc-head{display:flex;align-items:center;justify-content:space-between;margin-bottom:20px}
.hc-head h1{font-size:22px;font-weight:700;margin:0}
.hc-back{color:var(--ink-mute,#6b7280);text-decoration:none;font-size:13.5px}
.hc-stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px;margin-bottom:24px}
.hc-stat{background:#fff;border:1px solid var(--line,#e5e7eb);border-radius:10px;padding:14px}
.hc-stat-lbl{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--ink-mute,#6b7280)}
.hc-stat-val{font-size:22px;font-weight:700;margin-top:4px;font-family:var(--font-mono),ui-monospace,monospace}
.hc-stat-sub{font-size:11px;color:var(--ink-mute,#6b7280);margin-top:2px}
.hc-table{background:#fff;border:1px solid var(--line,#e5e7eb);border-radius:10px;overflow:hidden}
.hc-table table{width:100%;border-collapse:collapse;font-size:13px}
.hc-table th,.hc-table td{padding:9px 12px;text-align:left;border-bottom:1px solid var(--line,#e5e7eb)}
.hc-table th{background:var(--bg-sunken,#f9fafb);font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--ink-mute,#6b7280)}
.hc-table tbody tr:hover{background:var(--bg-sunken,#f9fafb);cursor:pointer}
.hc-table .num{font-family:var(--font-mono),ui-monospace,monospace;text-align:right}
.hc-empty{padding:32px;text-align:center;color:var(--ink-mute,#6b7280)}
.hc-chip{display:inline-block;padding:1px 7px;border-radius:999px;font-size:11px;font-weight:600;background:var(--bg-sunken,#f3f4f6);color:var(--ink-mute,#6b7280)}
.hc-chip.role-admin{background:rgba(37,99,235,.1);color:#1d4ed8}
.hc-chip.role-user{background:rgba(16,185,129,.1);color:#047857}
.hc-chip.role-superadmin{background:rgba(168,85,247,.1);color:#7e22ce}
.hc-detail{background:#fff;border:1px solid var(--line,#e5e7eb);border-radius:10px;padding:16px;margin-bottom:24px}
.hc-detail h2{margin:0 0 14px;font-size:16px;font-weight:700}
.hc-detail-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:10px;margin-bottom:16px}
.hc-detail-cell{padding:10px 12px;background:var(--bg-sunken,#f9fafb);border-radius:8px}
.hc-detail-k{font-size:10.5px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--ink-mute,#6b7280)}
.hc-detail-v{font-size:13px;font-weight:500;margin-top:2px;word-break:break-word}
.hc-msg{padding:10px 12px;border-radius:9px;margin-bottom:8px;font-size:13.5px;line-height:1.55;white-space:pre-wrap;word-break:break-word}
.hc-msg.user{background:rgba(37,99,235,.07);border-left:3px solid var(--accent,#2563eb)}
.hc-msg.assistant{background:#fafaf7;border-left:3px solid var(--ink-mute,#9ca3af)}
.hc-msg-meta{font-size:10.5px;color:var(--ink-mute,#6b7280);margin-top:4px;font-family:var(--font-mono),ui-monospace,monospace}
.hc-msg.refused{border-left-color:#f59e0b}
</style>
</head>
<body>
<div class="hc-wrap">

  <div class="hc-head">
    <h1>AI Help Chat — pregled</h1>
    <a href="<?= BASE_PATH ?>/pages/superadmin.php" class="hc-back">← Superadmin</a>
  </div>

  <?php if ($detail): ?>
    <a href="<?= BASE_PATH ?>/pages/superadmin_help.php" class="hc-back" style="display:inline-block;margin-bottom:12px">← Vsi pogovori</a>

    <div class="hc-detail">
      <h2><?= htmlspecialchars($detail['title'] ?? '(brez naslova)') ?></h2>
      <div class="hc-detail-grid">
        <div class="hc-detail-cell"><div class="hc-detail-k">Uporabnik</div><div class="hc-detail-v"><?= htmlspecialchars($detail['user_full_name'] ?? $detail['user_name'] ?? '—') ?></div></div>
        <div class="hc-detail-cell"><div class="hc-detail-k">E-mail</div><div class="hc-detail-v"><?= htmlspecialchars($detail['user_real_email'] ?? $detail['user_email'] ?? '—') ?></div></div>
        <div class="hc-detail-cell"><div class="hc-detail-k">Vloga</div><div class="hc-detail-v"><?= htmlspecialchars($detail['user_role'] ?? '—') ?></div></div>
        <div class="hc-detail-cell"><div class="hc-detail-k">Jezik</div><div class="hc-detail-v"><?= htmlspecialchars(strtoupper($detail['user_lang'] ?? '—')) ?></div></div>
        <div class="hc-detail-cell"><div class="hc-detail-k">Začet</div><div class="hc-detail-v"><?= htmlspecialchars($detail['created_at']) ?></div></div>
        <div class="hc-detail-cell"><div class="hc-detail-k">Zadnje</div><div class="hc-detail-v"><?= htmlspecialchars($detail['last_message_at'] ?? '—') ?></div></div>
        <div class="hc-detail-cell"><div class="hc-detail-k">Sporočil</div><div class="hc-detail-v"><?= (int)$detail['message_count'] ?></div></div>
        <div class="hc-detail-cell"><div class="hc-detail-k">Model</div><div class="hc-detail-v" style="font-size:11.5px;font-family:var(--font-mono),ui-monospace,monospace"><?= htmlspecialchars($detail['model'] ?? '—') ?></div></div>
        <div class="hc-detail-cell"><div class="hc-detail-k">Input tokens</div><div class="hc-detail-v"><?= number_format((int)$detail['total_input_tokens']) ?></div></div>
        <div class="hc-detail-cell"><div class="hc-detail-k">Output tokens</div><div class="hc-detail-v"><?= number_format((int)$detail['total_output_tokens']) ?></div></div>
        <div class="hc-detail-cell"><div class="hc-detail-k">Cache write</div><div class="hc-detail-v"><?= number_format((int)$detail['total_cache_create_tokens']) ?></div></div>
        <div class="hc-detail-cell"><div class="hc-detail-k">Cache read</div><div class="hc-detail-v"><?= number_format((int)$detail['total_cache_read_tokens']) ?></div></div>
        <div class="hc-detail-cell" style="background:rgba(37,99,235,.08)"><div class="hc-detail-k">Strošek</div><div class="hc-detail-v">$<?= number_format((float)$detail['total_cost_usd'], 6, '.', '') ?> · <?= _hc_eur((float)$detail['total_cost_usd']) ?></div></div>
      </div>

      <h2 style="margin-top:14px">Sporočila</h2>
      <?php if (empty($detailMessages)): ?>
        <div class="hc-empty">Ni sporočil.</div>
      <?php else: ?>
        <?php foreach ($detailMessages as $m): ?>
          <div class="hc-msg <?= $m['role'] ?><?= $m['refused'] ? ' refused' : '' ?>">
            <?= htmlspecialchars($m['content']) ?>
            <?php if ($m['role'] === 'assistant'): ?>
              <div class="hc-msg-meta">
                <?= htmlspecialchars($m['created_at']) ?>
                · in <?= number_format((int)$m['input_tokens']) ?>
                · out <?= number_format((int)$m['output_tokens']) ?>
                <?php if ((int)$m['cache_read_tokens']): ?>· cache-r <?= number_format((int)$m['cache_read_tokens']) ?><?php endif ?>
                <?php if ((int)$m['cache_create_tokens']): ?>· cache-w <?= number_format((int)$m['cache_create_tokens']) ?><?php endif ?>
                · $<?= number_format((float)$m['cost_usd'], 6, '.', '') ?>
                <?php if ((int)$m['latency_ms']): ?>· <?= (int)$m['latency_ms'] ?>ms<?php endif ?>
                <?php if ($m['refused']): ?>· <span style="color:#d97706;font-weight:700">REFUSED</span><?php endif ?>
              </div>
            <?php else: ?>
              <div class="hc-msg-meta"><?= htmlspecialchars($m['created_at']) ?></div>
            <?php endif ?>
          </div>
        <?php endforeach ?>
      <?php endif ?>
    </div>
  <?php endif; ?>

  <!-- Globalni totals -->
  <div class="hc-stats">
    <div class="hc-stat"><div class="hc-stat-lbl">Pogovori</div><div class="hc-stat-val"><?= number_format((int)$tot['conv_count']) ?></div></div>
    <div class="hc-stat"><div class="hc-stat-lbl">Sporočila</div><div class="hc-stat-val"><?= number_format((int)$tot['sum_msgs']) ?></div></div>
    <div class="hc-stat"><div class="hc-stat-lbl">Input tokens</div><div class="hc-stat-val"><?= number_format((int)$tot['sum_in']) ?></div><div class="hc-stat-sub">$1.00 / MTok</div></div>
    <div class="hc-stat"><div class="hc-stat-lbl">Output tokens</div><div class="hc-stat-val"><?= number_format((int)$tot['sum_out']) ?></div><div class="hc-stat-sub">$5.00 / MTok</div></div>
    <div class="hc-stat"><div class="hc-stat-lbl">Cache read</div><div class="hc-stat-val"><?= number_format((int)$tot['sum_cr']) ?></div><div class="hc-stat-sub">$0.10 / MTok</div></div>
    <div class="hc-stat"><div class="hc-stat-lbl">Cache write</div><div class="hc-stat-val"><?= number_format((int)$tot['sum_cw']) ?></div><div class="hc-stat-sub">$1.25 / MTok</div></div>
    <div class="hc-stat" style="background:rgba(37,99,235,.06)"><div class="hc-stat-lbl">Skupni strošek</div><div class="hc-stat-val">$<?= number_format((float)$tot['sum_cost'], 4, '.', '') ?></div><div class="hc-stat-sub"><?= _hc_eur((float)$tot['sum_cost']) ?></div></div>
  </div>

  <!-- Seznam pogovorov -->
  <div class="hc-table">
    <table>
      <thead>
        <tr>
          <th>ID</th>
          <th>Naslov</th>
          <th>Uporabnik</th>
          <th>Vloga</th>
          <th>Restavracija</th>
          <th class="num">Sporočil</th>
          <th class="num">In</th>
          <th class="num">Out</th>
          <th class="num">Cache</th>
          <th class="num">Strošek</th>
          <th>Čas</th>
        </tr>
      </thead>
      <tbody>
      <?php if (empty($conversations)): ?>
        <tr><td colspan="11" class="hc-empty">Ni pogovorov.</td></tr>
      <?php else: ?>
        <?php foreach ($conversations as $c):
            $url = BASE_PATH . '/pages/superadmin_help.php?conv=' . (int)$c['id'];
            $totalCache = (int)$c['total_cache_create_tokens'] + (int)$c['total_cache_read_tokens'];
        ?>
          <tr onclick="location.href='<?= $url ?>'">
            <td>#<?= (int)$c['id'] ?></td>
            <td style="max-width:280px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= htmlspecialchars($c['title'] ?? '—') ?></td>
            <td><?= htmlspecialchars($c['user_full_name'] ?? $c['user_name'] ?? '—') ?></td>
            <td><span class="hc-chip role-<?= htmlspecialchars($c['user_role'] ?? '') ?>"><?= htmlspecialchars($c['user_role'] ?? '—') ?></span></td>
            <td><?= htmlspecialchars($c['restaurant_name'] ?? '—') ?></td>
            <td class="num"><?= (int)$c['message_count'] ?></td>
            <td class="num"><?= _hc_fmt_tokens((int)$c['total_input_tokens']) ?></td>
            <td class="num"><?= _hc_fmt_tokens((int)$c['total_output_tokens']) ?></td>
            <td class="num" title="cache write + read"><?= _hc_fmt_tokens($totalCache) ?></td>
            <td class="num">$<?= number_format((float)$c['total_cost_usd'], 5, '.', '') ?></td>
            <td><?= htmlspecialchars($c['last_message_at'] ?? $c['created_at']) ?></td>
          </tr>
        <?php endforeach ?>
      <?php endif ?>
      </tbody>
    </table>
  </div>

</div>
</body>
</html>
