<?php
include __DIR__ . '/../config/database.php'; // Adjust path if necessary

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['serverId'])) {
    $serverId = intval($_POST['serverId']);

    $stmt = $conn->prepare("
        SELECT ma.map_name, ma.alias 
        FROM map_aliases ma 
        JOIN server_maps sm ON ma.id = sm.map_id 
        WHERE sm.server_id = ? AND ma.server_id = ?
    ");
    $stmt->bind_param("ii", $serverId, $serverId);
    $stmt->execute();
    $result = $stmt->get_result();

    $maps = [];
    while ($row = $result->fetch_assoc()) {
        $maps[] = $row;
    }
    echo json_encode($maps);
    $stmt->close();
}
?>