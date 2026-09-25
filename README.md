# Tamás Bau Kft. Webapp (PHP + MySQL)

Ez a projekt a korábbi statikus `index.html` verzió továbbfejlesztett, PHP/MySQL backendes változata. A frontend `fetch()` hívásokkal kommunikál a PHP API-val, az adminpanel pedig valós adatbázisból kezeli a felhasználókat, termékeket, kategóriákat, rendeléseket és ajánlatkéréseket.

## 1) Telepítés

### Composer függőségek

```bash
composer install
```

A projekt PHPMailer támogatással működik. Az SMTP diagnosztikai és tesztküldő admin funkció először a Composer `vendor/autoload.php` betöltőt próbálja használni, majd cPanel kompatibilis manuális fallbackként a `vendor/PHPMailer/src` fájlokat. Ha SMTP nincs konfigurálva és `APP_ENV=development`, a meglévő fejlesztői e-mail log fallback (`TB_DEV_MAIL_LOG`) továbbra is elérhető az alkalmazás többi e-mail folyamatában.

### `.env` létrehozása

```bash
cp .env.example .env
```

Ezután állítsd be a saját adatbázis- és SMTP-adataidat.

## 2) Környezeti változók

### Alkalmazás

- `APP_ENV` – `development` vagy `production`
- `TB_APP_NAME` – alkalmazás / feladó név
- `TB_APP_URL` – helyi vagy éles URL (pl. `http://localhost:8000`)
- `TB_CHECKOUT_PAYMENT_PROVIDER` – `offline`, `barion` vagy `stripe` (ha nincs online provider konfigurálva, biztonságos offline fallback marad aktív)

### Adatbázis

- `TB_DB_HOST` (alapértelmezett: `127.0.0.1`)
- `TB_DB_PORT` (alapértelmezett: `3306`)
- `TB_DB_NAME` (alapértelmezett: `tamasbau`)
- `TB_DB_USER` (alapértelmezett: `root`)
- `TB_DB_PASS` (alapértelmezett: üres)

### SMTP / e-mail

- `MAIL_HOST`
- `MAIL_PORT`
- `MAIL_USERNAME`
- `MAIL_PASSWORD`
- `MAIL_ENCRYPTION` (`tls`, `ssl` vagy üres)
- `MAIL_AUTH` (`true` / `false`)
- `MAIL_FROM_ADDRESS`
- `MAIL_FROM_NAME`
- `MAIL_TIMEOUT`
- `MAIL_EHLO_DOMAIN`
- `TB_DEV_MAIL_LOG` (fejlesztői log fájl, alapból `logs/mail-dev.log`)

> Visszafelé kompatibilitás: a korábbi `TB_MAIL_*` kulcsok továbbra is támogatottak.
> Prioritás: ha mindkét kulcskészlet meg van adva, a `MAIL_*` értékek élveznek elsőbbséget, a `TB_MAIL_*` csak fallback.

> Fontos: SMTP jelszót, `.env` fájlt vagy valódi szerverhozzáférést ne commitolj a repository-ba.

## 3) Adatbázis létrehozása és migráció

### Friss telepítés (destruktív schema + seed)

A `database/schema.sql` **teljesen újraépíti** a demo adatbázist és seed adatokat tölt be:

```bash
mysql -u root -p < database/schema.sql
```

A séma már tartalmazza:

- `quotes` tábla új státuszokkal (`new`, `in_progress`, `answered`, `closed`)
- `quotes.admin_reply`, `quotes.replied_at`
- `quote_replies` előzménytábla indexekkel és idegen kulcsokkal
- `support_chats` és `support_messages` táblák indexelt support inbox struktúrával
- teljes support lifecycle mezők: `status`, `admin_unread_count`, `customer_unread_count`, `last_customer_message_at`, `last_admin_message_at`, `deleted_at`

### Meglévő adatbázis frissítése (újrafuttatható migráció)

```bash
mysql -u root -p tamasbau < database/migrations/20260922_quote_reply_system.sql
mysql -u root -p tamasbau < database/migrations/20260922_support_chat_system.sql
mysql -u root -p tamasbau < database/migrations/20260925_support_chat_lifecycle_updates.sql
mysql -u root -p tamasbau < database/migrations/20260925_account_checkout_foundation.sql
```

A `20260925_support_chat_lifecycle_updates.sql` migráció a korábbi support chat telepítést bővíti `closed` státusszal, külön admin/customer olvasatlan számlálókkal, archiválással (`deleted_at`) és utolsó admin/customer aktivitás időbélyegekkel.

A `20260925_account_checkout_foundation.sql` migráció létrehozza a professzionális ügyfélfiók alap tábláit:

- `user_addresses`
- `user_notification_preferences`
- `wishlists`
- `gdpr_requests`
- `admin_activity_logs`

## 4) Helyi futtatás

A repository gyökeréből indítsd:

```bash
php -S localhost:8000
```

Ezután nyisd meg: `http://localhost:8000`

## 5) Admin és auth funkciók

### Demo admin belépés

- E-mail: `admin@tamasbau.hu`

Az első bejelentkezés előtt állíts be saját admin jelszót (példa SQL):

```sql
UPDATE users
SET password_hash = '$2y$10$A_SAJAT_HASHED_JELSZAVAD',
    is_active = 1
WHERE email = 'admin@tamasbau.hu';
```

Hash készítése:

```bash
php -r "echo password_hash('SajatErősJelszo123!', PASSWORD_DEFAULT), PHP_EOL;"
```

### Elfelejtett jelszó flow

- API végpont: `api/password-reset.php`
- `action=request` + `email` → token generálás
- `action=validate` + `token` → token ellenőrzés
- `action=reset` + `token` + `password` → új jelszó beállítás

Fejlesztői módban (`APP_ENV=development`) a reset token:

- API válaszban is visszajelenik (`dev.token`, `dev.reset_link`)
- valamint `logs/password-reset.log` fájlba kerül

## 6) Ajánlatkérés-válasz rendszer

### Publikus ajánlatkérés

A kapcsolatfelvételi űrlap a `api/quotes.php` végpontra küld:

- név
- e-mail cím
- telefonszám
- munkatípus
- üzenet

Minden adatbázis művelet prepared statementtel történik.

### Admin oldali kezelés

Az admin **Ajánlatkérések** tabon elérhető:

- státusz szűrő (`Összes`, `Új`, `Folyamatban`, `Megválaszolva`, `Lezárva`)
- keresés név / e-mail / telefonszám / üzenet alapján
- új ajánlatkérések badge + KPI számláló
- új sorok vizuális kiemelése
- részletek modal ügyféladatokkal és előzményekkel
- admin válasz küldése e-mailben
- státuszváltás és törlés megerősítéssel

### API műveletek

- `POST api/quotes.php` (`action=create`) – publikus ajánlatkérés létrehozása
- `GET api/quotes.php` – admin lista
- `GET api/quotes.php?id={ID}` – admin részletek + reply history
- `POST api/quotes.php` (`action=update_status`) – admin státuszváltás
- `POST api/quotes.php` (`action=reply`) – admin válasz + e-mail kiküldés + előzmény mentés
- `POST api/quotes.php` (`action=delete`) – admin törlés

### E-mail küldés viselkedése

- Ha PHPMailer telepítve van, a backend azt használja SMTP-hez.
- Ha PHPMailer nincs, a backend beépített SMTP socket fallbacket használ.
- Ha nincs SMTP konfiguráció és `APP_ENV=development`, az e-mail **nem vész el**, hanem a címzett és tartalom a `logs/mail-dev.log` fájlba kerül, valamint a fejlesztői API válaszban is megjelenik.
- Production környezetben e-mail tartalom nem szivárog vissza API válaszban.
- Ha az e-mail küldés hibás, az API nem ad hamis sikert, és a reply mentés rollbackelődik.

## 7) Support chat rendszer

### Publikus chat widget

- A jobb alsó sarokban lebegő support widget jelenik meg.
- Vendégként is használható, de bejelentkezett felhasználónál a widget automatikusan a profil `name` + `email` adatait használja.
- Kötelező mezők: e-mail cím és üzenet.
- A widget az e-mail cím alapján visszatölti a legutóbbi support beszélgetést és annak admin válaszait.
- A widget polling alapon frissít (20 mp), badge-et és toastot mutat, ha új admin válasz érkezik.
- Megnyitáskor a customer oldali `mark-read` hívás lenullázza a `customer_unread_count` mezőt.
- A kliensoldali chat renderelés minden support szöveget escape-elve ír a DOM-ba.

### Support státusz workflow

- Támogatott státuszok:
  - `new` – új ügyfélüzenet, még nem került aktív feldolgozásba
  - `open` – folyamatban lévő support egyeztetés
  - `resolved` – megoldottként jelölt beszélgetés
  - `closed` – lezárt beszélgetés
- Engedélyezett szerveroldali átmenetek:
  - `new` → `open|resolved|closed`
  - `open` → `resolved|closed`
  - `resolved` → `open|closed`
  - `closed` → `open`
- Ha az ügyfél visszaír egy `resolved` vagy `closed` beszélgetésbe, a chat automatikusan `open` státuszra vált.

### Admin support inbox

Az admin **Support Chat** tabon elérhető:

- beszélgetéslista ügyfél névvel, e-maillel, státusszal, utolsó aktivitással és admin oldali olvasatlan számlálóval
- modern modal nézet bal/jobb oldali üzenet buborékokkal
- admin válasz írása, státuszváltás (`new`, `open`, `resolved`, `closed`)
- gyorsgombok: **Megoldottnak jelölés**, **Lezárás**, **Újranyitás**, **Archiválás**
- szűrés aktív / archivált / összes beszélgetésre
- automatikus olvasatlannak-jelölt support üzenetkezelés admin és customer oldalra külön számlálóval
- admin polling badge és dashboard KPI az új customer válaszokra
- overview KPI-kártyák:
  - `Új Support Üzenetek`
  - `Nyitott Support Chatek`

### Olvasatlan számlálók

- `admin_unread_count`: customer üzenetnél nő, admin megnyitás/`mark_read` esetén nullázódik.
- `customer_unread_count`: admin válasznál nő, customer widget megnyitás/`mark_read` esetén nullázódik.
- `last_customer_message_at` és `last_admin_message_at` külön menti az utolsó kétoldali aktivitást.
- A régi `unread_count` mező továbbra is az admin oldali számlálóval együtt frissül a visszafelé kompatibilitás miatt.

### Archiválás / soft delete

- A support chat nem hard delete-tel, hanem `deleted_at` soft delete mezővel archiválódik.
- Az admin lista alapértelmezetten nem mutatja az archivált elemeket.
- Archiválni csak `resolved` vagy `closed` státuszú beszélgetést lehet.
- Archivált beszélgetés szükség esetén visszaállítható az admin listából.

### Opcionális e-mail értesítés

- Ha SMTP be van állítva, customer új üzenetnél admin értesítő e-mail megy az aktív admin fiókokra.
- Ha SMTP be van állítva, admin válasznál customer értesítő e-mail megy a chathez tartozó e-mail címre.
- Ha SMTP nincs beállítva, a support flow nem törik el: a rendszer kezelt figyelmeztetést naplóz, de a chat és az üzenet mentése sikeres marad.

### Support API-k

Mivel a projekt meglévő szerkezete file-alapú PHP endpointokat használ, a support rendszer ehhez igazodik:

- `POST api/support.php` – publikus új support üzenet / chat létrehozása vagy meglévő beszélgetéshez új ügyfélüzenet mentése
- `GET api/support.php?email=...&chat_id=...` – publikus beszélgetés lekérése
- `POST api/support.php` (`action=mark_read`) – customer oldali olvasatlan jelölés törlése
- `GET api/support-chats.php?scope=active|archived|all` – admin support chat lista + summary
- `GET api/support-chats.php?id={ID}` – admin support chat részletek + üzenetek
- `POST api/support-chats.php` (`action=reply`) – admin válasz küldése
- `POST api/support-chats.php` (`action=mark_read`) – admin oldali olvasatlan jelölés törlése
- `POST api/support-chats.php` (`action=update_status`) – státuszfrissítés átmenet-validációval
- `POST api/support-chats.php` (`action=archive|restore|delete`) – archiválás / visszaállítás soft delete alapon

Minden új SQL művelet prepared statementet használ.

## 8) Termékképek és kategóriák

- `products.image_url` mező támogatott az admin termék CRUD felületen
- hibás vagy hiányzó kép esetén ikon fallback látszik
- a kategóriák korlátlan mélységűek (`categories.parent_id` önhivatkozó FK)
- a `GET api/categories.php` flat listát és rekurzív `category_tree` választ is ad

## 9) Biztonsági megjegyzések

- Az admin műveletek szerveroldali jogosultság-ellenőrzéssel védettek (`require_admin()`).
- A session-alapú módosító műveletekhez CSRF token szükséges (`X-CSRF-Token`).
- Bejelentkezés, jelszó-reset, support és checkout műveleteknél alap rate-limit védelem aktív.
- Az e-mail címek validálása szerveroldalon történik.
- A frontend renderelés escape-eli az ügyfél- és adminszövegeket.
- SMTP jelszót vagy teljes éles konfigurációt ne naplózz, ne commitolj és ne jeleníts meg a kliensoldalon.
- A support admin műveletek kizárólag admin sessionnel érhetők el.
- Az SMTP diagnosztika és próba-e-mail műveletek kizárólag admin sessionnel és CSRF tokennel érhetők el.
- A webshop árak és a kosár műveletek csak aktív, bejelentkezett felhasználó számára érhetők el.
- Vendég vagy inaktív user esetén (az inaktív usert a `current_user()` nullként kezeli) a `GET api/products.php` válaszban a `price` mező `null`, és `price_visible: false` érték érkezik.
- Bejelentkezett aktív user esetén a `GET api/products.php` visszaadja a valós árat és `price_visible: true` értéket.
- A rendelési végpont (`api/orders.php`) hitelesített sessiont igényel; jogosulatlan kérésnél egységes `401` JSON válasz érkezik.
- Checkoutnál a backend minden tétel árát adatbázisból tölti, és a végösszeget szerveroldalon számolja újra (a kliensár nem megbízható forrás).
- API security headerek aktívak: `X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy`, `Permissions-Policy`, CSP.

## 10) Checkout provider és ügyfélfiók API

- `api/checkout-payment.php` biztosítja a konfigurálható fizetési provider absztrakciót (`offline|barion|stripe`).
- `api/orders.php` a checkout adatokat validálja (szállítás/számlázás/fizetés), szerveroldali árból számol, és visszaigazoló e-mail eseményt indít.
- `api/account.php` (bejelentkezett user):
  - rendelési előzmények és mentett kalkulációk
  - support chat előzmények
  - kívánságlista kezelés
  - számlázási/szállítási adatok mentése
  - értesítési beállítások
  - e-mail és jelszó módosítás újrahitelesítéssel
  - GDPR adatigénylés/fióktörlés kérés rögzítése

## 11) PWA / SEO / jogi alapok

- `manifest.webmanifest`
- `service-worker.js` (admin/API válaszokat nem cache-eli)
- `offline.html`
- `robots.txt`, `sitemap.xml`
- `sitemap.xml` és `robots.txt` jelenleg `https://tamasbau.hu` URL-t használ; ettől eltérő domain esetén élesítéskor frissítsd.
- jogi oldalak magyar nyelvű szerkeszthető mintái:
  - `adatkezelesi-tajekoztato.html`
  - `cookie-tajekoztato.html`
  - `aszf.html`
  - `elallasi-tajekoztato.html`
  - `impresszum.html`

## 12) cPanel workflow (terminál nélkül)

1. **Fájlok feltöltése cPanel File Managerben**
   - Töltsd fel a projektfájlokat a web gyökérkönyvtárba.
   - Ellenőrizd, hogy az `api/` mappa és az `index.html` elérhető.
2. **SMTP konfiguráció**
   - Környezeti változókkal: cPanelben állítsd be a `MAIL_*` kulcsokat.
   - Vagy másold az `api/config.example.php` fájlt `api/config.local.php` néven, és töltsd ki a valós SMTP adatokat.
   - Prioritás: ha ugyanaz a kulcs környezeti változóban és `api/config.local.php`-ban is meg van adva, az **env érték élvez elsőbbséget**.
3. **PHPMailer manuális feltöltése (Composer/SSH nélkül)**
   - Töltsd le a hivatalos PHPMailer csomagot.
   - A `vendor` mappát elsődlegesen a projekt gyökerébe töltsd fel (ahol az `index.html` található). Ha a tárhelystruktúra miatt csak `api/vendor` használható, azt is támogatja a fallback loader.
   - Hozd létre a következő szerkezetet:
     - `vendor/PHPMailer/src/Exception.php`
     - `vendor/PHPMailer/src/PHPMailer.php`
     - `vendor/PHPMailer/src/SMTP.php`
   - `api/vendor` esetén ennek megfelelően:
     - `api/vendor/PHPMailer/src/Exception.php`
     - `api/vendor/PHPMailer/src/PHPMailer.php`
     - `api/vendor/PHPMailer/src/SMTP.php`
   - Elfogadott alternatíva: `vendor/PHPMailer/PHPMailer/src/...` vagy `vendor/phpmailer/phpmailer/src/...`.
4. **SMTP diagnosztika megnyitása**
   - Lépj be admin felhasználóval.
   - Nyisd meg az Admin panelen az **SMTP diagnosztika** tabot.
   - Kattints az **SMTP kapcsolat ellenőrzése** gombra.
5. **Próba-e-mail küldése**
   - Adj meg egy cél e-mail címet (nincs előre kitöltött külső cím).
   - A küldést megerősítő ablak után indul a küldés.
6. **Gyakori hibák**
   - Port blokkolás (25/465/587 tiltva tárhelyszolgáltatónál).
   - Hibás TLS/SSL mód (`MAIL_ENCRYPTION` eltérés).
   - Hibás SMTP felhasználónév vagy jelszó.
   - Rossz SMTP host vagy port.
   - SPF/DKIM hiány miatt kézbesítési problémák.
7. **Biztonsági figyelmeztetések**
   - Az `api/config.local.php` fájlt ne töltsd fel GitHubra.
   - SMTP jelszót soha ne commitolj.
   - Az SMTP teszt funkció csak autentikált admin számára legyen használható.

## 13) SMTP diagnosztika API

- `POST api/smtp.php` + `action=status` (CSRF védetten) – admin-only konfiguráció összefoglaló
- `POST api/smtp.php` + `action=check` (**JSON body-ban**) – SMTP kapcsolat és hitelesítés ellenőrzése (küldés nélkül)
- `POST api/smtp.php` + `action=send_test` (**JSON body-ban**) – megerősítéssel próba-e-mail küldése explicit címzettre

## 14) API végpontok

- `api/auth.php` – regisztráció, login, logout, aktuális user
- `api/password-reset.php` – elfelejtett jelszó token kérés / ellenőrzés / reset
- `api/users.php` – admin user CRUD + admin jelszócsere
- `api/categories.php` – korlátlan mélységű kategóriafa CRUD + flat/tree lista
- `api/products.php` – termék CRUD
- `api/orders.php` – hitelesített checkout + rendelés lista + státusz frissítés
- `api/account.php` – professzionális ügyfélfiók adatok és beállítások
- `api/quotes.php` – ajánlatkérés létrehozás + admin válaszkezelés
- `api/support.php` – publikus support chat létrehozás + ügyfél előzmények lekérése
- `api/support-chats.php` – admin support inbox + részletek + reply + mark-read + státuszfrissítés
- `api/smtp.php` – admin SMTP diagnosztika + próba-e-mail
- `api/estimates.php` – bejelentkezett user kalkulációi
- `api/admin-analytics.php` – KPI + top termék/kategória + alacsony készlet + CSV export
- `api/admin-activity.php` – admin aktivitási napló lekérés
- `api/product-upload.php` – biztonságos admin termékkép-feltöltés (`uploads/products/`)
