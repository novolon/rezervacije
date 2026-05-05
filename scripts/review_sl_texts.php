<?php
/**
 * scripts/review_sl_texts.php
 *
 * Pregleda lang/sl.json prek Claude Sonnet 4.6 — preveri:
 *   - slovnično pravilnost
 *   - tone of voice: Friendly, Professional, Helpful
 *   - konsistenco
 *   - smiselnost glede na kontekst (key path)
 *
 * Vrne SAMO ključe, ki jih je popravil. Aplicira spremembe nazaj v sl.json.
 *
 * Uporaba:
 *   BLOG_AI_INSECURE_SSL=1 php scripts/review_sl_texts.php           # pregled vseh
 *   BLOG_AI_INSECURE_SSL=1 php scripts/review_sl_texts.php --dry      # samo pokaži
 *   BLOG_AI_INSECURE_SSL=1 php scripts/review_sl_texts.php --prefix=email   # samo email.*
 *   BLOG_AI_INSECURE_SSL=1 php scripts/review_sl_texts.php --batch=40        # batch size
 */
if (PHP_SAPI !== 'cli') exit('CLI only.');

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/blog_anthropic.php';

$args = $argv;
array_shift($args);
$dryRun   = in_array('--dry', $args, true);
$prefix   = '';
$batchSz  = 50;
foreach ($args as $a) {
    if (strpos($a, '--prefix=') === 0) $prefix = substr($a, 9);
    if (strpos($a, '--batch=')  === 0) $batchSz = max(10, (int)substr($a, 8));
}

$slPath = __DIR__ . '/../lang/sl.json';
$sl = json_decode(file_get_contents($slPath), true);
if (!is_array($sl)) {
    fwrite(STDERR, "Neveljaven lang/sl.json\n"); exit(1);
}

// Filter
$filtered = $prefix
    ? array_filter($sl, fn($k) => strpos($k, $prefix) === 0, ARRAY_FILTER_USE_KEY)
    : $sl;

$total = count($filtered);
echo "Pregledujem {$total} ključev" . ($prefix ? " (prefix: {$prefix})" : '') . "\n";
echo "Batch size: {$batchSz}, dry run: " . ($dryRun ? 'da' : 'ne') . "\n";
echo str_repeat('─', 60) . "\n";

$batches = array_chunk($filtered, $batchSz, true);
$allCorrections = [];
$batchN = 0;

foreach ($batches as $batch) {
    $batchN++;
    $count = count($batch);
    echo "Batch {$batchN}/" . count($batches) . " ({$count} ključev)... ";
    try {
        $corrections = review_batch($batch);
        if (empty($corrections)) {
            echo "✓ brez sprememb\n";
        } else {
            echo "✓ " . count($corrections) . " predlogov:\n";
            foreach ($corrections as $key => $newText) {
                $old = $batch[$key] ?? '';
                if ($old === $newText) continue; // identical, skip
                echo "  ─ {$key}\n";
                echo "    OLD: " . mb_strimwidth($old, 0, 90, '...') . "\n";
                echo "    NEW: " . mb_strimwidth($newText, 0, 90, '...') . "\n";
                $allCorrections[$key] = $newText;
            }
        }
    } catch (Throwable $e) {
        fwrite(STDERR, "  ✗ napaka: " . $e->getMessage() . "\n");
    }
    usleep(300000);
}

echo str_repeat('─', 60) . "\n";
echo "Skupaj predlogov: " . count($allCorrections) . "\n";

if (empty($allCorrections)) {
    echo "Ni sprememb. Konec.\n";
    exit(0);
}

if ($dryRun) {
    echo "(--dry: ne pišem v lang/sl.json)\n";
    exit(0);
}

// Apliciraj v sl.json
foreach ($allCorrections as $k => $v) $sl[$k] = $v;
$jsonOut = json_encode($sl, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
file_put_contents($slPath, $jsonOut);
echo "💾 lang/sl.json posodobljen ({" . count($allCorrections) . "} ključev).\n";
echo "\nNasvet: če so spremembe pomembne (subject, button labels, error messages),\n";
echo "razmisli o ponovnem prevodu v ostale jezike s scripts/translate_emails.php\n";
echo "ali ad-hoc lang/{lang}.json edit.\n";

/* ─────────────────────────────────────────────────────────────────
 * Review batch — Sonnet returns JSON of items to correct.
 * ───────────────────────────────────────────────────────────────── */
function review_batch(array $items): array {
    $payload = json_encode($items, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

    $userPrompt = <<<PROMPT
You are reviewing Slovenian (slovenščina) UI/email texts for a SaaS app
"Rezble" (rezervacijski sistem za gostinske obrate).

Tone of voice: Friendly, Professional, Helpful.
Audience: Slovenian-speaking restaurant owners, staff, and their guests.

For each provided key, check:
1. Grammar (sklon, čas, oblika glagola, particle "se", ...)
2. Spelling (šumniki, zatipki, ...)
3. Tone:
   - Friendly: topel, vljuden, ne hladno-formalen
   - Professional: ne preveč pogovorno, ne otročje
   - Helpful: jasno pove, kaj uporabnik mora narediti / kaj se je zgodilo
4. Consistency:
   - Vikanje ("vi") POVSOD za uporabnike (admin/staff/gostje), ne tikanje
   - Capitalisation: stavki s polno piko, gumbi brez pike, naslovi brez pike
   - "email" / "e-pošta" / "e-mail" — uporabljaj "email" (krajše, sodobno)
   - "rezervacija" (ne "rezervacjia")
   - "Rezble" / "Booked" UNCHANGED (brand names)
5. Context (iz key path-a, npr. "auth.login_button" je gumb, "main.welcome" je dobrodošlica)

Preserve EXACTLY:
- HTML tags: <strong>, <em>, <a>, <br>, ...
- Placeholders: {name}, {amount}, {date}, {n}, ...
- Brand names: Rezble, Booked
- Email addresses, URLs

Return STRICT JSON only. ONLY include keys that need correction.
If a key is fine as-is, OMIT it from output.
If you find a typo or grammar error, FIX it.
If tone is too formal/cold, make it warmer.
If text is unclear, rewrite for clarity.

Input items (JSON, key → current text):
{$payload}

Output JSON shape — only changed items:
{ "key1": "corrected text", "key2": "corrected text", ... }
PROMPT;

    $text = blog_ai_call(BLOG_AI_MODEL_TRANSLATE, [
        ['role' => 'user', 'content' => $userPrompt],
    ], [
        'system'     => 'You are a Slovenian copy editor for SaaS UI strings. You catch grammar errors, improve tone, ensure consistency. Output strict JSON with only changed items. Empty {} if nothing changes.',
        'max_tokens' => 6000,
    ]);
    $data = blog_ai_extract_json($text);
    return is_array($data) ? $data : [];
}
