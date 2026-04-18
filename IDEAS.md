# Ideje za prihodnje izboljšave

---

## Per-restavracija vloge (role per restaurant)

**Trenutno stanje:**
Vloga (`role`) je globalna na nivoju userja. Admin je admin za vse restavracije v `restaurant_admins`, user ima eno restavracijo via `restaurant_id`. Ni možno, da bi bila ista oseba "user" za restavracijo A in "admin" za restavracijo B.

**Predlog:**
Dodaj `role` stolpec v tabelo `restaurant_admins`:

```sql
ALTER TABLE restaurant_admins
  ADD COLUMN role ENUM('admin', 'user') NOT NULL DEFAULT 'admin';
```

S tem se vsak vnos v `restaurant_admins` obravnava neodvisno:
- `(restaurant_id=A, user_id=X, role='user')` → oseba X vidi rezervacije za A, ne more urejati nastavitev
- `(restaurant_id=B, user_id=X, role='admin')` → oseba X polno upravlja B

**Kaj bi bilo treba spremeniti:**
- `includes/functions.php` – `get_admin_restaurant_ids()` bi vrnil ločen seznam za admin in user dostop
- `includes/auth_check.php` – session bi vseboval per-restaurant vloge
- `pages/main.php`, `pages/admin.php` – prikaz na osnovi vloge za izbrano restavracijo
- `api/users.php` – pri ustvarjanju/urejanju shrani vlogo v `restaurant_admins.role`
- `api/restaurants.php`, `api/reservations.php` itd. – preveriti vlogo za vsak API klic

**Zakaj odloženo:**
Zahteva spremembo sheme + posodobitev vseh API-jev, ki preverjajo dostop. Ni urgentno, ker večina adminov upravlja svoje restavracije z enakim dostopom.
