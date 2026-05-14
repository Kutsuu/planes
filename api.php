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

    if ($method === 'GET' && $action === 'airline-flights') {
        respond(get_airline_flights_by_code());
    }

    if ($method === 'GET' && $action === 'planner-route') {
        respond(get_planner_route());
    }

    if ($method === 'GET' && $action === 'planner-airport-airline') {
        respond(get_planner_airport_airline());
    }

    if ($method === 'GET' && $action === 'favorites') {
        respond(get_favorites());
    }

    if ($method === 'GET' && $action === 'balance') {
        respond(get_api_balance());
    }

    if ($method === 'POST' && $action === 'scan-airports') {
        respond(scan_airports());
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

function normalize_airline_icao(?string $value): string
{
    $icao = strtoupper(trim((string) $value));

    if (!preg_match('/^[A-Z0-9]{3}$/', $icao)) {
        throw new InvalidArgumentException('Sisesta korrektne 3-kohaline lennufirma ICAO kood, naiteks SAS, BTI voi KLM.');
    }

    return $icao;
}

function get_airlines_by_airport(): array
{
    $icao = normalize_icao($_GET['icao'] ?? '');
    $forceRefresh = ($_GET['refresh'] ?? '') === '1';
    $loaded = load_airport_schedule($icao, $forceRefresh);

    return $loaded['data'] + ['source' => $loaded['source']];
}

function get_planner_route(): array
{
    $from = normalize_icao($_GET['from'] ?? '');
    $to = normalize_icao($_GET['to'] ?? '');
    $forceRefresh = ($_GET['refresh'] ?? '') === '1';
    $loaded = load_airport_schedule($from, $forceRefresh);
    $flights = query_planner_flights([
        'airport_icao' => $from,
        'from' => $from,
        'to' => $to,
    ]);

    return [
        'query' => [
            'mode' => 'route',
            'from' => $from,
            'to' => $to,
        ],
        'title' => $from . ' -> ' . $to,
        'flights' => $flights,
        'airlines' => group_planner_flights_by_airline($flights),
        'updated_at' => $loaded['data']['updated_at'] ?? time(),
        'source' => $loaded['source'],
        'api_requests' => $loaded['api_requests'],
    ];
}

function get_planner_airport_airline(): array
{
    $airport = normalize_icao($_GET['airport'] ?? '');
    $airline = normalize_airline_icao($_GET['airline'] ?? '');
    $direction = normalize_direction($_GET['direction'] ?? 'both');
    $forceRefresh = ($_GET['refresh'] ?? '') === '1';
    $loaded = load_airport_schedule($airport, $forceRefresh);
    $flights = query_planner_flights([
        'airport_icao' => $airport,
        'airline_icao' => $airline,
        'direction' => $direction,
    ]);
    $airlineLabel = airline_label_from_cache($airline);

    return [
        'query' => [
            'mode' => 'airport-airline',
            'airport' => $airport,
            'airline' => $airline,
            'direction' => $direction,
        ],
        'title' => $airport . ' + ' . ($airlineLabel['name'] ?? $airline),
        'airline' => $airlineLabel,
        'flights' => $flights,
        'airlines' => group_planner_flights_by_airline($flights),
        'updated_at' => $loaded['data']['updated_at'] ?? time(),
        'source' => $loaded['source'],
        'api_requests' => $loaded['api_requests'],
    ];
}

function load_airport_schedule(string $icao, bool $forceRefresh = false): array
{
    $cached = $forceRefresh ? null : read_cached_airlines($icao);

    if ($cached !== null) {
        return [
            'data' => $cached,
            'source' => 'sqlite-cache',
            'api_requests' => 0,
        ];
    }

    $apiResult = fetch_airlines_from_api($icao);
    save_airport_airlines($icao, $apiResult['airport_name'], $apiResult['airlines']);
    $fresh = read_cached_airlines($icao);

    return [
        'data' => $fresh ?? [
            'airport' => ['icao' => $icao, 'name' => $apiResult['airport_name']],
            'airlines' => $apiResult['airlines'],
            'updated_at' => time(),
        ],
        'source' => 'aerodatabox-api',
        'api_requests' => 1,
    ];
}

function normalize_direction(string $value): string
{
    $direction = strtolower(trim($value));
    if (!in_array($direction, ['departures', 'arrivals', 'both'], true)) {
        throw new InvalidArgumentException('Vali suund: departures, arrivals voi both.');
    }

    return $direction;
}

function query_planner_flights(array $filters): array
{
    $where = [];
    $params = [];

    if (!empty($filters['airport_icao'])) {
        $where[] = 'af.airport_icao = ?';
        $params[] = $filters['airport_icao'];
    }

    if (!empty($filters['from']) && !empty($filters['to'])) {
        $where[] = 'af.departure_icao = ?';
        $params[] = $filters['from'];
        $where[] = 'af.arrival_icao = ?';
        $params[] = $filters['to'];
    }

    if (!empty($filters['airline_icao'])) {
        $where[] = 'a.icao = ?';
        $params[] = $filters['airline_icao'];
    }

    if (!empty($filters['direction']) && !empty($filters['airport_icao'])) {
        if ($filters['direction'] === 'departures') {
            $where[] = 'af.departure_icao = ?';
            $params[] = $filters['airport_icao'];
        } elseif ($filters['direction'] === 'arrivals') {
            $where[] = 'af.arrival_icao = ?';
            $params[] = $filters['airport_icao'];
        }
    }

    $sql = "
        SELECT
            af.*,
            a.name AS airline_name,
            a.iata AS airline_iata,
            a.icao AS airline_icao,
            a.callsign AS airline_callsign
        FROM airport_flights af
        INNER JOIN airlines a ON a.id = af.airline_id
    ";

    if ($where) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }

    $sql .= ' ORDER BY a.name COLLATE NOCASE, af.number COLLATE NOCASE, af.departure_icao COLLATE NOCASE, af.arrival_icao COLLATE NOCASE';

    return array_map('planner_flight_from_row', query_all($sql, $params));
}

function planner_flight_from_row(array $row): array
{
    $flight = flight_from_row($row);
    $flight['airline'] = [
        'name' => $row['airline_name'] ?? null,
        'iata' => $row['airline_iata'] ?? null,
        'icao' => $row['airline_icao'] ?? null,
        'callsign' => $row['airline_callsign'] ?? null,
    ];

    return $flight;
}

function group_planner_flights_by_airline(array $flights): array
{
    $groups = [];

    foreach ($flights as $flight) {
        $airline = $flight['airline'] ?? ['name' => 'Unknown', 'icao' => null, 'iata' => null];
        $key = strtolower(($airline['icao'] ?? '') . '|' . ($airline['iata'] ?? '') . '|' . ($airline['name'] ?? ''));

        if (!isset($groups[$key])) {
            $groups[$key] = $airline + ['flights' => []];
        }

        $groups[$key]['flights'][] = $flight;
    }

    return array_values($groups);
}

function scan_airports(): array
{
    $config = app_config();
    $input = request_json();
    $airports = parse_airport_scan_codes($input['airports'] ?? '');
    $refresh = !empty($input['refresh']);
    $limit = max(1, (int) $config['airport_scan_limit']);

    if (!$airports) {
        throw new InvalidArgumentException('Sisesta vahemalt uks lennujaama ICAO kood.');
    }

    if (count($airports) > $limit) {
        throw new InvalidArgumentException('Korraga saab skaneerida kuni ' . $limit . ' lennujaama.');
    }

    $results = [];
    $totalAirlines = 0;
    $totalFlights = 0;
    $apiRequests = 0;

    foreach ($airports as $icao) {
        try {
            $data = (!$refresh) ? read_cached_airlines($icao) : null;
            $source = 'sqlite-cache';

            if ($data === null) {
                $apiResult = fetch_airlines_from_api($icao);
                save_airport_airlines($icao, $apiResult['airport_name'], $apiResult['airlines']);
                $data = read_cached_airlines($icao) ?? [
                    'airport' => ['icao' => $icao, 'name' => $apiResult['airport_name']],
                    'airlines' => $apiResult['airlines'],
                    'updated_at' => time(),
                ];
                $source = 'aerodatabox-api';
                $apiRequests++;
            }

            $airlineCount = count($data['airlines']);
            $flightCount = count_flights_in_airlines($data['airlines']);
            $totalAirlines += $airlineCount;
            $totalFlights += $flightCount;

            $results[] = [
                'icao' => $icao,
                'source' => $source,
                'airlines' => $airlineCount,
                'flights' => $flightCount,
            ];

            if ($source === 'aerodatabox-api') {
                $pause = max(0, (int) $config['airport_scan_pause_microseconds']);
                if ($pause > 0) {
                    usleep($pause);
                }
            }
        } catch (Throwable $error) {
            $results[] = [
                'icao' => $icao,
                'source' => 'error',
                'airlines' => 0,
                'flights' => 0,
                'error' => $error->getMessage(),
            ];
        }
    }

    return [
        'airports' => $results,
        'totals' => [
            'airports' => count($airports),
            'airlines' => $totalAirlines,
            'flights' => $totalFlights,
            'api_requests' => $apiRequests,
        ],
    ];
}

function parse_airport_scan_codes(mixed $raw): array
{
    if (is_array($raw)) {
        $parts = $raw;
    } else {
        $parts = preg_split('/[\s,;]+/', strtoupper((string) $raw), -1, PREG_SPLIT_NO_EMPTY);
    }

    $airports = [];
    foreach ($parts as $part) {
        $icao = normalize_icao((string) $part);
        $airports[$icao] = $icao;
    }

    return array_values($airports);
}

function count_flights_in_airlines(array $airlines): int
{
    $count = 0;
    foreach ($airlines as $airline) {
        $count += count($airline['flights'] ?? []);
    }

    return $count;
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
    $airlines = $stmt->fetchAll();

    $flightMap = read_airport_flights_by_airline($icao);
    $flightCount = 0;
    foreach ($flightMap as $flights) {
        $flightCount += count($flights);
    }

    if (count($airlines) > 0 && $flightCount === 0) {
        return null;
    }

    foreach ($airlines as &$airline) {
        $airline['flights'] = $flightMap[(int) $airline['id']] ?? [];
    }
    unset($airline);

    return [
        'airport' => [
            'icao' => $airport['icao'],
            'name' => $airport['name'],
        ],
        'airlines' => $airlines,
        'updated_at' => (int) $airport['last_fetched_at'],
    ];
}

function read_airport_flights_by_airline(string $airportIcao): array
{
    $stmt = db()->prepare("
        SELECT *
        FROM airport_flights
        WHERE airport_icao = ?
        ORDER BY number COLLATE NOCASE, departure_icao COLLATE NOCASE, arrival_icao COLLATE NOCASE
    ");
    $stmt->execute([$airportIcao]);

    $map = [];
    foreach ($stmt->fetchAll() as $row) {
        $airlineId = (int) $row['airline_id'];
        $map[$airlineId][] = flight_from_row($row);
    }

    return $map;
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
        'withLeg' => 'true',
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
            $flight = normalize_flight($row, $icao, $direction);

            if ($airline === null || $flight === null) {
                continue;
            }

            $airlineKey = airline_unique_key($airline);
            if (!isset($airlines[$airlineKey])) {
                $airline['flights'] = [];
                $airlines[$airlineKey] = $airline;
            }

            $airlines[$airlineKey]['flights'][flight_route_key($flight)] = $flight;
        }
    }

    $airlines = array_values(array_map(function (array $airline): array {
        $airline['flights'] = array_values($airline['flights']);
        usort($airline['flights'], fn (array $a, array $b): int => strcasecmp($a['number'], $b['number']));
        return $airline;
    }, $airlines));
    usort($airlines, fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']));

    return [
        'airport_name' => null,
        'airlines' => $airlines,
    ];
}

function get_airline_flights_by_code(): array
{
    $icao = normalize_airline_icao($_GET['icao'] ?? '');
    $forceRefresh = ($_GET['refresh'] ?? '') === '1';
    $cached = read_cached_airline_flights($icao);

    if (!$forceRefresh && $cached !== null) {
        return $cached + ['source' => 'sqlite-cache'];
    }

    if (!$forceRefresh) {
        $fallback = read_airport_cached_flights_for_airline($icao);
        if ($fallback['flights']) {
            return $fallback + ['source' => 'sqlite-airport-cache'];
        }
    }

    try {
        $apiResult = fetch_airline_flights_from_api($icao);
    } catch (Throwable $error) {
        $fallback = read_airport_cached_flights_for_airline($icao);
        if ($fallback['flights']) {
            return $fallback + [
                'source' => 'sqlite-airport-cache',
                'warning' => $error->getMessage(),
            ];
        }

        throw $error;
    }

    if (!$apiResult['flights']) {
        $fallback = read_airport_cached_flights_for_airline($icao);
        if ($fallback['flights']) {
            return $fallback + ['source' => 'sqlite-airport-cache'];
        }
    }

    save_airline_flights($icao, $apiResult['flights']);
    $fresh = read_cached_airline_flights($icao);

    return ($fresh ?? [
        'airline' => airline_label_from_cache($icao),
        'flights' => $apiResult['flights'],
        'updated_at' => time(),
    ]) + ['source' => 'aerodatabox-api'];
}

function read_cached_airline_flights(string $icao): ?array
{
    $config = app_config();
    $search = fetch_one('SELECT airline_icao, last_fetched_at FROM airline_searches WHERE airline_icao = ?', [$icao]);

    if (!$search) {
        return null;
    }

    if ((time() - (int) $search['last_fetched_at']) > (int) $config['cache_ttl_seconds']) {
        return null;
    }

    $rows = query_all('SELECT * FROM airline_flights WHERE airline_icao = ? ORDER BY number COLLATE NOCASE, departure_icao COLLATE NOCASE, arrival_icao COLLATE NOCASE', [$icao]);

    return [
        'airline' => airline_label_from_cache($icao),
        'flights' => array_map('flight_from_row', $rows),
        'updated_at' => (int) $search['last_fetched_at'],
    ];
}

function fetch_airline_flights_from_api(string $icao): array
{
    $config = app_config();
    $terms = array_unique(array_filter([$icao, cached_iata_for_airline($icao)]));
    $numbers = [];

    foreach ($terms as $term) {
        $search = aerodatabox_get('/flights/search/term', [
            'q' => $term,
            'limit' => (int) $config['airline_search_limit'],
        ]);

        foreach (extract_flight_numbers($search) as $number) {
            $numbers[$number] = $number;
        }

        if ($numbers) {
            break;
        }
    }

    $flights = [];
    foreach ($numbers as $number) {
        try {
            $details = aerodatabox_get('/flights/Number/' . rawurlencode($number));
        } catch (Throwable $error) {
            $flights[flight_route_key(['number' => $number])] = [
                'number' => $number,
                'call_sign' => null,
                'status' => null,
                'departure' => empty_airport_ref(),
                'arrival' => empty_airport_ref(),
                'error' => $error->getMessage(),
            ];
            continue;
        }

        foreach ($details as $row) {
            if (!is_array($row)) {
                continue;
            }

            $flight = normalize_flight($row);
            if ($flight === null) {
                continue;
            }

            $flights[flight_route_key($flight)] = $flight;
        }

        $pause = max(0, (int) $config['airline_detail_pause_microseconds']);
        if ($pause > 0) {
            usleep($pause);
        }
    }

    $flights = array_values($flights);
    usort($flights, fn (array $a, array $b): int => strcasecmp($a['number'], $b['number']));

    return [
        'airline' => airline_label_from_cache($icao),
        'flights' => $flights,
    ];
}

function extract_flight_numbers(array $response): array
{
    $items = $response['items'] ?? $response['data'] ?? $response['results'] ?? $response;
    if (!is_array($items)) {
        return [];
    }

    $numbers = [];
    foreach ($items as $item) {
        $number = is_array($item) ? ($item['number'] ?? $item['value'] ?? '') : $item;
        $number = trim((string) $number);
        if ($number !== '') {
            $numbers[] = $number;
        }
    }

    return array_values(array_unique($numbers));
}

function save_airline_flights(string $icao, array $flights): void
{
    $pdo = db();
    $pdo->beginTransaction();
    $pdo->prepare('INSERT INTO airline_searches (airline_icao, last_fetched_at) VALUES (?, ?) ON CONFLICT(airline_icao) DO UPDATE SET last_fetched_at = excluded.last_fetched_at')->execute([$icao, time()]);
    $pdo->prepare('DELETE FROM airline_flights WHERE airline_icao = ?')->execute([$icao]);

    $stmt = $pdo->prepare('
        INSERT INTO airline_flights (
            airline_icao, number, call_sign, status,
            departure_name, departure_iata, departure_icao, departure_time_local,
            arrival_name, arrival_iata, arrival_icao, arrival_time_local
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ');

    foreach ($flights as $flight) {
        $stmt->execute(flight_insert_values($flight, $icao));
    }

    $pdo->commit();
}

function read_airport_cached_flights_for_airline(string $icao): array
{
    $stmt = db()->prepare("
        SELECT af.*
        FROM airport_flights af
        INNER JOIN airlines a ON a.id = af.airline_id
        WHERE a.icao = ?
        ORDER BY af.number COLLATE NOCASE, af.departure_icao COLLATE NOCASE, af.arrival_icao COLLATE NOCASE
    ");
    $stmt->execute([$icao]);
    $flights = array_map('flight_from_row', $stmt->fetchAll());

    return [
        'airline' => airline_label_from_cache($icao),
        'flights' => $flights,
        'updated_at' => time(),
    ];
}

function cached_iata_for_airline(string $icao): ?string
{
    $row = fetch_one('SELECT iata FROM airlines WHERE icao = ? AND iata IS NOT NULL AND iata != "" LIMIT 1', [$icao]);
    return $row['iata'] ?? null;
}

function airline_label_from_cache(string $icao): array
{
    $row = fetch_one('SELECT name, iata, icao, callsign FROM airlines WHERE icao = ? ORDER BY name COLLATE NOCASE LIMIT 1', [$icao]);

    return $row ?: [
        'name' => $icao,
        'iata' => null,
        'icao' => $icao,
        'callsign' => null,
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
        CURLOPT_TIMEOUT => 25,
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

    $headers = parse_response_headers(substr($raw, 0, $headerSize));
    $body = substr($raw, $headerSize);

    if ($status >= 400) {
        $json = json_decode($body, true);
        $message = is_array($json) ? ($json['message'] ?? $json['error'] ?? null) : null;
        if (is_array($message)) {
            $message = $message['message'] ?? json_encode($message, JSON_UNESCAPED_UNICODE);
        }
        throw new RuntimeException((string) ($message ?: 'API tagastas vea HTTP ' . $status));
    }

    return [
        'status' => $status,
        'headers' => $headers,
        'body' => $body,
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

function normalize_flight(array $row, ?string $homeAirportIcao = null, ?string $direction = null): ?array
{
    $number = trim((string) ($row['number'] ?? $row['callSign'] ?? ''));

    if ($number === '') {
        return null;
    }

    $flight = [
        'number' => $number,
        'call_sign' => empty($row['callSign']) ? null : (string) $row['callSign'],
        'status' => empty($row['status']) ? null : (string) $row['status'],
        'departure' => normalize_airport_ref($row['departure'] ?? []),
        'arrival' => normalize_airport_ref($row['arrival'] ?? []),
    ];

    if ($homeAirportIcao && $direction === 'departures' && !airport_ref_has_code($flight['departure'])) {
        $flight['departure']['icao'] = $homeAirportIcao;
    }

    if ($homeAirportIcao && $direction === 'arrivals' && !airport_ref_has_code($flight['arrival'])) {
        $flight['arrival']['icao'] = $homeAirportIcao;
    }

    return $flight;
}

function normalize_airport_ref(array $movement): array
{
    $airport = $movement['airport'] ?? [];
    if (!is_array($airport)) {
        $airport = [];
    }

    return [
        'name' => empty($airport['name']) ? null : (string) $airport['name'],
        'iata' => empty($airport['iata']) ? null : strtoupper((string) $airport['iata']),
        'icao' => empty($airport['icao']) ? null : strtoupper((string) $airport['icao']),
        'time_local' => $movement['scheduledTime']['local'] ?? $movement['revisedTime']['local'] ?? null,
    ];
}

function empty_airport_ref(): array
{
    return [
        'name' => null,
        'iata' => null,
        'icao' => null,
        'time_local' => null,
    ];
}

function airport_ref_has_code(array $airport): bool
{
    return !empty($airport['icao']) || !empty($airport['iata']) || !empty($airport['name']);
}

function flight_route_key(array $flight): string
{
    return strtolower(trim((string) ($flight['number'] ?? ''))) . '|'
        . strtolower(trim((string) ($flight['departure']['icao'] ?? $flight['departure_icao'] ?? ''))) . '|'
        . strtolower(trim((string) ($flight['arrival']['icao'] ?? $flight['arrival_icao'] ?? '')));
}

function flight_insert_values(array $flight, string|int $owner): array
{
    $departure = $flight['departure'] ?? empty_airport_ref();
    $arrival = $flight['arrival'] ?? empty_airport_ref();

    return [
        $owner,
        $flight['number'],
        $flight['call_sign'] ?? null,
        $flight['status'] ?? null,
        $departure['name'] ?? null,
        $departure['iata'] ?? null,
        $departure['icao'] ?? null,
        $departure['time_local'] ?? null,
        $arrival['name'] ?? null,
        $arrival['iata'] ?? null,
        $arrival['icao'] ?? null,
        $arrival['time_local'] ?? null,
    ];
}

function flight_from_row(array $row): array
{
    return [
        'number' => $row['number'],
        'call_sign' => $row['call_sign'] ?? null,
        'status' => $row['status'] ?? null,
        'departure' => [
            'name' => $row['departure_name'] ?? null,
            'iata' => $row['departure_iata'] ?? null,
            'icao' => $row['departure_icao'] ?? null,
            'time_local' => $row['departure_time_local'] ?? null,
        ],
        'arrival' => [
            'name' => $row['arrival_name'] ?? null,
            'iata' => $row['arrival_iata'] ?? null,
            'icao' => $row['arrival_icao'] ?? null,
            'time_local' => $row['arrival_time_local'] ?? null,
        ],
    ];
}

function save_airport_airlines(string $icao, ?string $airportName, array $airlines): void
{
    $pdo = db();
    $pdo->beginTransaction();

    $stmt = $pdo->prepare('INSERT INTO airports (icao, name, last_fetched_at) VALUES (?, ?, ?) ON CONFLICT(icao) DO UPDATE SET name = excluded.name, last_fetched_at = excluded.last_fetched_at');
    $stmt->execute([$icao, $airportName, time()]);

    $pdo->prepare('DELETE FROM airport_airlines WHERE airport_icao = ?')->execute([$icao]);
    $pdo->prepare('DELETE FROM airport_flights WHERE airport_icao = ?')->execute([$icao]);

    $flightStmt = $pdo->prepare('
        INSERT INTO airport_flights (
            airport_icao, airline_id, number, call_sign, status,
            departure_name, departure_iata, departure_icao, departure_time_local,
            arrival_name, arrival_iata, arrival_icao, arrival_time_local
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ');

    foreach ($airlines as $airline) {
        $airlineId = upsert_airline($airline);
        $link = $pdo->prepare('INSERT OR IGNORE INTO airport_airlines (airport_icao, airline_id) VALUES (?, ?)');
        $link->execute([$icao, $airlineId]);

        foreach ($airline['flights'] ?? [] as $flight) {
            $flightStmt->execute(array_merge([$icao], flight_insert_values($flight, $airlineId)));
        }
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
