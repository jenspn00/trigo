<?php
// Produktion: log fejl men vis dem IKKE til brugeren
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

// Definer databaseforbindelsesoplysninger som konstanter
define('DB_HOST', 'localhost');
define('DB_USER', 'your_db_user');
define('DB_PASS', 'your_db_password');
define('DB_NAME', 'your_db_name');

// Opret en ny MySQLi-forbindelse
$mysqli = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);

// Tjek for forbindelsesfejl
if ($mysqli->connect_error) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['status' => 'error', 'message' => 'Database connection failed']);
    exit();
}

$mysqli->set_charset('utf8mb4');

// Valgfrit: beskyt simulator.php (kaldes så som simulator.php?key=...).
// Lad den være tom for at slå beskyttelsen fra.
define('SIMULATOR_KEY', '');
?>
