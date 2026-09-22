<?php
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo "Fejl: Kun POST.";
    exit;
}

// Modtag den rå POST-data (som er JSON-strengen). Loftet på 8 KB
// forhindrer at nogen fylder disken via sensor_data_log.txt.
$json_data = file_get_contents('php://input', false, null, 0, 8193);
if (strlen($json_data) > 8192) {
    http_response_code(413);
    echo "Fejl: For meget data.";
    exit;
}

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
// json_encode igen → én linje pr. post (ingen indlejrede linjeskift)
$log_entry = date('Y-m-d H:i:s') . " - Data modtaget: " . json_encode($data) . PHP_EOL;
file_put_contents('sensor_data_log.txt', $log_entry, FILE_APPEND | LOCK_EX);

// Send et "OK" svar tilbage til ESP8266
http_response_code(200); // OK
echo "Data modtaget succesfuldt.";

?>
