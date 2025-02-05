<?php
session_start();
// Debug logging setup
// $debugLogFile = 'debug_log.txt';
// $logDate = date("Y-m-d H:i:s");
// function debug_log($message) {
//     global $debugLogFile, $logDate;
//     $logMessage = "$logDate - $message\n";
//     file_put_contents($debugLogFile, $logMessage, FILE_APPEND);
// }

// Include this after your debug setup
include '../config/database.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['server'])) {
        $serverId = $_POST['server'];
        $sqlMaps = "SELECT ma.map_name, ma.alias 
                    FROM map_aliases ma
                    JOIN server_maps sm ON ma.id = sm.map_id
                    WHERE sm.server_id = ?";
        $stmt = $conn->prepare($sqlMaps);
        $stmt->bind_param("i", $serverId);
        $stmt->execute();
        $maps = $stmt->get_result();

        if (isset($_POST['for_dropdown'])) {
            // For the dropdown in the RCON interface
            $options = "<option value=''>Select Map</option>";
            while ($map = $maps->fetch_assoc()) {
                $mapName = htmlspecialchars($map['map_name']);
                $alias = htmlspecialchars($map['alias']);
                $options .= "<option value='$mapName'>$alias</option>";
            }
            // debug_log("Returning map options for dropdown for server $serverId");
            echo $options;
        } else {
            // For the map manager table
            $mapArray = [];
            while ($map = $maps->fetch_assoc()) {
                $mapArray[] = [
                    'map_name' => $map['map_name'],
                    'alias' => $map['alias']
                ];
            }
            // debug_log("Retrieved " . count($mapArray) . " maps for server $serverId for map manager");
            echo json_encode($mapArray);
        }
    } else {
        // debug_log("Server ID not provided in get_maps request");
        echo isset($_POST['for_dropdown']) ? "<option value=''>Select Map</option>" : json_encode([]);
    }
} else {
    // debug_log("Unexpected request method in get_maps.php");
    echo isset($_POST['for_dropdown']) ? "<option value=''>Select Map</option>" : json_encode([]);
}
?>