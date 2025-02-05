<?php
include __DIR__ . '/../config/database.php'; // Adjust the path if necessary

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['serverId']) && isset($_POST['gametype'])) {
    $serverId = intval($_POST['serverId']);
    $gametype = $_POST['gametype'];

    if (isset($conn) && $conn->connect_error === null) {
        $stmt = $conn->prepare("
            SELECT ag.gamet_alias 
            FROM server_gametypes sg 
            JOIN available_gametypes ag ON sg.available_gametype_id = ag.id 
            WHERE sg.server_id = ? AND ag.game_type = ? AND ag.server_id = ?
        ");
        if ($stmt) {
            $stmt->bind_param("iss", $serverId, $gametype, $serverId);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($result->num_rows > 0) {
                $row = $result->fetch_assoc();
                echo $row['gamet_alias'];
            } else {
                echo $gametype; // If no alias found, return the gametype
            }
            $stmt->close();
        } else {
            echo $gametype; // Could not prepare statement, return default gametype
        }
    } else {
        echo $gametype; // Database connection issue, return default gametype
    }
} else {
    echo ''; // Incorrect request method or missing parameters
}
?>