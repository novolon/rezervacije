<?php
/**
 * scripts/translate_emails.php
 *
 * One-off CLI script: prevede vse email.* ključe iz lang/sl.json
 * v 7 ostalih jezikov (en, de, it, fr, hr, es, pt) preko Sonnet.
 * Ohranja HTML tag-e in {placeholder}-je nedotaknjene.
 *
 * Uporaba:
 *   php scripts/translate_emails.php           # vse jezike
 *   php scripts/translate_emails.php en de     # samo izbrane
 *   php scripts/translate_emails.php --dry     # ne piši v fajle
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/blog_anthropic.php';

$args = array_slice($argv, 1);
$dryRun = in_array('--dry', $args, true);
$args = array_filter($args, fn($a) => $a !== '--dry');

$ALL_TARGETS = ['en','de','it','fr','hr','es','pt'];
$targets = empty($args) ? $ALL_TARGETS : array_values(array_intersect($args, $ALL_TARGETS));
if (empty($targets)) {
    fwrite(STDERR, "Ni veljavnih ciljnih jezikov.\nUporaba: php scripts/translate_emails.php [en de it fr hr es pt] [--dry]\n");
    exit(1);
}

$slPath = __DIR__ . '/../lang/sl.json';
$sl = json_decode(file_get_contents($slPath), true);
if (!is_array($sl)) {
    fwrite(STDERR, "lang/sl.json je neveljaven JSON.\n");
    exit(1);
}

// Filtriraj samo email.* ključe
$emailKeys = array_filter($sl, fn($k) => strpos($k, 'email.') === 0, ARRAY_FILTER_USE_KEY);
$count = count($emailKeys);
echo "Najdenih {$count} email.* ključev v sl.json.\n";
echo "Ciljni jeziki: " . implode(', ', $targets) . "\n";
if ($dryRun) echo "(--dry mode: ne bom pisal v fajle)\n";
echo str_repeat('─', 60) . "\n";

// Razdeli na batche po ~30 ključev (da ostanemo varno pod max_tokens limitom)
$batchSize = 35;
$batches = array_chunk($emailKeys, $batchSize, true);
echo "Razdelil v " . count($batches) . " batch-ov po max {$batchSize} ključev.\n\n";

foreach ($targets as $lang) {
    echo "▶ {$lang} (" . blog_ai_lang_label($lang) . ")\n";
    $langPath = __DIR__ . '/../lang/' . $lang . '.json';
    $existing = is_file($langPath) ? (json_decode(file_get_contents($langPath), true) ?: []) : [];

    $translated = [];
    $batchN = 0;
    foreach ($batches as $batch) {
        $batchN++;
        $batchKeys = array_keys($batch);
        echo "  Batch {$batchN}/" . count($batches) . " (" . count($batch) . " ključev)... ";
        try {
            $result = translate_email_batch($batch, $lang);
            foreach ($batchKeys as $k) {
                if (!isset($result[$k])) {
                    fwrite(STDERR, "    ⚠ manjka prevod za {$k}\n");
                    continue;
                }
                $translated[$k] = $result[$k];
            }
            echo "✓ " . count($result) . " prevodov\n";
        } catch (Throwable $e) {
            fwrite(STDERR, "  ✗ {$batchN} spodletel: " . $e->getMessage() . "\n");
        }
        usleep(300000); // 300ms med klici
    }

    // Združi: nove email.* prevode v existing JSON, ohrani vse ostalo
    foreach ($translated as $k => $v) {
        $existing[$k] = $v;
    }

    // Sortiraj — ohrani vrstni red kot v sl.json (za enostavnejši diff)
    $sorted = [];
    foreach (array_keys($sl) as $k) {
        if (isset($existing[$k])) $sorted[$k] = $existing[$k];
    }
    // Dodaj morebitne ključe, ki jih sl.json nima ampak je v existing (ne bi se smelo zgoditi)
    foreach ($existing as $k => $v) {
        if (!isset($sorted[$k])) $sorted[$k] = $v;
    }

    if ($dryRun) {
        echo "  [dry] preskočil pisanje v {$langPath}\n";
    } else {
        $jsonOut = json_encode($sorted, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        file_put_contents($langPath, $jsonOut);
        echo "  💾 zapisal " . count($translated) . " novih email.* ključev v {$langPath}\n";
    }
    echo "\n";
}

echo str_repeat('─', 60) . "\n";
echo "Končano.\n";

/**
 * Prevedi en batch ključev v izbrani jezik. Ohranja HTML in {placeholder}.
 */
function translate_email_batch(array $items, string $targetLang): array {
    $sourceLabel = blog_ai_lang_label('sl');
    $targetLabel = blog_ai_lang_label($targetLang);
    $payload = json_encode($items, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

    $userPrompt = <<<PROMPT
Translate the following short marketing/email strings from {$sourceLabel} into {$targetLabel}.

Source items (JSON, key → text):
{$payload}

CRITICAL RULES:
- Translate naturally and idiomatically. Do NOT translate brand names ("Rezble", "Rezervacije", "Booked").
- Preserve EXACTLY (do NOT translate, move, or alter):
  * All HTML tags: <strong>, <em>, <a>, <br>, <a href='mailto:...'>, etc.
  * Placeholders in curly braces: {name}, {appName}, {amount}, {plan}, {date}, {time}, {n}, {link}, {when}, {ref}, {code}, {percent}, {restName}, {rest}, {guests}, {email}, {status}, {type}, {adminName}
  * Entity references: &amp;, &nbsp;, etc.
  * Email addresses (e.g. zasebnost@rezervacije.si, info@rezervacije.si)
  * Inline CSS in style="..." attributes (color codes, font names)
- Each translation MUST fit on one line — escape newlines as \\n inside string values.
- For email body texts, use formal "you" form (Sie/Vy/Vi) where appropriate per locale convention.
- Day-name keys ('days.0' to 'days.6') represent days of the week starting with Monday.

OUTPUT — return STRICT JSON only (no markdown fences, no commentary):
{
  "key1": "translated text 1",
  "key2": "translated text 2",
  ...
}
PROMPT;

    $text = blog_ai_call(BLOG_AI_MODEL_TRANSLATE, [
        ['role' => 'user', 'content' => $userPrompt],
    ], [
        'system'     => 'You translate short HTML-aware marketing/email strings. Output strict JSON. Preserve HTML tags, {placeholders}, and brand names verbatim.',
        'max_tokens' => 8000,
    ]);
    $data = blog_ai_extract_json($text);
    if (!is_array($data)) {
        throw new RuntimeException('AI ni vrnil veljavnega JSON-a.');
    }
    return $data;
}
