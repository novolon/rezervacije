<?php
require_once '../config.php';
require_once '../includes/functions.php';
?>
<!DOCTYPE html>
<html lang="sl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/svg+xml" href="<?= BASE_PATH ?>/assets/images/icon.svg">
    <title>Pogoji uporabe – <?= h(APP_NAME) ?></title>
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
        <h1>Pogoji uporabe</h1>
        <p class="subtitle">Datum zadnje posodobitve: 1. april 2026</p>

        <h2>1. Splošno</h2>
        <p>
            Ti pogoji urejajo uporabo spletne storitve <?= h(APP_NAME) ?>, ki jo zagotavlja Panta Studio, s.p.
            (v nadaljevanju <strong>«ponudnik»</strong>). Z registracijo in uporabo storitve sprejmate te pogoje v celoti.
        </p>

        <h2>2. Storitev</h2>
        <p>
            <?= h(APP_NAME) ?> je SaaS platforma za upravljanje rezervacij v gostinskih obratih.
            Ponudnik zagotavlja:
        </p>
        <ul>
            <li>Spletno aplikacijo za urejanje rezervacij, razporedov in gostov</li>
            <li>Javno rezervacijsko stran za goste restavracije</li>
            <li>Pošiljanje email obvestil in opomnikov</li>
            <li>Statistike in poročila</li>
            <li>API za integracijo z zunanjimi sistemi (Premium)</li>
        </ul>

        <h2>3. Registracija in račun</h2>
        <p>
            Za uporabo storitve se morate registrirati z veljavnim email naslovom in podatki o podjetju.
            Odgovorni ste za varovanje gesel in za vse dejavnosti, ki se izvajajo z vašim računom.
            O morebitni zlorabi nas takoj obvestite.
        </p>

        <h2>4. Paketi in plačila</h2>
        <p>
            Storitev je na voljo v paketih Basic, Advanced in Premium. Cenik je dostopen na strani
            <a href="<?= BASE_PATH ?>/home/index.php#pricing">z cenami</a>.
            Naročnina se zaračunava mesečno ali letno (prihranek 20%) prek plačilnega sistema Stripe.
        </p>
        <p>
            <strong>Brezplačno preskusno obdobje:</strong> 30 dni brez plačilnih podatkov.
            Po izteku preskusa morate izbrati plačljivi paket ali se odjaviti.
        </p>
        <p>
            <strong>Odpoved:</strong> Naročnino lahko kadarkoli odpoveste iz nastavitev računa.
            Ob odpovedi ostane dostop aktiven do konca plačanega obdobja; povračilo ni možno.
        </p>

        <h2>5. Obveznosti stranke</h2>
        <p>Stranka se zavezuje, da:</p>
        <ul>
            <li>Ne bo zlorabljala storitve (spam, DOS napadi, scraping)</li>
            <li>Bo pridobila ustrezna soglasja od svojih gostov za obdelavo osebnih podatkov</li>
            <li>Ne bo shranjevala nezakonitih vsebin</li>
            <li>Bo spoštovala veljavno zakonodajo (vključno z GDPR)</li>
        </ul>

        <h2>6. Varstvo podatkov in DPA</h2>
        <p>
            Ponudnik je <strong>obdelovalec</strong> osebnih podatkov gostov restavracije;
            stranka (restavracija) ostaja <strong>upravljavec</strong>.
            Z registracijo sprejmete tudi Pogodbo o obdelavi podatkov (DPA), ki je del teh pogojev in
            ureja medsebojna razmerja v skladu z Uredbo EU 2016/679 (GDPR).
        </p>
        <p>
            Ponudnik podatkov ne bo posredoval tretjim osebam razen v primerih, določenih z zakonom ali
            navedenih v <a href="<?= BASE_PATH ?>/pages/privacy.php">Politiki zasebnosti</a>.
        </p>

        <h2>7. Razpoložljivost in SLA</h2>
        <p>
            Prizadevamo si za razpoložljivost 99 % časa. Načrtovana vzdrževalna dela izvajamo izven koničnega časa
            in o njih predhodno obvestimo. Za izpade, ki niso posledica naše malomarnosti, ne prevzemamo odgovornosti.
        </p>

        <h2>8. Omejitev odgovornosti</h2>
        <p>
            Ponudnik ne odgovarja za posredne ali posledične škode. Skupna odgovornost ponudnika je v vsakem primeru
            omejena na znesek, ki ga je stranka plačala v zadnjih 3 mesecih.
        </p>

        <h2>9. Intelektualna lastnina</h2>
        <p>
            Vsa programska oprema, dizajn in vsebine platforme so last ponudnika ali njegovih licencedajalcev.
            Stranka ne pridobi nobenih pravic na intelektualni lastnini ponudnika.
        </p>

        <h2>10. Spremembe pogojev</h2>
        <p>
            Ponudnik si pridržuje pravico do spremembe teh pogojev z 14-dnevnim predhodnim obvestilom po emailu.
            Nadaljnja uporaba storitve po uveljavitvi pomeni sprejem novih pogojev.
        </p>

        <h2>11. Veljavno pravo in reševanje sporov</h2>
        <p>
            Za te pogoje se uporablja slovensko pravo. Morebitne spore rešujemo sporazumno;
            v nasprotnem primeru je pristojno sodišče v Ljubljani.
        </p>

        <a class="legal-back" href="<?= BASE_PATH ?>/">&larr; Nazaj na aplikacijo</a>
    </div>
</div>
</body>
</html>
