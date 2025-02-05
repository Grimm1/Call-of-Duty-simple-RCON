<?php
session_start();
if (!isset($_SESSION['username'])) {
    header("Location: login.php");
    exit();
}

// Fetch server_id from POST or GET, then store in session if it's new or changed
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['server'])) {
    $_SESSION['selected_server_id'] = intval($_POST['server']);
} elseif (isset($_GET['server'])) {
    $_SESSION['selected_server_id'] = intval($_GET['server']);
}

// Handle AJAX request for updating session server
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_session_server'])) {
    $_SESSION['selected_server_id'] = intval($_POST['server']);
    echo json_encode(['success' => true]);
    exit;
}

// Use session data for server selection if available
$selectedServerId = isset($_SESSION['selected_server_id']) ? intval($_SESSION['selected_server_id']) : 0;

include __DIR__ . '/config/database.php';

$sql = "SELECT id, server_name, server_type FROM game_servers";
$result = $conn->query($sql);

if (!$result) {
    die("Error executing query: " . $conn->error);
}

// If no server ID from session, try to get the first server ID
if ($selectedServerId === 0 && $result->num_rows > 0) {
    $selectedServerId = $result->fetch_assoc()['id'];
    $result->data_seek(0); // Reset result pointer
    $aliasQuery = "SELECT map_name, alias FROM map_aliases WHERE server_id = ?";
    $stmt = $conn->prepare($aliasQuery);
    $stmt->bind_param("i", $selectedServerId);
    $stmt->execute();
    $aliasResult = $stmt->get_result();
}

if (isset($_GET['ajax']) && $_GET['ajax'] == 'rotation') {
    $selectedServerId = isset($_GET['server']) ? intval($_GET['server']) : 0;
    $rotationQuery = "SELECT id, name, rotation_string FROM server_map_rotation WHERE server_id = ?";
    $stmt = $conn->prepare($rotationQuery);
    $stmt->bind_param("i", $selectedServerId);
    $stmt->execute();
    $rotationResult = $stmt->get_result();

    $rotations = [];
    while ($rotation = $rotationResult->fetch_assoc()) {
        $rotations[] = $rotation; // Each rotation has an 'id'
    }

    echo json_encode($rotations);
    exit;
}

if (isset($_GET['ajax']) && $_GET['ajax'] == 'delete') {
    $id = isset($_GET['id']) ? intval($_GET['id']) : 0;

    if ($id > 0) {
        $deleteQuery = "DELETE FROM server_map_rotation WHERE id = ?";
        $stmt = $conn->prepare($deleteQuery);
        $stmt->bind_param("i", $id);

        if ($stmt->execute()) {
            echo json_encode(['success' => true, 'message' => 'Rotation deleted successfully']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to delete rotation: ' . $stmt->error]);
        }
    } else {
        echo json_encode(['success' => false, 'message' => 'Invalid ID']);
    }
    exit;
}
if (isset($_GET['ajax']) && $_GET['ajax'] == 'aliases') {
    $selectedServerId = isset($_GET['server']) ? intval($_GET['server']) : 0;

    $aliasQuery = "SELECT map_name, alias FROM map_aliases WHERE server_id = ?";
    $stmt = $conn->prepare($aliasQuery);
    $stmt->bind_param("i", $selectedServerId);
    $stmt->execute();
    $aliasResult = $stmt->get_result();

    $aliases = [];
    while ($row = $aliasResult->fetch_assoc()) {
        $aliases[$row['map_name']] = $row['alias'];
    }

    echo json_encode(['aliases' => $aliases]);
    exit;
}

if (isset($_POST['action']) && $_POST['action'] == 'addRotation') {
    $serverId = intval($_POST['server_id']);
    $insertQuery = "INSERT INTO server_map_rotation (server_id, rotation_string, name) VALUES (?, '', '')";
    $stmt = $conn->prepare($insertQuery);
    $stmt->bind_param("i", $serverId);

    if ($stmt->execute()) {
        $newId = $stmt->insert_id;
        echo json_encode(['success' => true, 'message' => 'New rotation added successfully', 'id' => $newId]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to add rotation']);
    }
    exit;
}

if (isset($_POST['ajax']) && $_POST['ajax'] == 'updateRotation') {
    $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
    $rotationString = isset($_POST['rotation_string']) ? $_POST['rotation_string'] : '';
    $rotationName = isset($_POST['name']) ? $_POST['name'] : '';

    if ($id > 0 && !empty($rotationString)) {
        $updateQuery = "UPDATE server_map_rotation SET rotation_string = ?, name = ? WHERE id = ?";
        $stmt = $conn->prepare($updateQuery);
        $stmt->bind_param("ssi", $rotationString, $rotationName, $id);

        if ($stmt->execute()) {
            echo json_encode(['success' => true, 'message' => 'Rotation updated successfully']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to update rotation: ' . $stmt->error]);
        }
    } else {
        echo json_encode(['success' => false, 'message' => 'Invalid ID or Rotation String']);
    }
    exit;
}

if (isset($_GET['ajax']) && $_GET['ajax'] == 'gametypes') {
    $selectedServerId = isset($_GET['server']) ? intval($_GET['server']) : 0;

    $gametypeQuery = "SELECT game_type, gamet_alias FROM available_gametypes WHERE server_id = ?";
    $stmt = $conn->prepare($gametypeQuery);
    $stmt->bind_param("i", $selectedServerId);
    $stmt->execute();
    $gametypeResult = $stmt->get_result();

    $gametypes = [];
    while ($row = $gametypeResult->fetch_assoc()) {
        $gametypes[$row['game_type']] = $row['gamet_alias'];
    }

    echo json_encode(['gametypes' => $gametypes]);
    exit;
}
if (isset($_POST['action']) && $_POST['action'] == 'addCurrentRotation') {
    $serverId = intval($_POST['server_id']);
    $rotationString = $_POST['rotation_string'];
    $rotationName = 'Default'; // Or any name you want to give to this rotation

    $insertQuery = "INSERT INTO server_map_rotation (server_id, rotation_string, name) VALUES (?, ?, ?)";
    $stmt = $conn->prepare($insertQuery);
    $stmt->bind_param("iss", $serverId, $rotationString, $rotationName);

    if ($stmt->execute()) {
        $newId = $stmt->insert_id;
        echo json_encode(['success' => true, 'message' => 'Current rotation added to database', 'id' => $newId]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to add current rotation']);
    }
    exit;
}

$aliasQuery = "SELECT map_name, alias FROM map_aliases WHERE server_id = ?";
$stmt = $conn->prepare($aliasQuery);
$stmt->bind_param("i", $selectedServerId);
$stmt->execute();
$aliasResult = $stmt->get_result();
$mapNames = [];
while ($row = $aliasResult->fetch_assoc()) {
    $mapNames[$row['map_name']] = $row['alias'];
}

?>



<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Call of Duty Simple RCON - Map Manager</title>
    <link rel="stylesheet" type="text/css" href="codrs.css?v=<?php echo time(); ?>">
    <script src="https://ajax.googleapis.com/ajax/libs/jquery/3.5.1/jquery.min.js"></script>
    <script type="text/javascript" src="functions/modal.js"></script>
    <style>

    </style>
</head>

<body>
    <div class="page-container">
        <header>
            <?php include 'header.php'; ?>
        </header>
        <main class="content">
            <div class="container">
                <form method="post" id="server_form" style="display: flex; align-items: center;">
                    <label for="server">Select Server:</label>
                    <select name="server" id="server" required <?php echo $result->num_rows > 0 ? '' : 'disabled'; ?>>
                        <?php
                        while ($row = $result->fetch_assoc()) {
                            echo "<option value='{$row['id']}' " . ($row['id'] == $selectedServerId ? 'selected' : '') . " data-server-type='{$row['server_type']}'>{$row['server_name']}</option>";
                        }
                        if ($result->num_rows === 0) {
                            echo "<option value=''>No servers available</option>";
                        }
                        ?>
                    </select>
                </form>

                <!-- Placeholder for dynamic content -->
                <div id="rotation-table" style="overflow-x: auto; max-width: 100%;"></div>
                <div class="add-button">
                    <button id="add-rotation">Add New Rotation</button>
                    <button id="get-current-rotation">Get Current Rotation</button>
                </div>
            </div>
            <div class="map-aliases-container" style="float: left;">
                <h3>Available Maps</h3>
                <!-- This table will be populated by JavaScript -->
            </div>
            <div class="map-rotation-editor" style="text-align: center; position: relative;">
                <!-- This will be populated by JavaScript when edit is clicked -->
            </div>
        </main>


        <script>
            document.addEventListener('DOMContentLoaded', function() {
                var serverId = document.getElementById('server').value;
                Promise.all([fetchRotations(serverId), fetchAliases(serverId), fetchGametypes(serverId)]).then(() => {
                    // No need to populate the right table on initial load
                }).catch(error => console.error('Error loading initial data:', error));

                document.getElementById('server').addEventListener('change', function() {
                    var serverId = this.value;
                    Promise.all([fetchRotations(serverId), fetchAliases(serverId), fetchGametypes(serverId)]).then(() => {
                        // Clear the right-hand table content after all data is fetched
                        document.querySelector('.map-rotation-editor').innerHTML = '';
                    });
                });

                document.getElementById('rotation-table').addEventListener('click', function(e) {
                    if (e.target && e.target.matches("button")) {
                        if (e.target.textContent === 'Edit') {
                            var rotationRow = e.target.closest('tr');
                            var rotationString = rotationRow.querySelector('td:nth-child(2)').textContent;
                            var rotationName = rotationRow.querySelector('.name-column').textContent;
                            var rotationId = rotationRow.querySelector('.action-column button[data-id]').getAttribute('data-id');

                            // Store the ID of the rotation being edited
                            document.querySelector('.map-rotation-editor').setAttribute('data-editing-id', rotationId);

                            populateRotationDetails(rotationString, rotationName);
                        } else if (e.target.textContent === 'Delete') {
                            var id = e.target.getAttribute('data-id');
                            deleteRotation(id);
                        }
                    }
                });

                document.getElementById('add-rotation').addEventListener('click', function() {
                    var serverId = document.getElementById('server').value;
                    addRotation(serverId);
                });

                // Event listener for the new delete buttons in the rotation details table
                document.querySelector('.map-rotation-editor').addEventListener('click', function(e) {
                    if (e.target && e.target.matches("button.delete-map")) {
                        removeFromRotation(e.target);
                    }
                });

                document.getElementById('get-current-rotation').addEventListener('click', function() {
                    var serverId = document.getElementById('server').value;
                    fetchCurrentRotation(serverId);
                });
            });

            function fetchCurrentRotation(serverId) {
                var xhr = new XMLHttpRequest();
                xhr.open('POST', 'functions/rcon_functions.php', true);
                xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
                xhr.onload = function() {
                    if (this.status == 200) {
                        var response = this.responseText;
                        processCurrentRotation(response, serverId);
                    } else {
                        console.error('Failed to fetch current rotation:', this.status, this.statusText);
                    }
                };
                xhr.send('server=' + serverId + '&command=sv_mapRotation');
            }

            function processCurrentRotation(response, serverId) {
                // Regular expression to match different formats
                var match = response.match(/"sv_mapRotation" is:\s*"([^"]*)"/);
                if (match && match[1]) {
                    var rotationString = match[1].replace(/\^7$/, '').trim();
                    // Handle cases where 'map' might appear without 'gametype'
                    rotationString = rotationString.replace(/map ([^ ]+)/g, 'gametype dm map $1');

                    var parsedRotation = [];
                    var parts = rotationString.split(' ');

                    for (var i = 0; i < parts.length; i++) {
                        if (parts[i] === 'map') {
                            var map = parts[i + 1];
                            var gametype = 'dm'; // Default to 'dm' if no gametype specified
                            if (i - 2 >= 0 && parts[i - 2] === 'gametype') {
                                gametype = parts[i - 1];
                            }
                            parsedRotation.push({
                                map: map,
                                gametype: gametype
                            });
                        }
                    }

                    if (parsedRotation.length > 0) {
                        addRotationToDatabase(serverId, parsedRotation.map(item => `gametype ${item.gametype} map ${item.map}`).join(' '));
                    } else {
                        console.error('No valid map rotations found in the string:', rotationString);
                    }
                } else {
                    console.error('Could not parse rotation string:', response);
                }
            }

            function addRotationToDatabase(serverId, rotationString) {
                var xhr = new XMLHttpRequest();
                xhr.open('POST', '<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>', true);
                xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
                xhr.onload = function() {
                    if (this.status == 200) {
                        var response = JSON.parse(this.responseText);
                        if (response.success) {
                            console.log('Rotation added to database:', response.message);
                            // After adding to the database, fetch the rotations again to update the table
                            fetchRotations(serverId);
                        } else {
                            console.error('Error adding rotation to database:', response.message);
                        }
                    } else {
                        console.error('Server error while adding rotation to database');
                    }
                };
                xhr.send('action=addCurrentRotation&server_id=' + serverId + '&rotation_string=' + encodeURIComponent(rotationString));
            }

            function fetchRotations(serverId) {
                return new Promise((resolve, reject) => {
                    var xhr = new XMLHttpRequest();
                    xhr.open('GET', '<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>?ajax=rotation&server=' + serverId, true);
                    xhr.onload = function() {
                        if (this.status == 200) {
                            var rotations = JSON.parse(this.responseText);
                            updateRotationsTable(rotations);
                            resolve();
                        } else {
                            console.error('Failed to fetch rotations');
                            reject(new Error('Failed to fetch rotations'));
                        }
                    };
                    xhr.onerror = function() {
                        reject(new Error('Network error'));
                    };
                    xhr.send();
                });
            }

            function deleteRotation(id) {
                var xhr = new XMLHttpRequest();
                xhr.open('GET', '<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>?ajax=delete&id=' + id, true);
                xhr.onload = function() {
                    if (this.status == 200) {
                        var response = JSON.parse(this.responseText);
                        if (response.success) {
                            console.log('Rotation deleted:', response.message);
                            fetchRotations(document.getElementById('server').value); // Refresh table
                        } else {
                            console.error('Error deleting rotation:', response.message);
                        }
                    } else {
                        console.error('Server error while deleting rotation');
                    }
                };
                xhr.send();
            }

            function updateRotationsTable(rotations) {
                var tableHTML = '<table class="map-rotation-table">' +
                    '<thead><tr><th class="name-column">Name</th><th>Rotation</th><th class="action-column">Go</th></tr></thead><tbody>';

                if (rotations.length > 0) {
                    rotations.forEach(function(rotation) {
                        tableHTML += '<tr>' +
                            '<td class="name-column">' + rotation.name + '</td>' +
                            '<td>' + rotation.rotation_string + '</td>' +
                            '<td class="action-column"><button>Edit</button><button data-id="' + rotation.id + '" class="delete-btn">Delete</button></td>' +
                            '</tr>';
                    });
                } else {
                    tableHTML += '<tr><td colspan="3">No rotations available for this server.</td></tr>';
                }

                tableHTML += '</tbody></table>';
                document.getElementById('rotation-table').innerHTML = tableHTML;
            }

            function fetchAliases(serverId) {
                return new Promise((resolve, reject) => {
                    var xhr = new XMLHttpRequest();
                    xhr.open('GET', '<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>?ajax=aliases&server=' + serverId, true);
                    xhr.onload = function() {
                        if (this.status == 200) {
                            var aliases = JSON.parse(this.responseText).aliases;
                            updateAliasesTable(aliases);
                            mapNames = aliases; // Reassigning mapNames with new aliases
                            resolve();
                        } else {
                            console.error('Failed to fetch aliases');
                            reject(new Error('Failed to fetch aliases'));
                        }
                    };
                    xhr.onerror = function() {
                        reject(new Error('Network error'));
                    };
                    xhr.send();
                });
            }

            function updateAliasesTable(aliases) {
                var tableHTML = '<table class="map-aliases-table"><thead><tr><th>Alias</th><th>Go</th></tr></thead><tbody>';

                if (Object.keys(aliases).length > 0) {
                    for (var mapName in aliases) {
                        if (aliases.hasOwnProperty(mapName)) {
                            tableHTML += '<tr>' +
                                '<td>' + aliases[mapName] + '</td>' +
                                '<td><button class="add-to-rotation" data-map="' + mapName + '">➔</button></td>' + // Using ➔ as a right arrow
                                '</tr>';
                        }
                    }
                } else {
                    tableHTML += '<tr><td colspan="2">No map aliases available for this server.</td></tr>';
                }

                tableHTML += '</tbody></table>';
                document.querySelector('.map-aliases-container').innerHTML = '<h3>Map Aliases</h3>' + tableHTML;

                // Add event listener for adding maps to rotation
                document.querySelectorAll('.map-aliases-container .add-to-rotation').forEach(button => {
                    button.addEventListener('click', function() {
                        var mapName = this.getAttribute('data-map');
                        addToRotation(mapName);
                    });
                });
            }

            function fetchGametypes(serverId) {
                return new Promise((resolve, reject) => {
                    var xhr = new XMLHttpRequest();
                    xhr.open('GET', '<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>?ajax=gametypes&server=' + serverId, true);
                    xhr.onload = function() {
                        if (this.status == 200) {
                            var gametypes = JSON.parse(this.responseText).gametypes;
                            updateGametypes(gametypes);
                            resolve();
                        } else {
                            console.error('Failed to fetch gametypes');
                            reject(new Error('Failed to fetch gametypes'));
                        }
                    };
                    xhr.onerror = function() {
                        reject(new Error('Network error'));
                    };
                    xhr.send();
                });
            }

            function updateGametypes(gametypes) {
                window.gametypes = gametypes;
            }

            function populateRotationDetails(rotationString, rotationName) {
                var rotationDetails = parseRotationString(rotationString);
                updateRotationDetailsTable(rotationDetails, rotationName);
            }

            function parseRotationString(rotationString) {
                var details = [];
                var parts = rotationString.split(' ');
                for (var i = 0; i < parts.length; i++) {
                    if (parts[i] === 'map') {
                        var map = parts[i + 1];
                        var gametype;
                        if (i - 2 >= 0 && parts[i - 2] === 'gametype') {
                            gametype = parts[i - 1];
                        } else {
                            gametype = details.length > 0 ? details[details.length - 1].gametype : 'unknown';
                        }
                        details.push({
                            map: map,
                            gametype: gametype
                        });
                    }
                }
                return details;
            }

            function updateRotationDetailsTable(details, rotationName) {
                var tableHTML = '<input type="text" id="rotation-name" value="' + (rotationName || '') + '" placeholder="Enter rotation name" required>' +
                    '<table class="rotation-details-table"><thead><tr><th>Map</th><th>Gametype</th><th>Action</th></tr></thead><tbody>';

                if (details.length > 0) {
                    details.forEach(function(item, index) {
                        var mapAlias = mapNames[item.map] || item.map;
                        if (window.gametypes) {
                            var currentGametype = item.gametype;
                            var gametypeAlias = window.gametypes[currentGametype] || currentGametype;

                            tableHTML += '<tr data-index="' + index + '">' +
                                '<td>' + mapAlias + '</td><td>' +
                                '<select class="gametype-select" data-original="' + currentGametype + '">' +
                                Object.entries(window.gametypes).map(([game_type, alias]) =>
                                    `<option value="${game_type}" ${game_type === currentGametype ? 'selected' : ''}>${alias}</option>`
                                ).join('') +
                                '</select>' +
                                '</td><td>' +
                                (index > 0 ? '<button class="move-up small-button" title="Move Up">↑</button>' : '') +
                                (index < details.length - 1 ? '<button class="move-down small-button" title="Move Down">↓</button>' : '') +
                                '<button class="delete-map small-button">Del</button>' +
                                '</td></tr>';
                        } else {
                            console.warn('Gametypes not yet loaded; using default gametype.');
                            tableHTML += '<tr><td>' + mapAlias + '</td><td>' + item.gametype + '</td><td><button class="delete-map small-button">Delete</button></td></tr>';
                        }
                    });
                } else {
                    tableHTML += '<tr><td colspan="3">No maps in this rotation.</td></tr>';
                }

                tableHTML += '</tbody></table><button id="save-rotation">Save</button>';
                document.querySelector('.map-rotation-editor').innerHTML = tableHTML;

                // Add event listener for save button with name check
                if (document.getElementById('save-rotation')) {
                    document.getElementById('save-rotation').addEventListener('click', function(e) {
                        var rotationNameInput = document.getElementById('rotation-name');
                        if (!rotationNameInput.value.trim()) {
                            customMessage('Please enter a rotation name before saving.');
                            e.preventDefault(); // Prevent saving if name is not provided
                        } else {
                            saveRotationChanges();
                        }
                    });
                }

                // Add event listeners for gametype changes
                document.querySelectorAll('.gametype-select').forEach(select => {
                    select.addEventListener('change', function() {
                        this.setAttribute('data-original', this.value);
                    });
                });

                // Event listeners for move up and move down
                document.querySelector('.map-rotation-editor').addEventListener('click', function(e) {
                    if (e.target && (e.target.matches("button.move-up") || e.target.matches("button.move-down"))) {
                        var direction = e.target.matches("button.move-up") ? -1 : 1;
                        moveRow(e.target, direction);
                    }
                });
            }

            function removeFromRotation(button) {
                var row = button.closest('tr');
                var tableBody = row.parentNode; // Capture the parent before removing the row
                row.remove();

                if (tableBody) { // Check if tableBody exists before calling updateRowIndices
                    updateRowIndices(tableBody);
                }
            }

            function saveRotationChanges() {
                var rightTable = document.querySelector('.rotation-details-table');
                if (!rightTable) {
                    console.error('Rotation details table not found');
                    return;
                }
                var rows = rightTable.querySelectorAll('tbody tr');
                var newRotationString = '';

                rows.forEach(function(row) {
                    if (row.cells && row.cells.length > 1) {
                        var cells = row.cells;
                        var gametypeSelector = cells[1].querySelector('.gametype-select');
                        if (gametypeSelector) {
                            var gametype = gametypeSelector.value;
                            var mapName = Object.keys(mapNames).find(key => mapNames[key] === cells[0].textContent);
                            if (mapName) {
                                newRotationString += 'gametype ' + gametype + ' map ' + mapName + ' ';
                            }
                        }
                    }
                });

                // Store the ID of the rotation being edited when the edit button is clicked
                var rotationId = document.querySelector('.map-rotation-editor').getAttribute('data-editing-id');
                if (!rotationId) {
                    console.error('Could not find rotation ID to update.');
                    return;
                }

                var rotationName = document.getElementById('rotation-name').value;

                var xhr = new XMLHttpRequest();
                xhr.open('POST', '<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>', true);
                xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
                xhr.onload = function() {
                    if (this.status == 200) {
                        var response = JSON.parse(this.responseText);
                        if (response.success) {
                            console.log('Rotation updated:', response.message);
                            fetchRotations(document.getElementById('server').value); // Refresh table
                            document.querySelector('.map-rotation-editor').innerHTML = '';
                        } else {
                            console.error('Error updating rotation:', response.message);
                        }
                    } else {
                        console.error('Server error while updating rotation');
                    }
                };
                xhr.send('ajax=updateRotation&id=' + rotationId + '&rotation_string=' + encodeURIComponent(newRotationString.trim()) + '&name=' + encodeURIComponent(rotationName));
            }

            function addToRotation(mapName) {
                if (!window.gametypes) {
                    console.error('Gametypes not loaded yet');
                    return;
                }

                var rightTableBody = document.querySelector('.rotation-details-table tbody');
                if (!rightTableBody) {
                    console.error('Table body for rotation details not found');
                    return;
                }

                // Check if adding this map would exceed the 1000 character limit
                var currentRotationString = getRotationString();
                var newMapString = 'gametype ' + Object.keys(window.gametypes)[0] + ' map ' + mapName + ' '; // Correct format

                // Console logs for debugging
                //console.log('Current Rotation Length:', currentRotationString.length);
                //console.log('New Map String Length:', newMapString.length);
                //console.log('Total Length:', currentRotationString.length + newMapString.length);
                //1024 limit but we need sv_maprotation at the beginning so 1000 to be safe
                if (currentRotationString.length + newMapString.length > 1000) {
                    customMessage('Adding this map would exceed the 1024 character limit for the rotation string.');
                    return;
                }

                // Check if adding this map would exceed the 32 map limit
                if (rightTableBody.children.length >= 32) {
                    customMessage('Rotation cannot contain more than 32 maps.');
                    return;
                }

                // If we're here, we can add the map
                var gametype = Object.keys(window.gametypes)[0] || 'dm'; // Default to first gametype or 'dm'
                var mapAlias = mapNames[mapName] || mapName; // Use alias or map name

                var newRow = '<tr><td>' + mapAlias + '</td>' +
                    '<td><select class="gametype-select" data-original="' + gametype + '">' +
                    Object.entries(window.gametypes).map(([game_type, alias]) =>
                        `<option value="${game_type}" ${game_type === gametype ? 'selected' : ''}>${alias}</option>`
                    ).join('') +
                    '</select></td>' +
                    '<td><button class="move-up small-button" title="Move Up">↑</button>' +
                    '<button class="move-down small-button" title="Move Down">↓</button>' +
                    '<button class="delete-map small-button">Delete</button></td></tr>';

                rightTableBody.insertAdjacentHTML('beforeend', newRow);
                updateRowIndices(rightTableBody);

                // Reattach event listeners for new elements
                var newRowElement = rightTableBody.lastElementChild;
                if (newRowElement) {
                    var newSelect = newRowElement.querySelector('.gametype-select');
                    if (newSelect) {
                        newSelect.addEventListener('change', function() {
                            this.setAttribute('data-original', this.value);
                        });
                    }

                    // Event listeners for move and delete buttons
                    newRowElement.querySelectorAll('button').forEach(button => {
                        button.addEventListener('click', function(e) {
                            e.preventDefault(); // Prevent default action like form submission or link navigation
                            if (this.classList.contains('move-up')) {
                                moveRow(this, -1);
                            } else if (this.classList.contains('move-down')) {
                                moveRow(this, 1);
                            } else if (this.classList.contains('delete-map')) {
                                removeFromRotation(this);
                            }
                        });
                    });
                }
            }

            function addRotation(serverId) {
                var xhr = new XMLHttpRequest();
                xhr.open('POST', '<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>', true);
                xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
                xhr.onload = function() {
                    if (this.status == 200) {
                        var response = JSON.parse(this.responseText);
                        if (response.success) {
                            console.log('New rotation added:', response.message);
                            fetchRotations(serverId); // Refresh the top table to show the new rotation
                        } else {
                            console.error('Error adding new rotation:', response.message);
                        }
                    } else {
                        console.error('Server error while adding rotation');
                    }
                };
                xhr.send('action=addRotation&server_id=' + serverId);
            }

            // Refresh click listeners for new move buttons
            document.querySelector('.map-rotation-editor').addEventListener('click', function(e) {
                if (e.target && e.target.matches("button.move-up")) {
                    moveRow(e.target, -1);
                } else if (e.target && e.target.matches("button.move-down")) {
                    moveRow(e.target, 1);
                } else if (e.target && e.target.matches("button.delete-map")) {
                    removeFromRotation(e.target);
                }
            }, {
                once: true
            }); // Use once to prevent multiple bindings

            function getRotationString() {
                var rows = document.querySelectorAll('.rotation-details-table tbody tr');
                return Array.from(rows).reduce((str, row) => {
                    var cells = row.cells;
                    if (cells && cells.length > 1) {
                        var gametype = cells[1].querySelector('.gametype-select').value;
                        var mapName = Object.keys(mapNames).find(key => mapNames[key] === cells[0].textContent);
                        if (mapName) {
                            str += 'gametype ' + gametype + ' map ' + mapName + ' '; // Correctly format the string
                        }
                    }
                    return str;
                }, '');
            }


            function moveRow(button, direction) {
                var row = button.closest('tr');
                var tableBody = row ? row.parentNode : null;
                if (!tableBody) return; // Ensure tableBody is not null
                var index = Array.prototype.indexOf.call(tableBody.children, row);
                var newIndex = index + direction;

                // Ensure we're within the bounds of the table
                if (newIndex >= 0 && newIndex < tableBody.children.length) {
                    if (direction === -1) {
                        tableBody.insertBefore(row, tableBody.children[newIndex]);
                    } else {
                        // For moving down, we insert before the next sibling or append if it's the last row
                        var nextSibling = tableBody.children[newIndex];
                        if (nextSibling) {
                            tableBody.insertBefore(row, nextSibling.nextSibling);
                        } else {
                            tableBody.appendChild(row);
                        }
                    }

                    updateRowIndices(tableBody);
                }
            }

            function updateRowIndices(tableBody) {
                if (!tableBody) return;

                var rows = Array.from(tableBody.children || []);
                rows.forEach((row, index) => {
                    if (row && row.cells && row.cells.length > 2) { // Check if row and cells exist
                        row.setAttribute('data-index', index);

                        // Remove existing move buttons to avoid duplication
                        var moveUpButton = row.querySelector('button.move-up');
                        var moveDownButton = row.querySelector('button.move-down');
                        if (moveUpButton) moveUpButton.remove();
                        if (moveDownButton) moveDownButton.remove();

                        // Preserve current cell content
                        var cellContent = row.cells[2].innerHTML;

                        // Insert move buttons in the correct order, while preserving existing content
                        var buttonHtml = '';
                        if (index > 0) {
                            buttonHtml += '<button class="move-up small-button" title="Move Up">↑</button>';
                        }
                        if (index < rows.length - 1) {
                            buttonHtml += '<button class="move-down small-button" title="Move Down">↓</button>';
                        }

                        // Insert the new buttons and preserve the existing delete button and other content
                        row.cells[2].innerHTML = buttonHtml + cellContent;
                    }
                });
            }
        </script>

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

</body>

</html>