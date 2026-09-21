# Tamás Bau Kft. Webapp (PHP + MySQL)

Ez a projekt a korábbi statikus `index.html` verzió továbbfejlesztett változata, ahol az adatok valódi MySQL adatbázisban vannak tárolva, a frontend pedig `fetch()` hívásokkal kommunikál a PHP API-val.

## 1) Adatbázis létrehozása

1. Hozz létre egy MySQL adatbázist (alapértelmezett név: `tamasbau`).
2. Importáld a sémát és seed adatokat:

```bash
mysql -u root -p < /home/runner/work/tamasbau/tamasbau/database/schema.sql
```

## 2) API konfiguráció (`api/config.php`)

Az API környezeti változókból olvassa a DB kapcsolatot:

- `TB_DB_HOST` (alapértelmezett: `127.0.0.1`)
- `TB_DB_PORT` (alapértelmezett: `3306`)
- `TB_DB_NAME` (alapértelmezett: `tamasbau`)
- `TB_DB_USER` (alapértelmezett: `root`)
- `TB_DB_PASS` (alapértelmezett: üres)

Példa futtatás exporttal:

```bash
export TB_DB_HOST=127.0.0.1
export TB_DB_PORT=3306
export TB_DB_NAME=tamasbau
export TB_DB_USER=root
export TB_DB_PASS=secret
```

## 3) Demo admin belépés

- E-mail: `admin@tamasbau.hu`
- Jelszó: `Admin123!`

A login modalban található **Gyors belépés Demo Adminisztrátorként** gomb ezt a felhasználót hitelesíti a backend felé.

## 4) Futtatás helyben

A repository gyökeréből indítsd:

```bash
php -S localhost:8000
```

Ezután nyisd meg: `http://localhost:8000`

## 5) API végpontok

- `api/auth.php` (regisztráció, login, logout, aktuális user)
- `api/users.php` (admin user CRUD)
- `api/categories.php` (kategória/alkategória CRUD)
- `api/products.php` (termék CRUD)
- `api/orders.php` (checkout + rendelés lista + státusz frissítés)
- `api/quotes.php` (ajánlatkérés létrehozás + admin kezelés)
- `api/estimates.php` (bejelentkezett user kalkulációi)
