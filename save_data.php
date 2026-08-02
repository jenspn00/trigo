<?php
// =================================================================
//  save_data.php  (v3)
//
//  Rettelser ift. v2:
//    1. ob_start() ØVERST → "headers already sent"-warnings væk.
//    2. Accepterer både {heading, elevation_angle} (index.html)
//       og {azimuth, elevation} (gun.html) felt-navne.
//    3. session_id understøttes (NULL hvis ikke sendt).
//    4. Reverse-geocoding kører som baggrundsjob efter klienten
//       har fået svar — vha. fastcgi_finish_request() eller en
//       fungerende Connection: close-fallback.
//    5. Validerer numerisk range (lat/lon/azimuth/elev).
// =================================================================

ini_set('display_errors', 0);
error_reporting(E_ALL);

// VIGTIGT: start output buffering MED det samme, så vi kan sætte
// Connection: close-headere før vi flusher.
ob_start();

require_once 'db_config.php';

$response = [];
$bg_ok    = false;
$bg_lat   = null;
$bg_lon   = null;
$bg_id    = null;
$bg_azi   = null;
$bg_elev  = null;

// ----------------------------------------------------------
//  Hjælpere
// ----------------------------------------------------------
function getAddressFromCoords(float $lat, float $lon): string {
    $url = "https://nominatim.openstreetmap.org/reverse?format=json"
         . "&lat={$lat}&lon={$lon}&zoom=18&addressdetails=1&accept-language=da";
    $ctx = stream_context_create([
        "http" => [
            "method"  => "GET",
            "header"  => "User-Agent: TrigoCitizenScienceProject/1.0 (industridata.dk)\r\n",
            "timeout" => 4,
        ]
    ]);
    $json = @file_get_contents($url, false, $ctx);
    if ($json) {
        $data = json_decode($json, true);
        if (isset($data['display_name'])) return $data['display_name'];
    }
    return "Adresse kunne ikke findes";
}

// Læs første eksisterende felt ud af et array — tillader at to
// klient-versioner sender forskellige navne.
function pickField(array $data, array $names, $default = null) {
    foreach ($names as $n) {
        if (array_key_exists($n, $data) && $data[$n] !== null && $data[$n] !== '') {
            return $data[$n];
        }
    }
    return $default;
}

// Sanitér session_id (deles af enkelt- og batch-flow)
function cleanSessionId($session_id) {
    if ($session_id === null) return null;
    $session_id = preg_replace('/[^a-zA-Z0-9\-_]/', '', (string)$session_id);
    if (strlen($session_id) === 0) return null;
    return substr($session_id, 0, 40);
}

// Validér og normalisér én observation → array klar til insert.
// Kaster Exception ved ugyldige data.
function normalizeRecord(array $data, ?string $fallbackSession, int $seq): array {
    $pos = $data['position'] ?? $data;
    $lat = pickField($pos, ['latitude',  'lat']);
    $lon = pickField($pos, ['longitude', 'lon', 'lng']);
    $alt = pickField($pos, ['altitude',  'alt'], null);

    $azimuth   = pickField($data, ['heading', 'azimuth', 'bearing']);
    $elevation = pickField($data, ['elevation_angle', 'elevation', 'pitch'], 0);

    if ($lat === null || $lon === null || $azimuth === null) {
        throw new Exception('Manglende felter (latitude, longitude eller heading/azimuth)');
    }

    $lat       = (float)$lat;
    $lon       = (float)$lon;
    $alt       = $alt === null ? 0.0 : (float)$alt;
    $azimuth   = (float)$azimuth;
    $elevation = (float)$elevation;

    if ($lat < -90  || $lat > 90)        throw new Exception("Ugyldig latitude: $lat");
    if ($lon < -180 || $lon > 180)       throw new Exception("Ugyldig longitude: $lon");
    if ($azimuth < 0 || $azimuth >= 360) throw new Exception("Ugyldig azimuth: $azimuth");
    if ($elevation < -90 || $elevation > 90) throw new Exception("Ugyldig elevation: $elevation");

    $id = (string) pickField($data, ['id'], (string)(round(microtime(true) * 10000) + $seq));
    if (isset($data['timestamp'])) {
        $ts = strtotime($data['timestamp']);
        $timestamp = $ts ? date('Y-m-d H:i:s', $ts) : date('Y-m-d H:i:s');
    } else {
        $timestamp = date('Y-m-d H:i:s');
    }

    $session_id = cleanSessionId($data['session_id'] ?? $fallbackSession);

    return [
        'id' => $id, 'session_id' => $session_id, 'timestamp' => $timestamp,
        'lat' => $lat, 'lon' => $lon, 'alt' => $alt,
        'azimuth' => $azimuth, 'elevation' => $elevation,
    ];
}

// ----------------------------------------------------------
//  Hovedlogik (v4: understøtter batch)
//
//  Payload kan være:
//    a) én observation:      { latitude, longitude, heading, ... }
//    b) batch (track-mode):  { session_id, observations: [ {...}, ... ] }
// ----------------------------------------------------------
$saved_ids = [];

try {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
        throw new Exception('Ugyldig JSON');
    }

    // Batch eller enkelt?
    if (isset($data['observations']) && is_array($data['observations'])) {
        $fallbackSession = $data['session_id'] ?? null;
        $rawRecords = array_slice($data['observations'], 0, 50); // hård grænse
        if (count($rawRecords) === 0) throw new Exception('Tom observations-liste');
    } else {
        $fallbackSession = null;
        $rawRecords = [$data];
    }

    $records = [];
    foreach ($rawRecords as $i => $r) {
        if (!is_array($r)) continue;
        $records[] = normalizeRecord($r, $fallbackSession, $i);
    }
    if (count($records) === 0) throw new Exception('Ingen gyldige observationer');

    // Insert (én prepared statement, genbrugt)
    $sql = "INSERT INTO observations
              (id, session_id, observed_at, latitude, longitude, altitude, azimuth, elevation)
              VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
    $stmt = $mysqli->prepare($sql);
    if (!$stmt) throw new Exception('SQL prepare-fejl: ' . $mysqli->error);

    foreach ($records as $rec) {
        $stmt->bind_param("sssddddd",
            $rec['id'], $rec['session_id'], $rec['timestamp'],
            $rec['lat'], $rec['lon'], $rec['alt'],
            $rec['azimuth'], $rec['elevation']);
        if (!$stmt->execute()) throw new Exception('SQL execute-fejl: ' . $stmt->error);
        $saved_ids[] = $rec['id'];
    }
    $stmt->close();

    $first = $records[0];
    $response = [
        'status'  => 'success',
        'message' => 'Data gemt',
        'saved'   => count($saved_ids),
        'id'      => $first['id'],
        'ids'     => $saved_ids,
    ];
    $bg_ok    = true;
    $bg_lat   = $first['lat'];
    $bg_lon   = $first['lon'];
    $bg_id    = $first['id'];
    $bg_azi   = $first['azimuth'];
    $bg_elev  = $first['elevation'];

} catch (Exception $e) {
    $response = ['status' => 'error', 'message' => $e->getMessage()];
    error_log('save_data.php: ' . $e->getMessage());
}

// ----------------------------------------------------------
//  Send svar til klienten og luk forbindelsen.
//  Adresseopslag (langsomt) køres BAGEFTER.
// ----------------------------------------------------------
header('Content-Type: application/json; charset=utf-8');
echo json_encode($response);

if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
} else {
    // mod_php-fallback: sæt headere FØR vi flusher
    $size = ob_get_length();
    header('Content-Length: ' . $size);
    header('Connection: close');
    ob_end_flush();
    @ob_flush();
    flush();
    // Nogle SAPI'er kører fpm under mod_proxy_fcgi — ingen garanti,
    // men adresseopslaget koster højst ~4 sek alligevel.
}

// ----------------------------------------------------------
//  Baggrundsjob: reverse-geocoding + tekstlog (failsafe)
// ----------------------------------------------------------
if ($bg_ok) {
    $address    = getAddressFromCoords($bg_lat, $bg_lon);
    $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $log_time   = date('Y-m-d H:i:s');

    $logEntry = sprintf(
        "[%s] IP: %s | ID: %s | Pos: %s, %s | Azi: %s | Ele: %s | Adresse: %s\n",
        $log_time, $ip_address, $bg_id,
        number_format($bg_lat, 5),
        number_format($bg_lon, 5),
        round($bg_azi),
        round($bg_elev),
        $address
    );
    file_put_contents('observation_log.txt', $logEntry, FILE_APPEND | LOCK_EX);

    // Gem også adressen i databasen — så fetch_log.php kan returnere
    // den uden et nyt opslag. Ved batch (track-mode) er alle
    // observationer fra samme sted → samme adresse på alle rækker,
    // med ét enkelt Nominatim-opslag.
    if (isset($mysqli) && $mysqli && !$mysqli->connect_error && count($saved_ids) > 0) {
        $upd = $mysqli->prepare("UPDATE observations SET address = ? WHERE id = ?");
        if ($upd) {
            foreach ($saved_ids as $sid) {
                $upd->bind_param("ss", $address, $sid);
                @$upd->execute();
            }
            $upd->close();
        }
    }
}

if (isset($mysqli) && $mysqli && !$mysqli->connect_error) {
    $mysqli->close();
}
