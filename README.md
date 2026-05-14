# Lennujaama lennufirmad

Vaike PHP/SQLite veebirakendus, mis kysib lennujaama ICAO koodi jargi AeroDataBox API-st lennujaama saabumised ja valjumised ning koondab sealt unikaalsed lennufirmad.

## Kaivitamine XAMPP-is

1. Pane projekt XAMPP-i veebikausta, naiteks `C:\xampp\htdocs\planes`.
2. Ava `config.php` ja kontrolli, et `aerodatabox_rapidapi_key` oleks olemas.
3. Veendu, et PHP laiendused `pdo_sqlite`, `sqlite3` ja `curl` on XAMPP-is lubatud.
4. Ava brauseris `http://localhost/planes/`.

Rakendus loob andmebaasi automaatselt faili `data/planes.sqlite`.

## API

Rakendus kasutab AeroDataBox RapidAPI endpointi:

- `GET /flights/airports/icao/{ICAO}`
- `offsetMinutes=0`
- `durationMinutes=720`
- `direction=Both`

Tulemused salvestatakse SQLite vahemallu, et RapidAPI limiiti mitte iga otsinguga kulutada.
