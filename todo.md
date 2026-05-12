[x] ce imam kot admin izbrane vse restavracije vidim rezervacijo, ce izberem tocno doloceno restavracijo pa ne, ceprav je rezervacija narejena za to restavracijo, enako velja za userja - ne vidi rezervacij
[x] onemogoci dodajanje rezervacij v preteklost, pusti pa pregled teh rezervacij
[x] naj bo vse skupaj responsive, in sicer na telefonu da je zgoraj viden dan in dnevne rezervacije spodaj pa koledar
[x] ce je rezervacija dolga 90 minut naj bo to tudi vidno v koledarju (da gre iz 16.00 na 17.30) trenutno ostane znotraj 16.00 prostora
[x] pri dodajanju rezervacije dodaj moznost spremembe trajanja rezervacije, ce admin to dovoli, default naj bo kar je nastavil admin
[x] st oseb naj bo prazno po defaultu
[x] <span class="slot-empty-hint">+ dodaj rezervacijo</span> tukaj naj bo hover kot na linku
[x] omogoci adminu da za vsako restavraijo nastavi moznost rezervacije od do v dnevu. recimo prva rezervacija je mozna ob 11.00 zadnja pa ob 21.00. za vsako rezervacijo posebaj
[x] dodaj moznost remember me na login
[x] kako lahko zagotovimo, da ce admin doda rezervacijo, da bo to tudi vidno v user accountu brez refresha strani, in obratno
[x] admin naj bo admin samo za svoje restavracije, lahko je vec adminov, ki vidijo samo svoje restavracije, vseeno pa omogoci vec adminov na restavracijo
[x] ko se nov user registrira ne pusti dodati rezervacije, dokler ne doda restavracije!
[x] Dodali bomo pakete za placilo aplikacije. Vsak nov racun je free za en mesec, en teden pred pretekom brezplacnega racuna, naj se pojavi obvestilo da bo trial potekel in da je potrebno placilo. Placilo bo izvedeno preko stripe-a. Imamo Osnovni paket, imamo Napredni paket in imamo Premium paket. Placuje se mesecno ali letno. Ce bo placilo mesecno, se mora to urediti avtomatsko preko stripa, je to mozno? V primeru letnega placila je moznost tudi placila po predracunu - v tem primeru jaz dobim email z zahtevkom.
Osnovni paket:
- enake funkcionalnosti kot ima trial
- cena mesecnega placila 4,99 eur, letno 49,99 eur.
Napredni paket:
- dodatno od osnovnega paketa: posiljanje emailov uporabnikom o rezervaciji in opomnik 1 dan pred rezervacijo.
- uporabniki lahko sami dodajo rezervacijo preko povezave
- restavracija dobi obvestilo in email da rezervacijo potrdi oz zavrne. V sistemu mora imeti uporabnik seznam nepotrjenih rezervacij, ki jih potem potrdi, zavrne ali spremeni.
- rezervacijski postopek za uporabnike je v vec korakih:
1. korak: izbira datuma preko koledarja. Admin za vsako restavracijo nastavi dneve v tednu ko so odprti. Naj ima tudi moznost tudi onemogociti dolocene datume.
2. korak: izbira ure. Vsaka ura je gumb. Admin za vsak dan v tednu nastavi case rezervacije, default ne 30 min od do.
3. podatki: ime, priimek, email, telefon
- uporabnik dobi email s potrditvijo in moznostjo urejanja rezervacije, ter opomnik 24h pred rezervacijo
- cena mesecnega placila 6,99 eur, letno 69,99 eur.
Premium paket:
- dodatno od premium paketa: uporabniki lahko sami dodajo rezervacijo preko embedanja na spletno stran ali povezave - tukaj moramo razviti moznost, da se preko javascripta doda div na spletno stran, ki bo omogocal proces rezervacije proces je enak kot na povezavi, le da se tukaj dogaja znotraj div-a
- branding restavracije in dolocanje primarne barve
- moznost samodejnega potrjevanja rezervacij z omejenim stevilom gostov v dolocenem casovnem obdobju
- sms obvestila
- cena mesecnega placila 9,99 eur, letno 99,99 eur.
Vse skupaj naj bo narejeno tako da bo enostavno kasneje dodajati funkcionalnosti v vsak paket posebaj.
Paketi naj imajo tudi moznost dodajanja zacasnih znizanih cen - superadmin.
Superadmin naj ima moznost rocno dolociti paket uporabniku brez placila, z omejitvijo trajanja.




- ✅ Ce je v urniku dan v tednu izklopljen, naj sploh ne bo clickable v spletni rezervaciji in v embedu.
- ✅ dodaj podporo za vec terminov vsak dan pri urniku (dopoldan + popoldan)
- ✅ pri blokiranih dnevih dodaj možnost, da se blokira samo del dneva
- ✅ V urnik dodaj toggle, da admin dovoli zaposlenim rezervacije na zaprte/blokirane dni
- ✅ če zaposleni želi dodati rezervacijo na blokiran/zaprt datum in override ni vklopljen, javi sporočilo z navodilom

- pri custom poljih dodaj moznost, da spremenim vrstni red, dodaj tudi moznost urejanja polja
- pri basic paketu se "Velja za" odstrani, saj nima moznosti spletnih rezervacij



je prisel/ni prisel flag za no-show userja 3x no-show brez obvestila - blokiraj rezervacije na to telefonsko in email. Dodaj obvestilo, naj poklice restavracijo.
Adminu dodaj moznost, da doloci stevilo no-show in ce to sploh upostevamo

affiliate program!


help
support
ai chat
analitika
ko prestavim na rezervacijah dan naprej ali nazaj preko btn-next-day, zamenja tudi "kdo prihaja" kar je super, bi se pa moralo tudi v koledarju prestaviti na pravilni dan
ko odprem main.php se gumb + nova rezervacija pojavi s sekundnim zamikom in je nadlezno, lahko to urediva?

ni prevedeno:
"auth.forgot_request_new": "Zahtevaj novega",
  "auth.reset_success_title": "Geslo je nastavljeno!",
  "book.slot_full_title": "Termin je popolnoma zaseden",
  "landing.nav_login_full": "Prijava za uporabnike",
  "landing.faq_0_a": "Ne. Sistem je popolnoma spletni – odprite brskalnik in se prijavite iz katerekoli naprave.",
  "stats.btn_returning_only": "Samo vrnjeni",
  "billing.proration_cycle_monthly": "mesec",
  "billing.upgrade_to": "Nadgradi na {name}",
  "billing.highest_plan": "Imate najvišji dostopni paket. Za spremembe uporabite gumb \"Upravljaj naročnino\" zgoraj.",
  "billing.pending_cancel_btn": "Preklicaj spremembo",
  "billing.dwn_eyebrow_cycle": "PREKLOP CIKLA",
  "billing_success.thank_you": "Zahvaljujemo se za zaupanje. Vse funkcionalnosti so zdaj na voljo.",
  "profile.email_section": "Sprememba email naslova",
  "profile.current_email": "Trenutni email:",
  "profile.new_email": "Nov email naslov",
  "profile.lang_desc": "Izberite jezik, v katerem se prikazuje upravljalnik.",
  "pending.reject_all": "✗ Zavrni vse",
  "pending.modal_reject": "✗ Zavrni",
  "pending.err_generic": "Napaka.",
  "waitlist.filter_all": "Vse stanje",
  "waitlist.filter_all_dates": "Vse datume",
  "waitlist.res_status_arrived": "Prispel",
  "survey.consent_public": "Strinjam se z objavo z imenom",
  "survey_results.btn_export": "Izvozi CSV",
  "survey_results.btn_export_premium": "Izvozi CSV (Premium)",
  "survey_results.status_submitted": "Izpolnjena",
  "survey_builder.field_description": "Opis (opcionalno)",
  "gdpr.col_actions": "Akcije",
  "gdpr.modal_erase_user_desc": "Vnesite user ID admina (vidite ga v Superadmin → Admini). Akcija je nepopravljiva: ime, email in kontaktni podatki se anonimizirajo.",
  "gdpr.confirm_anonymize": "Anonimizacija je NEPOPRAVLJIVA. Nadaljujem?",
  "gdpr.err_generic": "Napaka.",
  "gdpr_request.footer_note": "Po oddaji prejmete potrditveni email. Zahtevki se obravnavajo v 30 dneh.",
  "superadmin.no_email_error": "Vnesite email naslov.",
  "main.topbar_confirmed": "potrjenih",
  "main.topbar_pending": "nepotrjenih",
  "main.legend_pending": "Nepotrjeno",
  "guests.load_error": "Napaka pri nalaganju.",
  "guests.status_cancelled": "Odpovedana",
  "re.field_contact_phone": "Kontaktna telefonska",
  "re.toggle_custom_duration": "Sprememba trajanja per-rezervacija",
  "re.toggle_employee_override": "Zaposleni lahko dodajajo za blokirane dni",
  "re.toggle_allow_edit": "Dovoli urejanje rezervacije",
  "re.toggle_allow_cancel": "Dovoli odpoved rezervacije",
  "re.booking_link_intro": "Prilepite to kodo na katerokoli spletno stran.",
  "re.staff_note": "Ko ima restavracija vsaj enega zaposlenega, se pri dodajanju rezervacije pojavi izbira \"Sprejel\".",
  "re.cf_info_int_desc": "polje vidijo samo zaposleni pri dodajanju rezervacije.",
  "re.cf_info_both": "Intern + Online",
  "re.cf_info_both_desc": "oboje.",
  "re.survey_delay_suffix": "urah po prihodu gosta",
  "re.merge_group_name_label": "Ime skupine (opcionalno)",
  "re.no_users": "Ni sistemskih uporabnikov. Dodajte prvega.",
  "re.field_password_edit": "Novo geslo (pusti prazno)",
  "re.toast_user_deactivated": "{name} deaktiviran.",
  "re.toast_user_activated": "{name} aktiviran.",
  "re.toast_blackout_added": "Datum dodan!",
  "re.toast_schedule_saved": "Urnik shranjen!",
  "re.toast_embed_copied": "Embed koda kopirana!",
  "re.area_tables_count": "{count} miz",
  "re.toast_staff_removed": "Zaposleni odstranjen.",
  "re.err_load": "Napaka pri nalaganju",
  "booked.author.by": "Avtor:",
  "booked.subscribe.email_placeholder": "tvoj@email.si",
  "booked.subscribe.error": "Prijava ni uspela. Poskusi znova."

Za danes ni več prihajajočih rezervacij. (v kdo pride)
Išči strani, rezervacije, goste … (v searchu)
Zvest gost (tag gosta)
Sreda je v zadnjih 90 dneh najprometnješi dan (110 rezervacij). (v statistiki spodaj)
Koničasta ura v izbranem obdobju: 18:00 (61 rezervacij). (v statistiki spodaj)
Najpogostejša skupina ima 2 gost(a/ov) (125× v obdobju). (v statistiki spodaj)
Ni prihodnjih blokiranih datumov. (v blokiranih datumih)
Arhiv (v blokiranih datumih)
Pravila (urnik nastavitve)
Jeziki booking strani (spletne rezervacije nastavitve)
Določite, v katerih jezikih je booking stran na voljo gostom. Če omogočite preklop jezika, gostje izberejo sami; sicer vidijo samo primarni jezik. (spletne rezervacije nastavitve)
Primarni jezik (privzet ob prvem obisku; edini če switcher onemogočen) (spletne rezervacije nastavitve)
Razpoložljivi jeziki (kateri so v switcherju): (spletne rezervacije nastavitve)
Pokaži preklop jezika gostom (spletne rezervacije nastavitve)
Dropdown desno zgoraj v booking strani in widgetu. (spletne rezervacije nastavitve)


Kaj lahko narediva z anketo o zadovoljstvu, ce nekdo odpre account v recimo nemscini, sedaj vidi prednastavljeno anketo v slovenscini. 
Pri spletnih rezervacijah bi morali dati za vse, ki imajo vklopljen rezervacijsko povezavo, da izbere primarni jezik, to je obvezen podatek, lahko pa potem doda se ostale jezike.
Prednastavljena anketa o zadovoljstvu se mora tako prevesti v primarni jezik.
V kolikor ima dolocene se dodatne jezike, pa mora imeti vsako vprasanje ikonico za vsak jezik in za prednastavljena vprasanja ze vnesene prevode, za custom vprasanja pa moznost da prevede. Vsaka ikonica mora prikazati ali je prevod ze urejen ali ne. Ko klikne gor, se odpre moznost prevoda v ta jezik, spodaj pod inputom mora pa pisati original (primarni tekst).
Enako moramo narediti tudi za prostore, saj lahko v nekaterih primerih gost izbira prostor v postopku rezervacije.

Ce ima user moznost izbire jezika v postopku rezervacije mora biti to ob oddani rezervaciji zabelezno kater jezik je izbral, da lahko posljemo vse maile v pravilnem jeziku. Tudi v adminu mora biti vidno ob kliku na rezervacijo v katerem jeziku je opravil rezervacijo.