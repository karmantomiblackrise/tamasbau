# Tamás Bau Kft. Webapp (PHP + MySQL)

Ez a projekt a korábbi statikus `index.html` verzió továbbfejlesztett változata, ahol az adatok valódi MySQL adatbázisban vannak tárolva, a frontend pedig `fetch()` hívásokkal kommunikál a PHP API-val.

## 1) Adatbázis létrehozása

1. Hozz létre egy MySQL adatbázist (alapértelmezett név: `tamasbau`).
2. Importáld a sémát és seed adatokat:

```bash
mysql -u root -p < database/schema.sql
```

## 2) API konfiguráció (`api/config.php`)

Az API környezeti változókból olvassa a DB kapcsolatot:

- `TB_DB_HOST` (alapértelmezett: `127.0.0.1`)
- `TB_DB_PORT` (alapértelmezett: `3306`)
- `TB_DB_NAME` (alapértelmezett: `tamasbau`)
- `TB_DB_USER` (alapértelmezett: `root`)
- `TB_DB_PASS` (alapértelmezett: üres)
- `APP_ENV` (`development` esetén az elfelejtett jelszó token visszaadásra kerül API válaszban és logba)

Példa futtatás exporttal:

```bash
export TB_DB_HOST=127.0.0.1
export TB_DB_PORT=3306
export TB_DB_NAME=tamasbau
export TB_DB_USER=root
export TB_DB_PASS=secret
export APP_ENV=development
```

## 3) Demo admin belépés

- E-mail: `admin@tamasbau.hu`

A login modalban található **Gyors belépés Demo Adminisztrátorként** gomb az admin e-maillel indít belépést, majd jelszó bekérése után hitelesít a backend felé.

Az első bejelentkezés előtt állíts be saját admin jelszót (példa SQL):

```sql
UPDATE users
SET password_hash = '$2y$10$A_SAJAT_HASHED_JELSZAVAD',
    is_active = 1
WHERE email = 'admin@tamasbau.hu';
```

A hash előállításához használható parancs:

```bash
php -r "echo password_hash('SajatErősJelszo123!', PASSWORD_DEFAULT), PHP_EOL;"
```

## 4) Elfelejtett jelszó flow

- Login modalban elérhető az **Elfelejtette a jelszavát?** funkció.
- API végpont: `api/password-reset.php`
  - `action=request` + `email` → reset token létrehozás (`password_resets` tábla, hash-elt token, 1 órás lejárat)
  - `action=validate` + `token` → token ellenőrzés
  - `action=reset` + `token` + `password` → új jelszó beállítás (min. 8 karakter)
- A token egyszer használatos: reset után `used_at` mező beállításra kerül.
- Fejlesztői módban (`APP_ENV=development`) a token:
  - API válaszban (`dev.token`, `dev.reset_link`)
  - illetve `logs/password-reset.log` fájlban is megjelenik.

## 5) Termékképek

`products.image_url` mező támogatott az admin termék CRUD felületen.

- Webshop kártyán és admin listában kép jelenik meg.
- Hibás vagy hiányzó URL esetén automatikus ikon fallback látható.
- A `database/schema.sql` seed adatok működő publikus képlinkeket tartalmaznak.

## 6) Korlátlan mélységű kategóriahierarchia

- A `categories.parent_id` önhivatkozó idegen kulcs, így tetszőleges mélységű fa építhető.
- A seed adatok tartalmaznak 3+ szintű példát:
  - `Villanyszerelés / Kismegszakítók / Lakossági kismegszakítók`
  - `Villanyszerelés / Fi-relék / 1 fázisú Fi-relék`
- Törlési stratégia: a kategória nem törölhető, ha van közvetlen gyermeke vagy hozzá (illetve bármely leszármazottjához) termék tartozik. Az adatbázis szinten is `ON DELETE RESTRICT` védi ezt.
- Ciklikus hierarchia tiltott: kategória szerkesztésnél nem állítható saját magára vagy saját leszármazottjára.
- A `GET api/categories.php` válasz visszaadja:
  - `categories`: flat lista `parent_id` mezővel
  - `category_tree`: rekurzív nested fa `children` tömbökkel
- Webshop szűrésnél szülő kategória kiválasztásakor az összes leszármazott kategória termékei is megjelennek.

## 7) Futtatás helyben

A repository gyökeréből indítsd:

```bash
php -S localhost:8000
```

Ezután nyisd meg: `http://localhost:8000`

## 8) API végpontok

- `api/auth.php` (regisztráció, login, logout, aktuális user)
- `api/password-reset.php` (elfelejtett jelszó token kérés/ellenőrzés/reset)
- `api/users.php` (admin user CRUD)
- `api/categories.php` (korlátlan mélységű kategóriafa CRUD + flat/tree lista)
- `api/products.php` (termék CRUD)
- `api/orders.php` (checkout + rendelés lista + státusz frissítés)
- `api/quotes.php` (ajánlatkérés létrehozás + admin kezelés)
- `api/estimates.php` (bejelentkezett user kalkulációi)
