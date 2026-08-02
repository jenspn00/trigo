<?php
// get_intersections.php

// 1. Opsætning
header('Content-Type: application/json');
require_once 'db_config.php'; // Sørg for at denne sti passer

// Slå fejlvisning fra i outputtet (vigtigt for JSON)
ini_set('display_errors', 0);
error_reporting(E_ALL);

$response = [];

// --- KONFIGURATION ---
$time_window = 15; // Sekunder. Observationer skal være inden for 15 sek af hinanden for at blive parret.
$min_distance_observers = 0.0005; // Ca. 50 meter. Hvis observatører står for tæt, bliver beregningen upræcis.

/**
 * Funktion til at beregne skæringspunkt mellem to linjer givet ved (lat, lon, retning)
 * Dette er en 'flad-jords' approksimation, som virker fint over korte afstande (< 50km).
 */
function calculateIntersection($lat1, $lon1, $brng1, $lat2, $lon2, $brng2) {
    // Konverter grader til radianer
    $x1 = deg2rad($lon1);
    $y1 = deg2rad($lat1);
    $b1 = deg2rad($brng1);
    
    $x2 = deg2rad($lon2);
    $y2 = deg2rad($lat2);
    $b2 = deg2rad($brng2);

    // Beregn differencer
    $dx = $x2 - $x1;
    $dy = $y2 - $y1;

    // Beregn vinkel (bearing) mellem de to punkter
    $bearing12 = 2 * atan2(
        sin($dx/2) * cos(($y1+$y2)/2),
        cos($dx/2)
    ); // Simplificeret bearing

    // Beregn determinant for at se om linjer er parallelle
    $det = sin($b1 - $b2);
    
    // Hvis linjerne er næsten parallelle, returner null
    if (abs($det) < 0.001) {
        return null; 
    }

    // Lineær algebra løsning (Small angle approximation)
    // Konverter til Cartesisk koordinatsystem (tilnærmelsesvis)
    // Vi bruger latitude som Y og longitude * cos(lat) som X for at kompensere for jordens krumning
    $avgLat = ($lat1 + $lat2) / 2;
    $scaleX = cos(deg2rad($avgLat));

    // Punkt 1 (x, y) og hældning m1
    $px1 = $lon1 * $scaleX;
    $py1 = $lat1;
    // Matematisk vinkel er 90 - kompasretning. Tan tager radianer.
    $theta1 = deg2rad(90 - $brng1);
    $m1 = tan($theta1);

    // Punkt 2 (x, y) og hældning m2
    $px2 = $lon2 * $scaleX;
    $py2 = $lat2;
    $theta2 = deg2rad(90 - $brng2);
    $m2 = tan($theta2);

    // Håndter lodrette linjer (hvis m1 eller m2 går mod uendelig)
    if (abs(cos($theta1)) < 0.0001) $m1 = 1e9;
    if (abs(cos($theta2)) < 0.0001) $m2 = 1e9;

    if (abs($m1 - $m2) < 0.0001) return null; // Parallelle igen

    // Find X (Longitude-ish) i skæringspunktet
    // Formel: x = (m1*x1 - m2*x2 + y2 - y1) / (m1 - m2)
    $intersectX = ($m1 * $px1 - $m2 * $px2 + $py2 - $py1) / ($m1 - $m2);

    // Find Y (Latitude)
    // Formel: y = m1 * (x - x1) + y1
    $intersectY = $m1 * ($intersectX - $px1) + $py1;

    // Konverter tilbage til Lat/Lon
    $finalLat = $intersectY;
    $finalLon = $intersectX / $scaleX;

    // --- TJEK: Er punktet "foran" kameraerne? ---
    // Hvis skæringspunktet ligger bagved kameraet, er det en falsk positiv.
    // Vi tjekker simpel retning.
    
    // Vinkel fra Obs1 til Target
    $angleToTarget = rad2deg(atan2($intersectY - $py1, $intersectX - $px1));
    $mathAngle1 = 90 - $brng1;
    
    // Normaliser vinkler (-180 til 180)
    $diff = fmod($angleToTarget - $mathAngle1 + 540, 360) - 180;
    
    // Hvis forskellen er mere end 90 grader, ligger punktet bagved.
    if (abs($diff) > 90) return null;

    return ['lat' => $finalLat, 'lon' => $finalLon];
}

try {
    // 2. Hent friske observationer (Sidste 30 sekunder - vi vil have "live" data)
    $sql = "SELECT id, observed_at, latitude, longitude, azimuth 
            FROM observations 
            WHERE observed_at >= (NOW() - INTERVAL 30 SECOND)
            ORDER BY observed_at DESC";
            
    $result = $mysqli->query($sql);
    
    if ($result) {
        $observations = $result->fetch_all(MYSQLI_ASSOC);
        $intersections = [];
        $paired_ids = []; // For at undgå at tælle samme par to gange

        // 3. Loop gennem alle kombinationer af observationer
        for ($i = 0; $i < count($observations); $i++) {
            for ($j = $i + 1; $j < count($observations); $j++) {
                
                $obs1 = $observations[$i];
                $obs2 = $observations[$j];

                // ID Tjek: En enhed kan ikke krydse med sig selv (Hvis du logger device_id, brug det her)
                // Her bruger vi ID, som antager at hver række er unik. 
                // Bedre: if ($obs1['device_id'] == $obs2['device_id']) continue;

                // Tids-tjek: Er de observeret nogenlunde samtidig?
                $time1 = strtotime($obs1['observed_at']);
                $time2 = strtotime($obs2['observed_at']);
                if (abs($time1 - $time2) > $time_window) continue;

                // Afstands-tjek: Står observatørerne for tæt på hinanden?
                // Pythagoras approksimation er fin her
                $distSq = pow($obs1['latitude'] - $obs2['latitude'], 2) + pow($obs1['longitude'] - $obs2['longitude'], 2);
                if ($distSq < pow($min_distance_observers, 2)) continue;

                // 4. Beregn Skæringspunkt
                $point = calculateIntersection(
                    (float)$obs1['latitude'], (float)$obs1['longitude'], (float)$obs1['azimuth'],
                    (float)$obs2['latitude'], (float)$obs2['longitude'], (float)$obs2['azimuth']
                );

                if ($point) {
                    // Vi har et hit!
                    $intersections[] = [
                        'lat' => $point['lat'],
                        'lon' => $point['lon'],
                        'time' => date('H:i:s', max($time1, $time2)), // Brug nyeste tidspunkt
                        'source_ids' => [$obs1['id'], $obs2['id']]
                    ];
                }
            }
        }
        
        // Returner resultatet
        echo json_encode($intersections);
    } else {
        echo json_encode([]);
    }

} catch (Exception $e) {
    error_log("Fejl i get_intersections.php: " . $e->getMessage());
    echo json_encode(['error' => 'Server error']);
}

$mysqli->close();
?>
