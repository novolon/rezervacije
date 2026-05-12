Pred objavo na app.rezervacije.si je kar nekaj stvari. Tukaj prioritizirana lista:                                                                                                      
                                                                                                                                                                                          
  🔴 Kritično — brez tega se NE da deploy-ati                                                                                                                                             
                                                                                                                                                                                          
  Konfiguracija                                                                                                                                                                           
                                                                                                                                                                                          
  - config.php na prod: nov fajl z prod vrednostmi:                                                                                                                                       
    - APP_URL = https://app.rezervacije.si                                                                                                                                                
    - BASE_PATH = '' (prazen, ne /rezervacije-saas)                                                                                                                                       
    - Stripe LIVE keys (trenutno sk_test_…, pk_test_…)                                                                                                                                    
    - Live STRIPE_PRICES (drugačni ID-ji od test)
    - Live STRIPE_WEBHOOK_SECRET (z novim webhook endpointom v Stripe dashboardu)
    - DB credentials prod baze (drugačna od dev n0v0l0n)
    - MAILGUN_DOMAIN = prod domena (npr. mail.rezervacije.si), DKIM/SPF/MX records v DNS
    - MAIL_FROM = Rezervacije <noreply@rezervacije.si>
    - DB+API ključi (Anthropic, OpenAI, Unsplash, RacunHub) — preveri kvote/limite
  - .htaccess: RewriteBase /rezervacije-saas/ → RewriteBase /

  Database

  - Apply vse sql/migrate_*.sql fajle po vrsti na prod DB:
  migrate_to_saas → migrate_billing → migrate_billing_data → migrate_trial_v2 →
  migrate_email_verification → migrate_guests → migrate_phone_guest → backfill_guests →
  migrate_attended → migrate_noshow → migrate_waitlist → migrate_waitlist_max →
  migrate_self_booking → migrate_guest_edit → migrate_username →
  migrate_restaurant_contact → migrate_schedules_blackouts → migrate_multi_period →
  migrate_tables → migrate_table_settings → alter_custom_duration → migrate_surveys →
  migrate_gdpr → migrate_staff_customfields → migrate_subscription_invoices →
  migrate_hub_invoice → migrate_pending_plan → migrate_pending_downgrade →
  migrate_affiliate → migrate_blog
  - NE poženi seed_blog_demo.sql na prod (test članek)
  - Backup procedure: dnevni mysqldump + uploads/ snapshot

  Setup

  - Obišči /setup.php, ustvari prvi superadmin račun
  - Pobriši setup.php takoj po tem (kritično — ostane vrata)
  - Pobriši test-racun.php (ima trd API key v kodi, ne potrebuješ ga na prod)

  DNS + SSL

  - app.rezervacije.si → A record na Synology IP
  - SSL cert (Let's Encrypt v Synology Control Panel ali Certbot)
  - HTTPS redirect (HSTS header)

  🟡 Pomembno — pred prvim uporabnikom

  Stripe live mode

  - V Stripe dashboardu: ustvari Live mode produkte (Basic/Advanced/Premium × monthly/yearly = 6 cen) → kopiraj price ID-je v STRIPE_PRICES
  - Live webhook endpoint: https://app.rezervacije.si/api/stripe-webhook.php
  - Test live transakcijo z pravo kartico (storno preden zaračuna)

  Cron na Synology Task Scheduler

  - cron/reminders.php — daily 09:00
  - cron/survey_send.php — vsakih 15 min
  - cron/waitlist_expire.php — vsakih 15 min
  - cron/gdpr_cleanup.php — weekly
  - cron/affiliate_payable.php — monthly (ali kakor je nameščeno)
  - Vsi morajo poganjati kot user z dostopom do uploads/ in php-cli v PATH

  Email

  - Mailgun: verify nove sender domene (DKIM, SPF, MX) za prod
  - Test email flow: registracija, password reset, booking confirmed, reminder, subscribe confirm, plan change

  Blog (Booked)

  - Vnesi 3-5 pravih objavljenih člankov (pred launchem, da ima blog "vsebino")
  - Auto-translate booked. lang ključe* — v vseh lang/*.json (razen sl) imajo SL tekst (intentional za hitri build, ampak SEO bo sl). V superadmin-u lahko ad-hoc prevedeš ali pa naredim
  helper script.
  - Uploads /uploads/blog/ mora biti writable za PHP user
  - Test full flow: AI generate → preview → translate all → publish → public render

  Legal

  - pages/privacy.php — preveri vsebino z odvetnikom ali GDPR vzorcem (Slovenija + EU)
  - pages/terms.php — pogoji uporabe pred sklepanjem naročnin (potrebno za Stripe customer DPA tudi)
  - Cookie banner — preveri ali ga aplikacija pokaže (osnovni session cookie + rzlang)

  🟢 Priporočeno

  Performance

  - PHP opcache vključen na Synology
  - ini_set('display_errors','0') ✓ (že v config.php)
  - Sitemap cache (sitemap.xml.cache) writable
  - DB indexes preveri: EXPLAIN SELECT na ključnih queryjih (reservations po datumu, blog_post_translations FULLTEXT)

  Frontend / landing

  - cd home && npm run build lokalno → upload home/dist/ na Synology
  - Preveri da landing CTA-ji peljejo na app.rezervacije.si/register.php
  - home/index.html je deleted, home/_index.html je nov — kaj je razlog? Preveri build output

  QA pred launchom (preigraj scenarije)

  - Registracija → trial banner → email verify
  - Stripe checkout (test mode) → webhook → premium feature unlock
  - Plan upgrade/downgrade/cancel
  - Multi-restaurant: ustvari 2 restavraciji, preveri da staff member vidi samo svojo
  - Self-booking widget (Premium) → embed kod kopira → preveri na test strani
  - Survey: ustvari → odgovori → izvozi CSV
  - Waitlist: doseži zasedenost → join → cancel → cascade notification
  - Table management: določi mize, merge group, auto allocate
  - GDPR: "izvozi moje podatke" + "anonymize"
  - Blog: prijava → confirm email → modal → odjava → modal
  - Lang switcher na blog stane (po nedavnem fix-u)
  - Mobile responsive na admin straneh

  Monitoring

  - PHP error_log lokacija dokumentirana, log rotacijo nastavi
  - Mailgun delivery logs preveri prvih 24h
  - Stripe webhook delivery success rate

  Cleanup pred prvim commitom v main

  - blog-automation-navodila.md v repo-ju — to je tvoj draft. Premakni v gitignore ali zbriši?
  - home/_index.html vs home/index.html — kaj je dejansko prod fajl?