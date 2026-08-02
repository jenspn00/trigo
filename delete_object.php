<?php
error_reporting(0);
ini_set('display_errors', 0);
header('Content-Type: application/json');

require_once 'db_config.php';

$response = ['status' => 'error', 'message' => 'Invalid request.'];

// Tjek om det er en POST-anmodning
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $jsonInput = file_get_contents('php://input');
    $data = json_decode($jsonInput, true);

    // Valider de modtagne data
    if ($data !== null && isset($data['object_id']) && is_numeric($data['object_id'])) {
        $objectId = intval($data['object_id']);

        // Forbered en SQL-sætning for at slette objektet
        $stmt = $mysqli->prepare("DELETE FROM tracked_objects WHERE object_id = ?");

        if ($stmt) {
            $stmt->bind_param("i", $objectId);

            if ($stmt->execute()) {
                // Tjek om en række rent faktisk blev slettet
                if ($stmt->affected_rows > 0) {
                    $response['status'] = 'success';
                    $response['message'] = "Objekt #{$objectId} blev slettet.";
                } else {
                    $response['message'] = "Objekt #{$objectId} blev ikke fundet.";
                }
            } else {
                $response['message'] = 'Databasefejl under sletning.';
            }
            $stmt->close();
        } else {
            $response['message'] = 'Fejl ved forberedelse af database-sætning.';
        }
    } else {
        $response['message'] = 'Ugyldigt eller manglende object_id.';
    }
}

$mysqli->close();
echo json_encode($response);
?>
