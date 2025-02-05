<?php
include __DIR__ . '/../config/database.php'; // Adjust path if necessary

$rotationId = $_POST['rotationId'];
$sql = "SELECT rotation_string FROM server_map_rotation WHERE id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $rotationId);
$stmt->execute();
$result = $stmt->get_result();

if ($row = $result->fetch_assoc()) {
    echo $row['rotation_string'];
} else {
    echo ""; // Return empty if not found
}
?>