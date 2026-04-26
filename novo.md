# Nove funkcionalnosti iz Rezble designa

Naslednje funkcionalnosti so bile vidne v designu (`Rezble.html`), a niso del tega design update-a.
Implementirati jih kot ločene feature tickete.

---

## Dashboard

### ⌘K Command Palette
- Tipkovnica shortcut `Ctrl/Cmd+K` odpre overlay z iskanjem
- Ukazi: Nova rezervacija, Walk-in gost, Blokiraj mizo
- Navigacija: skok na posamezno stran
- Zadnje rezervacije s hitrim dostopom
- Fuzzy search po imenih gostov in rezervacij

### KPI strip z deltas
- 4 KPI kartice na vrhu dashboarda: pričakovani gostje, zasedenost, potrjeni/skupaj, povp. račun
- Vsaka KPI kartica prikazuje delta vs prejšnji teden (▲ / ▼ + %)
- Potrebuje zbiranje historičnih podatkov za primerjavo

### "Kdo prihaja" seznam (Up Next)
- Seznam naslednjih 5 rezervacij v 90 minutah
- Čas, ime, miza, opomba, status badge
- Klik odpre reservation drawer

### Reservation Drawer
- Slide-in panel z desne strani (namesto modala)
- Prikazuje: ime gosta, status, čas, miza, telefon, kdaj rezervirano
- Zgodovina gosta: število obiskov, povprečna poraba, ocena
- Akcije: Potrdi / Označi prišel / Zaključi, Pokliči, Sporočilo, Uredi, Odpovej
- Determinističen prikaz iz obstoječih podatkov (brez novih DB tabel)

### Floor View (Tloris)
- Tretja opcija v timeline segmented control (poleg Timeline / Seznam)
- Grafični prikaz miz z barvami po trenutni zasedenosti
- Svete mize = prosto, oranžna = prišli, zelena = potrjeno, rumena = nepotrjeno

### Mesečni koledar z zasedenostjo
- Kapacitetna vrstica (progress bar) na vsaki celici koledarja
- Število rezervacij + % zasedenosti
- Klik na dan naloži ta dan v razpored

---

## Statistika

### Bar chart (mesečni)
- Stolpčni graf rezervacij po mesecih (Chart.js je že integriran)
- Označen trenutni mesec z akcentno barvo

### Weekday heatmap
- Horizontalne vrstice po dnevih v tednu z intenziteto

### Source donut chart
- Kružni grafikon po viru rezervacije (splet, telefon, walk-in, Google)

### Top-5 gostov tabela
- Tabela z imenom, e-mailom, številom obiskov, povp. porabo, zadnjim obiskom

### AI Insights kartica
- Prikazuje vzorce ("Petki razprodani 4 tedne zapored")
- Temelji na analizi obstoječih podatkov, ne zahteva zunanjega AI

---

## Nastavitve (Settings redesign)

### Tabs v nastavitvah (admin.php redesign)
Design predvideva naslednje zavihke v nastavitvah:
- **Restavracija** — ime, opis, telefon, email, naslov, logotip, barva
- **Urnik** — tedenski urnik + nastavitve rezervacij (dolžina, buffer, max gostov) + dopusti
- **Pravila rezervacij** — min/max gostov, napredne nastavitve
- **Spletne rezervacije** — booking link, samourejanje (Advanced+), čakalna lista (Advanced+), embed koda (Premium)
- **Obvestila** — toggle-ji za email/SMS + predloge sporočil
- **Ekipa** — tabela zaposlenih z rolami
- **Anketa** — naslov, vprašanja, samodejno pošiljanje
- **Mize** — cone, mize, združene mize
- **Naročnina** — trenutni paket + primerjava paketov

### Plan tier badges v nastavitvah
- Oznake ADVANCED+ / PREMIUM ob funkcionalnostih ki zahtevajo višji paket
- Zaklenjena stanja z "upgrade" CTA

---

## Sidebar

### Sidebar collapse animacija
- Sidebar se skrči na ikone (68px), razširi na 252px
- Stanje shranjeno v localStorage (že implementirano v sidebar.php)
- V skrčenem stanju tooltip na hover za vsak nav item

---

## Register page
Redesign register.php na split-screen layout (enak vzorec kot login.php):
- Leva stran: branding (ista kot login)
- Desna stran: registracijski obrazec z DM Sans stilom
