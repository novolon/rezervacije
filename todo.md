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