<?php
// Include database configuration
require_once '../config/database.php';

// Check if POST data is available
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $gameType = $_POST['game_type'] ?? '';
    $gameTypeAlias = $_POST['game_type_alias'] ?? '';
    $serverId = $_POST['server'] ?? null;

    // Ensure all required data is present
    if (!empty($gameType) && !empty($gameTypeAlias) && !empty($serverId)) {
        try {
            // Start transaction
            $conn->begin_transaction();

            // Insert into available_gametypes table
            $stmt = $conn->prepare("INSERT INTO available_gametypes (game_type, gamet_alias, server_id) VALUES (?, ?, ?)");
            $stmt->bind_param("ssi", $gameType, $gameTypeAlias, $serverId);
            $stmt->execute();
            $gametypeId = $conn->insert_id;

            // Insert into server_gametypes table
            $stmt = $conn->prepare("INSERT INTO server_gametypes (server_id, available_gametype_id) VALUES (?, ?)");
            $stmt->bind_param("ii", $serverId, $gametypeId);
            $stmt->execute();

            // Commit transaction if both queries succeed
            $conn->commit();
            echo json_encode(['success' => true, 'message' => 'Game type added successfully.']);
        } catch (Exception $e) {
            // Rollback in case of any error
            $conn->rollback();
            echo json_encode(['success' => false, 'message' => 'Failed to add game type: ' . $e->getMessage()]);
        }
    } else {
        echo json_encode(['success' => false, 'message' => 'Missing required data.']);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
}
?>