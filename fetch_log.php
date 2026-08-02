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

$response = ['latest_observations' => []];

try {
    // Detektér om address-kolonnen findes (kompatibilitet med
    // databaser hvor migrationen endnu ikke er kørt).
    $hasAddress = false;
    $colCheck = $mysqli->query("SHOW COLUMNS FROM observations LIKE 'address'");
    if ($colCheck && $colCheck->num_rows > 0) $hasAddress = true;
    if ($colCheck) $colCheck->free();

    $cols = "id, session_id, observed_at, latitude, longitude, altitude, azimuth, elevation"
          . ($hasAddress ? ", address" : "");

    $stmt = $mysqli->prepare(
        "SELECT $cols
           FROM observations
          WHERE observed_at >= (NOW() - INTERVAL ? MINUTE)
          ORDER BY observed_at DESC
          LIMIT 500"
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
        // Bagudkompatibelt felt: dashboardet brugte 'elevation_angle' før
        $r['elevation_angle'] = $r['elevation'];
    }
    unset($r);

    $response['latest_observations'] = $rows;

} catch (Throwable $e) {
    error_log('fetch_log.php: ' . $e->getMessage());
    http_response_code(500);
    $response = ['status' => 'error', 'message' => 'Server fejl'];
}

echo json_encode($response);

if (isset($mysqli) && $mysqli && !$mysqli->connect_error) {
    $mysqli->close();
}
