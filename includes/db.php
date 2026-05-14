<?php

function app_config(): array
{
    static $config = null;

    if ($config === null) {
        $config = require __DIR__ . '/../config.php';
    }

    return $config;
}

function db(): PDO
{
    static $pdo = null;

    if ($pdo !== null) {
        return $pdo;
    }

    $config = app_config();
    $databasePath = $config['database_path'];
    $databaseDir = dirname($databasePath);

    if (!is_dir($databaseDir)) {
        mkdir($databaseDir, 0775, true);
    }

    $pdo = new PDO('sqlite:' . $databasePath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA temp_store = MEMORY');
    $pdo->exec('PRAGMA journal_mode = MEMORY');
    $pdo->exec('PRAGMA foreign_keys = ON');

    migrate($pdo);

    return $pdo;
}

function migrate(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS airports (
            icao TEXT PRIMARY KEY,
            name TEXT,
            last_fetched_at INTEGER
        );

        CREATE TABLE IF NOT EXISTS airlines (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            unique_key TEXT NOT NULL UNIQUE,
            name TEXT NOT NULL,
            iata TEXT,
            icao TEXT,
            callsign TEXT
        );

        CREATE TABLE IF NOT EXISTS airport_airlines (
            airport_icao TEXT NOT NULL,
            airline_id INTEGER NOT NULL,
            PRIMARY KEY (airport_icao, airline_id),
            FOREIGN KEY (airport_icao) REFERENCES airports(icao) ON DELETE CASCADE,
            FOREIGN KEY (airline_id) REFERENCES airlines(id) ON DELETE CASCADE
        );

        CREATE TABLE IF NOT EXISTS airport_flights (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            airport_icao TEXT NOT NULL,
            airline_id INTEGER NOT NULL,
            number TEXT NOT NULL,
            call_sign TEXT,
            status TEXT,
            departure_name TEXT,
            departure_iata TEXT,
            departure_icao TEXT,
            departure_time_local TEXT,
            arrival_name TEXT,
            arrival_iata TEXT,
            arrival_icao TEXT,
            arrival_time_local TEXT,
            FOREIGN KEY (airport_icao) REFERENCES airports(icao) ON DELETE CASCADE,
            FOREIGN KEY (airline_id) REFERENCES airlines(id) ON DELETE CASCADE
        );

        CREATE TABLE IF NOT EXISTS airline_searches (
            airline_icao TEXT PRIMARY KEY,
            last_fetched_at INTEGER NOT NULL
        );

        CREATE TABLE IF NOT EXISTS airline_flights (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            airline_icao TEXT NOT NULL,
            number TEXT NOT NULL,
            call_sign TEXT,
            status TEXT,
            departure_name TEXT,
            departure_iata TEXT,
            departure_icao TEXT,
            departure_time_local TEXT,
            arrival_name TEXT,
            arrival_iata TEXT,
            arrival_icao TEXT,
            arrival_time_local TEXT,
            FOREIGN KEY (airline_icao) REFERENCES airline_searches(airline_icao) ON DELETE CASCADE
        );

        CREATE TABLE IF NOT EXISTS favorite_airports (
            icao TEXT PRIMARY KEY,
            name TEXT,
            created_at INTEGER NOT NULL
        );

        CREATE TABLE IF NOT EXISTS favorite_airlines (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            unique_key TEXT NOT NULL UNIQUE,
            name TEXT NOT NULL,
            iata TEXT,
            icao TEXT,
            callsign TEXT,
            created_at INTEGER NOT NULL,
            UNIQUE(name, iata, icao)
        );
    ");

    ensure_column($pdo, 'airlines', 'unique_key', 'TEXT');
    ensure_column($pdo, 'favorite_airlines', 'unique_key', 'TEXT');
    backfill_unique_keys($pdo);
}

function ensure_column(PDO $pdo, string $table, string $column, string $definition): void
{
    $columns = $pdo->query("PRAGMA table_info($table)")->fetchAll();
    foreach ($columns as $existing) {
        if ($existing['name'] === $column) {
            return;
        }
    }

    $pdo->exec("ALTER TABLE $table ADD COLUMN $column $definition");
}

function backfill_unique_keys(PDO $pdo): void
{
    foreach (['airlines', 'favorite_airlines'] as $table) {
        $rows = $pdo->query("SELECT id, name, iata, icao FROM $table WHERE unique_key IS NULL OR unique_key = ''")->fetchAll();
        $stmt = $pdo->prepare("UPDATE $table SET unique_key = ? WHERE id = ?");

        foreach ($rows as $row) {
            $stmt->execute([airline_unique_key($row), $row['id']]);
        }
    }
}

function airline_unique_key(array $airline): string
{
    return strtolower(trim((string) $airline['name'])) . '|'
        . strtolower(trim((string) ($airline['iata'] ?? ''))) . '|'
        . strtolower(trim((string) ($airline['icao'] ?? '')));
}
