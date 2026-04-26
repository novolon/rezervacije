<?php
require_once '../config.php';
$_appName = htmlspecialchars(APP_NAME, ENT_QUOTES);

$pageTitle = 'Pogoji affiliate programa';
$extraCss  = ['design.css'];
require_once '../includes/html_head.php';
?>
<body>
<div style="min-height:100vh;background:var(--bg);padding:48px 16px">
<div style="max-width:700px;margin:0 auto">

    <a href="<?= BASE_PATH ?>/affiliate/register.php" style="display:inline-flex;align-items:center;gap:8px;color:var(--ink-mute);font-size:13px;margin-bottom:28px;text-decoration:none;font-weight:600">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m15 18-6-6 6-6"/></svg>
        Nazaj na registracijo
    </a>

    <div class="rz-card">
        <div style="display:flex;align-items:center;gap:12px;margin-bottom:28px;padding-bottom:20px;border-bottom:1px solid var(--line)">
            <svg width="32" height="32" viewBox="0 0 32 32" aria-hidden="true" style="flex:none">
                <rect width="32" height="32" rx="7" fill="var(--accent)"/>
                <path d="M9 8v16l4-4h5a5 5 0 0 0 5-5v-4a3 3 0 0 0-3-3H9Z" fill="#fff"/>
            </svg>
            <div>
                <div style="font-size:18px;font-weight:700;color:var(--ink)">Pogoji affiliate programa</div>
                <div style="font-size:12px;color:var(--ink-mute)"><?= $_appName ?></div>
            </div>
        </div>

        <div style="font-size:14px;line-height:1.75;color:var(--ink-soft)">

            <h2 style="font-size:13px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--ink-mute);margin:0 0 8px">1. Splošno</h2>
            <p style="margin:0 0 20px">S prijavo v affiliate program <?= $_appName ?> se strinjate s temi pogoji. Program je namenjen promociji platforme <?= $_appName ?> in nagrajevanju partnerjev za uspešno priporočanje novih plačljivih strank.</p>

            <h2 style="font-size:13px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--ink-mute);margin:0 0 8px">2. Pogoji sodelovanja</h2>
            <p style="margin:0 0 8px">V programu lahko sodelujete, če:</p>
            <ul style="margin:0 0 20px 20px">
                <li>Ste polnoletna fizična ali pravna oseba s sedežem v EU.</li>
                <li>Imate veljavne bančne podatke (IBAN) za izplačila.</li>
                <li>Vaša vloga je bila pregledana in odobrena s strani administratorja.</li>
            </ul>

            <h2 style="font-size:13px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--ink-mute);margin:0 0 8px">3. Atribucija in sledenje</h2>
            <p style="margin:0 0 20px">Vsak partner prejme unikatno referenčno kodo. Ko obiskovalec klikne vašo povezavo, se v brskalniku postavi sledilni piškotek z veljavnostjo 60 dni. Atribucija velja po principu <em>last-click</em> (zadnji klik zmaga).</p>

            <h2 style="font-size:13px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--ink-mute);margin:0 0 8px">4. Provizija</h2>
            <p style="margin:0 0 8px">Provizija se obračuna za vsako uspešno plačilo priporočene stranke v oknu 12 mesecev od prve registracije. Višina provizije je določena ob odobritvi in je navedena v vašem dashboardu. Izplačilo je možno po 45-dnevnem zadržalnem roku.</p>
            <p style="margin:0 0 8px">Provizija se ne obračuna za:</p>
            <ul style="margin:0 0 20px 20px">
                <li>Samopriporočilo (vaš lastni račun).</li>
                <li>Vračila in stornirane naročnine.</li>
                <li>Plačila po poteku 12-mesečnega okna.</li>
            </ul>

            <h2 style="font-size:13px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--ink-mute);margin:0 0 8px">5. Izplačila</h2>
            <p style="margin:0 0 20px">Izplačila se izvajajo ročno po zbiranju serije (SEPA nakazilo). Minimalni znesek za izplačilo je določen v nastavitvah programa. Za izplačilo morate imeti vpisane veljavne bančne podatke (IBAN).</p>

            <h2 style="font-size:13px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--ink-mute);margin:0 0 8px">6. Popustne kode</h2>
            <p style="margin:0 0 20px">Administrator vam lahko dodeli osebno popustno kodo za priporočene stranke. Koda se ne sme deliti v množičnih email kampanjah brez predhodnega soglasja. Kodo lahko deaktiviramo brez predhodnega obvestila v primeru zlorabe.</p>

            <h2 style="font-size:13px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--ink-mute);margin:0 0 8px">7. Prepovedano ravnanje</h2>
            <ul style="margin:0 0 20px 20px">
                <li>Zavajanje, lažne trditve ali spam v imenu <?= $_appName ?>.</li>
                <li>Kupovanje klikov, plačano oglaševanje z blagovno znamko brez soglasja.</li>
                <li>Samopriporočanje ali umetno napihovanje statistike.</li>
                <li>Deljenje popustnih kod na coupon agregatnih straneh.</li>
            </ul>

            <h2 style="font-size:13px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--ink-mute);margin:0 0 8px">8. Prekinitev sodelovanja</h2>
            <p style="margin:0 0 20px"><?= $_appName ?> si pridržuje pravico do prekinitve sodelovanja brez predhodnega obvestila v primeru kršitve pogojev. V tem primeru se neizplačane provizije, ki še niso dosegle statusa »izplačljivo«, razveljavljajo.</p>

            <h2 style="font-size:13px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--ink-mute);margin:0 0 8px">9. Varstvo podatkov</h2>
            <p style="margin:0 0 20px">Vaše osebne podatke obdelujemo skladno z Zakonom o varstvu osebnih podatkov (ZVOP-2) in Uredbo GDPR. Za namen izplačil hranimo IBAN in davčno številko.</p>

            <h2 style="font-size:13px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--ink-mute);margin:0 0 8px">10. Spremembe pogojev</h2>
            <p style="margin:0 0 20px">Pogoje lahko kadarkoli spremenimo. O bistvenih spremembah vas obvestimo po emailu. Nadaljevano sodelovanje po obvestilu pomeni sprejem novih pogojev.</p>

            <div style="border-top:1px solid var(--line);margin-top:12px;padding-top:16px;font-size:11px;color:var(--ink-mute)">
                Datum veljavnosti: januar 2026 · <?= $_appName ?>
            </div>
        </div>
    </div>

    <div style="text-align:center;margin-top:20px">
        <a href="<?= BASE_PATH ?>/affiliate/register.php" class="rz-btn rz-btn-primary" style="display:inline-flex">
            ← Nazaj na registracijo
        </a>
    </div>
</div>
</div>
</body>
</html>
