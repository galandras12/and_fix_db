# Adatbázis Optimalizáló (WordPress plugin)

Megmutatja, mi foglalja az adatbázist, és törli a felesleget.

## Telepítés
1. Az `adatbazis-optimalizalo.zip` feltöltése: WP admin → Bővítmények → Új hozzáadása → Bővítmény feltöltése.
2. Aktiválás után: **Eszközök → Adatbázis Optimalizáló**.

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
