<?php
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');

// Aggressiv buffer-håndtering for at sikre øjeblikkelig afsendelse
@ini_set('zlib.output_compression', 0);
if (ob_get_level() > 0) { ob_end_flush(); }
ob_implicit_flush(true);

require_once 'db_config.php';

// --- NY LOGIK: BRUGER 'id' I STEDET FOR 'timestamp' ---
// Hent det højeste ID, der findes i databasen ved start.
$result = $mysqli->query("SELECT MAX(id) as max_id FROM observations");
$row = $result->fetch_assoc();
// JavaScript's Date.now() er et stort tal, så vi behandler det som en streng.
$lastId = $row['max_id'] ?? '0';

// Fjern tidsgrænsen for eksekvering
set_time_limit(0);

// Kør i en uendelig løkke
while (true) {
    if (connection_aborted()) {
        $mysqli->close();
        exit();
    }

    // Hent observationer med et ID, der er HØJERE end det sidst sendte
    $stmt = $mysqli->prepare("SELECT id, latitude, longitude, observed_at, azimuth, elevation FROM observations WHERE id > ? ORDER BY id ASC");
    $stmt->bind_param("s", $lastId); // 's' for streng, da ID'et er et stort tal
    $stmt->execute();
    $new_observations_result = $stmt->get_result();
    
    $new_observations = $new_observations_result->fetch_all(MYSQLI_ASSOC);

    if (count($new_observations) > 0) {
        foreach ($new_observations as $obs) {
            echo "data: " . json_encode($obs) . "\n\n";
            $lastId = $obs['id']; // Opdater det seneste kendte ID
        }
    } else {
        // Send keep-alive for at forhindre timeout
        echo ": keep-alive\n\n";
    }
    
    // Tving data ud til browseren
    flush();
    
    // Vent 2 sekunder før næste tjek
    sleep(2);
}
?>
