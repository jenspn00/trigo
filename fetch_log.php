<?php
// =================================================================
//  fetch_log.php  (v2)
//
//  Tidligere version regex-parsede observation_log.txt — det
//  betød at session_id og address aldrig nåede til dashboardet,
//  og dashboardets "spring over samme bruger"-regel blokerede
//  ALLE krydsninger (alle var 'unknown').
//
//  Denne version læser direkte fra databasen.
// =================================================================

header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', 0);
error_reporting(E_ALL);

require_once 'db_config.php';

// Hvor langt tilbage hentes der? (default 5 min — dashboardet
// filtrerer selv yderligere på et kortere tidsvindue for krydsninger.)
$minutes = isset($_GET['minutes']) ? max(1, min(1440, (int)$_GET['minutes'])) : 5;

// Hvor mange rækker højst? I track-mode sender én observatør en pejling
// hvert 0,8 sek = 75 rækker/min, så tre observatører fylder ~1125 rækker på
// standardvinduets 5 minutter. Loftet er altså nået ved helt almindelig
// brug, og dashboardet så før kun de nyeste ~2 minutter uden at sige det.
// Loftet står indtil videre, fordi dashboardets parvise krydsningsløkke er
// O(n²); til gengæld meldes afkortningen nu tilbage, så den er synlig.
const OBS_LIMIT = 500;

$response = ['latest_observations' => [], 'truncated' => false, 'limit' => OBS_LIMIT];

try {
    // Detektér om address-kolonnen findes (kompatibilitet med
    // databaser hvor migrationen endnu ikke er kørt).
    $hasAddress = false;
    $colCheck = $mysqli->query("SHOW COLUMNS FROM observations LIKE 'address'");
    if ($colCheck && $colCheck->num_rows > 0) $hasAddress = true;
    if ($colCheck) $colCheck->free();

    // observed_at_ms: epoch i millisekunder, udregnet af MySQL selv.
    //
    // Den nøgne DATETIME-streng ("2026-09-23 09:00:00") er tvetydig: JS
    // tolker den som browserens lokaltid i Chrome, mens Safari slet ikke
    // kan parse formatet og giver Invalid Date. Det får dashboardets
    // computeFixes() til at springe hver eneste pejling over (isNaN), så
    // track-kortet forsvinder helt på iPhone/iPad — og samtidig falder
    // tidsvindue-filteret sammen, fordi NaN > x altid er false, så ALLE
    // par krydses uanset tid.
    //
    // Et epoch-tal er entydigt og parses ens i alle browsere. observed_at
    // bevares uændret af hensyn til evt. andre forbrugere.
    $cols = "id, session_id, observed_at, UNIX_TIMESTAMP(observed_at) * 1000 AS observed_at_ms,
             latitude, longitude, altitude, azimuth, elevation"
          . ($hasAddress ? ", address" : "");

    $stmt = $mysqli->prepare(
        "SELECT $cols
           FROM observations
          WHERE observed_at >= (NOW() - INTERVAL ? MINUTE)
          ORDER BY observed_at DESC
          LIMIT " . OBS_LIMIT
    );
    $stmt->bind_param("i", $minutes);
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = $res->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    foreach ($rows as &$r) {
        $r['latitude']  = (float)$r['latitude'];
        $r['longitude'] = (float)$r['longitude'];
        $r['altitude']  = isset($r['altitude'])  ? (float)$r['altitude']  : 0.0;
        $r['azimuth']   = (float)$r['azimuth'];
        $r['elevation'] = isset($r['elevation']) ? (float)$r['elevation'] : 0.0;
        $r['observed_at_ms'] = isset($r['observed_at_ms']) ? (float)$r['observed_at_ms'] : null;
        // Bagudkompatibelt felt: dashboardet brugte 'elevation_angle' før
        $r['elevation_angle'] = $r['elevation'];
    }
    unset($r);

    $response['latest_observations'] = $rows;
    // Rækker vi præcis loftet, er der efter al sandsynlighed mere derude.
    $response['truncated'] = count($rows) >= OBS_LIMIT;

} catch (Throwable $e) {
    error_log('fetch_log.php: ' . $e->getMessage());
    http_response_code(500);
    $response = ['status' => 'error', 'message' => 'Server fejl', 'truncated' => false];
}

echo json_encode($response);

if (isset($mysqli) && $mysqli && !$mysqli->connect_error) {
    $mysqli->close();
}
