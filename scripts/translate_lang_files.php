<?php
/**
 * scripts/translate_lang_files.php
 *
 * Prevede manjkajoče / še-ne-prevedene ključe v lang/{en,de,it,fr,hr,es,pt}.json
 * preko Claude Sonnet 4.6.
 *
 * Tone: profesionalen, prijazen, helpful — kot v sl.json.
 * Format: ohrani HTML tage, {placeholder}, %d/%s, posebne znake.
 *
 * Usage:
 *   BLOG_AI_INSECURE_SSL=1 php scripts/translate_lang_files.php           # vse jezike
 *   BLOG_AI_INSECURE_SSL=1 php scripts/translate_lang_files.php en de     # samo izbrane
 *   BLOG_AI_INSECURE_SSL=1 php scripts/translate_lang_files.php --batch=60
 *   BLOG_AI_INSECURE_SSL=1 php scripts/translate_lang_files.php --dry --lang=en
 */
if (PHP_SAPI !== 'cli') exit('CLI only.');

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/blog_anthropic.php';

$ALL_LANGS = ['en', 'de', 'it', 'fr', 'hr', 'es', 'pt'];
$args      = array_slice($argv, 1);
$dryRun    = in_array('--dry', $args, true);
$batchSz   = 60;
$onlyLang  = null;
$targets   = [];

foreach ($args as $a) {
    if (strpos($a, '--batch=') === 0) { $batchSz = max(20, (int)substr($a, 8)); continue; }
    if (strpos($a, '--lang=')  === 0) { $onlyLang = substr($a, 7); continue; }
    if ($a === '--dry') continue;
    if (in_array($a, $ALL_LANGS, true)) $targets[] = $a;
}
if ($onlyLang) $targets = [$onlyLang];
if (empty($targets)) $targets = $ALL_LANGS;

$slPath = __DIR__ . '/../lang/sl.json';
$sl     = json_decode(file_get_contents($slPath), true);
if (!is_array($sl)) { fwrite(STDERR, "Bad sl.json\n"); exit(1); }

echo "Targets: " . implode(', ', $targets) . "\n";
echo "Batch size: {$batchSz}, dry: " . ($dryRun ? 'yes' : 'no') . "\n";
echo str_repeat('─', 64) . "\n";

foreach ($targets as $lang) {
    $tgtPath = __DIR__ . "/../lang/$lang.json";
    $tgt     = json_decode(file_get_contents($tgtPath), true);
    if (!is_array($tgt)) { echo "$lang: bad json, skip\n"; continue; }

    // Najdi ključe za prevod (3 razlogi):
    // 1. Manjkajoči ključ.
    // 2. Vrednost identična trenutni SL vrednosti.
    // 3. Vrednost vsebuje slovenske markerje (zastarel prevod ki ni bil osvežen).
    //    Za HR detektor preskočimo (preveč legitimno-istih besed s SL).
    //    Za ostale langs: š/č/ž + slovenski funkcijski/lemmatični markerji.
    $todo = [];
    $detectStaleSL = !in_array($lang, ['hr'], true);
    // Široka slovenska beseda/stemi, ki se NE pojavijo v EN/DE/IT/FR/ES/PT.
    // Word boundaries (?<=^|\W) + (?=$|\W) za case-insensitive match.
    $slStems = [
        'ostani','ostanite','prijavljen','prijavljeni','prijavljena','odjava',
        'shrani','shranjen','shranjeno','uredi','urejeno','urejena',
        'izberi','izbrano','izbrana','izbrani','potrdi','potrjeno','potrjena',
        'prekliči','prekličete','preklic','poslan','poslano','poslana',
        'dodaj','dodano','dodana','odstrani','odstranjeno',
        'nastavitve','rezervacij','rezervacija','rezervacijo','rezervacije','rezervacijah','rezervacijam',
        'gostje','gostov','gostom','gostu','gostje','mize','mizah','mizo',
        'urnik','urniku','urnika','paket','paketa','paketom','paketu',
        'naročnina','naročnine','naročnino','račun','računa','računi',
        'popust','popusta','popuste','popusti',
        'pošlji','pošljite','pošlje','pošljemo',
        'kadar','koli','katerakoli','katerokoli','vsebuje','označi',
        'preverit','preverjen','preverjena','preverite',
        'pošljemo','poslan','sporočilo','sporočila','sporočil',
        'najprej','najprej','obdobje','obdobja','obdobjem','obdobju',
        'trialom','trialu','trial','preizkus','preizkušn',
        'med trial','vaše','vašega','vašo','vaši',
        'razpored','razporeda','razporedu',
        'celotno','celotne','celotni','celotnem',
        'naprej','nazaj','spodaj','zgoraj',
        'prosim','prosimo','prosite',
        'sem','smo','ste','so', // — but these ARE valid English/IT! skip these
    ];
    // Filter out ambiguous short words to avoid false positives in English/Romance.
    $ambiguous = ['sem','smo','ste','so','kot','vsi','vse','vsa'];
    $slStems = array_values(array_diff($slStems, $ambiguous));

    // Specifično slovenski znaki (ne v target langu).
    // EN/DE/IT/FR/ES/PT nikoli nimajo š,č,ž.
    $hasSlovenianChar = function($s) {
        return is_string($s) && preg_match('/[ščžŠČŽ]/u', $s);
    };

    // Compile word boundary regexes for stems (Unicode-aware).
    $stemRegex = '/(?<![\p{L}\p{N}])(' . implode('|', array_map(fn($w) => preg_quote($w, '/'), $slStems)) . ')(?![\p{L}\p{N}])/iu';

    foreach ($sl as $k => $v) {
        if (!isset($tgt[$k])) { $todo[$k] = $v; continue; }
        if ($tgt[$k] === $v)  { $todo[$k] = $v; continue; }
        if ($detectStaleSL && is_string($tgt[$k])) {
            // Numeri, enote, kode → preskoči.
            if (mb_strlen(trim($tgt[$k])) < 3) continue;
            // Slovenian distinct char → stale.
            if ($hasSlovenianChar($tgt[$k])) { $todo[$k] = $v; continue; }
            // Slovenian stem match → stale.
            if (preg_match($stemRegex, $tgt[$k])) { $todo[$k] = $v; continue; }
        }
    }
    $count = count($todo);
    echo "▶ {$lang} ({$count} keys to translate)\n";
    if ($count === 0) { echo "  ⏭  nothing to do\n\n"; continue; }

    $batches = array_chunk($todo, $batchSz, true);
    $allTrans = [];
    $batchN = 0;
    foreach ($batches as $batch) {
        $batchN++;
        $sz = count($batch);
        echo "  Batch {$batchN}/" . count($batches) . " ({$sz} keys)... ";
        $attempt = 0;
        $maxAttempts = 4;
        while ($attempt < $maxAttempts) {
            $attempt++;
            try {
                $translated = translate_batch_to_lang($batch, $lang);
                $allTrans = array_merge($allTrans, $translated);
                echo "✓ " . count($translated) . "\n";
                break;
            } catch (Throwable $e) {
                $msg = $e->getMessage();
                $isRate = stripos($msg, 'rate limit') !== false || stripos($msg, '429') !== false;
                if ($isRate && $attempt < $maxAttempts) {
                    $waitSec = 30 * $attempt;
                    echo "⏳ rate limit, čakam {$waitSec}s (poskus {$attempt}/{$maxAttempts})... ";
                    sleep($waitSec);
                    continue;
                }
                echo "✗ " . substr($msg, 0, 120) . "\n";
                break;
            }
        }
        // Pavza med batchi za zaščito pred TPM ratelimitom
        sleep(8);
    }

    // Merge — samo ključe, ki smo jih dejansko prevedli (drži obstoječe).
    foreach ($allTrans as $k => $v) {
        if (is_string($v) && $v !== '') $tgt[$k] = $v;
    }

    if ($dryRun) {
        echo "  (dry) " . count($allTrans) . " translations not written\n\n";
        continue;
    }
    file_put_contents($tgtPath, json_encode($tgt, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n");
    echo "  💾 saved " . count($allTrans) . " translations to lang/{$lang}.json\n\n";
}

echo str_repeat('─', 64) . "\n";
echo "Done.\n";

function translate_batch_to_lang(array $batch, string $targetLang): array {
    $sourceLabel = blog_ai_lang_label('sl');
    $targetLabel = blog_ai_lang_label($targetLang);

    // Items as JSON (key -> SL value)
    $payload = json_encode($batch, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

    $userPrompt = <<<PROMPT
Translate these UI strings from {$sourceLabel} into {$targetLabel} for **Rezble**, a SaaS reservation system for restaurants.

Source items (JSON, key → SL value):
{$payload}

TONE & STYLE — match the SL master:
- Profesionalen, prijazen, helpful (informal but respectful: "tvoj/vaš" choice → use formal `vy/Sie/sie/lei/usted/você` consistently in target language).
- Restaurant/hospitality industry vocabulary. Speak to restaurant owners and staff.
- Clear, concise, action-oriented (button labels, form labels, toast messages).
- Idiomatic, not literal. Localize: dates, hours, currency formatting where it appears as a literal example.

CRITICAL RULES — preserve EXACTLY:
1. {placeholder} markers in curly braces — e.g. {count}, {plan}, {days}, {dayWord}, {price}, {orig}, {final}, {year}, {min}, {max}, {time}, {date}, {label}, {name}, {email}, {url}, {n}, {q}, {terms_url}, {privacy_url}.
2. printf-style markers: %s, %d, %1\$s, %2\$d.
3. HTML tags: <strong>, <em>, <a>, <br>, <span>, <code>, etc. Keep all attributes intact (href, class, target, style, ...).
4. Newlines (\\n) and other escape sequences.
5. Brand names UNCHANGED: Rezble, Booked, Stripe, Mailgun, RacunHub, Anthropic, OpenAI, Unsplash, Slack.
6. Keys ending in `.0`–`.6` are days of week (starts Monday). Translate accordingly.
7. Keys ending in `.0`–`.11` (months.X) are months (starts January).
8. Single emoji or icon characters → keep verbatim.

KEY TYPES (interpret context from key path):
- `auth.*` → auth/login/register UI
- `re.*` → restaurant edit (Splošno, Urnik, Booking, Anketa, Mize tabs)
- `book.*` → public booking flow (guest-facing!) — use polite tone for guests
- `email.*` → email subjects and bodies (full sentences, professional)
- `landing.*` → marketing landing page (energetic, value-focused)
- `booked.*` → blog content (editorial, friendly)
- `billing.*` → payments and invoices (clear, reassuring)
- `superadmin.*` → admin tooling (terse, technical)
- `common.*` → generic UI labels and toasts (short, action-oriented)

OUTPUT — strict JSON, first char `{`, last char `}`. Only the keys from input. Each value is a single string. No commentary, no markdown.
{ "key.path": "translated value", ... }
PROMPT;

    $text = blog_ai_call(BLOG_AI_MODEL_TRANSLATE, [
        ['role' => 'user', 'content' => $userPrompt],
    ], [
        'system'     => "You translate restaurant SaaS UI strings. Output STRICT JSON: every value is a JSON string, every literal double-quote inside a value MUST be backslash-escaped (\\\"). When the source uses guillemets (« » or » «) or curly quotes, KEEP them as guillemets/curly quotes — do NOT replace with ASCII \". When you need quotation marks inside an English/other-lang value, prefer single quotes ('like this') or curly quotes (\u201Clike this\u201D). Preserve placeholders ({foo}, %s, %d), HTML tags (<strong>, <em>), escape sequences (\\n), brand names, and printf markers verbatim. Match the friendly + professional tone of the source.",
        'max_tokens' => 32000,
    ]);
    $data = blog_ai_extract_json($text);
    return is_array($data) ? $data : [];
}
