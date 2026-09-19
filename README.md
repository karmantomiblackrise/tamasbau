# tamasbau – stabil admin rendszer (PHP + MySQL + cPanel)

Ez a verzió az admin réteg stabil újraépítésére készült: megbízható auth, biztonságos session/CSRF, modern admin shell, diagnosztika és kontrollált helyreállítás.

## Követelmények
- PHP 8.1+
- MySQL 8+ vagy MariaDB 10.5+
- cPanel hozzáférés (MySQL Databases + phpMyAdmin)

## 1) cPanel adatbázis létrehozás
1. cPanel → **MySQL Databases**.
2. Hozz létre adatbázist (pl. `cpuser_tamasbau`).
3. Hozz létre adatbázis-felhasználót (pl. `cpuser_tamasbau_admin`).
4. **Add User To Database**: rendeld a felhasználót az adatbázishoz.
5. Adj **ALL PRIVILEGES** jogosultságot.

## 2) `config.php` kitöltése
A gyökérben lévő `config.php` fájlban töltsd ki a saját adatokat:

- `APP_URL` (pl. `https://tamasbau.hu`)
- `APP_BASE_PATH`:
  - root telepítés: `''`
  - alkönyvtár telepítés: pl. `'projektek/tamasbau'`
- `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS`
- `HEALTHCHECK_KEY`: hosszú, véletlen, titkos kulcs

> A session cookie path automatikusan az `APP_BASE_PATH` alapján áll be, így root és subdirectory telepítésnél is stabilan működik.

## 3) Schema import
1. phpMyAdmin → válaszd ki a cél adatbázist.
2. Importáld: `database/schema.sql`
3. Az SQL a szükséges táblákat hozza létre.

## 4) Alap admin belépés (friss telepítés)
1. Nyisd meg: `/admin/setup-check.php?key=SAJAT_HEALTHCHECK_KEY`
2. A helyreállításnál írd be: `YES`
3. Adj meg egy új admin jelszót (minimum 12 karakter)
4. Lépj be: `/admin/login.php` (felhasználónév: `admin`)
5. Azonnal jelszócsere: `Admin → Rendszer/Fiók → Jelszócsere`

## 5) Diagnosztika és biztonságos helyreállítás
- Diagnosztika: `/admin/setup-check.php`
- Alapértelmezésben csak ellenőriz (DB kapcsolat, `users` tábla, admin rekord).
- Javítás futtatása csak explicit módon:
  - érvényes kulcs (`?key=...`), és
  - POST megerősítés `confirm=YES`.

A helyreállítás kizárólag hiányzó admin rekord esetén hoz létre új `admin` felhasználót a megadott jelszóval, és naplóz:
- `logs/admin-recovery.log`
- Már létező admin jelszavát nem írja felül (védett működés).

A diagnosztika nem ír ki DB jelszót vagy titkos konfigurációs értéket.

## 6) Admin auth folyamat ellenőrzés
1. Login: `/admin/login.php`
2. Dashboard: `/admin/dashboard.php`
3. Logout: `/admin/logout.php`
4. Login újra

A login oldal konkrét, magyar hibákat ad:
- adatbázis kapcsolat hiba
- hiányzó `users` tábla
- hiányzó admin rekord
- hibás adatok
- CSRF/session hiba

## 7) Biztonsági elemek
- Minden admin oldal auth-gated (`require_admin()`).
- Minden admin POST CSRF tokennel védett.
- PDO prepared statementek használata.
- HTML escaping (`h()`).
- Session ID regenerálás belépéskor.
- Nincs veszélyes, publikus önjavító endpoint.
- Nincs rendelési reset vagy rendelési adat törlés.

## 8) Admin modulok
- Dashboard állapotkártyák
- Webhely: Blog, Árlista, Szolgáltatások, Menük, Oldalbeállítások
- Webshop: Termékek, Kategóriák, Márkák, Rendelések, Alacsony készlet, Szállítás/Fizetés, Webshop beállítások
- Ügyfélkapcsolat: Ajánlatkérések, Kapcsolati üzenetek
- Rendszer/Fiók: Felhasználók, Jelszócsere, Rendszerállapot

## 9) Korlát
Ez a stabilizációs kör elsődlegesen az admin és adatmodell-konzisztenciát célozza. A publikus oldalak és webshop publikus működésének bővítése külön fejlesztési lépés.
