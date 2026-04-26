<?php
require_once '../config.php';
?>
<!DOCTYPE html>
<html lang="sl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Pogoji affiliate programa – <?= h(APP_NAME) ?></title>
<link rel="stylesheet" href="<?= BASE_PATH ?>/assets/css/affiliate.css">
</head>
<body>
<div class="aff-auth" style="align-items:flex-start;padding:40px 16px">
<div class="aff-auth-card" style="max-width:680px">
    <div class="aff-auth-logo">
        <div class="aff-auth-logo-icon">R</div>
        Affiliate program – Pogoji sodelovanja
    </div>

    <div style="font-size:.88rem;line-height:1.7;color:#374151">

        <h2 style="font-size:1rem;font-weight:700;margin:0 0 8px">1. Splošno</h2>
        <p>S prijavo v affiliate program <?= h(APP_NAME) ?> se strinjate s temi pogoji. Program je namenjen promociji platforme <?= h(APP_NAME) ?> in nagrajevanju partnerjev za uspešno priporočanje novih plačljivih strank.</p>

        <h2 style="font-size:1rem;font-weight:700;margin:20px 0 8px">2. Pogoji sodelovanja</h2>
        <p>V programu lahko sodelujete, če:</p>
        <ul style="margin:6px 0 6px 20px">
            <li>Ste polnoletna fizična ali pravna oseba s sedežem v EU.</li>
            <li>Imate veljavne bančne podatke (IBAN) za izplačila.</li>
            <li>Vaša vloga je bila pregledan in odobren s strani administratorja.</li>
        </ul>

        <h2 style="font-size:1rem;font-weight:700;margin:20px 0 8px">3. Atribucija in sledenje</h2>
        <p>Vsak partner prejme unikatno referenčno kodo. Ko obiskovalec klikne vašo povezavo, se v brskalniku postavi sledilni piškotek z veljavnostjo 60 dni. Atribucija velja po principu <em>last-click</em> (zadnji klik zmaga).</p>

        <h2 style="font-size:1rem;font-weight:700;margin:20px 0 8px">4. Provizija</h2>
        <p>Provizija se obračuna za vsako uspešno plačilo priporočene stranke v oknu 12 mesecev od prve registracije. Višina provizije je določena ob odobritvi in je navedena v vašem dashboard-u. Izplačilo je možno po 45-dnevnem zadržalnem roku.</p>
        <p>Provizija se ne obračuna za:</p>
        <ul style="margin:6px 0 6px 20px">
            <li>Samopriporočilo (vaš lastni račun).</li>
            <li>Vračila in stornirane naročnine.</li>
            <li>Plačila po poteku 12-mesečnega okna.</li>
        </ul>

        <h2 style="font-size:1rem;font-weight:700;margin:20px 0 8px">5. Izplačila</h2>
        <p>Izplačila se izvajajo ročno po zbiranju serije (SEPA nakazilo). Minimalni znesek za izplačilo je določen v nastavitvah programa. Za izplačilo morate imeti vpisane veljavne bančne podatke (IBAN).</p>

        <h2 style="font-size:1rem;font-weight:700;margin:20px 0 8px">6. Popustne kode</h2>
        <p>Administrator vam lahko dodeli osebno popustno kodo za priporočene stranke. Koda se ne sme deliti v množičnih email kampanjah brez predhodnega soglasja. Kodo lahko deaktiviramo brez predhodnega obvestila v primeru zlorabe.</p>

        <h2 style="font-size:1rem;font-weight:700;margin:20px 0 8px">7. Prepovedano ravnanje</h2>
        <ul style="margin:6px 0 6px 20px">
            <li>Zavajanje, lažne trditve ali spam v imenu <?= h(APP_NAME) ?>.</li>
            <li>Kupovanje klikov, plačano oglaševanje z blagovno znamko brez soglasja.</li>
            <li>Samopriporočanje ali umetno napihovanje statistike.</li>
            <li>Deljenje popustnih kod na coupon agregatnih straneh.</li>
        </ul>

        <h2 style="font-size:1rem;font-weight:700;margin:20px 0 8px">8. Prekinitev sodelovanja</h2>
        <p><?= h(APP_NAME) ?> si pridržuje pravico do prekinitve sodelovanja brez predhodnega obvestila v primeru kršitve pogojev. V tem primeru se neizplačane provizije, ki še niso dosegle statusa »izplačljivo«, razveljavljajo.</p>

        <h2 style="font-size:1rem;font-weight:700;margin:20px 0 8px">9. Varstvo podatkov</h2>
        <p>Vaše osebne podatke obdelujemo skladno z <a href="<?= BASE_PATH ?>/pages/privacy.php" style="color:var(--aff-primary)">Politiko zasebnosti</a>. Za namen izplačil hranimo IBAN in davčno številko.</p>

        <h2 style="font-size:1rem;font-weight:700;margin:20px 0 8px">10. Spremembe pogojev</h2>
        <p>Pogoje lahko kadarkoli spremenimo. O bistvenih spremembah vas obvestimo po emailu. Nadaljevano sodelovanje po obvestilu pomeni sprejem novih pogojev.</p>

        <p style="margin-top:24px;color:var(--aff-ink-mute);font-size:.8rem">Datum veljavnosti: januar 2026 · <?= h(APP_NAME) ?></p>
    </div>

    <div style="margin-top:28px;text-align:center">
        <a href="<?= BASE_PATH ?>/affiliate/register.php" class="aff-btn" style="display:inline-block;width:auto;padding:10px 28px">← Nazaj na registracijo</a>
    </div>
</div>
</div>
</body>
</html>
