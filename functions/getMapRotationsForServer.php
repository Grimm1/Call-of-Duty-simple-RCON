<?php
include __DIR__ . '/../config/database.php'; // Adjust path if necessary

$serverId = $_POST['serverId'];
$sql = "SELECT id, name FROM server_map_rotation WHERE server_id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $serverId);
$stmt->execute();
$result = $stmt->get_result();

$rotations = [];
while ($row = $result->fetch_assoc()) {
    $rotations[] = $row;
}

// Set the header to ensure JSON content type
header('Content-Type: application/json');

// Output JSON
echo json_encode($rotations);
?>