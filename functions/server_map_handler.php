<?php
require_once 'config/database.php'; 

function handleServerMaps($serverId, $mapsString = null) {
    global $conn;

    if ($conn->connect_error) {
        die("Connection failed: " . $conn->connect_error);
    }

    if ($mapsString === true) {
        // Fetch operation - unchanged
        $query = "SELECT m.map_name, m.alias FROM map_aliases m 
                  JOIN server_maps sm ON m.id = sm.map_id 
                  WHERE sm.server_id = ?";
        $stmt = $conn->prepare($query);
        if (!$stmt) {
            throw new Exception("Prepare failed: " . $conn->error);
        }
        $stmt->bind_param("i", $serverId);
        if (!$stmt->execute()) {
            throw new Exception("Execute failed: " . $stmt->error);
        }
        $result = $stmt->get_result();
        
        $output = [];
        while ($row = $result->fetch_assoc()) {
            $output[] = $row['map_name'] . '=' . $row['alias'];
        }
        $stmt->close();
        return implode(';', $output);
    } elseif (is_string($mapsString)) {
        $conn->begin_transaction();
        try {
            // error_log("Processing map data: " . $mapsString); // Commented out for successful operation
            
            $pairs = explode(';', $mapsString);
            $skippedEntries = [];

            foreach ($pairs as $pair) {
                $pair = trim($pair);
                if ($pair !== '') {
                    list($map_name, $alias) = explode('=', $pair, 2);
                    $map_name = trim($map_name);
                    $alias = trim($alias);

                    try {
                        // Check if map already exists for this server
                        $checkQuery = "SELECT id FROM map_aliases WHERE map_name = ? AND server_id = ?";
                        $stmt = $conn->prepare($checkQuery);
                        $stmt->bind_param("si", $map_name, $serverId);
                        $stmt->execute();
                        $result = $stmt->get_result();
                        
                        if ($result->num_rows > 0) {
                            // Map exists, update alias if different
                            $mapId = $result->fetch_assoc()['id'];
                            $updateQuery = "UPDATE map_aliases SET alias = ? WHERE id = ?";
                            $stmt = $conn->prepare($updateQuery);
                            $stmt->bind_param("si", $alias, $mapId);
                            $stmt->execute();
                        } else {
                            // Insert new map alias
                            $insertMapQuery = "INSERT INTO map_aliases (map_name, alias, server_id) VALUES (?, ?, ?)";
                            $stmt = $conn->prepare($insertMapQuery);
                            $stmt->bind_param("ssi", $map_name, $alias, $serverId);
                            $stmt->execute();
                            $mapId = $stmt->insert_id;
                        }
                        
                        $stmt->close();

                        // Ensure map is linked to server
                        $insertLinkQuery = "INSERT INTO server_maps (server_id, map_id) VALUES (?, ?) ON DUPLICATE KEY UPDATE map_id=map_id";
                        $stmt = $conn->prepare($insertLinkQuery);
                        $stmt->bind_param("ii", $serverId, $mapId);
                        $stmt->execute();
                        $stmt->close();

                        // error_log("Inserted/Updated: $map_name=$alias"); // Commented out for successful operation
                    } catch (mysqli_sql_exception $e) {
                        if ($e->getCode() == 1062) { // Duplicate entry error code
                            $skippedEntries[] = "$map_name=$alias";
                            error_log("Skipped due to duplicate entry: $map_name=$alias");
                        } else {
                            throw $e; // Re-throw for other exceptions
                        }
                    }
                }
            }

            if (!empty($skippedEntries)) {
                $errorMessage = "The following entries were skipped due to duplicates: " . implode(", ", $skippedEntries);
                error_log($errorMessage);
            }

            $conn->commit();
            return empty($skippedEntries); // Return false if any entries were skipped, true otherwise
        } catch (Exception $e) {
            $conn->rollback();
            error_log("Database update failed: " . $e->getMessage());
            return false;
        }
    }
    return false;
}

function getMapId($conn, $map_name, $alias, $server_id) {
    $query = "SELECT id FROM map_aliases WHERE map_name = ? AND alias = ? AND server_id = ?";
    $stmt = $conn->prepare($query);
    if (!$stmt) {
        throw new Exception("Prepare failed: " . $conn->error);
    }
    $stmt->bind_param("ssi", $map_name, $alias, $server_id);
    if (!$stmt->execute()) {
        throw new Exception("Execute failed: " . $stmt->error);
    }
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();
    return $row ? $row['id'] : false;
}
?>