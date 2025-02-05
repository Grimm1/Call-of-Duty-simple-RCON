<?php
// Assuming database connection is handled elsewhere, like in a config file
require_once '../config/database.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $gameType = $_POST['game_type'] ?? '';
    $serverId = $_POST['server'] ?? null;

    if ($gameType && $serverId) {
        $conn->begin_transaction();

        try {
            // First, get the id of the game type to delete
            $stmt = $conn->prepare("
                SELECT ag.id 
                FROM available_gametypes ag 
                JOIN server_gametypes sg ON ag.id = sg.available_gametype_id 
                WHERE ag.game_type = ? AND sg.server_id = ?
            ");
            $stmt->bind_param("si", $gameType, $serverId);
            $stmt->execute();
            $result = $stmt->get_result();
            $gameTypeId = $result->fetch_assoc()['id'];

            if ($gameTypeId) {
                // Delete from server_gametypes
                $stmt = $conn->prepare("DELETE FROM server_gametypes WHERE available_gametype_id = ?");
                $stmt->bind_param("i", $gameTypeId);
                $stmt->execute();

                // Also delete from available_gametypes
                $stmt = $conn->prepare("DELETE FROM available_gametypes WHERE id = ?");
                $stmt->bind_param("i", $gameTypeId);
                $stmt->execute();

                $conn->commit();
                echo json_encode(["success" => true, "message" => "Game type and its association removed successfully."]);
            } else {
                $conn->rollback();
                echo json_encode(["error" => "Game type not found for this server."]);
            }
        } catch (Exception $e) {
            $conn->rollback();
            echo json_encode(["error" => "Failed to remove game type: " . $e->getMessage()]);
        }
    } else {
        echo json_encode(["error" => "Game type or Server ID not provided."]);
    }
} else {
    http_response_code(405);
    echo json_encode(["error" => "Method not allowed."]);
}