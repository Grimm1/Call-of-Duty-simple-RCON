<?php
include '../config/database.php';

header('Content-Type: application/json');

$sql = "SELECT * FROM game_servers";
$result = $conn->query($sql);

$servers = [];
if ($result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $servers[] = [
            'id' => $row['id'],
            'server_name' => $row['server_name'],
            'ip_or_hostname' => $row['ip_or_hostname'],
            'port' => $row['port'],
            'server_type' => $row['server_type']
        ];
    }
}

echo json_encode(['servers' => $servers]);
?>
