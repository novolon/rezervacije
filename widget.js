/**
 * Rezervacije Embed Widget
 * Uporaba:
 *   <div id="rez-widget"></div>
 *   <script src=".../widget.js" data-token="TOKEN" data-container="#rez-widget" data-lang="sl"></script>
 *
 * Podprti jeziki: sl (privzeto), en
 * Brez data-container: widget se vstavi tik pred <script> tag.
 */
(function () {
    'use strict';

    // ── i18n ──────────────────────────────────────────────────────
    const WIDGET_LABELS = {
        sl: 'Slovenščina', en: 'English', de: 'Deutsch', it: 'Italiano',
        fr: 'Français', hr: 'Hrvatski', es: 'Español', pt: 'Português',
    };

    const WIDGET_STRINGS = {
        sl: {
            loading:           'Nalagam...',
            subtitle:          'Spletna rezervacija',
            step1:             'Gostje',
            step2:             'Datum',
            step3:             'Termin',
            step4:             'Podatki',
            step1_title:       'Koliko gostov?',
            step1_sub:         'Izberite število gostov.',
            more_btn:          'Več →',
            enter_guests:      'Vnesite število gostov ({min}–{max})',
            next:              'Naprej →',
            step2_title:       'Izberite datum',
            cal_note:          'Sivi dnevi niso na voljo.',
            step3_title:       'Izberite termin',
            slots_loading:     'Nalagam termine...',
            no_slots:          'Za ta dan ni prostih terminov.',
            other_date:        '← Drug datum',
            wl_offer_title:    'Vpišite se na čakalno listo',
            wl_offer_desc:     'Ko se sprosti termin, vas bomo takoj obvestili.',
            first_name:        'Ime',
            last_name:         'Priimek',
            email:             'Email',
            phone:             'Telefon',
            pref_time:         'Prednostni čas',
            optional:          '(neobvezno)',
            gdpr_wl:           'Strinjam se z obdelavo podatkov za namen obveščanja o prostih terminih.',
            wl_submit:         'Vpišem se na čakalno listo',
            wl_slot_title:     'Termin je zaseden – čakalna lista',
            wl_slot_desc:      'Termin {time} je zaseden. Vpišete se na čakalno listo ali izberite drug termin zgoraj.',
            continue:          'Nadaljuj →',
            wl_done_title:     'Vpisani ste!',
            wl_done_msg:       'Ko se sprosti termin, vas bomo obvestili po emailu.',
            wl_legend:         'čakalna lista',
            areas_loading:     'Nalagam razpoložljivost...',
            step3b_title:      'Izberite prostor',
            area_any:          'Vseeno mi je',
            area_any_desc:     'Sistem samodejno izbere najboljši prostor',
            area_unavail:      'Ni prostih miz za vaš termin',
            step4_title:       'Vaši podatki',
            wl_notice:         'Čakalna lista:',
            wl_notice_desc:    'Ko se sprosti mesto, vas bomo obvestili po emailu.',
            full_name:         'Ime in priimek',
            full_name_ph:      'npr. Janez Novak',
            email_ph:          'janez@email.com',
            phone_ph:          '041 123 456',
            notes:             'Opombe',
            notes_ph:          'Alergije, posebne želje...',
            gdpr:              'Strinjam se z obdelavo osebnih podatkov za namen rezervacije. Prebral/a sem',
            privacy:           'Politiko zasebnosti',
            marketing:         'Strinjam se s prejemanjem novic (neobvezno).',
            submit:            'Potrdi rezervacijo',
            submit_wl:         'Vpis na čakalno listo',
            sending:           'Pošiljam...',
            summary_title:     'Podrobnosti',
            sum_rest:          'Restavracija',
            sum_date:          'Datum',
            sum_time:          'Ura',
            sum_guests:        'Število gostov',
            new_booking:       'Naredi novo rezervacijo',
            slot_full:         'Termin je popolnoma zaseden',
            slot_wl:           'Zasedeno – vpis na čakalno listo',
            no_slots_sfx:      'ni terminov',
            confirm_auto_ico:  '✅',
            confirm_pend_ico:  '📩',
            confirm_auto_ttl:  'Rezervacija potrjena!',
            confirm_pend_ttl:  'Prošnja sprejeta!',
            confirm_auto_msg:  'Vaša rezervacija je potrjena. Poslali smo vam potrditveni e-mail.',
            confirm_pend_msg:  'Vaša prošnja za rezervacijo je bila sprejeta. Ko jo potrdimo, vas obvestimo po e-pošti.',
            wl_confirm_ico:    '✉️',
            wl_confirm_ttl:    'Vpisani ste na čakalno listo!',
            wl_confirm_msg:    'Ko se sprosti termin, vas bomo obvestili po e-pošti. Imel/a boste 2 uri časa za potrditev.',
            guest_1:           'gost',
            guest_few:         'gostje',
            guest_many:        'gostov',
            select_ph:         '— Izberite —',
            err_name:          'Ime in priimek sta obvezna.',
            err_email_req:     'Email naslov je obvezen.',
            err_email_inv:     'Vnesite veljaven email naslov.',
            err_gdpr:          'Strinjanje z obdelavo podatkov je obvezno.',
            err_fn:            'Ime je obvezno.',
            err_ln:            'Priimek je obvezen.',
            err_email_short:   'Vnesite veljaven email.',
            err_gdpr_short:    'Soglasje je obvezno.',
            err_server:        'Napaka strežnika.',
            err_field:         'Polje "{label}" je obvezno.',
            months:            ['Januar','Februar','Marec','April','Maj','Junij','Julij','Avgust','September','Oktober','November','December'],
            days:              ['Ponedeljek','Torek','Sreda','Četrtek','Petek','Sobota','Nedelja'],
        },
        en: {
            loading:           'Loading...',
            subtitle:          'Online reservation',
            step1:             'Guests',
            step2:             'Date',
            step3:             'Time',
            step4:             'Details',
            step1_title:       'How many guests?',
            step1_sub:         'Select number of guests.',
            more_btn:          'More →',
            enter_guests:      'Enter number of guests ({min}–{max})',
            next:              'Next →',
            step2_title:       'Select date',
            cal_note:          'Grey days are not available.',
            step3_title:       'Select time',
            slots_loading:     'Loading times...',
            no_slots:          'No available times for this day.',
            other_date:        '← Other date',
            wl_offer_title:    'Join the waitlist',
            wl_offer_desc:     'We will notify you as soon as a slot opens.',
            first_name:        'First name',
            last_name:         'Last name',
            email:             'Email',
            phone:             'Phone',
            pref_time:         'Preferred time',
            optional:          '(optional)',
            gdpr_wl:           'I agree to the processing of my data for the purpose of waitlist notifications.',
            wl_submit:         'Join waitlist',
            wl_slot_title:     'Slot taken – waitlist',
            wl_slot_desc:      'Slot {time} is taken. You can join the waitlist or choose another time above.',
            continue:          'Continue →',
            wl_done_title:     'You\'re on the waitlist!',
            wl_done_msg:       'We\'ll notify you by email when a slot opens.',
            wl_legend:         'waitlist',
            areas_loading:     'Loading availability...',
            step3b_title:      'Select area',
            area_any:          'No preference',
            area_any_desc:     'System will automatically select the best area',
            area_unavail:      'No available tables for your slot',
            step4_title:       'Your details',
            wl_notice:         'Waitlist:',
            wl_notice_desc:    'We will notify you by email when a slot opens.',
            full_name:         'Full name',
            full_name_ph:      'e.g. John Smith',
            email_ph:          'john@example.com',
            phone_ph:          '+1 555 123 456',
            notes:             'Notes',
            notes_ph:          'Allergies, special requests...',
            gdpr:              'I agree to the processing of my personal data for reservation purposes. I have read the',
            privacy:           'Privacy Policy',
            marketing:         'I agree to receive news and offers (optional).',
            submit:            'Confirm reservation',
            submit_wl:         'Join waitlist',
            sending:           'Sending...',
            summary_title:     'Details',
            sum_rest:          'Restaurant',
            sum_date:          'Date',
            sum_time:          'Time',
            sum_guests:        'Guests',
            new_booking:       'Make a new reservation',
            slot_full:         'Slot is fully booked',
            slot_wl:           'Taken – join waitlist',
            no_slots_sfx:      'no times available',
            confirm_auto_ico:  '✅',
            confirm_pend_ico:  '📩',
            confirm_auto_ttl:  'Reservation confirmed!',
            confirm_pend_ttl:  'Request received!',
            confirm_auto_msg:  'Your reservation is confirmed. We sent you a confirmation email.',
            confirm_pend_msg:  'Your reservation request was received. We will notify you by email once confirmed.',
            wl_confirm_ico:    '✉️',
            wl_confirm_ttl:    'You\'re on the waitlist!',
            wl_confirm_msg:    'We will notify you by email when a slot opens. You\'ll have 2 hours to confirm.',
            guest_1:           'guest',
            guest_few:         'guests',
            guest_many:        'guests',
            select_ph:         '— Select —',
            err_name:          'Full name is required.',
            err_email_req:     'Email address is required.',
            err_email_inv:     'Please enter a valid email address.',
            err_gdpr:          'Consent to data processing is required.',
            err_fn:            'First name is required.',
            err_ln:            'Last name is required.',
            err_email_short:   'Please enter a valid email.',
            err_gdpr_short:    'Consent is required.',
            err_server:        'Server error.',
            err_field:         'Field "{label}" is required.',
            months:            ['January','February','March','April','May','June','July','August','September','October','November','December'],
            days:              ['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'],
        },
        de: {
            loading: 'Lädt...',
            subtitle: 'Online-Reservierung',
            step1: 'Gäste',
            step2: 'Datum',
            step3: 'Termin',
            step4: 'Angaben',
            step1_title: 'Wie viele Gäste?',
            step1_sub: 'Bitte Anzahl der Gäste wählen.',
            more_btn: 'Mehr →',
            enter_guests: 'Anzahl der Gäste eingeben ({min}–{max})',
            next: 'Weiter →',
            step2_title: 'Datum wählen',
            cal_note: 'Graue Tage sind nicht verfügbar.',
            step3_title: 'Termin wählen',
            slots_loading: 'Termine werden geladen...',
            no_slots: 'Für diesen Tag sind keine Termine verfügbar.',
            other_date: '← Anderes Datum',
            wl_offer_title: 'Auf die Warteliste eintragen',
            wl_offer_desc: 'Sobald ein Termin frei wird, benachrichtigen wir Sie sofort.',
            first_name: 'Vorname',
            last_name: 'Nachname',
            email: 'E-Mail',
            phone: 'Telefon',
            pref_time: 'Bevorzugte Uhrzeit',
            optional: '(optional)',
            gdpr_wl: 'Ich stimme der Verarbeitung meiner Daten zum Zweck der Benachrichtigung über freie Termine zu.',
            wl_submit: 'Auf die Warteliste eintragen',
            wl_slot_title: 'Termin belegt – Warteliste',
            wl_slot_desc: 'Der Termin {time} ist belegt. Sie können sich auf die Warteliste eintragen oder oben einen anderen Termin wählen.',
            continue: 'Weiter →',
            wl_done_title: 'Sie sind eingetragen!',
            wl_done_msg: 'Sobald ein Termin frei wird, benachrichtigen wir Sie per E-Mail.',
            wl_legend: 'Warteliste',
            areas_loading: 'Verfügbarkeit wird geladen...',
            step3b_title: 'Bereich wählen',
            area_any: 'Egal',
            area_any_desc: 'Das System wählt automatisch den besten Platz',
            area_unavail: 'Keine freien Tische für Ihren Termin',
            step4_title: 'Ihre Angaben',
            wl_notice: 'Warteliste:',
            wl_notice_desc: 'Sobald ein Platz frei wird, benachrichtigen wir Sie per E-Mail.',
            full_name: 'Vor- und Nachname',
            full_name_ph: 'z. B. Max Mustermann',
            email_ph: 'max@email.com',
            phone_ph: '041 123 456',
            notes: 'Anmerkungen',
            notes_ph: 'Allergien, besondere Wünsche...',
            gdpr: 'Ich stimme der Verarbeitung meiner personenbezogenen Daten zum Zweck der Reservierung zu. Ich habe die',
            privacy: 'Datenschutzrichtlinie',
            marketing: 'Ich stimme dem Erhalt von Newsletter-Angeboten zu (optional).',
            submit: 'Reservierung bestätigen',
            submit_wl: 'Auf Warteliste eintragen',
            sending: 'Wird gesendet...',
            summary_title: 'Details',
            sum_rest: 'Restaurant',
            sum_date: 'Datum',
            sum_time: 'Uhrzeit',
            sum_guests: 'Anzahl der Gäste',
            new_booking: 'Neue Reservierung erstellen',
            slot_full: 'Termin ist vollständig belegt',
            slot_wl: 'Belegt – Warteliste',
            no_slots_sfx: 'keine Termine',
            confirm_auto_ico: '✅',
            confirm_pend_ico: '📩',
            confirm_auto_ttl: 'Reservierung bestätigt!',
            confirm_pend_ttl: 'Anfrage eingegangen!',
            confirm_auto_msg: 'Ihre Reservierung ist bestätigt. Wir haben Ihnen eine Bestätigungs-E-Mail gesendet.',
            confirm_pend_msg: 'Ihre Reservierungsanfrage wurde entgegengenommen. Sobald wir sie bestätigen, informieren wir Sie per E-Mail.',
            wl_confirm_ico: '✉️',
            wl_confirm_ttl: 'Sie stehen auf der Warteliste!',
            wl_confirm_msg: 'Sobald ein Termin frei wird, benachrichtigen wir Sie per E-Mail. Sie haben dann 2 Stunden Zeit zur Bestätigung.',
            guest_1: 'Gast',
            guest_few: 'Gäste',
            guest_many: 'Gäste',
            select_ph: '— Bitte wählen —',
            err_name: 'Vor- und Nachname sind erforderlich.',
            err_email_req: 'E-Mail-Adresse ist erforderlich.',
            err_email_inv: 'Bitte geben Sie eine gültige E-Mail-Adresse ein.',
            err_gdpr: 'Die Zustimmung zur Datenverarbeitung ist erforderlich.',
            err_fn: 'Vorname ist erforderlich.',
            err_ln: 'Nachname ist erforderlich.',
            err_email_short: 'Bitte gültige E-Mail eingeben.',
            err_gdpr_short: 'Zustimmung ist erforderlich.',
            err_server: 'Serverfehler.',
            err_field: 'Das Feld "{label}" ist erforderlich.',
            months: ['Januar', 'Februar', 'März', 'April', 'Mai', 'Juni', 'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember'],
            days: ['Montag', 'Dienstag', 'Mittwoch', 'Donnerstag', 'Freitag', 'Samstag', 'Sonntag'],
        },
        it: {
            loading: 'Caricamento...',
            subtitle: 'Prenotazione online',
            step1: 'Ospiti',
            step2: 'Data',
            step3: 'Orario',
            step4: 'Dati',
            step1_title: 'Quanti ospiti?',
            step1_sub: 'Seleziona il numero di ospiti.',
            more_btn: 'Altro →',
            enter_guests: 'Inserisci il numero di ospiti ({min}–{max})',
            next: 'Avanti →',
            step2_title: 'Scegli la data',
            cal_note: 'I giorni grigi non sono disponibili.',
            step3_title: 'Scegli l\'orario',
            slots_loading: 'Caricamento orari...',
            no_slots: 'Nessun orario disponibile per questo giorno.',
            other_date: '← Altra data',
            wl_offer_title: 'Iscriviti alla lista d\'attesa',
            wl_offer_desc: 'Quando si libera un posto, ti avviseremo subito.',
            first_name: 'Nome',
            last_name: 'Cognome',
            email: 'Email',
            phone: 'Telefono',
            pref_time: 'Orario preferito',
            optional: '(facoltativo)',
            gdpr_wl: 'Acconsento al trattamento dei dati per ricevere notifiche sugli orari disponibili.',
            wl_submit: 'Iscrivimi alla lista d\'attesa',
            wl_slot_title: 'Orario occupato – lista d\'attesa',
            wl_slot_desc: 'L\'orario {time} è occupato. Puoi iscriverti alla lista d\'attesa o scegliere un altro orario qui sopra.',
            continue: 'Continua →',
            wl_done_title: 'Sei iscritto!',
            wl_done_msg: 'Quando si libera un posto, ti avviseremo via email.',
            wl_legend: 'lista d\'attesa',
            areas_loading: 'Caricamento disponibilità...',
            step3b_title: 'Scegli lo spazio',
            area_any: 'Non ho preferenze',
            area_any_desc: 'Il sistema sceglie automaticamente lo spazio migliore',
            area_unavail: 'Nessun tavolo disponibile per il tuo orario',
            step4_title: 'I tuoi dati',
            wl_notice: 'Lista d\'attesa:',
            wl_notice_desc: 'Quando si libera un posto, ti avviseremo via email.',
            full_name: 'Nome e cognome',
            full_name_ph: 'es. Mario Rossi',
            email_ph: 'mario@email.com',
            phone_ph: '333 123 456',
            notes: 'Note',
            notes_ph: 'Allergie, richieste speciali...',
            gdpr: 'Acconsento al trattamento dei dati personali ai fini della prenotazione. Ho letto la',
            privacy: 'Privacy Policy',
            marketing: 'Acconsento a ricevere comunicazioni promozionali (facoltativo).',
            submit: 'Conferma prenotazione',
            submit_wl: 'Iscriviti alla lista d\'attesa',
            sending: 'Invio in corso...',
            summary_title: 'Riepilogo',
            sum_rest: 'Ristorante',
            sum_date: 'Data',
            sum_time: 'Ora',
            sum_guests: 'Numero di ospiti',
            new_booking: 'Nuova prenotazione',
            slot_full: 'Orario completamente esaurito',
            slot_wl: 'Occupato – iscriviti alla lista d\'attesa',
            no_slots_sfx: 'nessun orario',
            confirm_auto_ico: '✅',
            confirm_pend_ico: '📩',
            confirm_auto_ttl: 'Prenotazione confermata!',
            confirm_pend_ttl: 'Richiesta ricevuta!',
            confirm_auto_msg: 'La tua prenotazione è confermata. Ti abbiamo inviato un\'email di conferma.',
            confirm_pend_msg: 'La tua richiesta di prenotazione è stata ricevuta. Ti avviseremo via email non appena sarà confermata.',
            wl_confirm_ico: '✉️',
            wl_confirm_ttl: 'Sei iscritto alla lista d\'attesa!',
            wl_confirm_msg: 'Quando si libera un posto, ti avviseremo via email. Avrai 2 ore di tempo per confermare.',
            guest_1: 'ospite',
            guest_few: 'ospiti',
            guest_many: 'ospiti',
            select_ph: '— Seleziona —',
            err_name: 'Nome e cognome sono obbligatori.',
            err_email_req: 'L\'indirizzo email è obbligatorio.',
            err_email_inv: 'Inserisci un indirizzo email valido.',
            err_gdpr: 'Il consenso al trattamento dei dati è obbligatorio.',
            err_fn: 'Il nome è obbligatorio.',
            err_ln: 'Il cognome è obbligatorio.',
            err_email_short: 'Inserisci un\'email valida.',
            err_gdpr_short: 'Il consenso è obbligatorio.',
            err_server: 'Errore del server.',
            err_field: 'Il campo "{label}" è obbligatorio.',
            months: ['Gennaio', 'Febbraio', 'Marzo', 'Aprile', 'Maggio', 'Giugno', 'Luglio', 'Agosto', 'Settembre', 'Ottobre', 'Novembre', 'Dicembre'],
            days: ['Lunedì', 'Martedì', 'Mercoledì', 'Giovedì', 'Venerdì', 'Sabato', 'Domenica'],
        },
        fr: {
            loading: 'Chargement...',
            subtitle: 'Réservation en ligne',
            step1: 'Convives',
            step2: 'Date',
            step3: 'Créneau',
            step4: 'Coordonnées',
            step1_title: 'Combien de convives ?',
            step1_sub: 'Sélectionnez le nombre de convives.',
            more_btn: 'Plus →',
            enter_guests: 'Entrez le nombre de convives ({min}–{max})',
            next: 'Suivant →',
            step2_title: 'Choisissez une date',
            cal_note: 'Les jours grisés ne sont pas disponibles.',
            step3_title: 'Choisissez un créneau',
            slots_loading: 'Chargement des créneaux...',
            no_slots: 'Aucun créneau disponible pour ce jour.',
            other_date: '← Autre date',
            wl_offer_title: 'Rejoindre la liste d\'attente',
            wl_offer_desc: 'Dès qu\'un créneau se libère, nous vous en informerons immédiatement.',
            first_name: 'Prénom',
            last_name: 'Nom',
            email: 'Email',
            phone: 'Téléphone',
            pref_time: 'Heure préférée',
            optional: '(facultatif)',
            gdpr_wl: 'J\'accepte le traitement de mes données afin d\'être informé(e) des créneaux disponibles.',
            wl_submit: 'M\'inscrire sur la liste d\'attente',
            wl_slot_title: 'Créneau complet – liste d\'attente',
            wl_slot_desc: 'Le créneau {time} est complet. Inscrivez-vous sur la liste d\'attente ou choisissez un autre créneau ci-dessus.',
            continue: 'Continuer →',
            wl_done_title: 'Vous êtes inscrit(e) !',
            wl_done_msg: 'Dès qu\'un créneau se libère, nous vous en informerons par email.',
            wl_legend: 'liste d\'attente',
            areas_loading: 'Chargement des disponibilités...',
            step3b_title: 'Choisissez un espace',
            area_any: 'Peu importe',
            area_any_desc: 'Le système sélectionne automatiquement le meilleur espace',
            area_unavail: 'Aucune table disponible pour votre créneau',
            step4_title: 'Vos coordonnées',
            wl_notice: 'Liste d\'attente :',
            wl_notice_desc: 'Dès qu\'une place se libère, nous vous en informerons par email.',
            full_name: 'Nom et prénom',
            full_name_ph: 'ex. Jean Dupont',
            email_ph: 'jean@email.com',
            phone_ph: '06 12 34 56 78',
            notes: 'Remarques',
            notes_ph: 'Allergies, demandes particulières...',
            gdpr: 'J\'accepte le traitement de mes données personnelles à des fins de réservation. J\'ai lu la',
            privacy: 'Politique de confidentialité',
            marketing: 'J\'accepte de recevoir des actualités (facultatif).',
            submit: 'Confirmer la réservation',
            submit_wl: 'S\'inscrire sur la liste d\'attente',
            sending: 'Envoi en cours...',
            summary_title: 'Récapitulatif',
            sum_rest: 'Restaurant',
            sum_date: 'Date',
            sum_time: 'Heure',
            sum_guests: 'Nombre de convives',
            new_booking: 'Nouvelle réservation',
            slot_full: 'Créneau complet',
            slot_wl: 'Complet – liste d\'attente',
            no_slots_sfx: 'aucun créneau',
            confirm_auto_ico: '✅',
            confirm_pend_ico: '📩',
            confirm_auto_ttl: 'Réservation confirmée !',
            confirm_pend_ttl: 'Demande reçue !',
            confirm_auto_msg: 'Votre réservation est confirmée. Un email de confirmation vous a été envoyé.',
            confirm_pend_msg: 'Votre demande de réservation a bien été reçue. Nous vous informerons par email dès qu\'elle sera confirmée.',
            wl_confirm_ico: '✉️',
            wl_confirm_ttl: 'Vous êtes sur la liste d\'attente !',
            wl_confirm_msg: 'Dès qu\'un créneau se libère, nous vous en informerons par email. Vous aurez 2 heures pour confirmer.',
            guest_1: 'convive',
            guest_few: 'convives',
            guest_many: 'convives',
            select_ph: '— Sélectionnez —',
            err_name: 'Le nom et le prénom sont obligatoires.',
            err_email_req: 'L\'adresse email est obligatoire.',
            err_email_inv: 'Veuillez entrer une adresse email valide.',
            err_gdpr: 'L\'acceptation du traitement des données est obligatoire.',
            err_fn: 'Le prénom est obligatoire.',
            err_ln: 'Le nom est obligatoire.',
            err_email_short: 'Veuillez entrer un email valide.',
            err_gdpr_short: 'Le consentement est obligatoire.',
            err_server: 'Erreur serveur.',
            err_field: 'Le champ "{label}" est obligatoire.',
            months: ['Janvier', 'Février', 'Mars', 'Avril', 'Mai', 'Juin', 'Juillet', 'Août', 'Septembre', 'Octobre', 'Novembre', 'Décembre'],
            days: ['Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi', 'Dimanche'],
        },
        hr: {
            loading: 'Učitavam...',
            subtitle: 'Online rezervacija',
            step1: 'Gosti',
            step2: 'Datum',
            step3: 'Termin',
            step4: 'Podaci',
            step1_title: 'Koliko gostiju?',
            step1_sub: 'Odaberite broj gostiju.',
            more_btn: 'Više →',
            enter_guests: 'Unesite broj gostiju ({min}–{max})',
            next: 'Dalje →',
            step2_title: 'Odaberite datum',
            cal_note: 'Sivi dani nisu dostupni.',
            step3_title: 'Odaberite termin',
            slots_loading: 'Učitavam termine...',
            no_slots: 'Za ovaj dan nema slobodnih termina.',
            other_date: '← Drugi datum',
            wl_offer_title: 'Upišite se na listu čekanja',
            wl_offer_desc: 'Čim se oslobodi termin, odmah ćemo vas obavijestiti.',
            first_name: 'Ime',
            last_name: 'Prezime',
            email: 'Email',
            phone: 'Telefon',
            pref_time: 'Željeno vrijeme',
            optional: '(neobavezno)',
            gdpr_wl: 'Pristаjem na obradu podataka u svrhu obavještavanja o slobodnim terminima.',
            wl_submit: 'Upiši me na listu čekanja',
            wl_slot_title: 'Termin je zauzet – lista čekanja',
            wl_slot_desc: 'Termin {time} je zauzet. Možete se upisati na listu čekanja ili odabrati drugi termin gore.',
            continue: 'Nastavi →',
            wl_done_title: 'Upisani ste!',
            wl_done_msg: 'Čim se oslobodi termin, obavijestit ćemo vas emailom.',
            wl_legend: 'lista čekanja',
            areas_loading: 'Učitavam dostupnost...',
            step3b_title: 'Odaberite prostor',
            area_any: 'Svejedno mi je',
            area_any_desc: 'Sustav automatski odabire najbolji prostor',
            area_unavail: 'Nema slobodnih stolova za vaš termin',
            step4_title: 'Vaši podaci',
            wl_notice: 'Lista čekanja:',
            wl_notice_desc: 'Čim se oslobodi mjesto, obavijestit ćemo vas emailom.',
            full_name: 'Ime i prezime',
            full_name_ph: 'npr. Ivan Horvat',
            email_ph: 'ivan@email.com',
            phone_ph: '091 123 456',
            notes: 'Napomene',
            notes_ph: 'Alergije, posebne želje...',
            gdpr: 'Pristаjem na obradu osobnih podataka u svrhu rezervacije. Pročitao/la sam',
            privacy: 'Politiku privatnosti',
            marketing: 'Pristаjem na primanje novosti (neobavezno).',
            submit: 'Potvrdi rezervaciju',
            submit_wl: 'Upis na listu čekanja',
            sending: 'Šaljem...',
            summary_title: 'Detalji',
            sum_rest: 'Restoran',
            sum_date: 'Datum',
            sum_time: 'Vrijeme',
            sum_guests: 'Broj gostiju',
            new_booking: 'Nova rezervacija',
            slot_full: 'Termin je potpuno zauzet',
            slot_wl: 'Zauzeto – upis na listu čekanja',
            no_slots_sfx: 'nema termina',
            confirm_auto_ico: '✅',
            confirm_pend_ico: '📩',
            confirm_auto_ttl: 'Rezervacija potvrđena!',
            confirm_pend_ttl: 'Zahtjev primljen!',
            confirm_auto_msg: 'Vaša rezervacija je potvrđena. Poslali smo vam potvrdni e-mail.',
            confirm_pend_msg: 'Vaš zahtjev za rezervaciju je primljen. Čim ga potvrdimo, obavijestit ćemo vas e-mailom.',
            wl_confirm_ico: '✉️',
            wl_confirm_ttl: 'Upisani ste na listu čekanja!',
            wl_confirm_msg: 'Čim se oslobodi termin, obavijestit ćemo vas e-mailom. Imat ćete 2 sata vremena za potvrdu.',
            guest_1: 'gost',
            guest_few: 'gosta',
            guest_many: 'gostiju',
            select_ph: '— Odaberite —',
            err_name: 'Ime i prezime su obavezni.',
            err_email_req: 'Email adresa je obavezna.',
            err_email_inv: 'Unesite ispravnu email adresu.',
            err_gdpr: 'Pristanak na obradu podataka je obavezan.',
            err_fn: 'Ime je obavezno.',
            err_ln: 'Prezime je obavezno.',
            err_email_short: 'Unesite ispravni email.',
            err_gdpr_short: 'Suglasnost je obavezna.',
            err_server: 'Greška poslužitelja.',
            err_field: 'Polje "{label}" je obavezno.',
            months: ['Siječanj', 'Veljača', 'Ožujak', 'Travanj', 'Svibanj', 'Lipanj', 'Srpanj', 'Kolovoz', 'Rujan', 'Listopad', 'Studeni', 'Prosinac'],
            days: ['Ponedjeljak', 'Utorak', 'Srijeda', 'Četvrtak', 'Petak', 'Subota', 'Nedjelja'],
        },
        es: {
            loading: 'Cargando...',
            subtitle: 'Reserva en línea',
            step1: 'Comensales',
            step2: 'Fecha',
            step3: 'Horario',
            step4: 'Datos',
            step1_title: '¿Cuántos comensales?',
            step1_sub: 'Selecciona el número de comensales.',
            more_btn: 'Más →',
            enter_guests: 'Introduce el número de comensales ({min}–{max})',
            next: 'Siguiente →',
            step2_title: 'Elige una fecha',
            cal_note: 'Los días en gris no están disponibles.',
            step3_title: 'Elige un horario',
            slots_loading: 'Cargando horarios...',
            no_slots: 'No hay horarios disponibles para este día.',
            other_date: '← Otra fecha',
            wl_offer_title: 'Apúntate a la lista de espera',
            wl_offer_desc: 'Te avisaremos en cuanto se libere un horario.',
            first_name: 'Nombre',
            last_name: 'Apellido',
            email: 'Email',
            phone: 'Teléfono',
            pref_time: 'Hora preferida',
            optional: '(opcional)',
            gdpr_wl: 'Acepto el tratamiento de mis datos para recibir avisos sobre horarios disponibles.',
            wl_submit: 'Apuntarme a la lista de espera',
            wl_slot_title: 'Horario ocupado – lista de espera',
            wl_slot_desc: 'El horario {time} está ocupado. Puedes apuntarte a la lista de espera o elegir otro horario arriba.',
            continue: 'Continuar →',
            wl_done_title: '¡Ya estás apuntado!',
            wl_done_msg: 'Te avisaremos por email cuando se libere un horario.',
            wl_legend: 'lista de espera',
            areas_loading: 'Cargando disponibilidad...',
            step3b_title: 'Elige un espacio',
            area_any: 'Me da igual',
            area_any_desc: 'El sistema elegirá automáticamente el mejor espacio',
            area_unavail: 'No hay mesas disponibles para tu horario',
            step4_title: 'Tus datos',
            wl_notice: 'Lista de espera:',
            wl_notice_desc: 'Te avisaremos por email cuando se libere una plaza.',
            full_name: 'Nombre y apellido',
            full_name_ph: 'p. ej. Juan García',
            email_ph: 'juan@email.com',
            phone_ph: '612 345 678',
            notes: 'Observaciones',
            notes_ph: 'Alergias, peticiones especiales...',
            gdpr: 'Acepto el tratamiento de mis datos personales para gestionar la reserva. He leído la',
            privacy: 'Política de privacidad',
            marketing: 'Acepto recibir novedades y promociones (opcional).',
            submit: 'Confirmar reserva',
            submit_wl: 'Apuntarse a la lista de espera',
            sending: 'Enviando...',
            summary_title: 'Detalles',
            sum_rest: 'Restaurante',
            sum_date: 'Fecha',
            sum_time: 'Hora',
            sum_guests: 'Número de comensales',
            new_booking: 'Hacer una nueva reserva',
            slot_full: 'El horario está completo',
            slot_wl: 'Ocupado – apuntarse a la lista de espera',
            no_slots_sfx: 'sin horarios',
            confirm_auto_ico: '✅',
            confirm_pend_ico: '📩',
            confirm_auto_ttl: '¡Reserva confirmada!',
            confirm_pend_ttl: '¡Solicitud recibida!',
            confirm_auto_msg: 'Tu reserva está confirmada. Te hemos enviado un email de confirmación.',
            confirm_pend_msg: 'Tu solicitud de reserva ha sido recibida. Te avisaremos por email cuando la confirmemos.',
            wl_confirm_ico: '✉️',
            wl_confirm_ttl: '¡Ya estás en la lista de espera!',
            wl_confirm_msg: 'Te avisaremos por email cuando se libere un horario. Tendrás 2 horas para confirmarlo.',
            guest_1: 'comensal',
            guest_few: 'comensales',
            guest_many: 'comensales',
            select_ph: '— Selecciona —',
            err_name: 'El nombre y apellido son obligatorios.',
            err_email_req: 'El email es obligatorio.',
            err_email_inv: 'Introduce un email válido.',
            err_gdpr: 'Es obligatorio aceptar el tratamiento de datos.',
            err_fn: 'El nombre es obligatorio.',
            err_ln: 'El apellido es obligatorio.',
            err_email_short: 'Introduce un email válido.',
            err_gdpr_short: 'El consentimiento es obligatorio.',
            err_server: 'Error del servidor.',
            err_field: 'El campo "{label}" es obligatorio.',
            months: ['Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'],
            days: ['Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado', 'Domingo'],
        },
        pt: {
            loading: 'A carregar...',
            subtitle: 'Reserva online',
            step1: 'Convidados',
            step2: 'Data',
            step3: 'Horário',
            step4: 'Dados',
            step1_title: 'Quantos convidados?',
            step1_sub: 'Selecione o número de convidados.',
            more_btn: 'Mais →',
            enter_guests: 'Introduza o número de convidados ({min}–{max})',
            next: 'Seguinte →',
            step2_title: 'Selecione a data',
            cal_note: 'Os dias a cinzento não estão disponíveis.',
            step3_title: 'Selecione o horário',
            slots_loading: 'A carregar horários...',
            no_slots: 'Não há horários disponíveis para este dia.',
            other_date: '← Outra data',
            wl_offer_title: 'Inscreva-se na lista de espera',
            wl_offer_desc: 'Assim que um horário ficar disponível, avisamos imediatamente.',
            first_name: 'Nome',
            last_name: 'Apelido',
            email: 'Email',
            phone: 'Telefone',
            pref_time: 'Horário preferido',
            optional: '(opcional)',
            gdpr_wl: 'Concordo com o tratamento dos meus dados para efeitos de notificação de horários disponíveis.',
            wl_submit: 'Inscrever-me na lista de espera',
            wl_slot_title: 'Horário ocupado – lista de espera',
            wl_slot_desc: 'O horário {time} está ocupado. Pode inscrever-se na lista de espera ou escolher outro horário acima.',
            continue: 'Continuar →',
            wl_done_title: 'Está inscrito!',
            wl_done_msg: 'Assim que um horário ficar disponível, avisamos por email.',
            wl_legend: 'lista de espera',
            areas_loading: 'A carregar disponibilidade...',
            step3b_title: 'Selecione o espaço',
            area_any: 'Sem preferência',
            area_any_desc: 'O sistema seleciona automaticamente o melhor espaço',
            area_unavail: 'Sem mesas disponíveis para o seu horário',
            step4_title: 'Os seus dados',
            wl_notice: 'Lista de espera:',
            wl_notice_desc: 'Assim que houver lugar disponível, avisamos por email.',
            full_name: 'Nome e apelido',
            full_name_ph: 'ex. João Silva',
            email_ph: 'joao@email.com',
            phone_ph: '912 345 678',
            notes: 'Observações',
            notes_ph: 'Alergias, pedidos especiais...',
            gdpr: 'Concordo com o tratamento dos meus dados pessoais para efeitos de reserva. Li a',
            privacy: 'Política de Privacidade',
            marketing: 'Concordo em receber novidades (opcional).',
            submit: 'Confirmar reserva',
            submit_wl: 'Inscrição na lista de espera',
            sending: 'A enviar...',
            summary_title: 'Detalhes',
            sum_rest: 'Restaurante',
            sum_date: 'Data',
            sum_time: 'Hora',
            sum_guests: 'Número de convidados',
            new_booking: 'Fazer nova reserva',
            slot_full: 'Horário completamente ocupado',
            slot_wl: 'Ocupado – inscrição na lista de espera',
            no_slots_sfx: 'sem horários',
            confirm_auto_ico: '✅',
            confirm_pend_ico: '📩',
            confirm_auto_ttl: 'Reserva confirmada!',
            confirm_pend_ttl: 'Pedido recebido!',
            confirm_auto_msg: 'A sua reserva está confirmada. Enviámos-lhe um email de confirmação.',
            confirm_pend_msg: 'O seu pedido de reserva foi recebido. Assim que for confirmado, avisamos por email.',
            wl_confirm_ico: '✉️',
            wl_confirm_ttl: 'Está inscrito na lista de espera!',
            wl_confirm_msg: 'Assim que um horário ficar disponível, avisamos por email. Terá 2 horas para confirmar.',
            guest_1: 'convidado',
            guest_few: 'convidados',
            guest_many: 'convidados',
            select_ph: '— Selecione —',
            err_name: 'O nome e o apelido são obrigatórios.',
            err_email_req: 'O endereço de email é obrigatório.',
            err_email_inv: 'Introduza um endereço de email válido.',
            err_gdpr: 'O consentimento para o tratamento de dados é obrigatório.',
            err_fn: 'O nome é obrigatório.',
            err_ln: 'O apelido é obrigatório.',
            err_email_short: 'Introduza um email válido.',
            err_gdpr_short: 'O consentimento é obrigatório.',
            err_server: 'Erro de servidor.',
            err_field: 'O campo "{label}" é obrigatório.',
            months: ['Janeiro', 'Fevereiro', 'Março', 'Abril', 'Maio', 'Junho', 'Julho', 'Agosto', 'Setembro', 'Outubro', 'Novembro', 'Dezembro'],
            days: ['Segunda-feira', 'Terça-feira', 'Quarta-feira', 'Quinta-feira', 'Sexta-feira', 'Sábado', 'Domingo'],
        },






    };

    // ── Config ────────────────────────────────────────────────────
    const scriptEl = document.currentScript
        || document.querySelector('script[data-token][src*="widget.js"]');
    if (!scriptEl) return;

    const token = scriptEl.getAttribute('data-token');
    if (!token) { console.warn('[RezWidget] Missing data-token attribute.'); return; }

    // Lang detection: data-lang atribut > localStorage > navigator.language > 'en' fallback.
    // localStorage je per-domain (gostov website), zato vsako restavracijo pomni svoj jezik.
    function _detectWidgetLang() {
        const supported = Object.keys(WIDGET_STRINGS);
        // 1. Eksplicitno preko data-lang
        const explicit = (scriptEl.getAttribute('data-lang') || '').toLowerCase();
        if (explicit && supported.indexOf(explicit) !== -1) return explicit;
        // 2. localStorage (uporabnik je že prej preklopil)
        try {
            const stored = localStorage.getItem('rezWidgetLang');
            if (stored && supported.indexOf(stored) !== -1) return stored;
        } catch (e) {}
        // 3. Browser preference (navigator.languages je array po prioriteti)
        const langs = navigator.languages || [navigator.language || 'en'];
        for (let i = 0; i < langs.length; i++) {
            const primary = (langs[i] || '').toLowerCase().split('-')[0];
            if (supported.indexOf(primary) !== -1) return primary;
        }
        // 4. Fallback na angleščino (mednarodni default)
        return supported.indexOf('en') !== -1 ? 'en' : supported[0];
    }
    let _wLang = _detectWidgetLang();
    let WS = WIDGET_STRINGS[_wLang] || WIDGET_STRINGS['sl'];
    function wt(key, p) {
        var s = (WS[key] != null) ? WS[key] : key;
        if (p) { for (var k in p) { s = s.replace(new RegExp('\\{'+k+'\\}','g'), p[k]); } }
        return s;
    }
    function _switchWidgetLang(newLang) {
        if (!WIDGET_STRINGS[newLang]) return;
        try { localStorage.setItem('rezWidgetLang', newLang); } catch (e) {}
        // Najlažje: reload widget host (host element ostane, scripta se ponovno izvede ne).
        // Praktično: shrani in povej userju da osveži stran. Ker je shadow DOM, full re-init
        // bi zahteval razgradnjo; kratkoročno reloadamo celo stran.
        location.reload();
    }

    const apiUrl     = scriptEl.src.replace(/\/widget\.js(\?.*)?$/, '') + '/api/book.php';
    const privacyUrl = scriptEl.src.replace(/\/widget\.js(\?.*)?$/, '') + '/pages/privacy.php';

    const sel = scriptEl.getAttribute('data-container');
    let host;
    if (sel) {
        host = document.querySelector(sel);
        if (!host) { console.warn('[RezWidget] Container "' + sel + '" ni najden.'); return; }
    } else {
        host = document.createElement('div');
        scriptEl.parentNode.insertBefore(host, scriptEl);
    }

    // ── Shadow DOM ────────────────────────────────────────────────
    const shadow = host.attachShadow({ mode: 'open' });

    // ── CSS ───────────────────────────────────────────────────────
    const styleEl = document.createElement('style');
    styleEl.textContent = `
:host {
    display: block;
    --f:  #1B4332;
    --f2: rgba(27,67,50,.55);
    --f3: rgba(27,67,50,.35);
    --cr: #FAFAF5;
    --tr: #C4704B;
    --th: #A85D3B;
    --sg: #A3B18A;
    --sl: #DAD7CD;
    --wh: #fff;
    --br: 14px;
    font-family: system-ui,-apple-system,'Segoe UI',sans-serif;
    font-size: 14px;
    line-height: 1.5;
    color: var(--f);
    -webkit-font-smoothing: antialiased;
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
button{font-family:inherit;cursor:pointer}
input,textarea{font-family:inherit}

.root{background:var(--cr);border-radius:var(--br);overflow:hidden;border:1px solid var(--sl);max-width:520px}

/* Header */
.hdr{background:var(--wh);border-bottom:1px solid var(--sl);padding:13px 18px;display:flex;align-items:center;gap:11px}
.logo{width:34px;height:34px;background:var(--f);border-radius:9px;display:flex;align-items:center;justify-content:center;color:#fff;font-weight:700;font-size:14px;flex-shrink:0}
.rname{font-weight:700;font-size:13px;color:var(--f)}
.rsub{font-size:11px;color:var(--f3);margin-top:1px}

/* Progress */
.prog{background:var(--wh);border-bottom:1px solid var(--sl);padding:11px 18px;display:flex;align-items:center}
.pi{display:flex;align-items:center;gap:5px;font-size:11px;font-weight:600;color:var(--f3)}
.pi .n{width:20px;height:20px;border-radius:50%;background:var(--sl);color:var(--f3);display:flex;align-items:center;justify-content:center;font-size:10px;font-weight:700;transition:all .2s;flex-shrink:0}
.pi.active{color:var(--f)}.pi.active .n{background:var(--f);color:#fff}
.pi.done .n{background:var(--sg);color:#fff}
.ps{flex:1;height:1px;background:var(--sl);margin:0 5px}

/* Body */
.body{padding:22px 18px}
.step{display:none}.step.active{display:block}

/* States */
.loading{padding:40px 18px;text-align:center;color:var(--f2);font-size:13px}
.errpanel{padding:40px 18px;text-align:center}
.errpanel .eico{font-size:38px;margin-bottom:12px}
.errpanel h3{font-size:16px;font-weight:700;margin-bottom:6px}
.errpanel p{font-size:13px;color:var(--f2)}

/* Titles */
.ttl{font-size:19px;font-weight:700;color:var(--f);margin-bottom:5px;display:flex;align-items:center;gap:8px}
.sub{font-size:13px;color:var(--f2);margin-bottom:18px}

/* Back */
.back{background:none;border:none;color:var(--f3);padding:0;line-height:0;transition:color .15s;flex-shrink:0}
.back:hover{color:var(--f)}

/* Guest buttons */
.gbtns{display:flex;flex-wrap:wrap;gap:9px;margin-bottom:18px}
.gbtn{width:50px;height:50px;border-radius:13px;border:2px solid var(--sl);background:var(--wh);color:var(--f);font-weight:700;font-size:16px;transition:all .15s}
.gbtn:hover{border-color:var(--f)}
.gbtn.sel{background:var(--f);color:#fff;border-color:var(--f)}
.gmore{padding:0 13px;height:50px;border-radius:13px;border:2px solid var(--sl);background:var(--wh);color:var(--f);font-weight:600;font-size:13px;transition:all .15s;white-space:nowrap}
.gmore:hover,.gmore.sel{border-color:var(--f)}
.gmore-wrap{margin-bottom:14px}
.gmore-wrap label{display:block;font-size:11px;font-weight:600;margin-bottom:6px;color:var(--f)}
.gmore-inp{width:110px;border:2px solid var(--sl);border-radius:11px;padding:9px 13px;font-size:16px;font-weight:600;color:var(--f);outline:none;transition:border-color .15s;background:var(--wh)}
.gmore-inp:focus{border-color:var(--f)}

/* Buttons */
.btn-p{width:100%;padding:13px;background:var(--tr);color:#fff;border:none;border-radius:13px;font-size:15px;font-weight:700;transition:background .15s;display:flex;align-items:center;justify-content:center;gap:8px}
.btn-p:hover:not(:disabled){background:var(--th)}
.btn-p:disabled{opacity:.4;cursor:not-allowed}
.btn-o{width:100%;padding:12px;border:2px solid var(--f);border-radius:13px;background:none;color:var(--f);font-size:14px;font-weight:700;transition:all .15s}
.btn-o:hover{background:var(--f);color:#fff}

/* Calendar */
.cal{background:var(--wh);border-radius:13px;border:1px solid var(--sl);overflow:hidden;margin-bottom:14px}
.cal-nav{display:flex;align-items:center;justify-content:space-between;padding:13px 15px;border-bottom:1px solid var(--sl)}
.cal-nb{background:none;border:none;color:var(--f3);padding:3px;line-height:0;transition:color .15s}
.cal-nb:hover:not(:disabled){color:var(--f)}
.cal-nb:disabled{opacity:.2;cursor:default}
.cal-ttl{font-weight:700;font-size:14px;color:var(--f)}
.cal-grid{padding:7px 11px 11px}
.cal-hdrs{display:grid;grid-template-columns:repeat(7,1fr);margin-bottom:3px}
.cal-dlbl{text-align:center;font-size:11px;font-weight:600;color:var(--f3);padding:4px 0}
.cal-days{display:grid;grid-template-columns:repeat(7,1fr);gap:2px}
.cal-day{aspect-ratio:1;display:flex;align-items:center;justify-content:center;border-radius:50%;font-size:13px;font-weight:500;border:none;background:none;color:var(--f);transition:all .15s}
.cal-day.av:hover{background:rgba(163,177,138,.2);cursor:pointer}
.cal-day.td{font-weight:700;color:var(--tr)}
.cal-day.td.sel{color:#fff}
.cal-day.sel{background:var(--f);color:#fff}
.cal-day.dis{color:var(--sl);cursor:default}
.cal-note{padding:7px 15px;font-size:11px;color:var(--f3);border-top:1px solid var(--sl)}

/* Slots */
.slots-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:9px;margin-bottom:14px}
.slot{border:2px solid var(--sl);border-radius:11px;padding:11px 6px;background:var(--wh);color:var(--f);font-weight:600;font-size:14px;transition:all .15s}
.slot:hover{border-color:var(--f)}
.slot.sel{background:var(--f);color:#fff;border-color:var(--f)}
.slot:disabled{opacity:.35;cursor:not-allowed}
.slot.wl{border-color:#F59E0B;color:#92400E;background:#FFFBEB}
.slot.wl:hover{background:#FEF3C7;border-color:#D97706}
.slot.wl.sel{background:#F59E0B;color:#fff;border-color:#F59E0B}
.empty{text-align:center;padding:28px 0;color:var(--f2);font-size:13px}
.empty .ico{font-size:30px;margin-bottom:8px}

/* Form */
.field{margin-bottom:13px}
.field label{display:block;font-size:12px;font-weight:600;color:var(--f);margin-bottom:5px}
.req{color:var(--tr)}
.opt{color:var(--f3);font-weight:400}
.inp,.ta{width:100%;border:2px solid var(--sl);border-radius:11px;padding:10px 13px;font-size:14px;color:var(--f);outline:none;transition:border-color .15s;background:var(--wh)}
.inp:focus,.ta:focus{border-color:var(--f)}
.ta{resize:vertical;min-height:76px}
.ferr{background:#FEE2E2;border:1px solid #FCA5A5;color:#991B1B;border-radius:11px;padding:10px 13px;font-size:13px;margin-bottom:13px;display:none}
.ferr.show{display:block}

/* GDPR */
.gdpr-row{display:flex;align-items:flex-start;gap:9px;margin-bottom:9px}
.gdpr-row label{display:flex;align-items:flex-start;gap:9px;cursor:pointer;font-size:12px;color:var(--f2);line-height:1.5;font-weight:400}
.gdpr-cb{width:15px;height:15px;accent-color:var(--f);cursor:pointer;flex-shrink:0;margin-top:2px}
.gdpr-lnk{color:var(--f);text-decoration:underline}
.gdpr-req{color:var(--tr)}

/* Confirm */
.conf{text-align:center;padding:12px 0}
.cico{font-size:50px;margin-bottom:14px}
.cttl{font-size:21px;font-weight:700;margin-bottom:7px}
.cmsg{font-size:13px;color:var(--f2);margin-bottom:22px;line-height:1.6}
.sum{background:var(--wh);border:1px solid var(--sl);border-radius:13px;padding:14px;margin-bottom:18px;text-align:left}
.sum-ttl{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--f3);margin-bottom:9px}
.sum-row{display:flex;justify-content:space-between;font-size:13px;padding:5px 0;border-bottom:1px solid rgba(218,215,205,.4)}
.sum-row:last-child{border-bottom:none}
.sum-row span:first-child{color:var(--f2)}
.sum-row span:last-child{font-weight:600}

/* Spinner */
.spin{display:inline-block;width:16px;height:16px;border:2px solid rgba(255,255,255,.4);border-top-color:#fff;border-radius:50%;animation:sp .7s linear infinite;flex-shrink:0}
@keyframes sp{to{transform:rotate(360deg)}}

/* Waitlist */
.cal-day.wl{opacity:.5;cursor:pointer}
.cal-day.wl:hover{opacity:.8;background:rgba(245,158,11,.15)}
.wl-panel{background:#FFFBEB;border:1px solid #FDE68A;border-radius:13px;padding:16px;margin-top:14px}
.wl-panel-ttl{font-size:13px;font-weight:700;color:#92400E;margin-bottom:4px}
.wl-panel-sub{font-size:12px;color:#B45309;margin-bottom:12px}
.wl-row2{display:grid;grid-template-columns:1fr 1fr;gap:8px}
.wl-inp{width:100%;border:1.5px solid #FDE68A;border-radius:10px;padding:9px 11px;font-size:13px;color:var(--f);outline:none;background:#fff;font-family:inherit;transition:border-color .15s}
.wl-inp:focus{border-color:#F59E0B}
.wl-lbl{display:block;font-size:11px;font-weight:600;color:#92400E;margin-bottom:4px}
.wl-gdpr{display:flex;align-items:flex-start;gap:8px;margin-bottom:10px}
.wl-gdpr label{font-size:11px;color:#78350F;line-height:1.5;cursor:pointer}
.wl-gdpr input{margin-top:2px;accent-color:#F59E0B;flex-shrink:0}
.wl-err{font-size:12px;color:#DC2626;background:#FEE2E2;border-radius:8px;padding:7px 10px;margin-bottom:8px;display:none}
.wl-err.show{display:block}
.wl-btn{width:100%;padding:11px;background:#F59E0B;color:#fff;border:none;border-radius:11px;font-size:13px;font-weight:700;cursor:pointer;font-family:inherit;transition:background .15s}
.wl-btn:hover:not(:disabled){background:#D97706}
.wl-btn:disabled{opacity:.5;cursor:not-allowed}
.wl-done{background:#F0FDF4;border:1px solid #BBF7D0;border-radius:13px;padding:14px;margin-top:14px;text-align:center}
.wl-done-ttl{font-size:14px;font-weight:700;color:#166534;margin-bottom:4px}
.wl-done-sub{font-size:12px;color:#15803D}
`;

    // ── HTML ──────────────────────────────────────────────────────
    const wrap = document.createElement('div');
    const _days_hdr = WS.days ? WS.days.map(d => d.substring(0,2)) : ['Po','To','Sr','Če','Pe','So','Ne'];
    wrap.innerHTML = `
<div class="root">
  <div id="wloading" class="loading">${wt('loading')}</div>
  <div id="werr" class="errpanel" style="display:none">
    <div class="eico">😔</div>
    <h3>${wt('unavailable_title') || 'Rezervacije niso na voljo'}</h3>
    <p id="werrmsg">${wt('unavailable_msg') || ''}</p>
  </div>
  <div id="wmain" style="display:none">
    <div class="hdr">
      <div class="logo" id="wlogo">R</div>
      <div style="flex:1;min-width:0">
        <div class="rname" id="wname">...</div>
        <div class="rsub">${wt('subtitle')}</div>
      </div>
      <div class="lang-switch" style="position:relative">
        <button id="wlang-btn" type="button" style="background:none;border:1px solid #DAD7CD;border-radius:6px;padding:5px 8px;font:600 11px/1 system-ui,sans-serif;color:#1B4332;cursor:pointer;display:flex;align-items:center;gap:4px">
          <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3a14 14 0 0 1 0 18M12 3a14 14 0 0 0 0 18"/></svg>
          <span id="wlang-cur">${_wLang.toUpperCase()}</span>
        </button>
        <div id="wlang-menu" style="display:none;position:absolute;right:0;top:calc(100% + 4px);background:#fff;border:1px solid #DAD7CD;border-radius:8px;box-shadow:0 4px 12px rgba(0,0,0,0.08);min-width:130px;z-index:100;overflow:hidden">
          ${Object.keys(WIDGET_STRINGS).map(lc => `<button type="button" data-wlang="${lc}" style="display:block;width:100%;text-align:left;background:none;border:none;padding:8px 12px;font:500 12px/1.4 system-ui,sans-serif;color:#1B4332;cursor:pointer;${lc === _wLang ? 'background:#FAFAF5;font-weight:600' : ''}">${WIDGET_LABELS[lc] || lc.toUpperCase()}</button>`).join('')}
        </div>
      </div>
    </div>
    <div class="prog">
      <div class="pi active" id="wp1"><div class="n">1</div><span class="pi-lbl">${wt('step1')}</span></div>
      <div class="ps"></div>
      <div class="pi" id="wp2"><div class="n">2</div><span class="pi-lbl">${wt('step2')}</span></div>
      <div class="ps"></div>
      <div class="pi" id="wp3"><div class="n">3</div><span class="pi-lbl">${wt('step3')}</span></div>
      <div class="ps"></div>
      <div class="pi" id="wp4"><div class="n">4</div><span class="pi-lbl">${wt('step4')}</span></div>
    </div>
    <div class="body">

      <div class="step active" id="ws1">
        <div class="ttl">${wt('step1_title')}</div>
        <div class="sub">${wt('step1_sub')}</div>
        <div class="gbtns" id="wgbtns"></div>
        <div class="gmore-wrap" id="wgmorewrap" style="display:none">
          <label id="wgmorelbl">${wt('enter_guests')}</label>
          <input class="gmore-inp" id="wgmoreinp" type="number" min="11" step="1">
        </div>
        <button class="btn-p" id="wbtn1" style="display:none" disabled>${wt('next')}</button>
      </div>

      <div class="step" id="ws2">
        <div class="ttl">
          <button class="back" id="wb2"><svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2"><path d="m13 18-6-6 6-6"/></svg></button>
          ${wt('step2_title')}
        </div>
        <div class="cal">
          <div class="cal-nav">
            <button class="cal-nb" id="wcalprev"><svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"><path d="m13 18-6-6 6-6"/></svg></button>
            <div class="cal-ttl" id="wcalttl"></div>
            <button class="cal-nb" id="wcalnext"><svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"><path d="m9 18 6-6-6-6"/></svg></button>
          </div>
          <div class="cal-grid">
            <div class="cal-hdrs">
              ${_days_hdr.map(d => `<div class="cal-dlbl">${d}</div>`).join('')}
            </div>
            <div class="cal-days" id="wcaldays"></div>
          </div>
          <div class="cal-note">${wt('cal_note')}</div>
        </div>
      </div>

      <div class="step" id="ws3">
        <div class="ttl">
          <button class="back" id="wb3"><svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2"><path d="m13 18-6-6 6-6"/></svg></button>
          ${wt('step3_title')}
        </div>
        <div class="sub" id="ws3sub"></div>
        <div class="empty" id="wsloading">${wt('slots_loading')}</div>
        <div id="wsempty" style="display:none">
          <div class="empty"><div class="ico">😕</div><div>${wt('no_slots')}</div></div>
          <div class="wl-panel" id="wwl-panel" style="display:none">
            <div class="wl-panel-ttl">${wt('wl_offer_title')}</div>
            <div class="wl-panel-sub">${wt('wl_offer_desc')}</div>
            <div class="wl-row2" style="margin-bottom:8px">
              <div><label class="wl-lbl">${wt('first_name')} <span style="color:#EF4444">*</span></label><input class="wl-inp" id="wwl-first" type="text"></div>
              <div><label class="wl-lbl">${wt('last_name')} <span style="color:#EF4444">*</span></label><input class="wl-inp" id="wwl-last" type="text"></div>
            </div>
            <div style="margin-bottom:8px"><label class="wl-lbl">${wt('email')} <span style="color:#EF4444">*</span></label><input class="wl-inp" id="wwl-email" type="email"></div>
            <div style="margin-bottom:8px"><label class="wl-lbl">${wt('phone')} <span style="color:#92400E;font-weight:400">${wt('optional')}</span></label><input class="wl-inp" id="wwl-phone" type="tel"></div>
            <div style="margin-bottom:10px"><label class="wl-lbl">${wt('pref_time')} <span style="color:#92400E;font-weight:400">${wt('optional')}</span></label><input class="wl-inp" id="wwl-time" type="time"></div>
            <div class="wl-gdpr"><input type="checkbox" id="wwl-gdpr"><label for="wwl-gdpr">${wt('gdpr_wl')}</label></div>
            <div class="wl-err" id="wwl-err"></div>
            <button class="wl-btn" id="wwl-submit">${wt('wl_submit')}</button>
          </div>
          <div class="wl-done" id="wwl-done" style="display:none">
            <div class="wl-done-ttl">✓ ${wt('wl_done_title')}</div>
            <div class="wl-done-sub">${wt('wl_done_msg')}</div>
          </div>
        </div>
        <div class="slots-grid" id="wsslots" style="display:none"></div>
        <div id="wsslots-legend" style="display:none;align-items:center;gap:6px;margin-top:8px;font-size:11px;color:#78350F">
          <span style="display:inline-block;width:11px;height:11px;border-radius:3px;border:2px solid #F59E0B;background:#FFFBEB;flex-shrink:0"></span> ${wt('wl_legend')}
        </div>
        <div id="wswl-notice" style="display:none;margin-top:14px;background:#FFFBEB;border:1px solid #FDE68A;border-radius:13px;padding:16px">
          <div style="font-size:13px;font-weight:700;color:#92400E;margin-bottom:4px">${wt('wl_slot_title')}</div>
          <div style="font-size:12px;color:#B45309;margin-bottom:12px" id="wswl-desc"></div>
          <button class="wl-btn" id="wswl-continue">${wt('continue')}</button>
        </div>
      </div>

      <div class="step" id="ws3b">
        <div class="ttl">
          <button class="back" id="wb3b"><svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2"><path d="m13 18-6-6 6-6"/></svg></button>
          ${wt('step3b_title')}
        </div>
        <div class="sub" id="ws3bsub"></div>
        <div class="empty" id="ws3bloading">${wt('areas_loading')}</div>
        <div id="ws3bbtns" style="display:none"></div>
      </div>

      <div class="step" id="ws4">
        <div class="ttl">
          <button class="back" id="wb4"><svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2"><path d="m13 18-6-6 6-6"/></svg></button>
          ${wt('step4_title')}
        </div>
        <div class="sub" id="ws4sub"></div>
        <div id="ws4-wl-notice" style="display:none;background:#FFFBEB;border:1px solid #FDE68A;border-radius:11px;padding:10px 14px;font-size:12px;color:#92400E;margin-bottom:12px">
          <strong>${wt('wl_notice')}</strong> <span id="ws4-wl-time" style="font-weight:700"></span> – ${wt('wl_notice_desc')}
        </div>
        <div class="ferr" id="wferr"></div>
        <div class="field"><label>${wt('full_name')} <span class="req">*</span></label><input class="inp" id="wfname" type="text" autocomplete="name" placeholder="${wt('full_name_ph')}"></div>
        <div class="field"><label>${wt('email')} <span class="req">*</span></label><input class="inp" id="wfemail" type="email" autocomplete="email" placeholder="${wt('email_ph')}"></div>
        <div class="field"><label>${wt('phone')} <span class="opt">${wt('optional')}</span></label><input class="inp" id="wfphone" type="tel" autocomplete="tel" placeholder="${wt('phone_ph')}"></div>
        <div class="field"><label>${wt('notes')} <span class="opt">${wt('optional')}</span></label><textarea class="ta" id="wfnotes" placeholder="${wt('notes_ph')}"></textarea></div>
        <div id="wcf-wrap"></div>
        <div class="gdpr-row">
          <label><input class="gdpr-cb" type="checkbox" id="wgdpr">
          <span>${wt('gdpr')} <a class="gdpr-lnk" id="wgdpr-link" href="#" target="_blank">${wt('privacy')}</a>. <span class="gdpr-req">*</span></span></label>
        </div>
        <div class="gdpr-row" style="margin-bottom:14px">
          <label><input class="gdpr-cb" type="checkbox" id="wmktg">
          <span>${wt('marketing')}</span></label>
        </div>
        <button class="btn-p" id="wbtnsubmit"><span id="wbtnlbl">${wt('submit')}</span></button>
      </div>

      <div class="step" id="ws5">
        <div class="conf">
          <div class="cico" id="wcico"></div>
          <div class="cttl" id="wcttl"></div>
          <div class="cmsg" id="wcmsg"></div>
          <div class="sum">
            <div class="sum-ttl">${wt('summary_title')}</div>
            <div class="sum-row"><span>${wt('sum_rest')}</span><span id="wcsrest"></span></div>
            <div class="sum-row"><span>${wt('sum_date')}</span><span id="wcsdate"></span></div>
            <div class="sum-row"><span>${wt('sum_time')}</span><span id="wcstime"></span></div>
            <div class="sum-row"><span>${wt('sum_guests')}</span><span id="wcsgst"></span></div>
          </div>
          <button class="btn-o" id="wbtnreset">${wt('new_booking')}</button>
        </div>
      </div>

    </div>
  </div>
</div>`;

    shadow.appendChild(styleEl);
    shadow.appendChild(wrap);

    // ── Lang switcher button handlers ─────────────────────────────
    const _langBtn  = shadow.getElementById('wlang-btn');
    const _langMenu = shadow.getElementById('wlang-menu');
    if (_langBtn && _langMenu) {
        _langBtn.addEventListener('click', (e) => {
            e.stopPropagation();
            _langMenu.style.display = _langMenu.style.display === 'block' ? 'none' : 'block';
        });
        _langMenu.querySelectorAll('button[data-wlang]').forEach(btn => {
            btn.addEventListener('click', () => {
                const newLang = btn.getAttribute('data-wlang');
                if (newLang && newLang !== _wLang) _switchWidgetLang(newLang);
                _langMenu.style.display = 'none';
            });
        });
        document.addEventListener('click', (e) => {
            if (!host.contains(e.target)) _langMenu.style.display = 'none';
        });
    }

    // ── Pomožne ───────────────────────────────────────────────────
    const $ = (id) => shadow.getElementById(id);
    const MONTHS  = WS.months;
    const DAYS_SL = WS.days;

    function guestLbl(n) {
        return n === 1 ? wt('guest_1') : n < 5 ? wt('guest_few') : wt('guest_many');
    }

    function fmtDate(ds) {
        const dt  = new Date(ds + 'T12:00:00');
        const dow = DAYS_SL[(dt.getDay() + 6) % 7];
        return `${dow}, ${dt.getDate()}. ${MONTHS[dt.getMonth()]} ${dt.getFullYear()}`;
    }

    // ── State ─────────────────────────────────────────────────────
    const state = {
        rest: null, guests: null, date: null, time: null, areaId: null,
        calYear: new Date().getFullYear(), calMonth: new Date().getMonth(),
    };

    // ── Progress ──────────────────────────────────────────────────
    function setStep(n) {
        // n může být číslo nebo '3b'
        const stepIds = [1, 2, 3, '3b', 4, 5];
        stepIds.forEach(id => {
            const s = $('ws' + id);
            if (s) s.className = 'step' + (id === n ? ' active' : '');
        });
        const progressN = (n === '3b') ? 3 : (typeof n === 'number' ? n : parseInt(n));
        for (let i = 1; i <= 4; i++) {
            const pi = $('wp' + i);
            if (!pi) continue;
            pi.className = 'pi' + (i === progressN ? ' active' : i < progressN ? ' done' : '');
            pi.querySelector('.n').textContent = i < progressN ? '✓' : i;
        }
        wrap.scrollIntoView({ behavior: 'smooth', block: 'start' });
        if (n === 4) updateStep4Sub();
    }

    // ── Init: naloži restavracijo ─────────────────────────────────
    (async () => {
        try {
            const res  = await fetch(`${apiUrl}?t=${encodeURIComponent(token)}&lang=${encodeURIComponent(_wLang)}`);
            const json = await res.json();
            if (!json.success) throw new Error(json.error || 'Napaka');
            state.rest = json.data;

            $('wloading').style.display = 'none';
            $('wmain').style.display    = '';

            $('wname').textContent = json.data.name;
            $('wlogo').textContent = (json.data.name || 'R')[0].toUpperCase();
            $('wgdpr-link').href = privacyUrl;

            // ── Lang nastavitve restavracije ──
            const langSwitcherEnabled = json.data.lang_switcher_enabled !== false;
            const availLangs = (json.data.available_languages && json.data.available_languages.length)
                ? json.data.available_languages
                : Object.keys(WIDGET_STRINGS);
            const primaryLang = json.data.primary_language || 'sl';

            // Skrij switcher če onemogočen
            if (!langSwitcherEnabled) {
                const btn = shadow.getElementById('wlang-btn');
                if (btn) btn.style.display = 'none';
            } else {
                // Filtriraj dropdown na dovoljene jezike
                const menu = shadow.getElementById('wlang-menu');
                if (menu) {
                    menu.querySelectorAll('button[data-wlang]').forEach(b => {
                        if (availLangs.indexOf(b.getAttribute('data-wlang')) === -1) {
                            b.style.display = 'none';
                        }
                    });
                }
            }

            // Če trenutni lang ni v dovoljenih → preklopi na primary
            if (availLangs.indexOf(_wLang) === -1) {
                const fallback = availLangs.indexOf(primaryLang) !== -1 ? primaryLang : availLangs[0];
                if (fallback && fallback !== _wLang) {
                    try { localStorage.setItem('rezWidgetLang', fallback); } catch (e) {}
                    location.reload();
                    return;
                }
            }
            // Če nimamo shranjene preference (prvič) IN switcher onemogočen → uporabi primary
            let storedLang = null;
            try { storedLang = localStorage.getItem('rezWidgetLang'); } catch (e) {}
            if (!langSwitcherEnabled && !storedLang && _wLang !== primaryLang) {
                try { localStorage.setItem('rezWidgetLang', primaryLang); } catch (e) {}
                location.reload();
                return;
            }

            buildGuestBtns();
            renderCal();
            renderCustomFields(json.data.custom_fields || []);
        } catch (e) {
            $('wloading').style.display = 'none';
            $('werrmsg').textContent    = e.message;
            $('werr').style.display     = '';
        }
    })();

    // ── Korak 1: Gostje ───────────────────────────────────────────
    function buildGuestBtns() {
        const { min_guests, max_guests } = state.rest;
        const wrap = $('wgbtns');
        const showTo = Math.min(10, max_guests);
        const hasMore = max_guests > 10;

        for (let n = min_guests; n <= showTo; n++) {
            const b = document.createElement('button');
            b.className = 'gbtn';
            b.textContent = n;
            b.addEventListener('click', () => { selectGuests(n, b); setStep(2); });
            wrap.appendChild(b);
        }

        if (hasMore) {
            const more = document.createElement('button');
            more.id = 'wgmore';
            more.className = 'gmore';
            more.textContent = 'Več →';
            more.addEventListener('click', toggleMore);
            wrap.appendChild(more);

            const inp = $('wgmoreinp');
            inp.min = 11;
            inp.max = max_guests;
            $('wgmorelbl').textContent = wt('enter_guests', { min: 11, max: max_guests });
            inp.placeholder = '11';
            inp.addEventListener('input', () => {
                const v = parseInt(inp.value);
                if (v >= 11 && v <= max_guests) selectGuests(v, shadow.getElementById('wgmore'));
                else { clearGuests(); if (v > max_guests) inp.value = max_guests; }
            });
            $('wbtn1').style.display = '';
        } else {
            $('wgmorewrap').style.display = 'none';
            $('wbtn1').style.display = 'none';
        }
    }

    let moreOpen = false;
    function toggleMore() {
        moreOpen = !moreOpen;
        $('wgmorewrap').style.display = moreOpen ? '' : 'none';
        const btn = shadow.getElementById('wgmore');
        if (moreOpen) { clearGuests(); btn.classList.add('sel'); $('wgmoreinp').focus(); }
        else btn.classList.remove('sel');
    }

    function clearGuests() {
        state.guests = null;
        shadow.querySelectorAll('.gbtn,.gmore').forEach(b => b.classList.remove('sel'));
        $('wbtn1').disabled = true;
    }

    function selectGuests(n, btn) {
        state.guests = n;
        shadow.querySelectorAll('.gbtn,.gmore').forEach(b => b.classList.remove('sel'));
        btn.classList.add('sel');
        if (btn.id !== 'wgmore') { moreOpen = false; $('wgmorewrap').style.display = 'none'; }
        $('wbtn1').disabled = false;
    }

    $('wbtn1').addEventListener('click', () => { if (state.guests) setStep(2); });

    // ── Korak 2: Kalendar ─────────────────────────────────────────
    function renderCal() {
        const year  = state.calYear;
        const month = state.calMonth;
        const today = new Date(); today.setHours(0, 0, 0, 0);

        $('wcalttl').textContent = `${MONTHS[month]} ${year}`;

        const isCurMon = year === today.getFullYear() && month === today.getMonth();
        const prev = $('wcalprev');
        prev.disabled = isCurMon;

        const first = new Date(year, month, 1);
        const last  = new Date(year, month + 1, 0);
        let dow = first.getDay() - 1;
        if (dow < 0) dow = 6;

        const grid  = $('wcaldays');
        grid.innerHTML = '';

        // Prazne celice
        for (let i = 0; i < dow; i++) {
            grid.appendChild(document.createElement('div'));
        }

        const openDays   = state.rest.open_days;
        const blackouts  = state.rest.blackout_dates || [];

        for (let d = 1; d <= last.getDate(); d++) {
            const dt     = new Date(year, month, d);
            const dayIdx = (dt.getDay() + 6) % 7; // 0=Pon
            const ds     = `${year}-${String(month + 1).padStart(2, '0')}-${String(d).padStart(2, '0')}`;
            const isPast = dt < today;
            const isOpen = (openDays >> dayIdx) & 1;
            const isBlk  = blackouts.includes(ds);
            const isTd   = dt.toDateString() === today.toDateString();
            const isSel  = ds === state.date;

            const cell = document.createElement('div');
            cell.textContent = d;

            if (isPast) {
                cell.className = 'cal-day dis' + (isTd ? ' td' : '');
            } else if (isBlk) {
                // Popolnoma blokiran datum – ni klika
                cell.className = 'cal-day dis' + (isTd ? ' td' : '');
            } else if (!isOpen) {
                // Izklopljen dan v tednu – ni klika (brez čakalne liste)
                cell.className = 'cal-day dis' + (isTd ? ' td' : '');
            } else {
                cell.className = 'cal-day av' + (isTd ? ' td' : '') + (isSel ? ' sel' : '');
                cell.addEventListener('click', () => selectDate(ds));
            }
            grid.appendChild(cell);
        }
    }

    $('wcalprev').addEventListener('click', () => calMove(-1));
    $('wcalnext').addEventListener('click', () => calMove(1));

    function calMove(dir) {
        state.calMonth += dir;
        if (state.calMonth > 11) { state.calMonth = 0; state.calYear++; }
        if (state.calMonth < 0)  { state.calMonth = 11; state.calYear--; }
        renderCal();
    }

    function selectDate(ds) {
        state.date = ds;
        renderCal();
        setStep(3);
        loadSlots(ds);
    }

    function selectDateWaitlist(ds) {
        state.date = ds;
        renderCal();
        $('wsloading').style.display = 'none';
        $('wsslots').style.display   = 'none';
        $('wsempty').style.display   = '';
        showWaitlistPanel();
        const dt = new Date(ds + 'T12:00:00');
        $('ws3sub').textContent = `${DAYS_SL[(dt.getDay() + 6) % 7]}, ${dt.getDate()}. ${MONTHS[dt.getMonth()]} ${dt.getFullYear()} · ${wt('no_slots_sfx')}`;
        setStep(3);
    }

    function showWaitlistPanel() {
        $('wwl-panel').style.display = '';
        $('wwl-done').style.display  = 'none';
        // Ponastavi
        ['wwl-first','wwl-last','wwl-email','wwl-phone','wwl-time'].forEach(id => {
            const el = $(id); if (el) el.value = '';
        });
        const g = $('wwl-gdpr'); if (g) g.checked = false;
        const e = $('wwl-err');  if (e) { e.textContent = ''; e.classList.remove('show'); }
    }

    // ── Korak 3: Termini ──────────────────────────────────────────
    async function loadSlots(date) {
        $('wsloading').style.display = '';
        $('wsempty').style.display   = 'none';
        $('wsslots').style.display   = 'none';
        $('wsslots').innerHTML = '';
        if ($('wwl-panel')) $('wwl-panel').style.display = 'none';
        if ($('wwl-done'))  $('wwl-done').style.display  = 'none';

        try {
            const guestParam = state.guests ? `&guest_count=${state.guests}` : '';
            const res  = await fetch(`${apiUrl}?t=${encodeURIComponent(token)}&date=${date}${guestParam}&lang=${encodeURIComponent(_wLang)}`);
            const json = await res.json();
            if (!json.success) throw new Error(json.error);
            const slots = json.data.slots || [];
            $('wsloading').style.display = 'none';

            if (!slots.length) {
                $('wsempty').style.display = '';
                if (state.rest.waitlist_enabled) showWaitlistPanel();
                return;
            }

            const grid = $('wsslots');
            let hasWaitlist = false;
            slots.forEach(slotObj => {
                const time   = typeof slotObj === 'string' ? slotObj : slotObj.time;
                const status = typeof slotObj === 'string' ? 'available' : (slotObj.status || 'available');
                if (status === 'full') return; // preskoči popolnoma zasedene termine
                const b = document.createElement('button');
                b.className = 'slot' + (status === 'waitlist' ? ' wl' : '');
                b.title = status === 'waitlist' ? 'Zasedeno – vpis na čakalno listo' : '';
                if (status === 'waitlist') {
                    hasWaitlist = true;
                    b.innerHTML = time + `<span style="font-size:.65rem;display:block;font-weight:500;line-height:1.2">${wt('wl_legend')}</span>`;
                    b.addEventListener('click', () => selectWaitlistSlot(time, b));
                } else {
                    b.textContent = time;
                    b.addEventListener('click', () => selectSlot(time, b));
                }
                grid.appendChild(b);
            });
            grid.style.display = '';
            const legend = $('wsslots-legend');
            if (legend) legend.style.display = hasWaitlist ? 'flex' : 'none';

            const dt  = new Date(date + 'T12:00:00');
            $('ws3sub').textContent = `${DAYS_SL[(dt.getDay() + 6) % 7]}, ${dt.getDate()}. ${MONTHS[dt.getMonth()]} · ${state.guests} ${guestLbl(state.guests)}`;

        } catch (e) {
            $('wsloading').style.display = 'none';
            $('wsempty').style.display   = '';
            if (state.rest.waitlist_enabled) showWaitlistPanel();
        }
    }

    function selectWaitlistSlot(time, btn) {
        shadow.querySelectorAll('.slot').forEach(b => b.classList.remove('sel'));
        btn.classList.add('sel');
        state._waitlistTime = time;
        state._isWaitlist   = false; // še ni potrjeno

        // Pokaži notice panel (isto kot book.php)
        const lbl = $('wswl-time-label');
        if (lbl) lbl.textContent = time;
        const notice = $('wswl-notice');
        if (notice) {
            notice.style.display = '';
            notice.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }
    }

    function continueToWaitlistWidget() {
        state._isWaitlist = true;
        const notice = $('wswl-notice');
        if (notice) notice.style.display = 'none';
        // Pokaži banner v koraku 4
        const n4 = $('ws4-wl-notice');
        const t4 = $('ws4-wl-time');
        if (n4) n4.style.display = '';
        if (t4) t4.textContent = state._waitlistTime || '';
        // Posodobi label gumba
        const lbl = $('wbtnlbl');
        if (lbl) lbl.textContent = wt('wl_submit');
        setStep(4);
    }

    function selectSlot(time, btn) {
        state.time   = time;
        state.areaId = null;
        shadow.querySelectorAll('.slot').forEach(b => b.classList.remove('sel'));
        btn.classList.add('sel');

        if (state.rest && state.rest.allow_area_choice) {
            setTimeout(() => loadAreas(state.date, time), 180);
        } else {
            setTimeout(() => setStep(4), 180);
        }
    }

    async function loadAreas(date, time) {
        $('ws3bloading').style.display = '';
        $('ws3bbtns').style.display    = 'none';
        $('ws3bbtns').innerHTML        = '';
        setStep('3b');

        const dt = new Date(date + 'T12:00:00');
        $('ws3bsub').textContent = `${DAYS_SL[(dt.getDay()+6)%7]}, ${dt.getDate()}. ${MONTHS[dt.getMonth()]} · ${time} · ${state.guests} ${guestLbl(state.guests)}`;

        try {
            const res  = await fetch(`${apiUrl}?t=${encodeURIComponent(token)}&date=${date}&time=${time}&guest_count=${state.guests||1}&lang=${encodeURIComponent(_wLang)}`);
            const json = await res.json();
            if (!json.success) throw new Error(json.error);

            const areas = json.data?.areas || [];
            $('ws3bloading').style.display = 'none';

            if (!areas.length) { setStep(4); return; }

            // Gumb "Vseeno mi je"
            const anyBtn = document.createElement('button');
            anyBtn.className = 'slot';
            anyBtn.style.cssText = 'width:100%;text-align:left;padding:12px 14px;border-radius:10px;border:1.5px solid var(--border);background:#fff;cursor:pointer;margin-bottom:8px';
            anyBtn.innerHTML = `<strong style="font-size:.9rem">${wt('area_any')}</strong><br><span style="font-size:.78rem;opacity:.6">${wt('area_any_desc')}</span>`;
            anyBtn.onclick = () => selectArea(null, anyBtn);
            $('ws3bbtns').appendChild(anyBtn);

            areas.forEach(area => {
                const btn = document.createElement('button');
                btn.className = 'slot';
                btn.disabled = !area.available;
                btn.style.cssText = `width:100%;text-align:left;padding:12px 14px;border-radius:10px;border:1.5px solid var(--border);background:#fff;margin-bottom:8px;cursor:${area.available?'pointer':'not-allowed'};opacity:${area.available?1:0.4}`;
                btn.innerHTML = `<strong style="font-size:.9rem">${area.name}</strong>${!area.available ? '<br><span style="font-size:.78rem;opacity:.6">' + wt('area_unavail') + '</span>' : ''}`;
                if (area.available) btn.onclick = () => selectArea(area.id, btn);
                $('ws3bbtns').appendChild(btn);
            });

            $('ws3bbtns').style.display = '';
        } catch(e) {
            setStep(4);
        }
    }

    function selectArea(areaId, btn) {
        state.areaId = areaId;
        $('ws3bbtns').querySelectorAll('button').forEach(b => b.style.borderColor = 'var(--border)');
        btn.style.borderColor = 'var(--primary)';
        setTimeout(() => setStep(4), 180);
    }

    // ── Čakalna lista submit ───────────────────────────────────────
    const waitlistApiUrl = scriptEl.src.replace(/\/widget\.js(\?.*)?$/, '') + '/api/waitlist.php';

    $('wwl-submit').addEventListener('click', async () => {
        const firstName = $('wwl-first')?.value.trim();
        const lastName  = $('wwl-last')?.value.trim();
        const email     = $('wwl-email')?.value.trim();
        const phone     = $('wwl-phone')?.value.trim();
        const timePref  = $('wwl-time')?.value.trim();
        const gdpr      = $('wwl-gdpr')?.checked;
        const errEl     = $('wwl-err');
        const btn       = $('wwl-submit');

        const showErr = (msg) => { errEl.textContent = msg; errEl.classList.add('show'); };
        errEl.classList.remove('show');

        if (!firstName) return showErr('Ime je obvezno.');
        if (!lastName)  return showErr('Priimek je obvezen.');
        if (!email || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) return showErr('Vnesite veljaven email.');
        if (!gdpr)      return showErr('Soglasje je obvezno.');

        btn.disabled = true;
        btn.textContent = 'Pošiljam...';

        try {
            const res  = await fetch(`${waitlistApiUrl}?t=${encodeURIComponent(token)}`, {
                method:  'POST',
                headers: { 'Content-Type': 'application/json' },
                body:    JSON.stringify({
                    date:        state.date,
                    time_pref:   timePref || '',
                    guests:      state.guests || 1,
                    first_name:  firstName,
                    last_name:   lastName,
                    email,
                    phone:       phone || '',
                    gdpr_consent: true,
                    lang:        _wLang,
                }),
            });
            const json = await res.json();
            if (!json.success) throw new Error(json.error || wt('err_server'));
            $('wwl-panel').style.display = 'none';
            $('wwl-done').style.display  = '';
        } catch (e) {
            showErr(e.message);
            btn.disabled = false;
            btn.textContent = wt('wl_submit');
        }
    });

    // ── Custom fields ─────────────────────────────────────────────
    function renderCustomFields(fields) {
        const cfWrap = $('wcf-wrap');
        if (!cfWrap) return;
        cfWrap.innerHTML = '';
        if (!fields || !fields.length) return;
        fields.forEach(f => {
            const fid = String(f.id);
            const div = document.createElement('div');
            div.className = 'field';

            if (f.field_type === 'checkbox') {
                const lbl2 = document.createElement('label');
                lbl2.style.cssText = 'display:flex;align-items:center;gap:8px;cursor:pointer;font-weight:400;font-size:14px;margin-top:4px';
                const cb = document.createElement('input');
                cb.type = 'checkbox';
                cb.id = 'wcf-' + fid;
                cb.dataset.cfid = fid;
                cb.style.cssText = 'width:16px;height:16px;accent-color:var(--f);cursor:pointer;flex-shrink:0';
                const sp = document.createElement('span');
                sp.textContent = f.label;
                lbl2.appendChild(cb);
                lbl2.appendChild(sp);
                div.appendChild(lbl2);
            } else {
                const lbl = document.createElement('label');
                lbl.innerHTML = escH(f.label) + (f.is_required
                    ? ' <span class="req">*</span>'
                    : ' <span class="opt">(neobvezno)</span>');
                div.appendChild(lbl);

                if (f.field_type === 'select' && f.options && f.options.length) {
                    const sel = document.createElement('select');
                    sel.className = 'inp';
                    sel.id = 'wcf-' + fid;
                    sel.dataset.cfid = fid;
                    const empty = document.createElement('option');
                    empty.value = ''; empty.textContent = wt('select_ph');
                    sel.appendChild(empty);
                    f.options.forEach(o => {
                        const opt = document.createElement('option');
                        opt.value = o; opt.textContent = o;
                        sel.appendChild(opt);
                    });
                    div.appendChild(sel);
                } else {
                    const inp = document.createElement('input');
                    inp.type = 'text';
                    inp.className = 'inp';
                    inp.id = 'wcf-' + fid;
                    inp.dataset.cfid = fid;
                    div.appendChild(inp);
                }
            }
            cfWrap.appendChild(div);
        });
    }

    function collectCustomFields() {
        const cf = {};
        const fields = (state.rest && state.rest.custom_fields) || [];
        fields.forEach(f => {
            const el = $('wcf-' + f.id);
            if (!el) return;
            cf[String(f.id)] = el.type === 'checkbox' ? (el.checked ? '1' : '0') : el.value.trim();
        });
        return cf;
    }

    function validateCustomFields() {
        const fields = (state.rest && state.rest.custom_fields) || [];
        for (const f of fields) {
            if (!f.is_required) continue;
            if (f.field_type === 'checkbox') continue;
            const el = $('wcf-' + f.id);
            if (!el || !el.value.trim()) {
                showErr(`Polje "${f.label}" je obvezno.`);
                if (el) el.focus();
                return false;
            }
        }
        return true;
    }

    function escH(s) {
        return String(s || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    // ── Korak 4: Obrazec ──────────────────────────────────────────
    function updateStep4Sub() {
        if (!state.date || !state.time) return;
        const dt = new Date(state.date + 'T12:00:00');
        $('ws4sub').textContent = `${DAYS_SL[(dt.getDay() + 6) % 7]}, ${dt.getDate()}. ${MONTHS[dt.getMonth()]} · ${state.time} · ${state.guests} ${guestLbl(state.guests)}`;
    }

    $('wbtnsubmit').addEventListener('click', async () => {
        if (state._isWaitlist) { await submitWaitlistFromStep4(); return; }

        const name  = $('wfname').value.trim();
        const email = $('wfemail').value.trim();
        const phone = $('wfphone').value.trim();
        const notes = $('wfnotes').value.trim();
        const err   = $('wferr');

        err.className = 'ferr';

        const gdprOk = $('wgdpr')?.checked;
        const mktg   = $('wmktg')?.checked;

        if (!name)  { showErr('Ime in priimek sta obvezna.'); return; }
        if (!email) { showErr('Email naslov je obvezen.'); return; }
        if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) { showErr('Vnesite veljaven email naslov.'); return; }
        if (!gdprOk) { showErr('Strinjanje z obdelavo podatkov je obvezno.'); return; }
        if (!validateCustomFields()) return;

        const customFields = collectCustomFields();

        setLoading(true);
        try {
            const res  = await fetch(`${apiUrl}?t=${encodeURIComponent(token)}`, {
                method:  'POST',
                headers: { 'Content-Type': 'application/json' },
                body:    JSON.stringify({ date: state.date, time: state.time, guest_name: name, email, phone, notes, guest_count: state.guests, area_id: state.areaId, custom_fields: customFields, gdpr_consent: true, marketing_consent: mktg ? true : false, lang: _wLang }),
            });
            const json = await res.json();
            if (!json.success) throw new Error(json.error || wt('err_server'));
            showConfirm(json.data.auto_confirm);
        } catch (e) {
            showErr(e.message);
            setLoading(false);
        }
    });

    async function submitWaitlistFromStep4() {
        const name  = $('wfname').value.trim();
        const email = $('wfemail').value.trim();
        const phone = $('wfphone').value.trim();
        const gdprOk = $('wgdpr')?.checked;

        if (!name)  { showErr('Ime in priimek sta obvezna.'); return; }
        if (!email) { showErr('Email naslov je obvezen.'); return; }
        if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) { showErr('Vnesite veljaven email naslov.'); return; }
        if (!gdprOk) { showErr('Strinjanje z obdelavo podatkov je obvezno.'); return; }

        const nameParts = name.split(' ');
        const firstName = nameParts[0] || '';
        const lastName  = nameParts.slice(1).join(' ') || '';

        setLoading(true);
        $('wbtnlbl').textContent = 'Pošiljam...';
        try {
            const res = await fetch(`${waitlistApiUrl}?t=${encodeURIComponent(token)}`, {
                method:  'POST',
                headers: { 'Content-Type': 'application/json' },
                body:    JSON.stringify({
                    date:        state.date,
                    time_pref:   state._waitlistTime || '',
                    guests:      state.guests || 1,
                    first_name:  firstName,
                    last_name:   lastName,
                    email,
                    phone,
                    gdpr_consent: true,
                    lang:        _wLang,
                }),
            });
            const json = await res.json();
            if (!json.success) throw new Error(json.error || wt('err_server'));
            // Pokaži potrditev (isto kot book.php waitlist done)
            $('wcico').textContent = wt('wl_confirm_ico');
            $('wcttl').textContent = wt('wl_confirm_ttl');
            $('wcmsg').textContent = wt('wl_confirm_msg');
            const dt = new Date(state.date + 'T12:00:00');
            $('wcsrest').textContent = state.rest.name;
            $('wcsdate').textContent = `${DAYS_SL[(dt.getDay() + 6) % 7]}, ${dt.getDate()}. ${MONTHS[dt.getMonth()]} ${dt.getFullYear()}`;
            $('wcstime').textContent = state._waitlistTime || '—';
            $('wcsgst').textContent  = `${state.guests} ${guestLbl(state.guests)}`;
            setStep(5);
        } catch (e) {
            showErr(e.message);
            setLoading(false);
        }
    }

    function showErr(msg) {
        const el = $('wferr');
        el.textContent = msg;
        el.className = 'ferr show';
    }

    function setLoading(on) {
        const btn = $('wbtnsubmit');
        btn.disabled = on;
        $('wbtnlbl').textContent = on ? 'Pošiljam...' : 'Pošlji rezervacijo';
        if (on) {
            const sp = document.createElement('span');
            sp.className = 'spin';
            sp.id = 'wspin';
            btn.appendChild(sp);
        } else {
            const sp = shadow.getElementById('wspin');
            if (sp) sp.remove();
        }
    }

    // ── Korak 5: Potrditev ────────────────────────────────────────
    function showConfirm(auto) {
        const dt  = new Date(state.date + 'T12:00:00');
        const ds  = `${DAYS_SL[(dt.getDay() + 6) % 7]}, ${dt.getDate()}. ${MONTHS[dt.getMonth()]} ${dt.getFullYear()}`;
        $('wcico').textContent = auto ? wt('confirm_auto_ico') : wt('confirm_pend_ico');
        $('wcttl').textContent = auto ? wt('confirm_auto_ttl') : wt('confirm_pend_ttl');
        $('wcmsg').textContent = auto ? wt('confirm_auto_msg') : wt('confirm_pend_msg');
        $('wcsrest').textContent  = state.rest.name;
        $('wcsdate').textContent  = ds;
        $('wcstime').textContent  = state.time;
        $('wcsgst').textContent   = `${state.guests} ${guestLbl(state.guests)}`;
        setStep(5);
    }

    // ── Reset ─────────────────────────────────────────────────────
    $('wbtnreset').addEventListener('click', () => {
        state.guests = null; state.date = null; state.time = null; state.areaId = null;
        state._isWaitlist = false; state._waitlistTime = null;
        const n4 = $('ws4-wl-notice'); if (n4) n4.style.display = 'none';
        const wn = $('wswl-notice');   if (wn) wn.style.display = 'none';
        shadow.querySelectorAll('.gbtn,.gmore').forEach(b => b.classList.remove('sel'));
        $('wbtn1').disabled = true;
        $('wgmorewrap').style.display = 'none';
        $('wgmoreinp').value = '';
        $('wfname').value = ''; $('wfemail').value = '';
        $('wfphone').value = ''; $('wfnotes').value = '';
        const gdprCb = $('wgdpr'); if (gdprCb) gdprCb.checked = false;
        const mktgCb = $('wmktg'); if (mktgCb) mktgCb.checked = false;
        ((state.rest && state.rest.custom_fields) || []).forEach(f => {
            const el = $('wcf-' + f.id);
            if (!el) return;
            if (el.type === 'checkbox') el.checked = false;
            else el.value = '';
        });
        moreOpen = false;
        setStep(1);
    });

    // ── Waitlist notice "Nadaljuj" gumb ───────────────────────────
    $('wswl-continue').addEventListener('click', continueToWaitlistWidget);

    // ── Back gumbi ────────────────────────────────────────────────
    $('wb2').addEventListener('click', () => setStep(1));
    $('wb3').addEventListener('click', () => {
        // Skrij waitlist notice ob vrnitvi na korak 2
        const notice = $('wswl-notice');
        if (notice) notice.style.display = 'none';
        setStep(2);
    });
    $('wb3b').addEventListener('click', () => setStep(3));
    $('wb4').addEventListener('click', () => {
        // Ponastavi waitlist stanje ob vrnitvi
        state._isWaitlist = false;
        const n4 = $('ws4-wl-notice');
        if (n4) n4.style.display = 'none';
        const lbl = $('wbtnlbl');
        if (lbl) lbl.textContent = 'Pošlji rezervacijo';
        state.rest?.allow_area_choice ? setStep('3b') : setStep(3);
    });

})();
