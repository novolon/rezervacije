# Blog avtomatizacija — navodila za Claude Code

## Pregled sistema

Ta dokument opisuje, kako postaviti avtomatiziran pipeline za pisanje blog člankov. Claude Code mora prebrati to datoteko in jo v celoti implementirati.

**Workflow:**
1. Claude Opus 4.6 → napiše članek v slovenščini (z navodili za slike)
2. **OpenAI DALL-E 3** ali **Unsplash API** → hero slika + 1–2 vsebinski sliki (izbira prek `IMAGE_PROVIDER`)
3. Claude Haiku 4.5 → prevede članek v 7 jezikov
4. Tvoj PHP API → objavi vse skupaj

---

## Struktura projekta

```
blog-automation/
├── .env                    ← API ključi (nikoli v git!)
├── .env.example            ← Predloga brez ključev
├── generate-article.js     ← Glavni script
├── lib/
│   ├── claude.js           ← Claude API klici
│   ├── images.js           ← Router: izbere OpenAI ali Unsplash
│   ├── openai.js           ← OpenAI DALL-E klici
│   ├── unsplash.js         ← Unsplash API klici
│   └── publisher.js        ← PHP API objava
├── output/                 ← Lokalno shranjevanje (za debug)
└── package.json
```

---

## 1. Namestitev

```bash
mkdir blog-automation && cd blog-automation
npm init -y
npm install dotenv @anthropic-ai/sdk openai axios unsplash-js node-fetch
```

---

## 2. .env datoteka

```env
ANTHROPIC_API_KEY=sk-ant-...
OPENAI_API_KEY=sk-...
UNSPLASH_ACCESS_KEY=tvoj-unsplash-access-key
BLOG_API_URL=https://tvoja-domena.si/api/articles
BLOG_API_TOKEN=tvoj-bearer-token
IMAGE_PROVIDER=openai          # openai ali unsplash
IMAGE_STYLE=professional photography, clean background, high quality, 4k
ARTICLE_LANGUAGES=en,de,it,hr,hu,cs,sk
```

`.env.example` (commitaj v git):
```env
ANTHROPIC_API_KEY=
OPENAI_API_KEY=
UNSPLASH_ACCESS_KEY=
BLOG_API_URL=
BLOG_API_TOKEN=
IMAGE_PROVIDER=openai
IMAGE_STYLE=professional photography, clean background, high quality, 4k
ARTICLE_LANGUAGES=en,de,it,hr,hu,cs,sk
```

**Unsplash API ključ:** Registracija je brezplačna na https://unsplash.com/developers — ustvari novo aplikacijo in kopiraj "Access Key".

---

## 3. lib/claude.js

```javascript
const Anthropic = require('@anthropic-ai/sdk');
require('dotenv').config();

const client = new Anthropic({ apiKey: process.env.ANTHROPIC_API_KEY });

const LANGUAGES = {
  en: 'English',
  de: 'German (Deutsch)',
  it: 'Italian (Italiano)',
  hr: 'Croatian (Hrvatski)',
  hu: 'Hungarian (Magyar)',
  cs: 'Czech (Čeština)',
  sk: 'Slovak (Slovenčina)',
};

/**
 * Napiše blog članek v slovenščini z navodili za slike.
 * Vrne strukturiran JSON z vsebino in image prompts.
 */
async function generateArticle(topic) {
  console.log(`\n[Claude Opus] Pišem članek: "${topic}"`);

  const response = await client.messages.create({
    model: 'claude-opus-4-6',
    max_tokens: 4000,
    messages: [{
      role: 'user',
      content: `Napiši profesionalen blog članek v slovenščini na temo: "${topic}"

Zahteve:
- Dolžina: 550–650 besed
- Ton: informativen, prijazen, strokoven
- Struktura: uvod, 2–3 poglavja z H2 naslovi, zaključek
- SEO optimiziran naslov (do 60 znakov)
- Meta opis (do 155 znakov)
- Označi 1–2 mesti v besedilu, kjer bi bila slika smiselna, z oznako: [SLIKA: opis vsebine slike]

Vrni SAMO veljaven JSON v tej obliki (brez markdown, brez razlag):
{
  "title": "Naslov članka",
  "meta_description": "Meta opis",
  "slug": "url-slug-clanka",
  "content": "Celotno besedilo članka v HTML formatu (p, h2, strong tagi). Oznake [SLIKA: ...] pusti v besedilu.",
  "hero_image_prompt": "Natančen angleški opis za hero sliko (za DALL-E). Opis mora biti specifičen, vizualen, ne abstrakten.",
  "inline_image_prompts": [
    "Opis za 1. vsebinsko sliko (angleško)",
    "Opis za 2. vsebinsko sliko (angleško, opcijsko)"
  ],
  "tags": ["tag1", "tag2", "tag3"]
}`
    }]
  });

  const text = response.content[0].text.trim();
  try {
    return JSON.parse(text);
  } catch (e) {
    // Poskusi počistiti če je JSON zavit v markdown blok
    const match = text.match(/```(?:json)?\s*([\s\S]*?)```/);
    if (match) return JSON.parse(match[1]);
    throw new Error(`Claude ni vrnil veljavnega JSON: ${text.substring(0, 200)}`);
  }
}

/**
 * Prevede vsebino članka v en ciljni jezik.
 * Uporablja Haiku za hitrost in cenovito učinkovitost.
 */
async function translateArticle(article, targetLangCode) {
  const langName = LANGUAGES[targetLangCode];
  console.log(`  [Claude Haiku] Prevajam v ${langName}...`);

  const response = await client.messages.create({
    model: 'claude-haiku-4-5-20251001',
    max_tokens: 4000,
    messages: [{
      role: 'user',
      content: `Prevedi naslednje dele blog članka iz slovenščine v ${langName}.

Pravila:
- Prevedi SAMO title, meta_description in content
- V content ohrani vse HTML tage (p, h2, strong) nespremenjene
- Oznake [SLIKA: ...] pusti nespremenjene
- slug, tags, hero_image_prompt, inline_image_prompts ostanejo enaki
- Vrni SAMO veljaven JSON, brez razlag

Vhodni JSON:
${JSON.stringify({
  title: article.title,
  meta_description: article.meta_description,
  content: article.content
}, null, 2)}

Vrni JSON v obliki:
{
  "title": "...",
  "meta_description": "...",
  "content": "..."
}`
    }]
  });

  const text = response.content[0].text.trim();
  try {
    const translated = JSON.parse(text);
    return {
      ...article,
      ...translated,
      lang: targetLangCode
    };
  } catch (e) {
    const match = text.match(/```(?:json)?\s*([\s\S]*?)```/);
    if (match) {
      const translated = JSON.parse(match[1]);
      return { ...article, ...translated, lang: targetLangCode };
    }
    throw new Error(`Haiku ni vrnil veljavnega JSON za ${langName}`);
  }
}

module.exports = { generateArticle, translateArticle, LANGUAGES };
```

---

## 4. lib/openai.js

```javascript
const OpenAI = require('openai');
const fs = require('fs');
const path = require('path');
const https = require('https');
require('dotenv').config();

const openai = new OpenAI({ apiKey: process.env.OPENAI_API_KEY });

const IMAGE_STYLE = process.env.IMAGE_STYLE || 'professional photography, clean background, high quality';

/**
 * Generira sliko z DALL-E 3 in jo shrani lokalno.
 * Vrne lokalno pot do datoteke.
 */
async function generateImage(prompt, filename, outputDir) {
  console.log(`  [DALL-E 3] Generiram sliko: "${prompt.substring(0, 60)}..."`);

  const fullPrompt = `${prompt}. Style: ${IMAGE_STYLE}. No text or watermarks.`;

  const response = await openai.images.generate({
    model: 'dall-e-3',
    prompt: fullPrompt,
    n: 1,
    size: '1792x1024',   // Hero format (16:9)
    quality: 'standard', // 'hd' za višjo kakovost (+2×cena)
    response_format: 'url'
  });

  const imageUrl = response.data[0].url;
  const localPath = path.join(outputDir, `${filename}.png`);

  await downloadFile(imageUrl, localPath);
  console.log(`  [DALL-E 3] Shranjena: ${localPath}`);

  return { url: imageUrl, localPath };
}

async function generateAllImages(article, outputDir) {
  fs.mkdirSync(outputDir, { recursive: true });

  const images = {};

  images.hero = await generateImage(article.hero_image_prompt, 'hero', outputDir);

  images.inline = [];
  const inlinePrompts = article.inline_image_prompts || [];
  for (let i = 0; i < Math.min(inlinePrompts.length, 2); i++) {
    const img = await generateImage(inlinePrompts[i], `inline-${i + 1}`, outputDir);
    images.inline.push(img);
  }

  return images;
}

function downloadFile(url, dest) {
  return new Promise((resolve, reject) => {
    const file = fs.createWriteStream(dest);
    https.get(url, (response) => {
      response.pipe(file);
      file.on('finish', () => file.close(resolve));
    }).on('error', (err) => {
      fs.unlink(dest, () => {});
      reject(err);
    });
  });
}

module.exports = { generateAllImages };
```

---

## 5. lib/unsplash.js

```javascript
const { createApi } = require('unsplash-js');
const fetch = require('node-fetch');
const fs = require('fs');
const path = require('path');
const https = require('https');
require('dotenv').config();

const unsplash = createApi({
  accessKey: process.env.UNSPLASH_ACCESS_KEY,
  fetch: fetch
});

/**
 * Poišče sliko na Unsplash glede na prompt/ključne besede.
 * Iz prompta izvleče 2–4 ključne besede za iskanje.
 * Vrne lokalno pot do shranjene slike.
 */
async function searchAndDownloadImage(prompt, filename, outputDir) {
  // Poenostavi prompt v kratke iskalne besede (prve 4 besede)
  const query = prompt
    .replace(/[^a-zA-Z0-9\s]/g, '')
    .split(' ')
    .slice(0, 4)
    .join(' ');

  console.log(`  [Unsplash] Iščem: "${query}"`);

  const result = await unsplash.search.getPhotos({
    query,
    page: 1,
    perPage: 5,
    orientation: 'landscape',
  });

  if (result.errors || !result.response?.results?.length) {
    throw new Error(`Unsplash ni našel slik za: "${query}"`);
  }

  // Vzemi najboljši rezultat (prvi)
  const photo = result.response.results[0];
  const imageUrl = photo.urls.regular; // regular = ~1080px širina
  const localPath = path.join(outputDir, `${filename}.jpg`);

  await downloadFile(imageUrl, localPath);

  // Obvezno: sporoči Unsplash o prenosu (pogoji uporabe!)
  await unsplash.photos.trackDownload({ downloadLocation: photo.links.download_location });

  console.log(`  [Unsplash] Shranjena: ${localPath} (avtor: ${photo.user.name})`);

  return {
    url: imageUrl,
    localPath,
    credit: {
      photographer: photo.user.name,
      profileUrl: photo.user.links.html,
      unsplashUrl: photo.links.html
    }
  };
}

/**
 * Poišče vse slike za članek: hero + vsebinske.
 */
async function getAllImages(article, outputDir) {
  fs.mkdirSync(outputDir, { recursive: true });

  const images = {};

  images.hero = await searchAndDownloadImage(
    article.hero_image_prompt,
    'hero',
    outputDir
  );

  images.inline = [];
  const inlinePrompts = article.inline_image_prompts || [];
  for (let i = 0; i < Math.min(inlinePrompts.length, 2); i++) {
    const img = await searchAndDownloadImage(
      inlinePrompts[i],
      `inline-${i + 1}`,
      outputDir
    );
    images.inline.push(img);
  }

  // Shrani kredite za morebitno prikazovanje v članku
  const creditsPath = path.join(outputDir, 'unsplash-credits.json');
  const credits = {
    hero: images.hero.credit,
    inline: images.inline.map(i => i.credit)
  };
  fs.writeFileSync(creditsPath, JSON.stringify(credits, null, 2));
  console.log(`  [Unsplash] Krediti shranjeni: ${creditsPath}`);

  return images;
}

function downloadFile(url, dest) {
  return new Promise((resolve, reject) => {
    const file = fs.createWriteStream(dest);
    https.get(url, (response) => {
      // Unsplash pogosto preusmeri — sledi preusmeritvi
      if (response.statusCode === 301 || response.statusCode === 302) {
        fs.unlink(dest, () => {});
        https.get(response.headers.location, (r2) => {
          r2.pipe(file);
          file.on('finish', () => file.close(resolve));
        }).on('error', reject);
        return;
      }
      response.pipe(file);
      file.on('finish', () => file.close(resolve));
    }).on('error', (err) => {
      fs.unlink(dest, () => {});
      reject(err);
    });
  });
}

module.exports = { getAllImages };
```

---

## 6. lib/images.js (router)

```javascript
require('dotenv').config();

const PROVIDER = (process.env.IMAGE_PROVIDER || 'openai').toLowerCase();

/**
 * Enotna vstopna točka za slike.
 * Glede na IMAGE_PROVIDER v .env pokliče OpenAI ali Unsplash.
 *
 * Uporaba:
 *   IMAGE_PROVIDER=openai    → DALL-E 3 (plačljivo, AI generirana)
 *   IMAGE_PROVIDER=unsplash  → Unsplash (brezplačno, prave fotografije)
 */
async function getAllImages(article, outputDir) {
  if (PROVIDER === 'unsplash') {
    console.log('  [Images] Ponudnik: Unsplash (brezplačno)');
    const { getAllImages } = require('./unsplash');
    return getAllImages(article, outputDir);
  } else {
    console.log('  [Images] Ponudnik: OpenAI DALL-E 3');
    const { generateAllImages } = require('./openai');
    return generateAllImages(article, outputDir);
  }
}

function getProviderName() {
  return PROVIDER === 'unsplash' ? 'Unsplash' : 'DALL-E 3';
}

module.exports = { getAllImages, getProviderName };
```

---

## 7. lib/publisher.js

```javascript
const axios = require('axios');
const fs = require('fs');
const FormData = require('form-data');
require('dotenv').config();

const API_URL = process.env.BLOG_API_URL;
const API_TOKEN = process.env.BLOG_API_TOKEN;

const headers = {
  'Authorization': `Bearer ${API_TOKEN}`,
  'Content-Type': 'application/json'
};

/**
 * Naloži sliko na server prek API-ja.
 * Vrne URL slike na strežniku.
 *
 * PRILAGODI: Ta funkcija je odvisna od tvojega PHP API-ja.
 * Če tvoj API sprejema base64, zamenjaj FormData z base64 enkodingom.
 */
async function uploadImage(localPath, altText) {
  console.log(`  [API] Nalagam sliko: ${localPath}`);

  const form = new FormData();
  form.append('image', fs.createReadStream(localPath));
  form.append('alt', altText);

  const response = await axios.post(`${API_URL}/images`, form, {
    headers: {
      'Authorization': `Bearer ${API_TOKEN}`,
      ...form.getHeaders()
    }
  });

  return response.data.url; // PRILAGODI glede na odgovor tvojega API-ja
}

/**
 * Vstavi URL slik v HTML vsebino na mestu oznak [SLIKA: ...].
 */
function injectImages(content, inlineImageUrls) {
  let result = content;
  let imageIndex = 0;

  result = result.replace(/\[SLIKA: ([^\]]+)\]/g, (match, description) => {
    if (imageIndex < inlineImageUrls.length) {
      const url = inlineImageUrls[imageIndex++];
      return `<figure><img src="${url}" alt="${description}" style="max-width:100%;height:auto;" loading="lazy"><figcaption>${description}</figcaption></figure>`;
    }
    return ''; // Če zmanjka slik, odstrani oznako
  });

  return result;
}

/**
 * Objavi en članek (ena jezikovna verzija) prek PHP API-ja.
 *
 * PRILAGODI: Strukturo body-ja glede na tvoj API.
 */
async function publishArticle(article, heroImageUrl, inlineImageUrls, lang = 'sl') {
  console.log(`  [API] Objavljam: "${article.title}" (${lang})`);

  const contentWithImages = injectImages(article.content, inlineImageUrls);

  const body = {
    title: article.title,
    slug: `${article.slug}${lang !== 'sl' ? '-' + lang : ''}`,
    content: contentWithImages,
    meta_description: article.meta_description,
    featured_image: heroImageUrl,
    tags: article.tags || [],
    lang: lang,
    status: 'draft'  // Spremeni v 'published' za takojšnjo objavo
  };

  const response = await axios.post(API_URL, body, { headers });
  return response.data;
}

module.exports = { uploadImage, publishArticle, injectImages };
```

---

## 8. generate-article.js (glavni script)

```javascript
#!/usr/bin/env node
require('dotenv').config();

const path = require('path');
const fs = require('fs');
const { generateArticle, translateArticle } = require('./lib/claude');
const { getAllImages, getProviderName } = require('./lib/images');
const { uploadImage, publishArticle } = require('./lib/publisher');

const LANGUAGES = (process.env.ARTICLE_LANGUAGES || 'en,de,it,hr,hu,cs,sk').split(',');

async function run() {
  const topic = process.argv[2];
  if (!topic) {
    console.error('Uporaba: node generate-article.js "Tema članka"');
    process.exit(1);
  }

  console.log('='.repeat(60));
  console.log(`BLOG PIPELINE: ${topic}`);
  console.log(`Ponudnik slik: ${getProviderName()}`);
  console.log('='.repeat(60));

  // --- KORAK 1: Napišemo članek ---
  console.log('\n[1/4] Generiranje članka (Claude Opus)...');
  const article = await generateArticle(topic);
  console.log(`  Naslov: ${article.title}`);
  console.log(`  Slug: ${article.slug}`);

  // Shrani za debug
  const outputDir = path.join('output', article.slug);
  fs.mkdirSync(outputDir, { recursive: true });
  fs.writeFileSync(path.join(outputDir, 'article-sl.json'), JSON.stringify(article, null, 2));

  // --- KORAK 2: Pridobivanje slik ---
  console.log(`\n[2/4] Pridobivanje slik (${getProviderName()})...`);
  const imagesDir = path.join(outputDir, 'images');
  const images = await getAllImages(article, imagesDir);
  console.log(`  Hero slika: ${images.hero.localPath}`);
  console.log(`  Vsebinske slike: ${images.inline.length}`);

  // --- KORAK 3: Nalaganje slik ---
  console.log('\n[3/4] Nalaganje slik na strežnik...');
  const heroUrl = await uploadImage(images.hero.localPath, article.title);
  const inlineUrls = [];
  for (let i = 0; i < images.inline.length; i++) {
    const url = await uploadImage(images.inline[i].localPath, `${article.title} - slika ${i + 1}`);
    inlineUrls.push(url);
  }

  // --- KORAK 4: Objava vseh jezikovnih verzij ---
  console.log('\n[4/4] Objavljanje...');

  // Slovenščina (original)
  const slResult = await publishArticle(article, heroUrl, inlineUrls, 'sl');
  console.log(`  Objavljen (sl): ID ${slResult.id || '?'}`);

  // Prevodi
  const results = { sl: slResult };
  for (const lang of LANGUAGES) {
    try {
      const translated = await translateArticle(article, lang);
      fs.writeFileSync(
        path.join(outputDir, `article-${lang}.json`),
        JSON.stringify(translated, null, 2)
      );
      const result = await publishArticle(translated, heroUrl, inlineUrls, lang);
      console.log(`  Objavljen (${lang}): ID ${result.id || '?'}`);
      results[lang] = result;
    } catch (err) {
      console.error(`  NAPAKA pri ${lang}: ${err.message}`);
    }
  }

  // Povzetek
  console.log('\n' + '='.repeat(60));
  console.log('ZAKLJUČENO');
  console.log(`Članek: ${article.title}`);
  console.log(`Slike: ${getProviderName()} — 1 hero + ${images.inline.length} vsebinski`);
  console.log(`Jeziki: sl + ${LANGUAGES.join(', ')}`);
  console.log(`Lokalni output: ${outputDir}/`);
  console.log('='.repeat(60));
}

run().catch((err) => {
  console.error('\nNAPAKA:', err.message);
  process.exit(1);
});
```

---

## 9. Zagon

```bash
# Enkratna namestitev
npm install

# Z OpenAI DALL-E 3 (AI slike, ~$0.24 za 3 slike)
IMAGE_PROVIDER=openai node generate-article.js "5 razlogov zakaj izbrati [tvoj produkt]"

# Z Unsplash (brezplačne fotografije)
IMAGE_PROVIDER=unsplash node generate-article.js "Kako začeti z [tema]"

# Ali nastavi IMAGE_PROVIDER v .env in zaženi brez prefiksa
node generate-article.js "Primerjava: [A] vs [B] — kaj izbrati?"
```

---

## 10. Strošek na članek

**Z OpenAI DALL-E 3:**

| Storitev | Model | Strošek |
|---|---|---|
| Pisanje članka | Claude Opus 4.6 | ~$0.03 |
| Hero slika | DALL-E 3 (1792×1024) | $0.08 |
| 2 vsebinski sliki | DALL-E 3 (1792×1024) | $0.16 |
| 7 prevodov | Claude Haiku 4.5 | ~$0.04 |
| **SKUPAJ** | | **~$0.31 / članek** |

**Z Unsplash:**

| Storitev | Model | Strošek |
|---|---|---|
| Pisanje članka | Claude Opus 4.6 | ~$0.03 |
| Vse slike | Unsplash API | $0.00 |
| 7 prevodov | Claude Haiku 4.5 | ~$0.04 |
| **SKUPAJ** | | **~$0.07 / članek** |

> Unsplash brezplačni tier: 50 zahtevkov/uro, kar zadostuje za normalno rabo. Za večje volume je na voljo plačljivi plan.

---

## 11. Prilagoditve za tvoj PHP API

Claude Code mora preveriti tvojo PHP kodo in prilagoditi:

1. `lib/publisher.js` → funkcija `uploadImage()`: format za nalaganje slik
2. `lib/publisher.js` → funkcija `publishArticle()`: struktura `body` objekta
3. `.env` → `BLOG_API_URL` in `BLOG_API_TOKEN`

Pokažite Claude Code-u naslednje datoteke iz tvojega projekta:
- PHP controller za ustvarjanje artikel
- PHP controller za nalaganje medijev/slik
- API dokumentacija (če obstaja)

---

## 12. Ideje za teme — prompt za Claude Code

Ko boš implementiral pipeline, ga tudi vprašaj:

```
Preberi celotno kodo tega PHP projekta.
Razumi vse funkcionalnosti, ki jih aplikacija ponuja.

Na podlagi tega predlagaj 20 idej za blog članke:
- Relevantne za naše uporabnike
- Odgovarjajo na pogosta vprašanja
- SEO optimizirane (long-tail keywords)
- Razvrščene po kategorijah

Za vsako idejo navedi naslov, ciljno ključno besedo in zakaj je primerna.
```

---

## Opombe

- Slike so shranjene v `output/<slug>/images/` za debug in arhiv
- Članki so privzeto objavljeni kot `draft` — preveri pred spremembo v `published`
- DALL-E 3 URL-ji potečejo po ~1 uri, zato slike takoj prenesemo lokalno
- Unsplash shrani kredite fotografov v `output/<slug>/images/unsplash-credits.json` — priporočeno jih prikaži v članku
- Unsplash pogoji: obvezno poklici `trackDownload` po vsakem prenosu (že vgrajeno v `unsplash.js`)
- Haiku prevaja sekvenčno — za paralelno izvajanje dodaj `Promise.all()`
- Za `form-data` pri nalaganju slik: `npm install form-data`