<?php
/**
 * scripts/translate_widget.php
 *
 * One-off CLI: prevede WIDGET_STRINGS iz widget.js iz sl v de/it/fr/hr/es/pt.
 * Strategija: izlušči vrednosti iz sl bloka, prevedi prek Sonnet, regeneriraj
 * blok in INSERT-aj v widget.js pred zaključno `};` od WIDGET_STRINGS.
 *
 * Uporaba:
 *   BLOG_AI_INSECURE_SSL=1 php scripts/translate_widget.php           # vse manjkajoče
 *   BLOG_AI_INSECURE_SSL=1 php scripts/translate_widget.php de it     # samo izbrane
 */
if (PHP_SAPI !== 'cli') exit('CLI only.');

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/blog_anthropic.php';

$widgetPath = __DIR__ . '/../widget.js';
$src = file_get_contents($widgetPath);

// 1. Izlušči sl blok (od `sl: {` do prvega top-level `},`)
if (!preg_match('/^(\s*)sl:\s*\{(.*?)^\1\},/ms', $src, $m)) {
    fwrite(STDERR, "Ne najdem sl bloka v widget.js\n");
    exit(1);
}
$slIndent = $m[1];
$slBody   = $m[2];

// 2. Parse strings v associative array
preg_match_all('/^\s*([a-zA-Z0-9_]+):\s*(\[[^\]]*\]|\'(?:[^\'\\\\]|\\\\.)*\'|"(?:[^"\\\\]|\\\\.)*"|`[^`]*`)\s*,?\s*$/m', $slBody, $matches, PREG_SET_ORDER);
$slStrings = [];
foreach ($matches as $mm) {
    $key = $mm[1];
    $rawVal = $mm[2];
    // Strip quotes (handle both single + double + array)
    if ($rawVal[0] === '[') {
        // Array — parse inside
        if (preg_match_all('/(?:\'((?:[^\'\\\\]|\\\\.)*)\'|"((?:[^"\\\\]|\\\\.)*)")/m', $rawVal, $arrM)) {
            $arr = [];
            for ($i = 0; $i < count($arrM[0]); $i++) {
                $arr[] = $arrM[1][$i] !== '' ? $arrM[1][$i] : $arrM[2][$i];
            }
            $slStrings[$key] = $arr;
        }
    } else {
        // String
        $unq = substr($rawVal, 1, -1);
        $unq = str_replace(["\\'", '\\"', "\\\\"], ["'", '"', "\\"], $unq);
        $slStrings[$key] = $unq;
    }
}
echo "Najdenih " . count($slStrings) . " ključev v sl bloku.\n";

$ALL_TARGETS = ['de','it','fr','hr','es','pt'];
$args = array_slice($argv, 1);
$targets = empty($args) ? $ALL_TARGETS : array_values(array_intersect($args, $ALL_TARGETS));
echo "Ciljni jeziki: " . implode(', ', $targets) . "\n";
echo str_repeat('─', 60) . "\n";

// 3. Za vsak ciljni jezik: prevedi prek Sonnet, sestavi JS blok, vstavi
foreach ($targets as $lang) {
    echo "▶ {$lang} (" . blog_ai_lang_label($lang) . ")\n";

    // Preveri da blok še ne obstaja
    if (preg_match('/^\s*' . preg_quote($lang) . ':\s*\{/m', $src)) {
        echo "  ⏭  blok za {$lang} že obstaja, preskočim\n\n";
        continue;
    }

    // Pripravi flat strings za prevod (array vrednosti dodaj kot npr. "key.0", "key.1")
    $flatItems = [];
    foreach ($slStrings as $key => $val) {
        if (is_array($val)) {
            foreach ($val as $i => $v) $flatItems[$key . '.' . $i] = $v;
        } else {
            $flatItems[$key] = $val;
        }
    }
    echo "  Prevajam " . count($flatItems) . " stringov... ";
    try {
        $translated = translate_widget_batch($flatItems, $lang);
        echo "✓ " . count($translated) . " prevodov\n";
    } catch (Throwable $e) {
        fwrite(STDERR, "  ✗ napaka: " . $e->getMessage() . "\n\n");
        continue;
    }

    // Združi nazaj v iste strukture (array → array, string → string)
    $merged = [];
    foreach ($slStrings as $key => $val) {
        if (is_array($val)) {
            $merged[$key] = [];
            foreach (array_keys($val) as $i) {
                $merged[$key][] = $translated[$key . '.' . $i] ?? $val[$i];
            }
        } else {
            $merged[$key] = $translated[$key] ?? $val;
        }
    }

    // 4. Sestavi JS blok
    $newBlock = $slIndent . $lang . ": {\n";
    foreach ($merged as $key => $val) {
        if (is_array($val)) {
            $newBlock .= $slIndent . "    " . $key . ": [" . implode(', ', array_map(fn($v) => "'" . str_replace(["'", "\\"], ["\\'", "\\\\"], $v) . "'", $val)) . "],\n";
        } else {
            $escVal = str_replace(["\\", "'"], ["\\\\", "\\'"], $val);
            $newBlock .= $slIndent . "    " . $key . ": '" . $escVal . "',\n";
        }
    }
    $newBlock .= $slIndent . "},\n";

    // 5. Vstavi pred `};` (konec WIDGET_STRINGS bloka)
    $closingPattern = '/(\n\s*\};)/';
    if (preg_match($closingPattern, $src)) {
        $src = preg_replace($closingPattern, "\n" . $newBlock . '$1', $src, 1);
    } else {
        fwrite(STDERR, "  ✗ ne najdem zaključka `};`\n");
        continue;
    }
    file_put_contents($widgetPath, $src);
    echo "  💾 vstavljen blok za {$lang}\n\n";
}

echo str_repeat('─', 60) . "\n";
echo "Končano.\n";

function translate_widget_batch(array $items, string $targetLang): array {
    $sourceLabel = blog_ai_lang_label('sl');
    $targetLabel = blog_ai_lang_label($targetLang);
    $payload = json_encode($items, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    $userPrompt = <<<PROMPT
Translate the following short UI strings (booking widget) from {$sourceLabel} into {$targetLabel}.

Source items (JSON):
{$payload}

CRITICAL RULES:
- Translate naturally and idiomatically. Casual but professional tone for a restaurant booking interface.
- Preserve EXACTLY {placeholder} markers in curly braces (e.g. {min}, {max}, {time}, {n}, {date}).
- Preserve EXACTLY arrow → and other special chars where present.
- Each translation MUST be one line — no newlines inside values.
- Keep brand names ("Rezble", "Booked", "Booking") UNCHANGED.
- Keys ending with .0..6 represent days of the week (starting Monday) — translate as day names.
- Keys ending with .0..11 represent month names (starting January) — translate as month names.
- Output strict JSON only — first character is { and last is }.

OUTPUT — return strict JSON:
{ "key1": "translated", "key2": "translated", ... }
PROMPT;

    $text = blog_ai_call(BLOG_AI_MODEL_TRANSLATE, [
        ['role' => 'user', 'content' => $userPrompt],
    ], [
        'system'     => 'You translate short UI strings for a restaurant booking widget. Output strict JSON. Preserve placeholders verbatim.',
        'max_tokens' => 6000,
    ]);
    $data = blog_ai_extract_json($text);
    return is_array($data) ? $data : [];
}
