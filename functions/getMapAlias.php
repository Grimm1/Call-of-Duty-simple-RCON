<?php
include __DIR__ . '/../config/database.php'; // Adjust the path if necessary

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['serverId']) && isset($_POST['mapname'])) {
    $serverId = intval($_POST['serverId']);
    $mapname = $_POST['mapname'];

    if (isset($conn) && $conn->connect_error === null) {
        $stmt = $conn->prepare("
            SELECT ma.alias 
            FROM server_maps sm 
            JOIN map_aliases ma ON sm.map_id = ma.id 
            WHERE sm.server_id = ? AND ma.map_name = ? AND ma.server_id = ?
        ");
        if ($stmt) {
            $stmt->bind_param("iss", $serverId, $mapname, $serverId); // Binding parameters with types
            $stmt->execute();
            $result = $stmt->get_result();

            if ($result->num_rows > 0) {
                $row = $result->fetch_assoc();
                echo $row['alias'];
            } else {
                echo $mapname; // If no alias found, return the map name
            }
            $stmt->close();
        } else {
            echo $mapname; // Could not prepare statement, return default mapname
        }
    } else {
        echo $mapname; // Database connection issue, return default mapname
    }
} else {
    echo ''; // Incorrect request method or missing parameters
}
?>