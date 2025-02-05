<?php

error_reporting(E_ALL);
ini_set('display_errors', 1);


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
        $server_id = (int)$_POST['delete_server_id']; // Cast to integer

        // Before deleting the server from game_servers
        $sql = "DELETE FROM server_gametypes WHERE server_id = ?";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            $response['error'] = 'SQL preparation failed for server_gametypes: ' . $conn->error;
            goto end;
        }
        $stmt->bind_param("i", $server_id);
        $stmt->execute();
        $stmt->close();

        // Remove all maps associated with the server from server_maps
        $sql = "DELETE FROM server_maps WHERE server_id = ?";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            $response['error'] = 'SQL preparation failed for server_maps: ' . $conn->error;
            goto end;
        }
        $stmt->bind_param("i", $server_id);
        $stmt->execute();
        $stmt->close();

        // Remove map aliases associated with the server
        $sql = "DELETE FROM map_aliases WHERE server_id = ?";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            $response['error'] = 'SQL preparation failed for map_aliases: ' . $conn->error;
            goto end;
        }
        $stmt->bind_param("i", $server_id);
        $stmt->execute();
        $stmt->close();

        // Then, delete the server from game_servers
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
        $server_id = (int)$_POST['edit_server_id']; // Cast to integer
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
        $server_id = (int)$_POST['update_server_id']; // Cast to integer
        $server_name = $_POST['server_name'];
        $ip_or_hostname = $_POST['ip_or_hostname'];
        $port = (int)$_POST['port']; // Ensure port is an integer
        $rcon_password = $_POST['rcon_password'];
        $server_type = $_POST['server_type'];
    
        $sql = "UPDATE game_servers SET server_name=?, ip_or_hostname=?, port=?, rcon_password=?, server_type=? WHERE id=?";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            $response['error'] = 'SQL preparation failed for updating server: ' . $conn->error;
            goto end;
        }
        $stmt->bind_param("ssissi", $server_name, $ip_or_hostname, $port, $rcon_password, $server_type, $server_id);
        if ($stmt->execute()) {
            // Clear existing gametype associations for this server
            $clear_stmt = $conn->prepare("DELETE FROM server_gametypes WHERE server_id = ?");
            if (!$clear_stmt) {
                $response['error'] = 'SQL preparation failed for clearing gametypes: ' . $conn->error;
                goto end;
            }
            $clear_stmt->bind_param("i", $server_id);
            $clear_stmt->execute();
            $clear_stmt->close();
    
            // Also clear from available_gametypes for this server
            $clear_avail_stmt = $conn->prepare("DELETE FROM available_gametypes WHERE server_id = ?");
            if (!$clear_avail_stmt) {
                $response['error'] = 'SQL preparation failed for clearing available gametypes: ' . $conn->error;
                goto end;
            }
            $clear_avail_stmt->bind_param("i", $server_id);
            $clear_avail_stmt->execute();
            $clear_avail_stmt->close();
    
            // Fetch and link new gametypes for updated server type
            $stmt = $conn->prepare("SELECT id, game_type, gamet_alias FROM default_gametypes WHERE server_type = ?");
            if (!$stmt) {
                $response['error'] = 'SQL preparation failed for fetching default gametypes: ' . $conn->error;
                goto end;
            }
            $stmt->bind_param("s", $server_type);
            $stmt->execute();
            $result = $stmt->get_result();
    
            while ($row = $result->fetch_assoc()) {
                // Insert into available_gametypes without checking for existing entries
                $insert_stmt = $conn->prepare("INSERT INTO available_gametypes (game_type, gamet_alias, server_id) VALUES (?, ?, ?)");
                if (!$insert_stmt) {
                    $response['error'] = 'SQL preparation failed for inserting new gametype: ' . $conn->error;
                    goto end;
                }
                $insert_stmt->bind_param("ssi", $row['game_type'], $row['gamet_alias'], $server_id);
                $insert_stmt->execute();
                $available_gametype_id = $conn->insert_id;
                $insert_stmt->close();
    
                // Link the server to this new available gametype
                $link_stmt = $conn->prepare("INSERT INTO server_gametypes (server_id, available_gametype_id) VALUES (?, ?)");
                if (!$link_stmt) {
                    $response['error'] = 'SQL preparation failed for linking gametypes: ' . $conn->error;
                    goto end;
                }
                $link_stmt->bind_param("ii", $server_id, $available_gametype_id);
                $link_stmt->execute();
                $link_stmt->close();
            }
            $stmt->close();
    
            $response['message'] = 'Server updated successfully!';
        } else {
            $response['error'] = 'Error updating server: ' . $stmt->error;
        }
    
    } else {
        $server_name = $_POST['server_name'];
        $ip_or_hostname = $_POST['ip_or_hostname'];
        $port = (int)$_POST['port']; // Ensure port is an integer
        $rcon_password = $_POST['rcon_password'];
        $server_type = $_POST['server_type'];

        $sql = "INSERT INTO game_servers (server_name, ip_or_hostname, port, rcon_password, server_type) VALUES (?, ?, ?, ?, ?)";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            $response['error'] = 'SQL preparation failed for adding new server: ' . $conn->error;
            goto end;
        }
        $stmt->bind_param("ssiss", $server_name, $ip_or_hostname, $port, $rcon_password, $server_type);
        if ($stmt->execute()) {
            $server_id = $conn->insert_id;

            // Fetch and link gametypes for new server type
            $stmt = $conn->prepare("SELECT id, game_type, gamet_alias FROM default_gametypes WHERE server_type = ?");
            if (!$stmt) {
                $response['error'] = 'SQL preparation failed for fetching default gametypes: ' . $conn->error;
                goto end;
            }
            $stmt->bind_param("s", $server_type);
            $stmt->execute();
            $result = $stmt->get_result();

            while ($row = $result->fetch_assoc()) {
                // Check if this gametype is already in available_gametypes for this server
                $check_stmt = $conn->prepare("SELECT id FROM available_gametypes WHERE game_type = ? AND gamet_alias = ? AND server_id = ?");
                if (!$check_stmt) {
                    $response['error'] = 'SQL preparation failed for checking available gametypes: ' . $conn->error;
                    goto end;
                }
                $check_stmt->bind_param("ssi", $row['game_type'], $row['gamet_alias'], $server_id);
                $check_stmt->execute();
                $check_result = $check_stmt->get_result();

                if ($check_result->num_rows == 0) {
                    // Insert into available_gametypes if not present
                    $insert_stmt = $conn->prepare("INSERT INTO available_gametypes (game_type, gamet_alias, server_id) VALUES (?, ?, ?)");
                    if (!$insert_stmt) {
                        $response['error'] = 'SQL preparation failed for inserting new gametype: ' . $conn->error;
                        goto end;
                    }
                    $insert_stmt->bind_param("ssi", $row['game_type'], $row['gamet_alias'], $server_id);
                    $insert_stmt->execute();
                    $available_gametype_id = $conn->insert_id;
                    $insert_stmt->close();
                } else {
                    // Use existing id if already present
                    $available_gametype_row = $check_result->fetch_assoc();
                    $available_gametype_id = $available_gametype_row['id'];
                }

                // Link the server to this available gametype
                $link_stmt = $conn->prepare("INSERT INTO server_gametypes (server_id, available_gametype_id) VALUES (?, ?)");
                if (!$link_stmt) {
                    $response['error'] = 'SQL preparation failed for linking gametypes: ' . $conn->error;
                    goto end;
                }
                $link_stmt->bind_param("ii", $server_id, $available_gametype_id);
                $link_stmt->execute();

                // Clean up statements
                $check_stmt->close();
                $link_stmt->close();
            }
            $stmt->close();

            $response['message'] = 'New server added successfully!';
        } else {
            $response['error'] = 'Error: ' . $stmt->error;
        }
    }

    if (
        !isset($response['error']) && !isset($response['message']) &&
        !isset($_POST['edit_server_id']) && !isset($_POST['update_server_id'])
    ) {
        $response['error'] = 'Missing server ID or map name';
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
    .then(response => {
        console.log('Response status:', response.status);
        return response.text(); // Retrieve the raw response text
    })
    .then(text => {
        console.log('Raw response:', text);
        try {
            // Parse the JSON response
            const data = JSON.parse(text);
            
            if (data.message) {
                displayMessage(data.message, 'success');
            } else if (data.error) {
                displayMessage(data.error, 'error');
            } else if (data.edit_server) {
                const editServer = data.edit_server;
                // Update form fields with server data for editing
                document.getElementById('update_server_id').value = editServer.id;
                document.querySelector('[name="server_name"]').value = editServer.server_name;
                document.querySelector('[name="ip_or_hostname"]').value = editServer.ip_or_hostname;
                document.querySelector('[name="port"]').value = editServer.port;
                document.querySelector('[name="rcon_password"]').value = editServer.rcon_password;
                document.querySelector('[name="server_type"]').value = editServer.server_type;
            }
            
            // Refresh the servers table after any operation
            refreshServersTable();
        } catch (e) {
            // Log parsing errors
            console.error('Failed to parse JSON:', e);
            // Display a user-friendly error message with part of the raw response
            displayMessage('Server response could not be parsed: ' + text.slice(0, 200) + '...', 'error');
        }
    })
    .catch(error => {
        // Handle network or fetch errors
        console.error('Fetch error:', error);
        displayMessage('An error occurred: ' + error.message, 'error');
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
                <div class="form-group">
                    <label>Server Type:</label>
                    <select name="server_type" required>
                        <?php foreach ($game_names as $key => $name): ?>
                            <option value="<?php echo $key; ?>"><?php echo $name; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <input type="submit" value="Add Server">
                <button type="button" onclick="cancelEdit()">Cancel</button>
            </form>
        </div>
        <div class="third-container">
            <table id="servers_table">
                <tr>
                    <th>Server Name</th>
                    <th>IP or Hostname</th>
                    <th>Port</th>
                    <th>Server Type</th>
                    <th>Actions</th>
                </tr>
                <!-- Server rows will be dynamically added here -->
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
