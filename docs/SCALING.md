# Scaling — kaj narediti za 1M+ rezervacij

Ta dokument popisuje vse korake (po prioriteti), da app deluje hitro tudi pri
1M+ zapisov v bazi. Ne potrebujejo se vsi takoj — prvi sklop je dovolj za
varen scale do ~5M rezervacij.

---

## 🔴 1. Pogoji apply

### A. Apply `sql/migrate_perf_indexes.sql`

```bash
mysql rezervacije_saas < sql/migrate_perf_indexes.sql
```

**Kaj rešuje:**

- `reservations.idx_date_rest` ima napačen vrstni red `(reservation_date, restaurant_id)`. Pri 1M zapisov in ~1000 restavracij MySQL bere VSE zapise za datum range, nato filtrira po restaurant_id — linearen padec hitrosti.
- Migracija doda pravilen `idx_rest_date_time (restaurant_id, reservation_date, reservation_time)` — multi-tenant query je zdaj O(log N), neodvisno od velikosti tabele.
- Plus: `(restaurant_id, status, reservation_date)` za status filter, `email`/`phone` za guest history, `survey_answers.response_id`, `reservation_table_assignments`, `waitlist`, `subscriptions`, `blog_subscribers`.

**Po apply:**
```sql
EXPLAIN SELECT * FROM reservations
WHERE restaurant_id = 42
  AND reservation_date BETWEEN '2026-01-01' AND '2026-01-31';
-- type=range, key=idx_rest_date_time, rows≈30 (≈ število rezervacij)
```
Če `rows` >> 1000, indeks ne deluje — preveri da je apply uspešno opravljen.

### B. PHP opcache na produkciji

Na Synology v **Web Station → PHP → Edit → Extensions** vključi `opcache`. V `php.ini`:

```ini
opcache.enable=1
opcache.memory_consumption=128
opcache.max_accelerated_files=10000
opcache.validate_timestamps=0  ; PROD: false (boljši performance)
opcache.revalidate_freq=0
```

`validate_timestamps=0` pomeni da PHP NE preverja sprememb fajlov pri vsakem requestu. **Na deploy kliči `opcache_reset()`** ali restart PHP-FPM/Apache. Brez opcache je PHP 5–10× počasnejši.

### C. MySQL config

V Synology MariaDB Edit `my.cnf`:

```ini
innodb_buffer_pool_size = 1G        # ali več, glede na razpoložljivi RAM
innodb_log_file_size    = 256M
max_connections         = 300       # default 151 — premalo pri visokem prometu
query_cache_type        = 0         # query cache je deprecated v MariaDB 10.3+
```

`innodb_buffer_pool_size` naj bo ~50–70% razpoložljivega RAM-a. Tukaj se cache-ajo prebrane strani indexov in podatkov.

---

## 🟡 2. Pred 100K rezervacij

### A. Cron za stare podatke

Trenutno aktivnih cron jobov:

| Cron | Frekvenca | Namen |
|---|---|---|
| `reminders.php` | dnevno 09:00 | 24h opomniki |
| `survey_send.php` | vsakih 15-30 min | pošiljanje delayed survey emailov |
| `waitlist_expire.php` | vsakih 15 min | expire 2h potrditev windows |
| `gdpr_cleanup.php` | tedensko | anonimizacija 3-let starih podatkov |

GDPR cleanup že redno čisti — ni dodatne intervencije.

### B. CLAUDE.md update

Dodaj v CLAUDE.md razdelek "Database Conventions" z ukazom za apply migracij.

---

## 🟢 3. Pri 500K+ rezervacij

### A. Pagination admin listov

Audit: vse `pages/*.php` ki prikazujejo rezervacije / goste — mora imeti `LIMIT` + `OFFSET` ali keyset pagination.

```bash
grep -rn "fetchAll" api/reservations.php api/guests.php pages/main.php | grep -v "LIMIT"
```

### B. Static asset CDN

Pri ~10K obiskih/dan na javnih booking straneh, pojdi za Cloudflare CDN free tier:
- `app.rezble.com` skozi CF proxy
- `assets/` cache TTL 1 leto (.htaccess že ima mod_expires)
- Ne CF za API endpoint-e (POST requests)

---

## 🔵 4. Pri 5M+ rezervacij — razmisli o teh, ne potrebuje takoj

### A. Tabela partitioning

```sql
ALTER TABLE reservations
  PARTITION BY RANGE (YEAR(reservation_date)) (
    PARTITION p2024 VALUES LESS THAN (2025),
    PARTITION p2025 VALUES LESS THAN (2026),
    PARTITION p2026 VALUES LESS THAN (2027),
    PARTITION pmax  VALUES LESS THAN MAXVALUE
);
```

Stare letne particije se lahko `OPTIMIZE` ali eksportirajo → brez DELETE-ov, brez fragmentacije.

### B. Read replica

MySQL replica za `stats.php`, blog public reads, GDPR exports. Glavna baza ostane pisalna.

### C. Redis za sessions

`includes/session.php` (če bo) → Redis namesto file-based, ko bo več Synology instanc.

### D. Archive starih rezervacij

Tabela `reservations_archive` (>2 leti). Cron premakne vrstice, glavna baza ostane manjša → indeksi se prilegajo v RAM.

---

## 📊 Realistični profil pri 1M rezervacijah

Predpostavka: ~1000 aktivnih restavracij, 3 leta zgodovine.

| Restavracija ima | Tipično |
|---|---|
| Rezervacij na dan | 5–50 |
| Rezervacij v mesecu | 150–1500 |
| Rezervacij skupaj | 500–5000 |

Vse pogoste poizvedbe so **per-tenant**. Iz 1M vrstic vsak query bere samo ~1500 v najslabšem primeru. **Z dobrim indeksom je to 1–5 ms.**

Brez popravljenih indeksov isti query traja 200–800 ms (linearno raste z velikostjo tabele). Pri 5M rezervacij bo timeout-al.

---

## 🛠️ Diagnostika

Slow query log:
```ini
slow_query_log = 1
slow_query_log_file = /var/log/mysql/slow.log
long_query_time = 0.5  ; nad 500ms je sumljivo
```

Najpogostejši krivci:
1. Manjkajoči compound index → fix z `migrate_perf_indexes.sql`
2. `LIKE '%term%'` brez FULLTEXT → uporabi FULLTEXT (`blog_post_translations` ga že ima)
3. `ORDER BY ... LIMIT` brez pokrivnega indexa → keyset pagination
4. PHP N+1 queryji → batch SELECTs ali JOIN-e

---

## ✅ Quick check po apply

```bash
# 1. Indexi prisotni
mysql rezervacije_saas -e "SHOW INDEX FROM reservations" | grep -E "idx_rest|idx_email|idx_phone"

# 2. EXPLAIN za najpogostejši query
mysql rezervacije_saas -e "EXPLAIN SELECT * FROM reservations WHERE restaurant_id=1 AND reservation_date='2026-05-15'"

# 3. PHP opcache
php -i | grep opcache.enable
```
