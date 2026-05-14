# Lennujaama ja lennufirma lennud

PHP/SQLite veebirakendus, mis kasutab AeroDataBox RapidAPI-t.

## Lehed

- `index.html` - sisesta lennujaama ICAO kood ja saad lennufirmad. Iga lennufirma all kuvatakse sama ajavahemiku flight numbrid ja marsruudid.
- `airline.html` - sisesta lennufirma ICAO kood ja saad flight numbrid koos marsruutidega.

## Kaivitamine XAMPP-is

1. Projekt peab olema kaustas `C:\xampp\htdocs\planes`.
2. Ava `config.php` ja kontrolli, et `aerodatabox_rapidapi_key` oleks olemas.
3. Veendu, et PHP laiendused `pdo_sqlite`, `sqlite3` ja `curl` on XAMPP-is lubatud.
4. Ava brauseris `http://localhost/planes/`.

SQLite andmebaas luuakse automaatselt faili `data/planes.sqlite`.

## Kasutatud AeroDataBox endpointid

- `GET /flights/airports/icao/{ICAO}` - lennujaama saabumised ja valjumised.
- `GET /flights/search/term` - flight number otsing lennufirma ICAO prefiksi jargi.
- `GET /flights/Number/{flightNumber}` - flight number marsruudi detailid.

Tulemused salvestatakse SQLite vahemallu, et RapidAPI limiiti mitte iga otsinguga kulutada.
