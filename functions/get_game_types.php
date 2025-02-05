<?php
// Assuming database connection is handled elsewhere, like in a config file
require_once '../config/database.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $serverId = $_POST['server'] ?? null;

    if ($serverId) {
        try {
            $stmt = $conn->prepare("
                SELECT ag.game_type, ag.gamet_alias AS alias 
                FROM available_gametypes ag 
                JOIN server_gametypes sg ON ag.id = sg.available_gametype_id 
                WHERE sg.server_id = ?
            ");
            $stmt->bind_param("i", $serverId);
            $stmt->execute();
            $result = $stmt->get_result();

            $gameTypes = [];
            while ($row = $result->fetch_assoc()) {
                $gameTypes[] = $row;
            }

            echo json_encode($gameTypes);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(["error" => "An error occurred while fetching game types: " . $e->getMessage()]);
        }
    } else {
        http_response_code(400);
        echo json_encode(["error" => "Server ID not provided."]);
    }
} else {
    http_response_code(405);
    echo json_encode(["error" => "Method not allowed."]);
}