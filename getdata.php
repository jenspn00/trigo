<?php
// Modtag den rå POST-data (som er JSON-strengen)
$json_data = file_get_contents('php://input');

// Tjek om data er modtaget
if (empty($json_data)) {
    // Send en fejl-header tilbage
    http_response_code(400); // Bad Request
    echo "Fejl: Ingen data modtaget.";
    exit;
}

// Afkod JSON-strengen til et PHP-objekt eller array
// 'true' som andet argument konverterer til et associativt array
$data = json_decode($json_data, true);

if (is_null($data)) {
    // Send en fejl-header tilbage
    http_response_code(400); // Bad Request
    echo "Fejl: Ugyldigt JSON-format.";
    exit;
}

// (Her ville du typisk indsætte data i din database)

// For test: Gem de modtagne data i en tekstfil
// 'FILE_APPEND' tilføjer til filen i stedet for at overskrive
// 'LOCK_EX' forhindrer andre i at skrive til filen på samme tid
$log_entry = date('Y-m-d H:i:s') . " - Data modtaget: " . $json_data . PHP_EOL;
file_put_contents('sensor_data_log.txt', $log_entry, FILE_APPEND | LOCK_EX);

// Send et "OK" svar tilbage til ESP8266
http_response_code(200); // OK
echo "Data modtaget succesfuldt.";

?>
