<?php

return [
    // RapidAPI / AeroDataBox key. Keep this server-side and never put it in app.js.
    'aerodatabox_rapidapi_key' => 'fb22a6c304mshf247da1c6619a17p13ee84jsne1a95f220b77',
    'aerodatabox_host' => 'aerodatabox.p.rapidapi.com',
    'aerodatabox_base_url' => 'https://aerodatabox.p.rapidapi.com',

    // Cache saves RapidAPI quota. FIDS endpoint supports a maximum 12-hour window.
    'cache_ttl_seconds' => 86400,
    'flight_offset_minutes' => 0,
    'flight_duration_minutes' => 720,
    'airline_search_limit' => 20,
    'airline_detail_pause_microseconds' => 450000,
    'airport_scan_limit' => 8,
    'airport_scan_pause_microseconds' => 700000,

    'database_path' => __DIR__ . '/data/planes.sqlite',
];
