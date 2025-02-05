<?php
session_start();

include '../config/database.php';

if (!isset($_SESSION['username'])) {
    echo json_encode(['error' => 'Not logged in']);
    exit();
}

$serverId = $_POST['server'] ?? null;
$mapName = $_POST['map_name'] ?? null;

if ($serverId && $mapName) {
    $conn->begin_transaction();

    // Delete from server_maps
    $stmt = $conn->prepare("DELETE FROM server_maps 
                            WHERE server_id = ? AND map_id IN (SELECT id FROM map_aliases WHERE map_name = ? AND server_id = ?)");
    $stmt->bind_param("isi", $serverId, $mapName, $serverId);
    $server_maps_deleted = $stmt->execute();

    // Delete from map_aliases
    $stmt = $conn->prepare("DELETE FROM map_aliases WHERE map_name = ? AND server_id = ?");
    $stmt->bind_param("si", $mapName, $serverId);
    $map_aliases_deleted = $stmt->execute();

    if ($server_maps_deleted && $map_aliases_deleted) {
        $conn->commit();
        echo json_encode(['message' => 'Map association removed successfully.']);
    } else {
        $conn->rollback();
        echo json_encode(['error' => 'Failed to remove map association: ' . $stmt->error]);
    }
} else {
    echo json_encode(['error' => 'Missing server ID or map name']);
}
