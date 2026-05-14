<?php

declare(strict_types=1);

require __DIR__ . '/includes/db.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $method = $_SERVER['REQUEST_METHOD'];
    $action = $_GET['action'] ?? '';

    if ($method === 'GET' && $action === 'airlines') {
        respond(get_airlines_by_airport());
    }

    if ($method === 'GET' && $action === 'favorites') {
        respond(get_favorites());
    }

    if ($method === 'GET' && $action === 'balance') {
        respond(get_api_balance());
    }

    if ($method === 'POST' && $action === 'favorite-airport') {
        respond(save_favorite_airport());
    }

    if ($method === 'POST' && $action === 'favorite-airline') {
        respond(save_favorite_airline());
    }

    if ($method === 'DELETE' && $action === 'favorite-airport') {
        respond(delete_favorite_airport());
    }

    if ($method === 'DELETE' && $action === 'favorite-airline') {
        respond(delete_favorite_airline());
    }

    http_response_code(404);
    respond(['error' => 'Tundmatu paring.']);
} catch (Throwable $error) {
    http_response_code(500);
    respond(['error' => $error->getMessage()]);
}

function respond(array $payload): void
{
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function request_json(): array
{
    $raw = file_get_contents('php://input');
    $json = json_decode($raw ?: '{}', true);

    return is_array($json) ? $json : [];
}

function normalize_icao(?string $value): string
{
    $icao = strtoupper(trim((string) $value));

    if (!preg_match('/^[A-Z0-9]{4}$/', $icao)) {
        throw new InvalidArgumentException('Sisesta korrektne 4-kohaline ICAO kood, naiteks EETN.');
    }

    return $icao;
}

function get_airlines_by_airport(): array
{
    $icao = normalize_icao($_GET['icao'] ?? '');
    $forceRefresh = ($_GET['refresh'] ?? '') === '1';
    $cached = read_cached_airlines($icao);

    if (!$forceRefresh && $cached !== null) {
        return $cached + ['source' => 'sqlite-cache'];
    }

    $apiResult = fetch_airlines_from_api($icao);
    save_airport_airlines($icao, $apiResult['airport_name'], $apiResult['airlines']);
    $fresh = read_cached_airlines($icao);

    return ($fresh ?? [
        'airport' => ['icao' => $icao, 'name' => $apiResult['airport_name']],
        'airlines' => $apiResult['airlines'],
        'updated_at' => time(),
    ]) + ['source' => 'aerodatabox-api'];
}

function read_cached_airlines(string $icao): ?array
{
    $config = app_config();
    $pdo = db();
    $airport = fetch_one('SELECT icao, name, last_fetched_at FROM airports WHERE icao = ?', [$icao]);

    if (!$airport || !$airport['last_fetched_at']) {
        return null;
    }

    if ((time() - (int) $airport['last_fetched_at']) > (int) $config['cache_ttl_seconds']) {
        return null;
    }

    $stmt = $pdo->prepare("
        SELECT a.id, a.name, a.iata, a.icao, a.callsign
        FROM airlines a
        INNER JOIN airport_airlines aa ON aa.airline_id = a.id
        WHERE aa.airport_icao = ?
        ORDER BY a.name COLLATE NOCASE
    ");
    $stmt->execute([$icao]);

    return [
        'airport' => [
            'icao' => $airport['icao'],
            'name' => $airport['name'],
        ],
        'airlines' => $stmt->fetchAll(),
        'updated_at' => (int) $airport['last_fetched_at'],
    ];
}

function fetch_airlines_from_api(string $icao): array
{
    $config = app_config();
    $key = trim((string) $config['aerodatabox_rapidapi_key']);

    if ($key === '') {
        throw new RuntimeException('Lisa AeroDataBox RapidAPI voti faili config.php.');
    }

    $response = aerodatabox_get('/flights/airports/icao/' . rawurlencode($icao), [
        'offsetMinutes' => (int) $config['flight_offset_minutes'],
        'durationMinutes' => (int) $config['flight_duration_minutes'],
        'direction' => 'Both',
        'withLeg' => 'false',
        'withCancelled' => 'false',
        'withCodeshared' => 'false',
        'withCargo' => 'false',
        'withPrivate' => 'false',
        'withLocation' => 'false',
    ]);

    $airlines = [];

    foreach (['departures', 'arrivals'] as $direction) {
        $rows = $response[$direction] ?? [];

        if (!is_array($rows)) {
            continue;
        }

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $airline = normalize_airline($row);
            if ($airline === null) {
                continue;
            }

            $airlines[airline_unique_key($airline)] = $airline;
        }
    }

    $airlines = array_values($airlines);
    usort($airlines, fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']));

    return [
        'airport_name' => null,
        'airlines' => $airlines,
    ];
}

function get_api_balance(): array
{
    $response = aerodatabox_request('/subscriptions/balance');
    $headers = $response['headers'];

    return [
        'requests_limit' => $headers['x-ratelimit-requests-limit'] ?? null,
        'requests_remaining' => $headers['x-ratelimit-requests-remaining'] ?? null,
        'api_units_limit' => $headers['x-ratelimit-api-units-limit'] ?? null,
        'api_units_remaining' => $headers['x-ratelimit-api-units-remaining'] ?? null,
        'tier' => $headers['x-tier'] ?? null,
    ];
}

function aerodatabox_get(string $path, array $params = []): array
{
    $response = aerodatabox_request($path, $params);
    $status = $response['status'];
    $body = $response['body'];

    if ($status === 204 || trim($body) === '') {
        return [];
    }

    $json = json_decode($body, true);
    if (!is_array($json)) {
        throw new RuntimeException('API vastus ei olnud korrektne JSON.');
    }

    if ($status >= 400) {
        $message = $json['message'] ?? $json['error'] ?? ('API tagastas vea HTTP ' . $status);
        if (is_array($message)) {
            $message = $message['message'] ?? json_encode($message, JSON_UNESCAPED_UNICODE);
        }
        throw new RuntimeException((string) $message);
    }

    return $json;
}

function aerodatabox_request(string $path, array $params = []): array
{
    $config = app_config();
    $query = $params ? '?' . http_build_query($params) : '';
    $url = rtrim($config['aerodatabox_base_url'], '/') . $path . $query;

    $curl = curl_init($url);
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_USERAGENT => 'planes-icao-airlines/1.0',
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'Content-Type: application/json',
            'x-rapidapi-host: ' . $config['aerodatabox_host'],
            'x-rapidapi-key: ' . $config['aerodatabox_rapidapi_key'],
        ],
    ]);

    $raw = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $headerSize = (int) curl_getinfo($curl, CURLINFO_HEADER_SIZE);
    $curlError = curl_error($curl);
    curl_close($curl);

    if ($raw === false || $curlError) {
        throw new RuntimeException('API paring ebaonnestus: ' . $curlError);
    }

    if ($status >= 400) {
        $body = substr($raw, $headerSize);
        $json = json_decode($body, true);
        $message = $json['message'] ?? $json['error'] ?? ('API tagastas vea HTTP ' . $status);
        if (is_array($message)) {
            $message = $message['message'] ?? json_encode($message, JSON_UNESCAPED_UNICODE);
        }
        throw new RuntimeException((string) $message);
    }

    return [
        'status' => $status,
        'headers' => parse_response_headers(substr($raw, 0, $headerSize)),
        'body' => substr($raw, $headerSize),
    ];
}

function parse_response_headers(string $rawHeaders): array
{
    $headers = [];

    foreach (preg_split('/\r\n|\n|\r/', trim($rawHeaders)) as $line) {
        if (!str_contains($line, ':')) {
            continue;
        }

        [$name, $value] = explode(':', $line, 2);
        $headers[strtolower(trim($name))] = trim($value);
    }

    return $headers;
}

function normalize_airline(array $row): ?array
{
    $source = $row['airline'] ?? $row;

    if (!is_array($source)) {
        return null;
    }

    $name = trim((string) ($source['name'] ?? $source['airline_name'] ?? ''));
    $iata = trim((string) ($source['iata'] ?? $source['iata_code'] ?? ''));
    $icao = trim((string) ($source['icao'] ?? $source['icao_code'] ?? ''));
    $callsign = trim((string) ($source['callsign'] ?? ''));

    if (strtolower($name) === 'private flights') {
        return null;
    }

    if ($name === '' && $iata === '' && $icao === '') {
        return null;
    }

    return [
        'name' => $name !== '' ? $name : ($icao !== '' ? $icao : $iata),
        'iata' => $iata !== '' ? strtoupper($iata) : null,
        'icao' => $icao !== '' ? strtoupper($icao) : null,
        'callsign' => $callsign !== '' ? $callsign : null,
    ];
}

function save_airport_airlines(string $icao, ?string $airportName, array $airlines): void
{
    $pdo = db();
    $pdo->beginTransaction();

    $stmt = $pdo->prepare('INSERT INTO airports (icao, name, last_fetched_at) VALUES (?, ?, ?) ON CONFLICT(icao) DO UPDATE SET name = excluded.name, last_fetched_at = excluded.last_fetched_at');
    $stmt->execute([$icao, $airportName, time()]);

    $pdo->prepare('DELETE FROM airport_airlines WHERE airport_icao = ?')->execute([$icao]);

    foreach ($airlines as $airline) {
        $airlineId = upsert_airline($airline);
        $link = $pdo->prepare('INSERT OR IGNORE INTO airport_airlines (airport_icao, airline_id) VALUES (?, ?)');
        $link->execute([$icao, $airlineId]);
    }

    $pdo->commit();
}

function upsert_airline(array $airline): int
{
    $pdo = db();
    $uniqueKey = airline_unique_key($airline);
    $stmt = $pdo->prepare('INSERT OR IGNORE INTO airlines (unique_key, name, iata, icao, callsign) VALUES (?, ?, ?, ?, ?)');
    $stmt->execute([$uniqueKey, $airline['name'], $airline['iata'], $airline['icao'], $airline['callsign']]);

    $found = fetch_one('SELECT id FROM airlines WHERE unique_key = ?', [$uniqueKey]);

    return (int) $found['id'];
}

function save_favorite_airport(): array
{
    $input = request_json();
    $icao = normalize_icao($input['icao'] ?? '');
    $name = trim((string) ($input['name'] ?? ''));

    $stmt = db()->prepare('INSERT INTO favorite_airports (icao, name, created_at) VALUES (?, ?, ?) ON CONFLICT(icao) DO UPDATE SET name = excluded.name');
    $stmt->execute([$icao, $name !== '' ? $name : null, time()]);

    return get_favorites();
}

function save_favorite_airline(): array
{
    $input = request_json();
    $name = trim((string) ($input['name'] ?? ''));

    if ($name === '') {
        throw new InvalidArgumentException('Lennufirma nimi puudub.');
    }

    $airline = [
        'name' => $name,
        'iata' => empty($input['iata']) ? null : strtoupper(trim((string) $input['iata'])),
        'icao' => empty($input['icao']) ? null : strtoupper(trim((string) $input['icao'])),
        'callsign' => empty($input['callsign']) ? null : trim((string) $input['callsign']),
    ];

    $stmt = db()->prepare('INSERT OR IGNORE INTO favorite_airlines (unique_key, name, iata, icao, callsign, created_at) VALUES (?, ?, ?, ?, ?, ?)');
    $stmt->execute([
        airline_unique_key($airline),
        $airline['name'],
        $airline['iata'],
        $airline['icao'],
        $airline['callsign'],
        time(),
    ]);

    return get_favorites();
}

function delete_favorite_airport(): array
{
    $icao = normalize_icao($_GET['icao'] ?? '');
    db()->prepare('DELETE FROM favorite_airports WHERE icao = ?')->execute([$icao]);

    return get_favorites();
}

function delete_favorite_airline(): array
{
    $id = (int) ($_GET['id'] ?? 0);

    if ($id < 1) {
        throw new InvalidArgumentException('Lemmiku ID puudub.');
    }

    db()->prepare('DELETE FROM favorite_airlines WHERE id = ?')->execute([$id]);

    return get_favorites();
}

function get_favorites(): array
{
    return [
        'airports' => query_all('SELECT icao, name, created_at FROM favorite_airports ORDER BY created_at DESC'),
        'airlines' => query_all('SELECT id, name, iata, icao, callsign, created_at FROM favorite_airlines ORDER BY created_at DESC'),
    ];
}

function query_all(string $sql, array $params = []): array
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll();
}

function fetch_one(string $sql, array $params = []): ?array
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch();

    return $row === false ? null : $row;
}
