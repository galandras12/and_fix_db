# And DB fix (WordPress plugin) – v1.2

Fejlesztő: galandras12, Claude Code · https://github.com/galandras12/and_fix_db

Megmutatja, mi foglalja az adatbázist, és törli a felesleget.

## Telepítés
1. Az `and-db-fix.zip` feltöltése: WP admin → Bővítmények → Új hozzáadása → Bővítmény feltöltése.
2. Aktiválás után: **Eszközök → And DB fix**.

## Mit tud
- Teljes DB-méret és a táblák listája méret szerint, a táblák **forrásának** (melyik plugin) becslésével, plugin szerinti összesítéssel.
- A legnagyobb `wp_options` bejegyzések és az autoload méret; egy kattintással kikapcsolható az autoload.
- Törlés: revíziók, auto-draft, kuka, spam komment, lejárt/összes tranziens, oEmbed cache, árva postmeta/commentmeta/termmeta, Action Scheduler (lefutott feladatok + logok), WooCommerce lejárt munkamenetek.
- `OPTIMIZE TABLE` – ez adja vissza ténylegesen a helyet.
- Opcionális heti automatikus takarítás (csak a biztonságosnak jelölt elemek).

## Fontos
- Törlés előtt **készíts mentést**.
- Ha 400 MB-ot mutat, de a táblák összege kisebb, vagy olyan táblák vannak, amelyek már nem telepített plugin maradékai, azokat phpMyAdminban kell eldobni (a plugin csak listázza őket).
- Nem teszteltem élő WordPress/MySQL környezetben; először teszt- vagy mentett oldalon próbáld ki.
- Biztonsági mentés gomb: SQL (vagy .sql.gz) letöltés `oldalcím_dátum_idő.sql` néven, phpMyAdminban visszatölthető.
- Tábla törlése (DROP) gomb a maradék plugin-táblákhoz.

## 1.2
- Modern, AJAX-os felület (fülek, folyamatjelzők, oldalugrás nélkül).
- Táblázat oszlopra rendezhető (méret, sorok, név, forrás…) és csoportosítható (forrás / motor).
- Kapcsolók (csúszkák) a heti automatikus takarításhoz.
- Állítható adatbázis-limit (alap 100 MB, 0 = nincs limit); színes fejléc és admin sáv: zöld <70%, citromsárga 70–80%, narancs 80–95%, piros ≥95% figyelmeztetéssel.
- Email: havi jelentés (limit kihasználtság + forrásonkénti havi növekedés a napi mérésekből), 70% / 80% figyelmeztetés, 95% felett ismétlődő figyelmeztetés beállítható időközzel, teszt email.
