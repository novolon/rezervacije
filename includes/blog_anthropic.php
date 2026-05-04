<?php
/**
 * blog_anthropic.php – cURL wrapper za Claude API + 4 high-level funkcije:
 *   - blog_ai_suggest_topics($n)
 *   - blog_ai_generate_article($topic, $lang, $opts)
 *   - blog_ai_translate_translation($post_id, $source_lang, $target_lang)
 *   - blog_ai_translate_media_alts($media_id, $source_lang, $target_langs)
 *   - blog_ai_suggest_tags($content)
 *
 * Modeli:
 *   - generate / suggest_topics: claude-opus-4-7
 *   - translate / suggest_tags : claude-sonnet-4-6 (boljša cena/kvaliteta razmerje za prevode)
 *
 * Vse funkcije so SUPERADMIN-only – klicalec mora to zagotoviti.
 */

if (!defined('ANTHROPIC_API_KEY') || !ANTHROPIC_API_KEY) {
    // Brez ključa funkcije ne morejo delati – metanje napake je odgovornost klicalca.
}

const BLOG_AI_MODEL_GENERATE  = 'claude-opus-4-7';
const BLOG_AI_MODEL_TRANSLATE = 'claude-sonnet-4-6';
const BLOG_AI_API_VERSION     = '2023-06-01';
const BLOG_AI_API_URL         = 'https://api.anthropic.com/v1/messages';

/* ─────────────────────────────────────────────────────────────────
 * RESBLE FEATURE TRUTH – AI ne sme omenjati ničesar zunaj tega.
 * Ta seznam mora biti single source of truth za vse AI prompte.
 * Sinhroniziran z includes/plans.php (`user_has_feature`).
 * ───────────────────────────────────────────────────────────────── */
function blog_ai_app_facts() {
    return <<<FACTS
PRODUCT: Rezble (also called "Rezervacije") — multi-tenant SaaS reservation system for hospitality businesses (restaurants, pizzerias, cafes, bistros, taverns, gostilne).

TARGET AUDIENCE: hospitality owners and managers — small to mid-size restaurants, pizzerias, cafes, gostilne. Many are non-technical, busy, balancing service + admin work.

CORE FEATURES (these are the ONLY features you may mention):
- Reservations management: full calendar + day schedule, manual booking entry, edit, cancel
- Multi-restaurant: one owner can manage multiple venues from one account
- Staff management: invite users with restricted access (single restaurant, view + manage reservations)
- Public booking page: shareable link guests use to book online 24/7 without login (Advanced/Premium plans)
- Embeddable booking widget: drop a script on the restaurant's own website (Premium plan)
- Booking approval mode: requests are pending until staff confirms (Advanced/Premium)
- Auto-confirm mode: bookings confirmed instantly (Premium)
- Email notifications: confirmation, cancellation, reservation summaries (Advanced/Premium)
- 24h reminder emails to guests, sent automatically by background cron (Advanced/Premium)
- SMS notifications: text message confirmations and reminders (Premium)
- Table management: define tables, areas, capacity, merge groups for combining tables for big parties; auto-allocate or manual assignment (Advanced/Premium)
- Waitlist: when slot is full, guest joins waitlist with 2-hour confirm window; cascade notifications when slot opens (Advanced/Premium)
- Guest database: every booking enriches a per-restaurant guest profile with history, notes, allergies, contact (Advanced/Premium)
- Surveys: post-visit feedback forms with custom questions, multi-language responses, CSV export (Premium)
- Custom branding: hide "powered by Rezble", upload logo to public booking page (Premium)
- GDPR compliance: data export, anonymization request handling, automated 3-year cleanup
- Multi-language: full Slovenian/English/German/Italian/French/Croatian/Spanish/Portuguese support
- Plans: Trial (free), Basic (4.99 EUR/mo), Advanced (6.99 EUR/mo), Premium (9.99 EUR/mo), with annual options

EXPLICITLY NOT IN THE PRODUCT (do NOT mention or invent):
- Online ordering / takeout / delivery
- POS integration, payments at the door, table-side payments
- Inventory management, recipe costing, food waste tracking
- Loyalty programs, gift cards, vouchers
- Marketing automation (newsletters, segmented campaigns)
- Staff scheduling / shift management / payroll
- Kitchen display systems (KDS)
- AI table optimization / dynamic pricing
- Reviews aggregation (Google/TripAdvisor)
- Mobile native apps (the app is web-only, but mobile-responsive)

URL: app.rezervacije.si  |  Brand: Rezble  |  Blog: Booked

VOICE & STYLE:
- Friendly, professional, helpful — never condescending or salesy
- Speak directly to the owner: "you", "your guests", "your restaurant"
- Practical: real numbers, real workflows, real before/after scenarios
- SEO-aware: naturally include the target keyword in title, H1 (= title), meta description, and 2-3 times in body without keyword-stuffing
- Avoid hype words: "revolutionary", "game-changer", "best-in-class", "next-gen"
- Use short paragraphs (2-4 sentences). Use H2 subheadings. Use bulleted lists where they help.
- Include 1-2 concrete numbers or stats in the article (industry data is fine, just don't fabricate Rezble-specific numbers)
- End with a soft CTA toward the relevant Rezble plan or feature, using one of the available shortcodes
FACTS;
}

/* ─────────────────────────────────────────────────────────────────
 * Low-level cURL caller
 * ───────────────────────────────────────────────────────────────── */
function blog_ai_call($model, array $messages, array $opts = []) {
    if (!defined('ANTHROPIC_API_KEY') || !ANTHROPIC_API_KEY) {
        throw new RuntimeException('ANTHROPIC_API_KEY ni nastavljen v config.php');
    }
    $payload = [
        'model'      => $model,
        'max_tokens' => $opts['max_tokens'] ?? 4096,
        'messages'   => $messages,
    ];
    if (!empty($opts['system'])) $payload['system'] = $opts['system'];
    // temperature je deprecated za Claude 4.x modele — namerno izpuščeno.

    $ch = curl_init(BLOG_AI_API_URL);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER     => [
            'x-api-key: ' . ANTHROPIC_API_KEY,
            'anthropic-version: ' . BLOG_AI_API_VERSION,
            'content-type: application/json',
        ],
        CURLOPT_TIMEOUT        => 180,
        CURLOPT_CONNECTTIMEOUT => 15,
    ]);
    $resp = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($resp === false) {
        throw new RuntimeException('Anthropic cURL napaka: ' . $err);
    }
    $json = json_decode($resp, true);
    if ($code >= 400 || !is_array($json)) {
        $msg = $json['error']['message'] ?? ('HTTP ' . $code);
        throw new RuntimeException('Anthropic API: ' . $msg);
    }
    if (empty($json['content'][0]['text'])) {
        throw new RuntimeException('Anthropic odgovor brez vsebine.');
    }
    return $json['content'][0]['text'];
}

/* ─────────────────────────────────────────────────────────────────
 * JSON extractor – pobere čist JSON tudi če je zavit v ```json blok
 * ───────────────────────────────────────────────────────────────── */
function blog_ai_extract_json($text) {
    $text = trim($text);
    // Najprej poskusi neposredno
    $direct = json_decode($text, true);
    if (is_array($direct)) return $direct;
    // Markdown blok
    if (preg_match('/```(?:json)?\s*([\s\S]*?)```/', $text, $m)) {
        $inner = trim($m[1]);
        $parsed = json_decode($inner, true);
        if (is_array($parsed)) return $parsed;
    }
    // Najdi prvi { ali [ in zadnji } ali ]
    $first = strpos($text, '{');
    $firstA = strpos($text, '[');
    if ($firstA !== false && ($first === false || $firstA < $first)) $first = $firstA;
    $last = strrpos($text, '}');
    $lastA = strrpos($text, ']');
    if ($lastA !== false && ($last === false || $lastA > $last)) $last = $lastA;
    if ($first !== false && $last !== false && $last > $first) {
        $candidate = substr($text, $first, $last - $first + 1);
        $parsed = json_decode($candidate, true);
        if (is_array($parsed)) return $parsed;
        // Še poskusi popravo: nadomesti literalne newline znotraj string vrednosti
        $repaired = blog_ai_repair_json_strings($candidate);
        if ($repaired !== null) {
            $parsed2 = json_decode($repaired, true);
            if (is_array($parsed2)) return $parsed2;
        }
    }
    // Diagnoza: log surove odzive (utf8mb4 char len)
    error_log('blog_ai_extract_json FAIL (raw, first 800 chars): ' . substr($text, 0, 800));
    error_log('blog_ai_extract_json json_last_error: ' . json_last_error_msg());
    throw new RuntimeException('AI ni vrnil veljavnega JSON-a. Začetek: ' . substr($text, 0, 200));
}

/**
 * Heuristični popravek: literalne nove vrstice znotraj JSON string vrednosti
 * (med " in ") nadomesti z \n. Pomaga, ko AI včasih ne escape-a.
 * Vrne null, če sploh ne vsebuje stringov ali se ne da popraviti.
 */
function blog_ai_repair_json_strings($s) {
    $out = '';
    $inStr = false;
    $escape = false;
    $len = strlen($s);
    for ($i = 0; $i < $len; $i++) {
        $c = $s[$i];
        if ($escape) {
            $out .= $c; $escape = false; continue;
        }
        if ($c === '\\') { $out .= $c; $escape = true; continue; }
        if ($c === '"') { $inStr = !$inStr; $out .= $c; continue; }
        if ($inStr) {
            if ($c === "\n")      { $out .= '\\n'; continue; }
            if ($c === "\r")      { $out .= '\\r'; continue; }
            if ($c === "\t")      { $out .= '\\t'; continue; }
        }
        $out .= $c;
    }
    return $out;
}

/* ─────────────────────────────────────────────────────────────────
 * 1) SUGGEST TOPICS – vrne 20+ idej za blog članke
 * ───────────────────────────────────────────────────────────────── */
function blog_ai_suggest_topics($count = 20, $existingTopicsToAvoid = []) {
    $count = max(10, min(30, (int)$count));
    $facts = blog_ai_app_facts();

    $avoidList = '';
    if (!empty($existingTopicsToAvoid)) {
        $avoidList = "Avoid topics that overlap with these (already in queue or published):\n- " . implode("\n- ", array_slice($existingTopicsToAvoid, 0, 50)) . "\n\n";
    }

    $userPrompt = <<<PROMPT
Suggest {$count} blog post topics for the Rezble (Booked) blog. The blog's purpose: organic SEO traffic that converts hospitality owners into trial signups.

{$avoidList}REQUIREMENTS for each topic:
1. Maps to a real, existing Rezble feature (or a hospitality industry pain point that Rezble solves) — check the FACTS strictly.
2. Has clear search intent — owners or managers actually Google this.
3. Mix of formats: how-to, listicle, problem→solution, before/after, comparison, checklist.
4. Mix of categories: operations, guest experience, marketing, technology, business growth.
5. Long-tail keywords welcome (3-6 words). Skip generic high-volume terms ("restaurant marketing").
6. The desired master language is "en" by default unless the topic is intrinsically local (e.g. "Slovenia-specific regulations") — then "sl".

OUTPUT FORMAT — return STRICT JSON only, no markdown fences, no commentary:
{
  "topics": [
    {
      "topic": "Article working title (in target language) — clear and clickable, 50-65 chars",
      "target_keyword": "primary SEO keyword phrase",
      "brief": "2-3 sentence rationale: what the article covers, what problem it solves for the owner, which Rezble feature it ties into",
      "desired_lang": "en or sl",
      "desired_word_count": 700,
      "category_hint": "operations | guest-experience | marketing | technology | growth"
    },
    ...
  ]
}
PROMPT;

    $text = blog_ai_call(BLOG_AI_MODEL_GENERATE, [
        ['role' => 'user', 'content' => $userPrompt],
    ], [
        'system'      => $facts,
        'max_tokens'  => 6000,
        'temperature' => 0.8,
    ]);
    $data = blog_ai_extract_json($text);
    if (empty($data['topics']) || !is_array($data['topics'])) {
        throw new RuntimeException('Manjka polje "topics" v AI odgovoru.');
    }
    return $data['topics'];
}

/* ─────────────────────────────────────────────────────────────────
 * 2) GENERATE ARTICLE – polna vsebina + image prompts
 * ───────────────────────────────────────────────────────────────── */
function blog_ai_generate_article($topic, $lang = 'en', array $opts = []) {
    $facts          = blog_ai_app_facts();
    $brief          = $opts['brief']           ?? '';
    $targetKeyword  = $opts['target_keyword']  ?? '';
    $wordCount      = (int)($opts['word_count'] ?? 700);
    $maxInlineImgs  = max(0, min(3, (int)($opts['max_inline_images'] ?? 2)));
    $langLabel      = blog_ai_lang_label($lang);

    $userPrompt = <<<PROMPT
Write a complete blog article in {$langLabel} on the topic:
"{$topic}"

Brief / context: {$brief}
Target SEO keyword: "{$targetKeyword}"
Target length: ~{$wordCount} words (allow ±15%).

STRUCTURE:
- Compelling title (~50-65 chars). Include or paraphrase the target keyword.
- Excerpt: 1-2 sentences hooking the reader (max 200 chars).
- Body in markdown:
  * Open with a 2-3 sentence intro that names the pain or opportunity.
  * 3-5 H2 subheadings (## Heading) — each section 80-180 words.
  * Use short paragraphs, bullet lists where useful. NO H1.
  * Place {$maxInlineImgs} image placeholders on their own line, in the body where they belong, using EXACTLY this syntax: <!--IMG:1--> ... <!--IMG:2--> (only as many as {$maxInlineImgs}, sequential numbering). Do NOT use markdown image syntax for these — only the HTML comment placeholder.
  * End with a concluding section + ONE soft CTA shortcode chosen from: [cta:register], [cta:pricing], [cta:demo], [cta:subscribe]. Pick the most relevant.
- Meta title (≤60 chars) and meta description (≤155 chars), both keyword-aware.
- URL slug: short, kebab-case, in {$langLabel} (no diacritics, lowercase, max ~6 words).
- 3-5 tag suggestions in {$langLabel} (lowercase, single or two words).

IMAGES — for the hero image and each placeholder, write a vivid, specific DALL-E prompt IN ENGLISH (DALL-E performs best in English regardless of article language):
- 18-30 words
- Specify: scene, subject, lighting, mood, composition, style ("editorial photography", "warm natural light", "shallow depth of field", "documentary-style")
- Avoid: text, logos, faces of identifiable people, brand marks
- Hospitality-relevant where possible: restaurant interior, host stand, table setting, server with tablet, kitchen team huddle, bustling dining room, owner reviewing dashboard on laptop
- Each image needs an ALT text in {$langLabel} (descriptive, ≤120 chars).
- Each image needs a CAPTION in {$langLabel} (REQUIRED, never empty): one short sentence (max ≤140 chars) that adds context to the image — link it to the surrounding article content. The caption is shown publicly under the image, so it must be a useful, complete sentence (not a duplicate of the alt). Examples:
  * "Spletni rezervacijski sistem omogoča gostom rezervacijo 24/7 — brez klicev v gostinski lokal."
  * "Avtomatska SMS opomnika pošljeta sporočilo 24 ur in 2 uri pred rezervacijo."

Adhere strictly to the FACTS. Do NOT invent features Rezble does not have.

OUTPUT — return STRICT JSON only, no markdown fences, no commentary:
{
  "lang": "{$lang}",
  "title": "...",
  "slug": "...",
  "excerpt": "...",
  "meta_title": "...",
  "meta_description": "...",
  "content_md": "Full markdown body with ## headings and <!--IMG:n--> placeholders.",
  "tags": ["tag1", "tag2", "..."],
  "category_hint": "operations | guest-experience | marketing | technology | growth",
  "hero_image": {
    "prompt": "English DALL-E prompt",
    "alt": "Alt text in {$langLabel}",
    "caption": "REQUIRED short sentence in {$langLabel} — never empty"
  },
  "inline_images": [
    {
      "index": 1,
      "prompt": "English DALL-E prompt",
      "alt": "Alt text in {$langLabel}",
      "caption": "REQUIRED short sentence in {$langLabel} — never empty"
    }
    // up to {$maxInlineImgs} entries
  ]
}
PROMPT;

    $text = blog_ai_call(BLOG_AI_MODEL_GENERATE, [
        ['role' => 'user', 'content' => $userPrompt],
    ], [
        'system'      => $facts,
        'max_tokens'  => 8000,
        'temperature' => 0.7,
    ]);
    $data = blog_ai_extract_json($text);

    foreach (['title','slug','content_md','hero_image'] as $required) {
        if (empty($data[$required])) {
            throw new RuntimeException('AI manjka polje: ' . $required);
        }
    }
    if (empty($data['inline_images'])) $data['inline_images'] = [];
    if (empty($data['tags']))          $data['tags']          = [];
    return $data;
}

/* ─────────────────────────────────────────────────────────────────
 * 3) TRANSLATE TRANSLATION – prevede en post translation v drug jezik
 * ───────────────────────────────────────────────────────────────── */
function blog_ai_translate_translation($source, $sourceLang, $targetLang) {
    if ($sourceLang === $targetLang) {
        throw new RuntimeException('Source in target jezik sta enaka.');
    }
    $sourceLangLabel = blog_ai_lang_label($sourceLang);
    $targetLangLabel = blog_ai_lang_label($targetLang);

    $payload = [
        'title'            => $source['title']            ?? '',
        'excerpt'          => $source['excerpt']          ?? '',
        'meta_title'       => $source['meta_title']       ?? '',
        'meta_description' => $source['meta_description'] ?? '',
        'content_md'       => $source['content_md']       ?? '',
    ];

    $userPrompt = <<<PROMPT
Translate the following blog article from {$sourceLangLabel} into {$targetLangLabel}.

RULES:
- Translate naturally and idiomatically — not literally. Preserve the friendly+professional tone.
- Preserve ALL markdown formatting: ## headings, **bold**, *italic*, > quotes, lists, and link syntax [text](url).
- Preserve EXACTLY (do not translate, do not move, do not reformat):
  * `<!--IMG:1-->`, `<!--IMG:2-->`, `<!--IMG:3-->` placeholders
  * `![alt](media:N)` markdown image references — translate the alt text but keep the `(media:N)` part untouched
  * CTA shortcodes: `[cta:register]`, `[cta:pricing]`, `[cta:demo]`, `[cta:subscribe]`
  * URLs and email addresses
- The slug must be regenerated for the target language: kebab-case, lowercase, no diacritics, ≤6 words.
- Meta title ≤60 chars, meta description ≤155 chars (these are HARD limits — translate adaptively if needed).
- Keep the Rezble product name UNCHANGED (do not translate "Rezble" or "Booked").

INPUT (JSON):
__PAYLOAD_PLACEHOLDER__

OUTPUT — return STRICT JSON only, no markdown fences, no commentary:
{
  "lang": "{$targetLang}",
  "title": "...",
  "slug": "...",
  "excerpt": "...",
  "meta_title": "...",
  "meta_description": "...",
  "content_md": "..."
}
PROMPT;

    $userPrompt = str_replace(
        '__PAYLOAD_PLACEHOLDER__',
        json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
        $userPrompt
    );

    $system = 'You are an expert localizer for hospitality SaaS marketing content. You translate blog articles preserving structure, tone, and SEO intent. CRITICAL: Output STRICT JSON only — no markdown fences, no commentary before or after, no thinking-out-loud. The very first character of your response MUST be { and the last character must be }. All newlines inside string values MUST be escaped as \\n.';

    // 2 poskusa — če prvi vrne neparseljiv JSON, ponovi s strožjim opozorilom
    $attempts = 2;
    $lastError = null;
    for ($a = 1; $a <= $attempts; $a++) {
        try {
            $effSystem = $a === 1 ? $system : ($system . ' Your previous response was malformed. Return ONLY a single, valid JSON object. Make sure to escape all special characters within string values.');
            $text = blog_ai_call(BLOG_AI_MODEL_TRANSLATE, [
                ['role' => 'user', 'content' => $userPrompt],
            ], [
                'system'     => $effSystem,
                'max_tokens' => 8000,
            ]);
            $data = blog_ai_extract_json($text);

            foreach (['title','slug','content_md'] as $required) {
                if (empty($data[$required])) {
                    throw new RuntimeException('Prevod manjka polje: ' . $required);
                }
            }
            return $data;
        } catch (Throwable $e) {
            $lastError = $e;
            error_log('blog_ai_translate_translation attempt ' . $a . '/' . $attempts . ' [' . $sourceLang . '→' . $targetLang . ']: ' . $e->getMessage());
            if ($a < $attempts) usleep(500000); // 0.5s pause
        }
    }
    throw $lastError;
}

/* ─────────────────────────────────────────────────────────────────
 * 4) TRANSLATE MEDIA ALT/CAPTION – batch za 1 sliko v večjezikih
 * ───────────────────────────────────────────────────────────────── */
function blog_ai_translate_media_alts($sourceAlt, $sourceCaption, $sourceLang, array $targetLangs) {
    $sourceLangLabel = blog_ai_lang_label($sourceLang);
    $targetLabels = [];
    foreach ($targetLangs as $lc) {
        $targetLabels[$lc] = blog_ai_lang_label($lc);
    }
    $langsList = implode(', ', array_map(function($lc) use ($targetLabels) {
        return $lc . ' (' . $targetLabels[$lc] . ')';
    }, $targetLangs));

    $userPrompt = <<<PROMPT
Translate the following image alt text and caption from {$sourceLangLabel} into these languages: {$langsList}.

Alt text (≤120 chars, descriptive): "{$sourceAlt}"
Caption (optional, may be empty): "{$sourceCaption}"

RULES:
- Translate naturally; preserve meaning over literal wording.
- Keep alt under 120 chars per language.
- Keep "Rezble" / "Booked" unchanged.

OUTPUT — return STRICT JSON only:
{
  "alt": { "{$targetLangs[0]}": "...", ... },
  "caption": { "{$targetLangs[0]}": "...", ... }
}
PROMPT;

    $text = blog_ai_call(BLOG_AI_MODEL_TRANSLATE, [
        ['role' => 'user', 'content' => $userPrompt],
    ], [
        'system'      => 'You translate short image alt/caption text. Output strict JSON.',
        'max_tokens'  => 1500,
        'temperature' => 0.3,
    ]);
    $data = blog_ai_extract_json($text);
    return [
        'alt'     => $data['alt']     ?? [],
        'caption' => $data['caption'] ?? [],
    ];
}

/* ─────────────────────────────────────────────────────────────────
 * 5) SUGGEST TAGS – glede na vsebino predlaga 5-8 tagov
 * ───────────────────────────────────────────────────────────────── */
function blog_ai_suggest_tags($title, $contentMd, $lang = 'en') {
    $langLabel = blog_ai_lang_label($lang);
    $excerpt = mb_substr(strip_tags($contentMd), 0, 1500);

    $userPrompt = <<<PROMPT
Suggest 5-8 tag names in {$langLabel} for this article. Tags should be lowercase, 1-3 words, useful as filterable topics on a hospitality SaaS blog.

Title: {$title}
Body excerpt: {$excerpt}

OUTPUT — return STRICT JSON only:
{ "tags": ["tag one", "tag two", ...] }
PROMPT;

    $text = blog_ai_call(BLOG_AI_MODEL_TRANSLATE, [
        ['role' => 'user', 'content' => $userPrompt],
    ], [
        'max_tokens'  => 500,
        'temperature' => 0.5,
    ]);
    $data = blog_ai_extract_json($text);
    return $data['tags'] ?? [];
}

/* ─────────────────────────────────────────────────────────────────
 * 6) TRANSLATE SHORT STRINGS — batch prevod kratkih nizov v več jezikov
 *    Uporaba: tagi (samo "name"), kategorije (name + description),
 *    media (alt + caption), ipd.
 *
 * @param array  $items        ['key1' => 'source text', 'key2' => '...']
 * @param string $sourceLang   npr. 'en'
 * @param array  $targetLangs  npr. ['sl','de','it']
 * @param string $context      "tag name", "category name", "image alt", ...
 * @return array  ['key1' => ['sl' => 'prevod', 'de' => '...']]
 * ───────────────────────────────────────────────────────────────── */
function blog_ai_translate_short_strings(array $items, $sourceLang, array $targetLangs, $context = 'short string') {
    if (empty($items)) return [];
    $targetLangs = array_values(array_unique(array_filter($targetLangs, function($l) use ($sourceLang) {
        return $l !== $sourceLang;
    })));
    if (empty($targetLangs)) return [];

    $sourceLabel = blog_ai_lang_label($sourceLang);
    $langDescriptions = [];
    foreach ($targetLangs as $lc) {
        $langDescriptions[] = '"' . $lc . '" = ' . blog_ai_lang_label($lc);
    }
    $langsList = implode(', ', $langDescriptions);

    $payload = json_encode($items, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    $userPrompt = <<<PROMPT
You are translating short strings ({$context}) from {$sourceLabel} into multiple languages.

Source language: {$sourceLang} ({$sourceLabel})
Target languages: {$langsList}

Rules:
- Translate naturally and idiomatically — not literally.
- Keep capitalization style (lowercase tags stay lowercase; titlecase categories stay titlecase).
- Keep brand names UNCHANGED ("Rezble", "Booked").
- Each translation must fit on one line — no newlines inside values.
- Output strict JSON only — no markdown fences, no commentary.

Source items (JSON):
__PAYLOAD_PLACEHOLDER__

Output JSON shape (one entry per source key, with one sub-entry per target lang):
{
  "key1": { "sl": "...", "de": "...", ... },
  "key2": { "sl": "...", "de": "...", ... }
}
PROMPT;

    $userPrompt = str_replace('__PAYLOAD_PLACEHOLDER__', $payload, $userPrompt);

    $text = blog_ai_call(BLOG_AI_MODEL_TRANSLATE, [
        ['role' => 'user', 'content' => $userPrompt],
    ], [
        'system'     => 'You translate short marketing strings (tags, categories, captions). Output strict JSON only — first character is { and last is }. All values are single-line strings.',
        'max_tokens' => 4000,
    ]);
    $data = blog_ai_extract_json($text);
    return is_array($data) ? $data : [];
}

/* ─────────────────────────────────────────────────────────────────
 * Helper: jezikovne oznake za prompte
 * ───────────────────────────────────────────────────────────────── */
function blog_ai_lang_label($code) {
    static $map = [
        'sl' => 'Slovenian (slovenščina)',
        'en' => 'English',
        'de' => 'German (Deutsch)',
        'it' => 'Italian (Italiano)',
        'fr' => 'French (Français)',
        'hr' => 'Croatian (Hrvatski)',
        'es' => 'Spanish (Español)',
        'pt' => 'Portuguese (Português)',
    ];
    return $map[$code] ?? $code;
}
