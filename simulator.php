<?php
// =================================================================
//  simulator.php  (v4 — silent insert, dump at end)
//
//  v3 streamede output undervejs i loopet. På Nordicways
//  LiteSpeed/HTTP3-proxy dropper forbindelsen ofte midt i en
//  chunked response — derfor så vi kun den første observation.
//
//  v4 samler ALT output i en buffer og sender ét stort svar
//  efter alle inserts er gennemført. Ingen flush, ingen sleep.
// =================================================================

set_time_limit(60);
ini_set('display_errors', 0);
error_reporting(E_ALL);

require_once 'db_config.php';
require_once 'helpers.php';

// Simulatoren skriver direkte i produktions-tabellen. Er
// SIMULATOR_KEY defineret i db_config.php, kræves ?key=<nøgle>,
// så fremmede ikke kan fylde databasen med falske flyvninger.
if (defined('SIMULATOR_KEY') && SIMULATOR_KEY !== ''
    && !hash_equals((string)SIMULATOR_KEY, (string)($_GET['key'] ?? ''))) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Adgang naegtet: simulator.php?key=... kraeves (se SIMULATOR_KEY i db_config.php).\n";
    exit;
}

function lerp(float $a, float $b, float $t): float { return $a + ($b - $a) * $t; }

$out      = []; // alle output-linjer samles her
$errors   = [];
$inserted = 0;

// --- Parametre ---
$sim_minutes = 3;
$dt_seconds  = 9;
$num_steps   = (int) ceil(($sim_minutes * 60) / $dt_seconds);

$end_unix    = time();
$start_unix  = $end_unix - ($num_steps * $dt_seconds);

$helicopter_path = [
    'start' => ['lat' => 55.4594, 'lon' => 8.3881, 'alt' =>  20],
    'end'   => ['lat' => 55.5262, 'lon' => 8.5532, 'alt' => 300],
];

$observers = [
    ['name' => 'Fano',      'lat' => 55.4411, 'lon' => 8.4036, 'session' => 'sim-fano'],
    ['name' => 'Saedding',  'lat' => 55.5055, 'lon' => 8.4087, 'session' => 'sim-saedding'],
    ['name' => 'Storegade', 'lat' => 55.4708, 'lon' => 8.4520, 'session' => 'sim-storegade'],
];

$out[] = "Trigo simulator (v4 burst)";
$out[] = "==========================";
$out[] = "Rute:        Fano Strand -> Esbjerg Lufthavn";
$out[] = "Trin:        " . ($num_steps + 1) . " a $dt_seconds sek";
$out[] = "Tidsvindue:  " . date('H:i:s', $start_unix) . " -> " . date('H:i:s', $end_unix) . " (server lokal tid)";
$out[] = "";

// --- Tjek schema: er session_id-kolonnen tilstede? ---
$hasSession = false;
$colCheck = $mysqli->query("SHOW COLUMNS FROM observations LIKE 'session_id'");
if ($colCheck && $colCheck->num_rows > 0) $hasSession = true;
if ($colCheck) $colCheck->free();

if (!$hasSession) {
    $out[] = "!!! session_id-kolonnen mangler — kor schema_migration.sql forst !!!";
    $out[] = "";
}

// --- Forbered statement ---
if ($hasSession) {
    $sql = "INSERT INTO observations
                (id, session_id, observed_at, latitude, longitude, altitude, azimuth, elevation)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
    $bindFmt = "sssddddd";
} else {
    // Fallback: kor uden session_id (men dashboardet vil ikke kunne triangulere)
    $sql = "INSERT INTO observations
                (id, observed_at, latitude, longitude, altitude, azimuth, elevation)
            VALUES (?, ?, ?, ?, ?, ?, ?)";
    $bindFmt = "ssddddd";
}

$stmt = $mysqli->prepare($sql);
if (!$stmt) {
    $out[] = "SQL prepare-fejl: " . $mysqli->error;
    echo "<pre>" . implode("\n", $out) . "</pre>";
    exit;
}

$obs_alt = 2.0;

// --- Generer + indsaet alle rakker ---
for ($i = 0; $i <= $num_steps; $i++) {
    $t = $i / max(1, $num_steps);

    $heli_lat = lerp($helicopter_path['start']['lat'], $helicopter_path['end']['lat'], $t);
    $heli_lon = lerp($helicopter_path['start']['lon'], $helicopter_path['end']['lon'], $t);
    $heli_alt = lerp($helicopter_path['start']['alt'], $helicopter_path['end']['alt'], $t);

    $step_unix   = $start_unix + ($i * $dt_seconds);
    $observed_at = date('Y-m-d H:i:s', $step_unix);

    foreach ($observers as $obs_idx => $obs) {
        $bearing = calculateBearing($obs['lat'], $obs['lon'], $heli_lat, $heli_lon);
        $noise_b = mt_rand(-20, 20) / 10.0;
        $azimuth = fmod($bearing + $noise_b + 360, 360);

        $dist_m  = beregnAfstand($obs['lat'], $obs['lon'], $heli_lat, $heli_lon);
        $elev    = $dist_m > 0
                 ? rad2deg(atan2($heli_alt - $obs_alt, $dist_m))
                 : 0.0;
        $noise_e = mt_rand(-10, 10) / 10.0;
        $elev    = max(-90, min(90, $elev + $noise_e));

        // Kort, sikker, unik id: 13 cifre = ms-timestamp + 2 cifre observer-idx
        $id = $step_unix . sprintf('%02d', $i) . $obs_idx;

        if ($hasSession) {
            $stmt->bind_param(
                $bindFmt,
                $id, $obs['session'], $observed_at,
                $obs['lat'], $obs['lon'], $obs_alt,
                $azimuth, $elev
            );
        } else {
            $stmt->bind_param(
                $bindFmt,
                $id, $observed_at,
                $obs['lat'], $obs['lon'], $obs_alt,
                $azimuth, $elev
            );
        }

        if ($stmt->execute()) {
            $inserted++;
        } else {
            $errors[] = "Trin $i, " . $obs['name'] . ": " . $stmt->error;
            // Stop hvis det er en strukturel fejl (samme fejl gentager sig)
            if (count($errors) >= 3) {
                $out[] = "Stoppede efter 3 fejl — strukturelt problem.";
                break 2;
            }
        }
    }
}

$stmt->close();

// --- Statusrapport ---
$out[] = "Indsatte rakker:   $inserted";
$out[] = "Fejl:              " . count($errors);
foreach ($errors as $e) $out[] = "  - $e";
$out[] = "";

// --- Diagnostik: hvad ser fetch_log.php nu? ---
$out[] = "--- Tidszone-tjek ---";
$row = $mysqli->query("SELECT NOW() AS n, UTC_TIMESTAMP() AS u, @@global.time_zone AS gtz, @@session.time_zone AS stz")->fetch_assoc();
$out[] = "MySQL NOW():        " . $row['n'];
$out[] = "MySQL UTC:          " . $row['u'];
$out[] = "MySQL time_zone:    global=" . $row['gtz'] . ", session=" . $row['stz'];
$out[] = "PHP date():         " . date('Y-m-d H:i:s');
$out[] = "PHP gmdate():       " . gmdate('Y-m-d H:i:s');
$out[] = "PHP date_default_timezone: " . date_default_timezone_get();
$out[] = "";

// Samme PHP-beregnede tidsgrænse som fetch_log.php
$cutoff = $mysqli->real_escape_string(date('Y-m-d H:i:s', time() - 5 * 60));
$row = $mysqli->query(
    "SELECT COUNT(*) AS c,
            MIN(observed_at) AS first_obs,
            MAX(observed_at) AS last_obs
       FROM observations
      WHERE observed_at >= '$cutoff'"
)->fetch_assoc();
$out[] = "--- Det fetch_log.php henter (seneste 5 min) ---";
$out[] = "Rakker:  " . $row['c'];
$out[] = "Forste:  " . ($row['first_obs'] ?? '(ingen)');
$out[] = "Sidste:  " . ($row['last_obs']  ?? '(ingen)');
$out[] = "";

if ($hasSession && (int)$row['c'] > 0) {
    $sample = $mysqli->query(
        "SELECT id, session_id, observed_at, latitude, longitude, azimuth, elevation
           FROM observations
          WHERE observed_at >= '$cutoff'
          ORDER BY observed_at DESC LIMIT 9"
    );
    $out[] = "--- Sample (DESC) ---";
    while ($r = $sample->fetch_assoc()) {
        $out[] = sprintf(
            "%-15s %-14s %s  %.4f,%.4f  azi=%5.1f  elev=%5.1f",
            $r['id'], $r['session_id'], $r['observed_at'],
            $r['latitude'], $r['longitude'],
            (float)$r['azimuth'], (float)$r['elevation']
        );
    }
}

$mysqli->close();

// --- Send ALT output i et hug ---
header('Content-Type: text/plain; charset=utf-8');
echo implode("\n", $out) . "\n";
