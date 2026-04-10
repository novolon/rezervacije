<?php
require_once '../config.php';
require_once '../includes/functions.php';
?>
<!DOCTYPE html>
<html lang="sl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Politika zasebnosti – <?= h(APP_NAME) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= BASE_PATH ?>/assets/css/login.css">
    <style>
        body { background: #F9FAFB; }
        .legal-wrap { max-width: 760px; margin: 40px auto; padding: 0 20px 60px; }
        .legal-logo { display:flex; align-items:center; gap:10px; margin-bottom:32px; text-decoration:none; color:#111827; }
        .legal-logo svg { flex-shrink:0; }
        .legal-logo span { font-size:1.1rem; font-weight:700; }
        .legal-card { background:#fff; border-radius:16px; padding:40px 44px; box-shadow:0 1px 3px rgba(0,0,0,.08); }
        .legal-card h1 { font-size:1.6rem; font-weight:700; margin:0 0 6px; color:#111827; }
        .legal-card .subtitle { color:#6B7280; font-size:.875rem; margin:0 0 32px; }
        .legal-card h2 { font-size:1.05rem; font-weight:600; margin:28px 0 10px; color:#111827; }
        .legal-card p, .legal-card li { font-size:.9rem; line-height:1.7; color:#374151; }
        .legal-card ul { padding-left:20px; margin:8px 0; }
        .legal-card li { margin-bottom:4px; }
        .legal-card a { color:#F59E0B; text-decoration:none; }
        .legal-card a:hover { text-decoration:underline; }
        .legal-back { display:inline-block; margin-top:24px; font-size:.85rem; color:#6B7280; text-decoration:none; }
        .legal-back:hover { color:#111827; }
        @media (max-width:600px) { .legal-card { padding:24px 20px; } }
    </style>
</head>
<body>
<div class="legal-wrap">
    <a class="legal-logo" href="<?= BASE_PATH ?>/">
        <svg width="32" height="32" viewBox="0 0 40 40" fill="none">
            <rect width="40" height="40" rx="10" fill="#F59E0B"/>
            <path d="M10 14h20M10 20h20M10 26h12" stroke="#fff" stroke-width="2.5" stroke-linecap="round"/>
        </svg>
        <span><?= h(APP_NAME) ?></span>
    </a>

    <div class="legal-card">
        <h1>Politika zasebnosti</h1>
        <p class="subtitle">Datum zadnje posodobitve: 1. april 2026</p>

        <h2>1. Kdo smo</h2>
        <p>
            <?= h(APP_NAME) ?> je storitev za upravljanje rezervacij, ki jo zagotavlja Panta Studio, s.p.
            (v nadaljevanju <strong>«mi»</strong>, <strong>«SaaS»</strong> ali <strong>«upravljavec»</strong>).
            Restavracije in gostinski obrati, ki uporabljajo našo platformo (v nadaljevanju <strong>«restavracije»</strong>),
            so samostojni upravljavci osebnih podatkov svojih gostov in zaposlenih.
        </p>
        <p>
            Kontaktni naslov za vprašanja v zvezi z zasebnostjo:<br>
            <a href="mailto:zasebnost@rezervacije.si">zasebnost@rezervacije.si</a>
        </p>

        <h2>2. Katere podatke zbiramo</h2>
        <p><strong>Podatki adminov (upravljavcev restavracij):</strong></p>
        <ul>
            <li>Ime in priimek, email naslov, geslo (kriptirano)</li>
            <li>Podatki o podjetju (naziv, naslov, davčna/DDV številka)</li>
            <li>Datum in IP naslov ob soglasju s pogoji</li>
            <li>Podatki o naročnini in plačilih (Stripe – brez shranjevanja kartičnih podatkov)</li>
        </ul>
        <p><strong>Podatki gostov (self-booking):</strong></p>
        <ul>
            <li>Ime, email naslov, telefonska številka</li>
            <li>Datum, čas in število gostov rezervacije</li>
            <li>IP naslov ob oddaji rezervacije (za GDPR soglasje)</li>
            <li>Odgovori na ankete (opcijsko, z ločenim soglasjem)</li>
        </ul>
        <p><strong>Tehnični podatki (avtomatsko):</strong></p>
        <ul>
            <li>Piškotki seje (samo nujni, za delovanje aplikacije)</li>
            <li>Dnevniki napak (error logs)</li>
        </ul>

        <h2>3. Namen in pravna podlaga obdelave</h2>
        <ul>
            <li><strong>Izvajanje pogodbe</strong> (čl. 6(1)(b) GDPR) – zagotavljanje storitve, upravljanje naročnin, pošiljanje potrdil rezervacij</li>
            <li><strong>Zakonita obveza</strong> (čl. 6(1)(c) GDPR) – računovodstvo, davčne obveznosti</li>
            <li><strong>Privolitev</strong> (čl. 6(1)(a) GDPR) – marketinška komunikacija (samo ob izrecni potrditvi)</li>
            <li><strong>Zakoniti interes</strong> (čl. 6(1)(f) GDPR) – varnost sistema, preprečevanje zlorab</li>
        </ul>

        <h2>4. Posredovanje podatkov tretjim stranem</h2>
        <p>Vaše podatke ne prodajamo. Podatke posredujemo le naslednjim obdelovalcem:</p>
        <ul>
            <li><strong>Stripe Inc.</strong> – obdelava plačil (varuje jih PCI-DSS standard)</li>
            <li><strong>Mailgun Technologies</strong> – pošiljanje transakcijskih emailov</li>
            <li><strong>Synology Inc.</strong> – gostovanje strežnika (EU podatkovni center)</li>
        </ul>
        <p>Restavracije, ki uporabljajo platformo, imajo dostop do podatkov svojih gostov v okviru sklenjenega DPA (Pogodba o obdelavi podatkov).</p>

        <h2>5. Rok hrambe podatkov</h2>
        <ul>
            <li>Podatki adminov: do preklica računa + 5 let (za računovodske namene)</li>
            <li>Rezervacije: 3 leta od datuma rezervacije, nato anonimizacija</li>
            <li>Podatki gostov (self-booking): 3 leta od zadnje rezervacije</li>
            <li>Dnevniki napak: 30 dni</li>
        </ul>

        <h2>6. Vaše pravice</h2>
        <p>Kot posameznik imate naslednje pravice:</p>
        <ul>
            <li><strong>Dostop</strong> – vpogled v vaše osebne podatke</li>
            <li><strong>Popravek</strong> – popravek netočnih podatkov</li>
            <li><strong>Izbris</strong> – »pravica do pozabe«</li>
            <li><strong>Prenosljivost</strong> – izvoz podatkov v strojno berljivi obliki (JSON)</li>
            <li><strong>Ugovor</strong> – ugovor obdelavi za namen neposrednega trženja</li>
            <li><strong>Omejitev</strong> – omejitev obdelave v določenih primerih</li>
        </ul>
        <p>
            Zahtevek za uveljavljanje pravic oddajte na:
            <a href="<?= BASE_PATH ?>/pages/gdpr_request.php">strani za GDPR zahtevke</a>
            ali pišite na <a href="mailto:zasebnost@rezervacije.si">zasebnost@rezervacije.si</a>.
            Na zahtevek odgovorimo v 30 dneh.
        </p>

        <h2>7. Piškotki</h2>
        <p>
            Aplikacija uporablja izključno <strong>nujne piškotke seje</strong> (za prijavo in delovanje).
            Ne uporabljamo analitičnih, oglaševalskih ali sledilnih piškotkov.
            Ker ne nastavimo neobveznih piškotkov, privolitev ni zahtevana.
        </p>

        <h2>8. Varnost podatkov</h2>
        <p>
            Gesla so kriptirana z bcrypt. Prenos podatkov poteka prek HTTPS (TLS).
            Dostop do baze je omejen na pooblaščene osebe. Redno izvajamo varnostne kopije.
        </p>

        <h2>9. Pritožba</h2>
        <p>
            Pritožbo v zvezi z obdelavo osebnih podatkov lahko vložite pri
            <strong>Informacijskemu pooblaščencu RS</strong>:
            <a href="https://www.ip-rs.si" target="_blank" rel="noopener">www.ip-rs.si</a>,
            Dunajska 22, 1000 Ljubljana.
        </p>

        <h2>10. Spremembe politike</h2>
        <p>
            O bistvenih spremembah vas obvestimo po emailu ali z obvestilom v aplikaciji
            vsaj 14 dni pred uveljavitvijo.
        </p>

        <a class="legal-back" href="<?= BASE_PATH ?>/">&larr; Nazaj na aplikacijo</a>
    </div>
</div>
</body>
</html>
