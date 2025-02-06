<?php
session_start();
if (!isset($_SESSION['username'])) {
    header("Location: login.php");
    exit();
}

include 'config/database.php';
include 'functions/resolveHostname.php';

$response = [];

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['server']) && isset($_POST['map_name'])) {
        // Map deletion code
        $serverId = $_POST['server'];
        $mapName = $_POST['map_name'];

        $conn->begin_transaction();

        // Delete from server_maps
        $sql = "DELETE FROM server_maps 
                WHERE server_id = ? AND map_id IN (SELECT id FROM map_aliases WHERE map_name = ? AND server_id = ?)";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            $response['error'] = 'SQL preparation failed for server_maps: ' . $conn->error;
            goto end;
        }
        $stmt->bind_param("isi", $serverId, $mapName, $serverId);
        $server_maps_deleted = $stmt->execute();
        $stmt->close();

        // Delete from map_aliases
        $sql = "DELETE FROM map_aliases WHERE map_name = ? AND server_id = ?";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            $response['error'] = 'SQL preparation failed for map_aliases: ' . $conn->error;
            goto end;
        }
        $stmt->bind_param("si", $mapName, $serverId);
        $map_aliases_deleted = $stmt->execute();

        if ($server_maps_deleted && $map_aliases_deleted) {
            $conn->commit();
            $response['message'] = 'Map association and aliases removed successfully.';
        } else {
            $conn->rollback();
            $response['error'] = 'Failed to remove map association and aliases: ' . $conn->error;
        }
    } elseif (isset($_POST['delete_server_id'])) {
        // Server deletion code
        $server_id = (int)$_POST['delete_server_id'];

        $sql = "DELETE FROM server_gametypes WHERE server_id = ?";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            $response['error'] = 'SQL preparation failed for server_gametypes: ' . $conn->error;
            goto end;
        }
        $stmt->bind_param("i", $server_id);
        $stmt->execute();
        $stmt->close();

        $sql = "DELETE FROM server_maps WHERE server_id = ?";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            $response['error'] = 'SQL preparation failed for server_maps: ' . $conn->error;
            goto end;
        }
        $stmt->bind_param("i", $server_id);
        $stmt->execute();
        $stmt->close();

        $sql = "DELETE FROM map_aliases WHERE server_id = ?";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            $response['error'] = 'SQL preparation failed for map_aliases: ' . $conn->error;
            goto end;
        }
        $stmt->bind_param("i", $server_id);
        $stmt->execute();
        $stmt->close();

        $sql = "DELETE FROM game_servers WHERE id = ?";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            $response['error'] = 'SQL preparation failed for game_servers: ' . $conn->error;
            goto end;
        }
        $stmt->bind_param("i", $server_id);
        if ($stmt->execute()) {
            $response['message'] = 'Server and its associated maps, aliases, and gametypes deleted successfully!';
        } else {
            $response['error'] = 'Error deleting server: ' . $conn->error;
        }
    } elseif (isset($_POST['edit_server_id'])) {
        $server_id = (int)$_POST['edit_server_id'];
        $sql = "SELECT * FROM game_servers WHERE id=?";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            $response['error'] = 'SQL preparation failed for fetching server: ' . $conn->error;
            goto end;
        }
        $stmt->bind_param("i", $server_id);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows > 0) {
            $edit_server = $result->fetch_assoc();
            $response['edit_server'] = $edit_server;
        } else {
            $response['error'] = "Server not found.";
        }
    } elseif (isset($_POST['update_server_id']) && !empty($_POST['update_server_id'])) {
        $conn->begin_transaction(); // Start transaction for updating server
        
        $server_id = (int)$_POST['update_server_id'];
        $server_name = $_POST['server_name'];
        $ip_or_hostname = $_POST['ip_or_hostname'];
        $port = (int)$_POST['port'];
        $rcon_password = $_POST['rcon_password'];

        $sql = "UPDATE game_servers SET server_name=?, ip_or_hostname=?, port=?, rcon_password=? WHERE id=?";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            $response['error'] = 'SQL preparation failed for updating server: ' . $conn->error;
            $conn->rollback(); // Rollback if SQL preparation fails
            goto end;
        }
        $stmt->bind_param("ssisi", $server_name, $ip_or_hostname, $port, $rcon_password, $server_id);
        if ($stmt->execute()) {
            $rconResponse = sendRconCommand($server_id, 'gamename');
            
            if (strpos($rconResponse, 'Error:') !== false) {
                $response['error'] = $rconResponse;
                $conn->rollback(); // Rollback if RCON command fails
                goto end;
            } else {
                $matches = [];
                if (preg_match('/"gamename" is:\s*"([^"]+)/', $rconResponse, $matches)) {
                    $detectedGameName = trim($matches[1]);
                    $detectedGameName = preg_replace('/\^[0-9]/', '', $detectedGameName);
            
                    $server_type = mapGameNameToType($detectedGameName);
                    
                    if ($server_type) {
                        $updateTypeStmt = $conn->prepare("UPDATE game_servers SET server_type = ? WHERE id = ?");
                        $updateTypeStmt->bind_param("si", $server_type, $server_id);
                        if ($updateTypeStmt->execute()) {
                            updateGametypes($conn, $server_id, $server_type);
                            $conn->commit(); // Commit only if everything is successful
                            $response['message'] = 'Server updated successfully with auto-detected type!';
                        } else {
                            $response['error'] = 'Failed to update server type: ' . $updateTypeStmt->error;
                            $conn->rollback(); // Rollback if setting server type fails
                        }
                        $updateTypeStmt->close();
                    } else {
                        $response['error'] = 'Unable to determine server type from game name: ' . $detectedGameName;
                        $conn->rollback(); // Rollback if server type can't be determined
                    }
                } else {
                    $response['error'] = 'Unable to parse game name from RCON response: ' . $rconResponse;
                    $conn->rollback(); // Rollback if game name parsing fails
                }
            }
        } else {
            $response['error'] = 'Error updating server: ' . $stmt->error;
            $conn->rollback(); // Rollback if server update fails
        }
    

    } else {
        // Adding new server
        $conn->begin_transaction(); // Start transaction for adding a new server
        
        $server_name = $_POST['server_name'];
        $ip_or_hostname = $_POST['ip_or_hostname'];
        $port = (int)$_POST['port'];
        $rcon_password = $_POST['rcon_password'];

        $sql = "INSERT INTO game_servers (server_name, ip_or_hostname, port, rcon_password) VALUES (?, ?, ?, ?)";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            $response['error'] = 'SQL preparation failed for adding new server: ' . $conn->error;
            $conn->rollback();
            goto end;
        }
        $stmt->bind_param("ssis", $server_name, $ip_or_hostname, $port, $rcon_password);
        if ($stmt->execute()) {
            $server_id = $conn->insert_id;

            $rconResponse = sendRconCommand($server_id, 'gamename');
            
            if (strpos($rconResponse, 'Error:') !== false) {
                $response['error'] = $rconResponse;
                $conn->rollback(); // Rollback if RCON command fails
                goto end;
            } else {
                $matches = [];
                if (preg_match('/"gamename" is:\s*"([^"]+)/', $rconResponse, $matches)) {
                    $detectedGameName = trim($matches[1]);
                    $detectedGameName = preg_replace('/\^[0-9]/', '', $detectedGameName);
            
                    $server_type = mapGameNameToType($detectedGameName);
                    
                    if ($server_type) {
                        $updateTypeStmt = $conn->prepare("UPDATE game_servers SET server_type = ? WHERE id = ?");
                        $updateTypeStmt->bind_param("si", $server_type, $server_id);
                        if ($updateTypeStmt->execute()) {
                            updateGametypes($conn, $server_id, $server_type);
                            $conn->commit(); // Commit only if everything is successful
                            $response['message'] = 'New server added successfully with auto-detected type!';
                        } else {
                            $response['error'] = 'Failed to set server type for new server: ' . $updateTypeStmt->error;
                            $conn->rollback(); // Rollback if setting server type fails
                        }
                        $updateTypeStmt->close();
                    } else {
                        $response['error'] = 'Unable to determine server type from game name: ' . $detectedGameName;
                        $conn->rollback(); // Rollback if server type can't be determined
                    }
                } else {
                    $response['error'] = 'Unable to parse game name from RCON response: ' . $rconResponse;
                    $conn->rollback(); // Rollback if game name parsing fails
                }
            }
        } else {
            $response['error'] = 'Error adding new server: ' . $stmt->error;
            $conn->rollback(); // Rollback if server insertion fails
        }
    }

    end:
    header('Content-Type: application/json');
    echo json_encode($response);
    exit();
}

$sql = "SELECT * FROM game_servers";
$result = $conn->query($sql);

$game_names = [
    'cod' => 'Call of Duty',
    'cod2' => 'Call of Duty 2',
    'cod4' => 'Call of Duty 4',
    'codwaw' => 'Call of Duty: World at War'
];

function sendRconCommand($serverId, $command) {
    // Implementation should be in 'rcon_functions.php', but here for completeness:
    global $conn;
    
    $stmt = $conn->prepare("SELECT ip_or_hostname, port, rcon_password FROM game_servers WHERE id = ?");
    $stmt->bind_param("i", $serverId);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        $server = $result->fetch_assoc();
        $ip = resolveHostname($server['ip_or_hostname']);
        if (!$ip) {
            return "Error: Unable to resolve hostname.";
        }
        $port = $server['port'];
        $password = $server['rcon_password'];

        $context = stream_context_create(array(
            'socket' => array(
                'timeout' => .5
            )
        ));

        $sock = @stream_socket_client("udp://$ip:$port", $errno, $errstr, 2, STREAM_CLIENT_CONNECT, $context);
        if (!$sock) {
            return "Error: Server is offline or not responding.";
        }

        $command = "\xff\xff\xff\xffrcon " . $password . " " . $command;
        fwrite($sock, $command);

        stream_set_timeout($sock, 1);
        $response = '';
        $readStart = microtime(true);
        while ((microtime(true) - $readStart) < 2) {
            $response .= fread($sock, 2048);
            if (feof($sock)) break; 
        }
        fclose($sock);

        $response = str_replace("\xff\xff\xff\xffprint\n", '', $response);

        return $response;
    } else {
        return "Error: Server not found.";
    }
}

function mapGameNameToType($gameName) {
        // Remove color codes (like ^7) from the game name before comparison
        $gameName = preg_replace('/\^[0-9]/', '', $gameName);
        $gameName = trim($gameName); // Trim any leading/trailing whitespace
    
    $gameMap = [
        'Call of Duty' => 'cod',
        'Call of Duty 2' => 'cod2',
        'Call of Duty 4' => 'cod4',
        'Call of Duty: World at War' => 'codwaw'
    ];
    return $gameMap[$gameName] ?? null;
}

function updateGametypes($conn, $server_id, $server_type) {
    // Clear existing associations from available_gametypes for this server
    $clearAvailableStmt = $conn->prepare("DELETE FROM available_gametypes WHERE server_id = ?");
    $clearAvailableStmt->bind_param("i", $server_id);
    $clearAvailableStmt->execute();
    $clearAvailableStmt->close();

    // Clear existing links from server_gametypes for this server
    $clearServerGametypesStmt = $conn->prepare("DELETE FROM server_gametypes WHERE server_id = ?");
    $clearServerGametypesStmt->bind_param("i", $server_id);
    $clearServerGametypesStmt->execute();
    $clearServerGametypesStmt->close();

    // Fetch and link new gametypes
    $stmt = $conn->prepare("SELECT id, game_type, gamet_alias FROM default_gametypes WHERE server_type = ?");
    $stmt->bind_param("s", $server_type);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        // Insert new associations here 
        $insert_stmt = $conn->prepare("INSERT INTO available_gametypes (game_type, gamet_alias, server_id) VALUES (?, ?, ?)");
        $insert_stmt->bind_param("ssi", $row['game_type'], $row['gamet_alias'], $server_id);
        if ($insert_stmt->execute()) {
            $available_gametype_id = $conn->insert_id;
            $insert_stmt->close();

            $link_stmt = $conn->prepare("INSERT INTO server_gametypes (server_id, available_gametype_id) VALUES (?, ?)");
            $link_stmt->bind_param("ii", $server_id, $available_gametype_id);
            $link_stmt->execute();
            $link_stmt->close();
        } else {
            // Log or handle if insertion fails
            error_log("Failed to insert gametype: " . $conn->error);
        }
    }
    $stmt->close();
}

?>

<!DOCTYPE html>
<html>

<head>
    <title>Call of Duty Simple RCON</title>
    <link rel="stylesheet" type="text/css" href="codrs.css">
    <script type="text/javascript" src="functions/modal.js"></script>
    <script>
        function sendForm(formId) {
            const form = document.getElementById(formId);
            const formData = new FormData(form);

            fetch(form.action, {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.message) {
                        displayMessage(data.message, 'success');
                    } else if (data.error) {
                        displayMessage(data.error, 'error');
                    } else if (data.edit_server) {
                        const editServer = data.edit_server;
                        document.getElementById('update_server_id').value = editServer.id;
                        document.querySelector('[name="server_name"]').value = editServer.server_name;
                        document.querySelector('[name="ip_or_hostname"]').value = editServer.ip_or_hostname;
                        document.querySelector('[name="port"]').value = editServer.port;
                        document.querySelector('[name="rcon_password"]').value = editServer.rcon_password;
                    }
                    refreshServersTable();
                })
                .catch(error => {
                    displayMessage('An error occurred: ' + error, 'error');
                });
        }

        function refreshServersTable() {
            fetch('functions/fetch_servers.php')
                .then(response => response.json())
                .then(data => {
                    if (data.error) {
                        displayMessage(data.error, 'error');
                        return;
                    }

                    const table = document.getElementById('servers_table');
                    let tableContent = `<tr>
                                        <th>Server Name</th>
                                        <th>IP or Hostname</th>
                                        <th>Port</th>
                                        <th>Server Type</th>
                                        <th>Actions</th>
                                    </tr>`;

                    data.servers.forEach(server => {
                        tableContent += `<tr>
                                        <td>${server.server_name}</td>
                                        <td>${server.ip_or_hostname}</td>
                                        <td>${server.port}</td>
                                        <td>${server.server_type}</td>
                                        <td>
                                            <button onclick="editServer(${server.id})">Edit</button>
                                            <button onclick="confirmDelete(${server.id})">Delete</button>
                                        </td>
                                     </tr>`;
                    });

                    table.innerHTML = tableContent;
                    adjustContainerHeight(document.querySelector('.third-container'), table);
                })
                .catch(error => {
                    displayMessage('An error occurred while refreshing the servers table: ' + error, 'error');
                });
        }

        function confirmDelete(serverId) {
            customConfirm("Are you sure you want to delete this server?", function(confirm) {
                if (confirm) {
                    document.getElementById('delete_server_id').value = serverId;
                    sendForm('delete_form');
                }
            });
        }

        function clearOutput() {
            document.getElementById('output_box').innerHTML = '';
        }

        function editServer(serverId) {
            document.getElementById('edit_server_id').value = serverId;
            sendForm('edit_form');
            // Change button text to 'Save' when editing
            document.querySelector('#server_form input[type="submit"]').value = 'Save';
        }

        function cancelEdit() {
            document.getElementById('update_server_id').value = '';
            document.getElementById('server_form').reset();
            // Change button text back to 'Add Server' when cancelling
            document.querySelector('#server_form input[type="submit"]').value = 'Add Server';
            window.location.href = window.location.pathname; // Reload the page to reset the form
        }

        document.addEventListener('DOMContentLoaded', function() {
            var thirdContainer = document.querySelector('.third-container');
            var serversTable = document.getElementById('servers_table');
            adjustContainerHeight(thirdContainer, serversTable);

            // Initial refresh of servers table
            refreshServersTable();
        });

        function adjustContainerHeight(container, table) {
            container.style.height = (table.offsetHeight + 20) + 'px';
        }

        function displayMessage(message, type) {
            var messageContainer = document.getElementById('message_container');
            messageContainer.innerHTML = message;
            messageContainer.className = 'container message ' + type;
            messageContainer.style.display = 'block';
        }
    </script>
</head>

<body>
    <div class="page-container">
        <header>
            <?php include 'header.php'; ?>
        </header>

        <!-- New container for displaying messages -->
        <div class="container message" id="message_container" style="display: none;"></div>

        <div class="container" style="height: auto;">
            <form class="server-form" method="post" id="server_form" onsubmit="sendForm('server_form'); return false;">
                <input type="hidden" name="update_server_id" id="update_server_id" value="">
                <div class="form-group">
                    <label>Server Name:</label>
                    <input type="text" name="server_name" required>
                </div>
                <div class="form-group">
                    <label>IP or Hostname:</label>
                    <input type="text" name="ip_or_hostname" required>
                </div>
                <div class="form-group">
                    <label>Port:</label>
                    <input type="number" name="port" required style="appearance: textfield; -webkit-appearance: none; -moz-appearance: textfield;">
                </div>
                <div class="form-group">
                    <label>RCON Password:</label>
                    <input type="password" name="rcon_password" required>
                </div>
                <input type="submit" value="Add Server">
                <button type="button" onclick="cancelEdit()">Cancel</button>
            </form>
        </div>
        <div class="third-container">
            <table id="servers_table">
                <!-- Server rows will be dynamically added here via JavaScript -->
            </table>
            <form id="delete_form" method="post" style="display:none;">
                <input type="hidden" id="delete_server_id" name="delete_server_id">
            </form>
            <form id="edit_form" method="post" style="display:none;">
                <input type="hidden" id="edit_server_id" name="edit_server_id">
            </form>
        </div>
        <div id="confirm-dialog" style="display: none;">
            <div id="confirm-content">
                <p id="confirm-message"></p>
                <button id="confirm-yes">Yes</button>
                <button id="confirm-no">No</button>
            </div>
        </div>
        <footer>
            <?php include 'footer.php'; ?>
        </footer>
    </div>
</body>

</html>
