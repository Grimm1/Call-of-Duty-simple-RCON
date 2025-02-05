<?php
include __DIR__ . '/../config/database.php'; // Adjust path if necessary

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['serverId'])) {
    $serverId = intval($_POST['serverId']);

    $stmt = $conn->prepare("
        SELECT ag.game_type, ag.gamet_alias 
        FROM available_gametypes ag 
        JOIN server_gametypes sg ON ag.id = sg.available_gametype_id 
        WHERE sg.server_id = ? AND ag.server_id = ?
    ");
    $stmt->bind_param("ii", $serverId, $serverId);
    $stmt->execute();
    $result = $stmt->get_result();

    $gametypes = [];
    while ($row = $result->fetch_assoc()) {
        $gametypes[] = $row;
    }
    echo json_encode($gametypes);
    $stmt->close();
}
?>