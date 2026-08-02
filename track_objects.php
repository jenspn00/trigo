<?php
// track_objects.php

// 1. Opsætning
header('Content-Type: application/json');
require_once 'db_config.php';

// Slå fejlvisning fra i outputtet
ini_set('display_errors', 0);
error_reporting(E_ALL);

$response = [
    'latest_observations' => [],
    'tracked_objects' => []
];

try {
    // 2. Hent KUN observationer fra de sidste 5 minutter
    // Vi bruger "NOW() - INTERVAL 5 MINUTE" til at filtrere gamle data fra
    $sql_obs = "SELECT id, observed_at, latitude, longitude, altitude, azimuth, elevation 
                FROM observations 
                WHERE observed_at >= (NOW() - INTERVAL 5 MINUTE)
                ORDER BY observed_at DESC";
                
    $result_obs = $mysqli->query($sql_obs);
    
    if ($result_obs) {
        $observations = $result_obs->fetch_all(MYSQLI_ASSOC);
        
        // Konverter strenge til tal (floats) så JavaScript kan forstå dem
        foreach ($observations as &$obs) {
            $obs['latitude'] = (float)$obs['latitude'];
            $obs['longitude'] = (float)$obs['longitude'];
            $obs['azimuth'] = (float)$obs['azimuth'];
            $obs['elevation'] = isset($obs['elevation']) ? (float)$obs['elevation'] : 0;
        }
        $response['latest_observations'] = $observations;
    }

    // 3. (Plads til fremtidig triangulering af objekter)
    $response['tracked_objects'] = []; 

} catch (Exception $e) {
    error_log("Fejl i track_objects.php: " . $e->getMessage());
}

// 4. Send JSON til Javascript
echo json_encode($response);

$mysqli->close();
?>
