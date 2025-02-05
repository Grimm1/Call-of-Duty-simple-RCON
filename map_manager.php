<?php
// Start session for permission checks
session_start();
if (!isset($_SESSION['permissions']) || !isset($_SESSION['permissions']['edit_maps'])) {
    $_SESSION['error_message'] = "You do not have permission to access this page.";
    header("Location: perm_fail.php");
    exit();
}

// Fetch server_id from POST or GET, then store in session if it's new or changed
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['server'])) {
    $_SESSION['selected_server_id'] = $_POST['server'];
} elseif (isset($_GET['server'])) {
    $_SESSION['selected_server_id'] = $_GET['server'];
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_session_server'])) {
    $_SESSION['selected_server_id'] = $_POST['server'];
    echo json_encode(['success' => true]);
    exit;
}

// Use session data for server selection if available
$selectedServerId = isset($_SESSION['selected_server_id']) ? intval($_SESSION['selected_server_id']) : 0;

// Include database configuration
include 'config/database.php';

// Fetch all servers for the dropdown
$sql = "SELECT id, server_name, server_type FROM game_servers";
$result = $conn->query($sql);

// Check if query was successful
if (!$result) {
    echo "Error executing query: " . $conn->error;
    exit;
}

// If no server ID from session, try to get the first server ID
if ($selectedServerId === 0 && $result->num_rows > 0) {
    $selectedServerId = $result->fetch_assoc()['id'];
    $result->data_seek(0); // Reset result pointer
}

// Handle POST requests for map management
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $serverId = $_POST['server'] ?? $selectedServerId; // Use session if not provided in POST
    $serverName = $_POST['hidden_server_name'] ?? '';

    // Determine if this is an AJAX request
    $isAjax = isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

    if (isset($_POST['delete_map'])) {
        $mapName = $_POST['map_name'] ?? '';
        if ($serverId && $mapName) {
            $conn->begin_transaction();
            try {
                // Delete map from server_maps and map_aliases tables
                $stmt = $conn->prepare("DELETE FROM server_maps WHERE server_id = ? AND map_id IN (SELECT id FROM map_aliases WHERE map_name = ? AND server_id = ?)");
                $stmt->bind_param("isi", $serverId, $mapName, $serverId);
                $stmt->execute();

                $stmt = $conn->prepare("DELETE FROM map_aliases WHERE map_name = ? AND server_id = ?");
                $stmt->bind_param("si", $mapName, $serverId);
                $stmt->execute();

                $conn->commit();
                $message = 'Map and its alias removed successfully.';
            } catch (Exception $e) {
                $conn->rollback();
                $message = 'Failed to remove map and alias: ' . $e->getMessage();
            }
        } else {
            $message = 'Server ID or Map name not provided.';
        }
    } elseif (isset($_POST['add_all_maps'])) {
        $serverType = ''; // This should be dynamically set based on server selection
        $stmt = $conn->prepare("SELECT server_type FROM game_servers WHERE id = ?");
        $stmt->bind_param("i", $serverId);
        $stmt->execute();
        $result = $stmt->get_result();
        $serverData = $result->fetch_assoc();
        $serverType = $serverData['server_type'] ?? '';

        if ($serverType) {
            $mapsToAdd = [];
            $stmt = $conn->prepare("SELECT mp_name, map_alias FROM default_maps WHERE server_type = ?");
            $stmt->bind_param("s", $serverType);
            $stmt->execute();
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                $mapsToAdd[$row['mp_name']] = $row['map_alias'];
            }

            if (!empty($mapsToAdd)) {
                foreach ($mapsToAdd as $mapName => $mapAlias) {
                    $stmt = $conn->prepare("SELECT id FROM map_aliases WHERE map_name = ? AND server_id = ?");
                    $stmt->bind_param("si", $mapName, $serverId);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    if ($row = $result->fetch_assoc()) {
                        // Alias exists, add to server_maps
                        $mapId = $row['id'];
                        $stmt = $conn->prepare("INSERT IGNORE INTO server_maps (server_id, map_id) VALUES (?, ?)");
                        $stmt->bind_param("ii", $serverId, $mapId);
                        $stmt->execute();
                    } else {
                        // New alias, insert into map_aliases then add to server_maps
                        $stmt = $conn->prepare("INSERT INTO map_aliases (map_name, alias, server_id) VALUES (?, ?, ?)");
                        $stmt->bind_param("ssi", $mapName, $mapAlias, $serverId);
                        $stmt->execute();
                        $mapId = $conn->insert_id;
                        $stmt = $conn->prepare("INSERT IGNORE INTO server_maps (server_id, map_id) VALUES (?, ?)");
                        $stmt->bind_param("ii", $serverId, $mapId);
                        $stmt->execute();
                    }
                }
                $message = 'Default maps added/updated successfully.';
            }
        } else {
            $message = 'Server type could not be determined for adding default maps.';
        }
    } elseif (isset($_POST['remove_all_maps'])) {
        if ($serverId) {
            $conn->begin_transaction();
            try {
                // Remove all maps for this server
                $stmt = $conn->prepare("DELETE FROM server_maps WHERE server_id = ?");
                $stmt->bind_param("i", $serverId);
                $stmt->execute();

                $stmt = $conn->prepare("DELETE FROM map_aliases WHERE server_id = ?");
                $stmt->bind_param("i", $serverId);
                $stmt->execute();

                $conn->commit();
                $message = 'All maps and their aliases removed successfully for the selected server.';
            } catch (Exception $e) {
                $conn->rollback();
                $message = 'Failed to remove all maps and aliases: ' . $e->getMessage();
            }
        } else {
            $message = 'Server ID not provided for removing all maps and aliases.';
        }
    } else {
        $mapName = $_POST['map_name'] ?? '';
        $mapAlias = $_POST['map_alias'] ?? '';

        if (!empty($serverName) && !empty($mapName) && !empty($mapAlias) && $serverId) {
            $stmt = $conn->prepare("SELECT id FROM map_aliases WHERE map_name = ? AND server_id = ?");
            $stmt->bind_param("si", $mapName, $serverId);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($row = $result->fetch_assoc()) {
                // Map alias exists, add to server_maps
                $mapId = $row['id'];
                $stmt = $conn->prepare("INSERT IGNORE INTO server_maps (server_id, map_id) VALUES (?, ?)");
                $stmt->bind_param("ii", $serverId, $mapId);
                $stmt->execute() ? $message = 'Map association added successfully.' : $message = 'Failed to add map association: ' . $stmt->error;
            } else {
                // New map alias, insert into both tables
                $stmt = $conn->prepare("INSERT INTO map_aliases (map_name, alias, server_id) VALUES (?, ?, ?)");
                $stmt->bind_param("ssi", $mapName, $mapAlias, $serverId);
                if ($stmt->execute()) {
                    $mapId = $conn->insert_id;
                    $stmt = $conn->prepare("INSERT IGNORE INTO server_maps (server_id, map_id) VALUES (?, ?)");
                    $stmt->bind_param("ii", $serverId, $mapId);
                    $stmt->execute() ? $message = 'Map added successfully.' : $message = 'Failed to add map association: ' . $stmt->error;
                } else {
                    $message = 'Failed to insert map alias: ' . $stmt->error;
                }
            }
        } else {
            $message = 'Missing required data.';
        }
    }
    $_SESSION['selected_server_id'] = $serverId;
    // Respond with JSON for AJAX requests, otherwise redirect
    if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'message' => $message]);
        exit;
    } else {
        $redirectUrl = $_SERVER['PHP_SELF'] . '?server=' . urlencode($serverId);
        header("Location: $redirectUrl");
        exit;
    }
    
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_game_type'])) {
    $gameType = $_POST['game_type'] ?? '';
    $gameTypeAlias = $_POST['game_type_alias'] ?? '';
    $serverId = $_POST['server'] ?? null;

    if ($serverId && $gameType && $gameTypeAlias) {
        // Add logic to insert new game type into the database
        // Example:
        $stmt = $conn->prepare("INSERT INTO available_gametypes (game_type, gamet_alias) VALUES (?, ?)");
        $stmt->bind_param("ss", $gameType, $gameTypeAlias);
        if ($stmt->execute()) {
            $gameTypeId = $stmt->insert_id;
            // Link the game type to the server
            $stmt = $conn->prepare("INSERT INTO server_gametypes (server_id, available_gametype_id) VALUES (?, ?)");
            $stmt->bind_param("ii", $serverId, $gameTypeId);
            $stmt->execute();
            $message = 'Game type added successfully.';
        } else {
            $message = 'Failed to add game type: ' . $stmt->error;
        }
    } else {
        $message = 'Server ID, Game Type, or Alias not provided.';
    }
}

// Fetch all servers for the dropdown
$sql = "SELECT id, server_name, server_type FROM game_servers";
$result = $conn->query($sql);

// Determine the selected server ID from GET data if available, otherwise default to the first one
$result->data_seek(0); // Reset result pointer
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Call of Duty Simple RCON - Map Manager</title>
    <link rel="stylesheet" type="text/css" href="codrs.css?v=<?php echo time(); ?>">
    <script type="text/javascript" src="functions/modal.js"></script>
</head>

<body>
    <main class="content">
        <div class="page-container">
            <header>
                <?php include 'header.php'; ?>
            </header>
            <div class="container">
                <?php if (isset($message)) echo "<p>$message</p>"; ?>
                <form method="post" id="server_form" style="display: flex; align-items: center;">
                    <label for="server">Select Server:</label>
                    <select name="server" id="server" required>
                        <?php
                        while ($row = $result->fetch_assoc()) {
                            echo "<option value='{$row['id']}' " . ($row['id'] == $selectedServerId ? 'selected' : '') . ">{$row['server_name']}</option>";
                        }
                        if ($result->num_rows === 0) echo "<option value=''>No servers available</option>";
                        ?>
                    </select>
                </form>
                <form method="post" id="map_form" style="display: flex; flex-direction: column; align-items: center; margin-top: 10px;">
                    <div style="display: flex; align-items: center;">
                        <label for="map_name" style="margin-right: 10px;">Map Name:</label>
                        <input type="text" name="map_name" id="map_name" required>
                        <label for="map_alias" style="margin-left: 10px; margin-right: 10px;">Map Alias:</label>
                        <input type="text" name="map_alias" id="map_alias" required>
                        <button type="submit" id="add_single_map" style="margin-left: 10px;">Add</button>
                    </div>
                    <div style="display: flex; margin-top: 10px;">
                        <button type="button" id="add_all_maps" style="margin-right: 10px;">Add All Default Maps</button>
                        <button type="button" id="remove_all_maps" style="margin-left: 10px;">Remove All</button>
                        <button type="button" class="button-style" onclick="location.href='sra_handler.php'" style="margin-left: 10px;">Import/export</button>
                    </div>
                    <input type="hidden" name="hidden_server_name" id="hidden_server_name" value="<?php echo htmlspecialchars($serverName ?? ''); ?>">
                </form>
                <div id="map_table">
                    <table style="margin-top: 20px;">
                        <thead>
                            <tr>
                                <th>Map Name</th>
                                <th>Alias</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody id="map_list">
                            <!-- This will be populated by AJAX -->
                        </tbody>
                    </table>
                    <button id="expandTable" style="margin-top: 10px;">Show All Maps</button>
                </div>
                <form method="post" id="gametype_form" style="display: flex; flex-direction: column; align-items: center; margin-top: 10px;">
                    <div style="display: flex; align-items: center; margin-top: 10px;">
                        <label for="game_type" style="margin-right: 10px;">Game Type:</label>
                        <input type="text" name="game_type" id="game_type" required>
                        <label for="game_type_alias" style="margin-left: 10px; margin-right: 10px;">Game Type Alias:</label>
                        <input type="text" name="game_type_alias" id="game_type_alias" required>
                        <input type="hidden" name="hidden_server_name" id="hidden_server_name" value="<?php echo htmlspecialchars($serverName ?? ''); ?>">
                        <button type="submit" id="add_single_gametype" style="margin-left: 10px;">Add</button>
                    </div>
                </form>
                <div id="game_type_table">
                    <table style="margin-top: 20px;">
                        <thead>
                            <tr>
                                <th>Game Type</th>
                                <th>Alias</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody id="game_type_list">
                            <!-- This will be populated by AJAX -->
                        </tbody>
                    </table>
                    <button id="expandGameTypes" style="margin-top: 10px;">Show All Game Types</button>
                </div>
            </div>
            <div id="confirm-dialog" style="display: none;">
                <div id="confirm-content">
                    <p id="confirm-message"></p>
                    <button id="confirm-yes">Yes</button>
                    <button id="confirm-no">No</button>
                </div>
            </div>
            <div id="message-dialog" style="display: none;">
                <div id="message-content">
                    <p id="message-text"></p>
                    <button id="message-ok">OK</button>
                </div>
            </div>
            <footer>
                <?php include 'footer.php'; ?>
            </footer>
        </div>
    </main>
    <script>
document.addEventListener('DOMContentLoaded', function() {
    let serverId = <?php echo $selectedServerId; ?>;

    // Event listener for server selection
    document.getElementById('server').addEventListener('change', function() {
        updateHiddenServerNameAndId();
        serverId = this.value; // Update the serverId
        loadMaps(serverId);
        loadGameTypes(serverId);
        
        // AJAX call to update session server ID
        let xhr = new XMLHttpRequest();
        xhr.open('POST', '<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>', true);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        xhr.onload = function() {
            if (this.status == 200) {
                console.log('Server ID updated in session');
            } else {
                console.error('Failed to update server ID in session');
            }
        };
        xhr.send('update_session_server=1&server=' + serverId);
    });
    // Helper function to escape quotes in JavaScript strings
    function escapeJS(str) {
        return str.replace(/'/g, "\\'").replace(/"/g, '\\"');
    }

    // Function to confirm map deletion
    function confirmDeleteMap(mapName, mapAlias, serverId) {
        console.log('confirmDeleteMap called'); // Debug log
        customConfirm(`Are you sure you want to delete the map ${mapAlias}?`, function(confirm) {
            if (confirm) {
                deleteMap(mapName, serverId);
            }
        });
    }

    // Function to delete a map
    function deleteMap(mapName, serverId) {
        let xhr = new XMLHttpRequest();
        xhr.open('POST', 'functions/delete_map.php', true);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        xhr.onload = function() {
            if (this.status == 200) {
                let response = JSON.parse(this.responseText);
                if (!response.error) {
                    let mapList = document.getElementById('map_list');
                    let allMaps = JSON.parse(mapList.getAttribute('data-all-maps'));
                    let newMaps = allMaps.filter(map => map.map_name !== mapName);

                    mapList.setAttribute('data-all-maps', JSON.stringify(newMaps));
                    updateMapList(mapList, newMaps, mapName);
                    updateExpandButton(newMaps);
                    displayAjaxMessage('Map deleted successfully.');
                } else {
                    displayAjaxMessage(response.error);
                }
            } else {
                displayAjaxMessage('Failed to delete map. Status: ' + this.status);
            }
        };
        xhr.send('map_name=' + encodeURIComponent(mapName) + '&server=' + serverId + '&delete_map=1');
    }

    // Function to load maps from server
    function loadMaps(serverId) {
        let xhr = new XMLHttpRequest();
        xhr.open('POST', 'functions/get_maps.php', true);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        xhr.onload = function() {
            if (this.status == 200) {
                try {
                    let allMaps = JSON.parse(this.responseText);
                    let displayedMaps = allMaps.slice(0, 5);
                    let html = displayedMaps.map(map => `
                        <tr>
                            <td>${map.map_name}</td>
                            <td>${map.alias}</td>
                            <td><button class="delete-map" data-map-name="${escapeJS(map.map_name)}" data-map-alias="${escapeJS(map.alias)}">Delete</button></td>
                        </tr>
                    `).join('') || '<tr><td colspan="3">No maps found or error occurred.</td></tr>';

                    document.getElementById('map_list').innerHTML = html;
                    document.getElementById('map_list').setAttribute('data-all-maps', JSON.stringify(allMaps));

                    updateExpandButton(allMaps);

                    // Bind click events dynamically
                    document.querySelectorAll('.delete-map').forEach(button => {
                        button.addEventListener('click', function() {
                            confirmDeleteMap(this.dataset.mapName, this.dataset.mapAlias, serverId);
                        });
                    });
                } catch (e) {
                    console.error('Failed to parse maps:', e);
                    console.error('Server returned:', this.responseText);
                    displayAjaxMessage('Error loading maps.');
                }
            } else {
                console.error('Failed to load maps. Status:', this.status);
                displayAjaxMessage('Failed to load maps. Please try again.');
            }
        };
        xhr.send('server=' + serverId);
    }

    // Function to update the expand button visibility for maps
    function updateExpandButton(allMaps) {
        let expandButton = document.getElementById('expandTable');
        expandButton.style.display = allMaps.length > 5 ? 'block' : 'none';
        expandButton.textContent = 'Show All Maps';
    }

    // Function to update the map list when a map is deleted
    function updateMapList(mapList, newMaps, mapName) {
        let rows = mapList.getElementsByTagName('tr');
        for (let i = 0; i < rows.length; i++) {
            if (rows[i].getElementsByTagName('td')[0].textContent === mapName) {
                mapList.removeChild(rows[i]);
                break;
            }
        }
    }

    // Function to load game types from server
    function loadGameTypes(serverId) {
        let xhr = new XMLHttpRequest();
        xhr.open('POST', 'functions/get_game_types.php', true);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        xhr.onload = function() {
            if (this.status == 200) {
                try {
                    let gameTypes = JSON.parse(this.responseText);
                    let gameTypeList = document.getElementById('game_type_list');
                    gameTypeList.setAttribute('data-all-game-types', JSON.stringify(gameTypes)); 

                    let displayedGameTypes = gameTypes.slice(0, 5);
                    let html = displayedGameTypes.map(gameType => `
                        <tr>
                            <td>${gameType.game_type}</td>
                            <td>${gameType.alias}</td>
                            <td><button class="delete-game-type" data-game-type="${escapeJS(gameType.game_type)}" data-game-type-alias="${escapeJS(gameType.alias)}">Delete</button></td>
                        </tr>
                    `).join('') || '<tr><td colspan="3">No game types found.</td></tr>';
                    gameTypeList.innerHTML = html;

                    updateExpandGameTypesButton(gameTypes);

                    // Bind click events dynamically
                    document.querySelectorAll('.delete-game-type').forEach(button => {
                        button.addEventListener('click', function() {
                            deleteGameType(this.dataset.gameType, this.dataset.gameTypeAlias, serverId);
                        });
                    });
                } catch (e) {
                    console.error('Failed to parse game types:', e);
                    displayAjaxMessage('Error loading game types.');
                }
            } else {
                console.error('Failed to load game types. Status:', this.status);
                displayAjaxMessage('Failed to load game types. Please try again.');
            }
        };
        xhr.send('server=' + serverId);
    }

    // Function to update the expand button visibility for game types
    function updateExpandGameTypesButton(gameTypes) {
        let expandButton = document.getElementById('expandGameTypes');
        expandButton.style.display = gameTypes.length > 5 ? 'block' : 'none';
        expandButton.textContent = 'Show All Game Types';
    }

    // Function to handle adding a single map via AJAX
    document.getElementById('add_single_map').addEventListener('click', function(event) {
        if (!validateForm(event)) {
            event.preventDefault();
        } else {
            updateHiddenServerNameAndId();
            let formData = new FormData(document.getElementById('map_form'));

            let xhr = new XMLHttpRequest();
            xhr.open('POST', '<?php echo $_SERVER['PHP_SELF']; ?>', true);
            xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest'); // Indicates AJAX request

            xhr.onload = function() {
                if (this.status == 200) {
                    try {
                        let response = JSON.parse(this.responseText);
                        if (response.success) {
                            let mapList = document.getElementById('map_list');
                            let allMaps = JSON.parse(mapList.getAttribute('data-all-maps')) || [];
                            let newMap = {
                                map_name: document.getElementById('map_name').value,
                                alias: document.getElementById('map_alias').value
                            };

                            if (!allMaps.some(map => map.map_name === newMap.map_name)) {
                                allMaps.unshift(newMap);
                                mapList.setAttribute('data-all-maps', JSON.stringify(allMaps));

                                let newRow = `<tr>
                                    <td>${newMap.map_name}</td>
                                    <td>${newMap.alias}</td>
                                    <td><button class="delete-map" data-map-name="${escapeJS(newMap.map_name)}" data-map-alias="${escapeJS(newMap.alias)}">Delete</button></td>
                                </tr>`;
                                mapList.insertAdjacentHTML('afterbegin', newRow);

                                updateExpandButton(allMaps);

                                // Update shown maps if not expanded
                                if (document.getElementById('expandTable').textContent === 'Show All Maps') {
                                    let displayedMaps = allMaps.slice(0, 5);
                                    let html = '';
                                    displayedMaps.forEach(map => {
                                        html += `<tr>
                                            <td>${map.map_name}</td>
                                            <td>${map.alias}</td>
                                            <td><button class="delete-map" data-map-name="${escapeJS(map.map_name)}" data-map-alias="${escapeJS(map.alias)}">Delete</button></td>
                                        </tr>`;
                                    });
                                    mapList.innerHTML = html;
                                }
                                displayAjaxMessage('Map added successfully.');

                                // Rebind events for the new row
                                document.querySelectorAll('.delete-map').forEach(button => {
                                    button.addEventListener('click', function() {
                                        confirmDeleteMap(this.dataset.mapName, this.dataset.mapAlias, serverId);
                                    });
                                });
                            } else {
                                displayAjaxMessage('Map already exists.');
                            }
                        } else {
                            displayAjaxMessage(response.message || 'Failed to add map.');
                        }
                    } catch (e) {
                        console.error('Failed to parse response:', e);
                        console.error('Server returned:', this.responseText);
                    }
                } else {
                    console.error('Failed to add map. Status:', this.status);
                    displayAjaxMessage('Error adding map. Please try again.');
                }
            };
            xhr.send(formData);
            event.preventDefault(); // Prevent form from submitting traditionally
        }
    });

    // Event listener for expanding/collapsing the table for maps
    document.getElementById('expandTable').addEventListener('click', function() {
        let mapList = document.getElementById('map_list');
        let allMaps = JSON.parse(mapList.getAttribute('data-all-maps'));

        if (this.textContent === 'Show All Maps') {
            let html = '';
            allMaps.forEach(map => {
                html += `<tr>
                    <td>${map.map_name}</td>
                    <td>${map.alias}</td>
                    <td><button class="delete-map" data-map-name="${escapeJS(map.map_name)}" data-map-alias="${escapeJS(map.alias)}">Delete</button></td>
                </tr>`;
            });
            mapList.innerHTML = html;
            this.textContent = 'Show Less';
        } else {
            loadMaps(serverId); // Reset to initial state with AJAX call
            this.textContent = 'Show All Maps';
        }

        // Rebind events after changing the HTML
        document.querySelectorAll('.delete-map').forEach(button => {
            button.addEventListener('click', function() {
                confirmDeleteMap(this.dataset.mapName, this.dataset.mapAlias, serverId);
            });
        });
    });

    // Event listener for expanding/collapsing the table for game types
    document.getElementById('expandGameTypes').addEventListener('click', function() {
        let gameTypeList = document.getElementById('game_type_list');
        let allGameTypes = JSON.parse(gameTypeList.getAttribute('data-all-game-types'));

        if (this.textContent === 'Show All Game Types') {
            let html = '';
            allGameTypes.forEach(gameType => {
                html += `<tr>
                    <td>${gameType.game_type}</td>
                    <td>${gameType.alias}</td>
                    <td><button class="delete-game-type" data-game-type="${escapeJS(gameType.game_type)}" data-game-type-alias="${escapeJS(gameType.alias)}">Delete</button></td>
                </tr>`;
            });
            gameTypeList.innerHTML = html;
            this.textContent = 'Show Less';
        } else {
            loadGameTypes(serverId); // Reset to initial state with AJAX call
            this.textContent = 'Show All Game Types';
        }

        // Rebind events after changing the HTML
        document.querySelectorAll('.delete-game-type').forEach(button => {
            button.addEventListener('click', function() {
                deleteGameType(this.dataset.gameType, this.dataset.gameTypeAlias, serverId);
            });
        });
    });

    // Function to add a single game type
    document.getElementById('add_single_gametype').addEventListener('click', function(event) {
        event.preventDefault(); // Prevent the form from submitting normally

        let gameType = document.getElementById('game_type').value;
        let gameTypeAlias = document.getElementById('game_type_alias').value;

        if (!gameType || !gameTypeAlias) {
            displayAjaxMessage('Please fill all fields.');
            return;
        }

        let xhr = new XMLHttpRequest();
        xhr.open('POST', 'functions/add_game_type.php', true);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');

        xhr.onload = function() {
            if (this.status == 200) {
                let response = JSON.parse(this.responseText);
                if (response.success) {
                    // Clear inputs after successful addition
                    document.getElementById('game_type').value = '';
                    document.getElementById('game_type_alias').value = '';

                    // Update the game types table
                    loadGameTypes(serverId);
                    displayAjaxMessage('Game type added successfully.');
                } else {
                    displayAjaxMessage(response.message || 'Failed to add game type.');
                }
            } else {
                console.error('Failed to add game type. Status:', this.status);
                displayAjaxMessage('An error occurred while adding the game type.');
            }
        };

        xhr.send(`game_type=${encodeURIComponent(gameType)}&game_type_alias=${encodeURIComponent(gameTypeAlias)}&server=${serverId}`);
    });

    // Function for showing AJAX messages
    function displayAjaxMessage(message) {
        showAjaxMessage(message, function() {
            // Optionally reload maps or game types if needed after message is dismissed
            // loadMaps(serverId);
            // loadGameTypes(serverId);
        });
    }

    // Helper function to update hidden form fields
    function updateHiddenServerNameAndId() {
        let serverSelect = document.getElementById('server');
        let hiddenServerName = document.getElementById('hidden_server_name');
        let hiddenServerId = document.getElementById('hidden_server_id') || document.createElement('input');

        if (serverSelect && hiddenServerName) {
            hiddenServerName.value = serverSelect.options[serverSelect.selectedIndex].text;

            hiddenServerId.type = 'hidden';
            hiddenServerId.name = 'server';
            hiddenServerId.id = 'hidden_server_id';
            hiddenServerId.value = serverSelect.value;

            if (!document.getElementById('hidden_server_id')) {
                document.getElementById('map_form').appendChild(hiddenServerId);
            }
        }
    }

    // Validate form inputs
    function validateForm(event) {
        let mapName = document.getElementById('map_name');
        let mapAlias = document.getElementById('map_alias');

        if (!mapName.value || !mapAlias.value) {
            displayAjaxMessage("Please fill all fields.");
            return false;
        }
        return true;
    }

    // Function to delete a game type
    function deleteGameType(gameType, gameTypeAlias, serverId) {
        console.log('deleteGameType called'); // Debug log
        customConfirm(`Are you sure you want to delete the game type ${gameTypeAlias}?`, function(confirm) {
            if (confirm) {
                let xhr = new XMLHttpRequest();
                xhr.open('POST', 'functions/delete_game_type.php', true);
                xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
                xhr.onload = function() {
                    if (this.status == 200) {
                        let response = JSON.parse(this.responseText);
                        if (response.success) {
                            let gameTypeList = document.getElementById('game_type_list');
                            let allGameTypes = JSON.parse(gameTypeList.getAttribute('data-all-game-types'));
                            let newGameTypes = allGameTypes.filter(type => type.game_type !== gameType);

                            gameTypeList.setAttribute('data-all-game-types', JSON.stringify(newGameTypes));
                            updateGameTypeList(gameTypeList, newGameTypes, gameType);
                            updateExpandGameTypesButton(newGameTypes);
                            displayAjaxMessage(response.message || 'Game type deleted successfully.');
                        } else {
                            displayAjaxMessage(response.error || 'Failed to delete game type.');
                        }
                    } else {
                        console.error('Failed to delete game type. Status:', this.status);
                        displayAjaxMessage('An error occurred while deleting the game type.');
                    }
                };
                xhr.send('game_type=' + encodeURIComponent(gameType) + '&server=' + serverId);
            }
        });
    }

    // Function to update the game type list when a game type is deleted
    function updateGameTypeList(gameTypeList, newGameTypes, gameType) {
        let rows = gameTypeList.getElementsByTagName('tr');
        for (let i = 0; i < rows.length; i++) {
            if (rows[i].getElementsByTagName('td')[0].textContent === gameType) {
                gameTypeList.removeChild(rows[i]);
                break;
            }
        }
    }

    // Event listener for server selection
    document.getElementById('server').addEventListener('change', function() {
        updateHiddenServerNameAndId();
        serverId = this.value; // Update the serverId
        loadMaps(serverId);
        loadGameTypes(serverId);
    });

    // Load initial maps and game types
    loadMaps(serverId);
    loadGameTypes(serverId);

    document.getElementById('add_all_maps').addEventListener('click', function(event) {
        customConfirm("Are you sure you want to add all default maps?", function(confirm) {
            if (confirm) {
                updateHiddenServerNameAndId();
                let hiddenField = document.createElement('input');
                hiddenField.type = 'hidden';
                hiddenField.name = 'add_all_maps';
                hiddenField.value = 'true';
                document.getElementById('map_form').appendChild(hiddenField);
                document.getElementById('map_form').submit();
            }
        });
    });

    document.getElementById('remove_all_maps').addEventListener('click', function(event) {
        customConfirm("Are you sure you want to remove all maps from the server?", function(confirm) {
            if (confirm) {
                updateHiddenServerNameAndId();
                let hiddenField = document.createElement('input');
                hiddenField.type = 'hidden';
                hiddenField.name = 'remove_all_maps';
                hiddenField.value = 'true';
                document.getElementById('map_form').appendChild(hiddenField);
                document.getElementById('map_form').submit();
            }
        });
    });
});
</script>
</body>