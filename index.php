<?php
session_start();
if (!isset($_SESSION['username'])) {
    header("Location: login.php");
    exit();
}

// Fetch server_id from POST or GET, then store in session if it's new or changed
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['server'])) {
    $_SESSION['selected_server_id'] = $_POST['server'];
} elseif (isset($_GET['server'])) {
    $_SESSION['selected_server_id'] = $_GET['server'];
}

// Handle AJAX request for updating session server
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_session_server'])) {
    $_SESSION['selected_server_id'] = $_POST['server'];
    echo json_encode(['success' => true]);
    exit;
}

// Use session data for server selection if available
$selectedServerId = isset($_SESSION['selected_server_id']) ? intval($_SESSION['selected_server_id']) : 0;

// Attempt to include and connect to the database
try {
    include __DIR__ . '/config/database.php';
    
    // Check if connection was successful
    if (!$conn) {
        throw new Exception("Database connection failed.");
    }
    
    $sql = "SELECT id, server_name, ip_or_hostname, port, rcon_password, server_type FROM game_servers";
    $result = $conn->query($sql);

    // If no server ID from session, try to get the first server ID
    if ($selectedServerId === 0 && $result->num_rows > 0) {
        $selectedServerId = $result->fetch_assoc()['id'];
        $result->data_seek(0); // Reset result pointer
    }
} catch (Exception $e) {
    // Redirect to installation page if database access fails
    header("Location: /utils/install.php");
    exit();
}

// Use session data for server selection if available
$selectedServerId = isset($_SESSION['selected_server_id']) ? intval($_SESSION['selected_server_id']) : 0;

include __DIR__ . '/config/database.php';


$sql = "SELECT id, server_name, ip_or_hostname, port, rcon_password, server_type FROM game_servers";
$result = $conn->query($sql);

// If no server ID from session, try to get the first server ID
if ($selectedServerId === 0 && $result->num_rows > 0) {
    $selectedServerId = $result->fetch_assoc()['id'];
    $result->data_seek(0); // Reset result pointer
}
?>

<!DOCTYPE html>
<html lang="en">
<title>Call of Duty Simple RCON</title>
<link rel="stylesheet" type="text/css" href="codrs.css?v=<?php echo time(); ?>">
<script src="https://ajax.googleapis.com/ajax/libs/jquery/3.5.1/jquery.min.js"></script>
<script type="text/javascript" src="functions/modal.js"></script>

<script>
    // Function to replace color codes with HTML spans
    function colorizeName(name, isHTML = true) {
        // Remove 'is:' and 'default:' before processing color codes if present
        name = name.replace(/^is:\s*/, '').replace(/default:.*$/, '');

        let result = name.replace(/\^([0-9])/g, function(match, colorCode) {
            var colors = {
                '0': 'black',
                '1': 'red',
                '2': 'green',
                '3': 'yellow',
                '4': 'blue',
                '5': 'cyan',
                '6': 'purple',
                '7': 'white',
                '8': 'orange',
                '9': 'grey'
            };
            return isHTML ? '<span style="color:' + colors[colorCode] + ';">' : '';
        }).replace(/\^x([0-9a-fA-F]{6})/g, function(match, hexColor) {
            return isHTML ? '<span style="color:#' + hexColor + ';">' : '';
        });

        if (isHTML) {
            result += '</span>'.repeat((name.match(/\^([0-9]|x[0-9a-fA-F]{6})/g) || []).length);
        }

        return result;
    }

    let autoRefreshInterval = null;

    function refreshData() {
        var serverId = document.getElementById('server').value;
        queryServerInfo(serverId);
    }

    function toggleAutoRefresh() {
        const autoRefreshCheckbox = document.getElementById('auto_refresh');
        const refreshTime = document.getElementById('refresh_time').value;

        if (autoRefreshCheckbox.checked) {
            if (autoRefreshInterval) {
                clearInterval(autoRefreshInterval);
            }
            autoRefreshInterval = setInterval(refreshData, refreshTime * 1000);
        } else {
            if (autoRefreshInterval) {
                clearInterval(autoRefreshInterval);
                autoRefreshInterval = null;
            }
        }
    }

    function sendRconCommand(event) {
        event.preventDefault();
        var serverId = document.getElementById('server').value;
        var command = document.getElementById('command').value;
        document.getElementById('hidden_server').value = serverId; // Set the hidden field

        var xhr = new XMLHttpRequest();
        xhr.open('POST', 'functions/rcon_functions.php', true);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        xhr.onreadystatechange = function() {
            if (this.readyState == 4 && this.status == 200) {
                document.getElementById('output_box').textContent = this.responseText;
            }
        };
        xhr.send('server=' + serverId + '&command=' + encodeURIComponent(command));
    }

    function populateGametypeSelect() {
        var serverId = document.getElementById('server').value;
        var xhr = new XMLHttpRequest();
        xhr.open('POST', 'functions/getGametypesForServer.php', true);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        xhr.onreadystatechange = function() {
            if (this.readyState == 4 && this.status == 200) {
                var gametypes = JSON.parse(this.responseText);
                var gametypeSelect = document.getElementById('gametype_select');
                gametypeSelect.innerHTML = ''; // Clear existing options

                for (var gametype of gametypes) {
                    var option = document.createElement("option");
                    option.value = gametype.game_type; // Gametype for RCON command
                    option.text = gametype.gamet_alias; // Alias for display
                    gametypeSelect.add(option);
                }
            }
        };
        xhr.send('serverId=' + serverId);
    }

    // Function to change gametype
    function changeGametype() {
        var serverId = document.getElementById('server').value;
        var gametypeSelect = document.getElementById('gametype_select');
        var selectedGametype = gametypeSelect.value;

        customConfirm("Change the gametype to " + gametypeSelect.options[gametypeSelect.selectedIndex].text + "?", function(confirmed) {
            if (confirmed) {
                $.ajax({
                    type: 'POST',
                    url: 'functions/rcon_functions.php',
                    data: {
                        'server': serverId,
                        'command': 'g_gametype ' + selectedGametype
                    },
                    success: function(response) {
                        queryServerInfo(serverId); // Refresh server info
                        customMessage("Map restart required", null); // Show message
                    },
                    error: function(xhr, status, error) {
                        console.error('An AJAX error occurred: ' + error);
                        customMessage('Failed to change gametype. Please try again.');
                    }
                });
            }
        });
    }

    function queryServerInfo(serverId) {
        var xhr = new XMLHttpRequest();
        xhr.open('POST', 'functions/queryCodServer.php', true);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        xhr.onreadystatechange = function() {
            if (this.readyState == 4 && this.status == 200) {
                var response = JSON.parse(this.responseText);

                // Update player table
                var playerTableBody = document.getElementById('player_table_body');
                playerTableBody.innerHTML = ''; // Clear existing rows

                var kickPlayerSelect = document.getElementById('kick_player_select');
                kickPlayerSelect.innerHTML = '<option value="">Select Player</option>'; // Reset dropdown

                for (var playerId in response.players) {
                    var player = response.players[playerId];
                    var row = playerTableBody.insertRow();
                    row.insertCell(0).textContent = playerId;

                    // Remove quotation marks and apply color codes
                    var name = player.name.replace(/"/g, '').trim();
                    var coloredName = colorizeName(name);

                    // Insert colored name into the table
                    var nameCell = row.insertCell(1);
                    nameCell.innerHTML = coloredName;
                    row.insertCell(2).textContent = player.score;
                    row.insertCell(3).textContent = player.ping;

                    // Strip color codes for the dropdown
                    var plainName = colorizeName(name, false);

                    // Add player ID and name to the kick dropdown
                    var option = document.createElement("option");
                    option.text = playerId + " - " + plainName;
                    option.value = playerId;
                    kickPlayerSelect.add(option);
                }

                // Display the server hostname with color codes
                var serverNameElement = document.getElementById('server_name_display');
                if (response.serverInfo && response.serverInfo.sv_hostname) {
                    serverNameElement.innerHTML = colorizeName(response.serverInfo.sv_hostname);
                } else {
                    serverNameElement.innerHTML = 'Server Name Not Available';
                }

                // Handle map display
                if (response.serverInfo && response.serverInfo.mapname) {
                    var mapName = response.serverInfo.mapname;
                    var serverType = document.getElementById('server').options[document.getElementById('server').selectedIndex].getAttribute('data-server-type');

                    // AJAX call to get map alias
                    var mapAliasXhr = new XMLHttpRequest();
                    mapAliasXhr.open('POST', 'functions/getMapAlias.php', true);
                    mapAliasXhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
                    mapAliasXhr.onload = function() {
                        if (this.status == 200) {
                            var alias = this.responseText || mapName; // If no alias, use mapname
                            document.getElementById('current_map').textContent = alias;

                            // Update map image
                            var mapImageContainer = document.querySelector('.map-image-container');
                            var mapImage = mapImageContainer.querySelector('img');
                            var fallbackImage = 'gamevars/fallback_map.png';
                            var mapImageSrc = 'gamevars/map_images/' + serverType + '/' + mapName + '.png';

                            mapImage.src = mapImageSrc;
                            mapImageContainer.style.backgroundImage = "url('" + mapImageSrc + "')";
                            mapImage.alt = "Current Map: " + alias;

                            // Check if the map image exists, if not, revert to fallback
                            var img = new Image();
                            img.onload = function() {
                                // Image exists
                            };
                            img.onerror = function() {
                                mapImage.src = fallbackImage;
                                mapImageContainer.style.backgroundImage = "url('" + fallbackImage + "')";
                            };
                            img.src = mapImageSrc; // This will trigger the load or error event
                        }
                    };
                    mapAliasXhr.send('serverId=' + serverId + '&mapname=' + encodeURIComponent(mapName));

                    // Display the game name
                    if (response.serverInfo && response.serverInfo.gamename) {
                        document.getElementById('current_game_name').textContent = response.serverInfo.gamename;
                    } else {
                        document.getElementById('current_game_name').textContent = "Not Available";
                    }
                }

                // Handle gametype display
                if (response.serverInfo && response.serverInfo.g_gametype) {
                    var gametype = response.serverInfo.g_gametype;

                    // AJAX call to get gametype alias
                    var gametypeAliasXhr = new XMLHttpRequest();
                    gametypeAliasXhr.open('POST', 'functions/getGametypeAlias.php', true);
                    gametypeAliasXhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
                    gametypeAliasXhr.onload = function() {
                        if (this.status == 200) {
                            var alias = this.responseText || gametype;
                            document.getElementById('current_gametype').textContent = alias;
                        }
                    };
                    gametypeAliasXhr.send('serverId=' + serverId + '&gametype=' + encodeURIComponent(gametype));
                }

                // Populate map selection
                populateMapSelect();
            }
        };
        xhr.send('serverId=' + serverId);
    }

    function clearOutput() {
        document.getElementById('output_box').textContent = '';
    }

    function refreshData() {
        var serverId = document.getElementById('server').value;
        queryServerInfo(serverId); // This will refresh the server info
    }

    function kickPlayer() {
        var serverId = document.getElementById('server').value;
        var playerIdToKick = document.getElementById('kick_player_select').value;
        var playerName = document.getElementById('kick_player_select').options[document.getElementById('kick_player_select').selectedIndex].text.split(' - ')[1];

        if (playerIdToKick === "") {
            customMessage("Please select a player to kick.");
            return;
        }

        customConfirm("Are you sure you want to kick player " + playerName + "?", function(confirm) {
            if (confirm) {
                // Construct the RCON command
                var command = "clientkick " + playerIdToKick;

                var xhr = new XMLHttpRequest();
                xhr.open('POST', 'functions/rcon_functions.php', true);
                xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
                xhr.onreadystatechange = function() {
                    if (this.readyState == 4 && this.status == 200) {
                        queryServerInfo(serverId); // Refresh the table after kicking a player
                    }
                };
                xhr.send('server=' + serverId + '&command=' + encodeURIComponent(command));
            }
        });
    }

    function confirmAndSendRCON(command) {
        var serverId = document.getElementById('server').value;
        var confirmMessage;

        switch (command) {
            case 'map_restart':
                confirmMessage = "Are you sure you want to restart the map?";
                break;
            case 'fast_restart':
                confirmMessage = "Are you sure you want to perform a fast restart?";
                break;
            case 'map_rotate':
                confirmMessage = "Are you sure you want to rotate the map?";
                break;
            default:
                return;
        }

        customConfirm(confirmMessage, function(confirm) {
            if (confirm) {
                // Send RCON command
                var xhr = new XMLHttpRequest();
                xhr.open('POST', 'functions/rcon_functions.php', true);
                xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
                xhr.onreadystatechange = function() {
                    if (this.readyState == 4 && this.status == 200) {
                        queryServerInfo(serverId);
                    }
                };
                xhr.send('server=' + serverId + '&command=' + encodeURIComponent(command));
            }
        });
    }

    // Function to populate map selection dropdown
    function populateMapSelect() {
        var serverId = document.getElementById('server').value;
        var xhr = new XMLHttpRequest();
        xhr.open('POST', 'functions/getMapsForServer.php', true);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        xhr.onreadystatechange = function() {
            if (this.readyState == 4 && this.status == 200) {
                var maps = JSON.parse(this.responseText);
                var mapSelect = document.getElementById('map_select');
                mapSelect.innerHTML = ''; // Clear existing options

                for (var map of maps) {
                    var option = document.createElement("option");
                    option.value = map.map_name; // Map name for RCON command
                    option.text = map.alias; // Alias for display
                    mapSelect.add(option);
                }
            }
        };
        xhr.send('serverId=' + serverId);
    }

    // Function to change map
    function changeMap() {
        var serverId = document.getElementById('server').value;
        var mapSelect = document.getElementById('map_select');
        var selectedMap = mapSelect.value;

        customConfirm("Change the map to " + mapSelect.options[mapSelect.selectedIndex].text + "?", function(confirm) {
            if (confirm) {
                var command = 'map ' + selectedMap;

                var xhr = new XMLHttpRequest();
                xhr.open('POST', 'functions/rcon_functions.php', true);
                xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
                xhr.onreadystatechange = function() {
                    if (this.readyState == 4 && this.status == 200) {
                        queryServerInfo(serverId); // Refresh server info
                    }
                };
                xhr.send('server=' + serverId + '&command=' + encodeURIComponent(command));
            }
        });
    }

    // Function to populate map rotation selection dropdown
    function populateMapRotationSelect() {
        var serverId = document.getElementById('server').value;
        var xhr = new XMLHttpRequest();
        xhr.open('POST', 'functions/getMapRotationsForServer.php', true);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        xhr.onreadystatechange = function() {
            if (this.readyState == 4 && this.status == 200) {
                var rotations = JSON.parse(this.responseText);
                var rotationSelect = document.getElementById('map_rotation_select');
                rotationSelect.innerHTML = ''; // Clear existing options

                for (var rotation of rotations) {
                    var option = document.createElement("option");
                    option.value = rotation.id; // Use id for value
                    option.text = rotation.name; // Display name for readability
                    rotationSelect.add(option);
                }
            }
        };
        xhr.send('serverId=' + serverId);
    }

    // Function to apply map rotation
    function applyMapRotation() {
    var serverId = document.getElementById('server').value;
    var rotationSelect = document.getElementById('map_rotation_select');
    var selectedRotationId = rotationSelect.value; // Get the selected rotation id

    if (selectedRotationId === "") {
        customMessage("Please select a map rotation.");
        return;
    }

    var selectedRotationName = rotationSelect.options[rotationSelect.selectedIndex].text;
    
    // Use customConfirm to ask for confirmation before proceeding
    customConfirm("Apply the map rotation: " + selectedRotationName + "?", function(confirm) {
        if (confirm) {
            // Fetch rotation string from server based on selected rotation ID
            var xhr = new XMLHttpRequest();
            xhr.open('POST', 'functions/getRotationString.php', true);
            xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
            xhr.onload = function() {
                if (this.status == 200) {
                    var rotationString = this.responseText;
                    if (rotationString) {
                        // Step 1: Set the rotation
                        var setRotationCommand = 'set sv_maprotationcurrent "' + rotationString + '"';
                        
                        // Step 2: After setting rotation, send map_rotate command
                        sendRconCommandForRotation(setRotationCommand, serverId, function() {
                            var mapRotateCommand = 'map_rotate';
                            sendRconCommandForRotation(mapRotateCommand, serverId, function() {
                                // Step 3: Refresh server info after map rotation
                                queryServerInfo(serverId);
                                customMessage("Map rotation applied and map rotated", null);
                            });
                        });
                    } else {
                        customMessage('Failed to retrieve rotation string.');
                    }
                }
            };
            xhr.send('rotationId=' + selectedRotationId); // Send rotation id to get the correct rotation string
        }
    });
}
    // Ensure this function is defined correctly
    function sendRconCommandForRotation(command, serverId, callback) {
        var xhr = new XMLHttpRequest();
        xhr.open('POST', 'functions/rcon_functions.php', true);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        xhr.onreadystatechange = function() {
            if (this.readyState == 4 && this.status == 200) {
                if (callback) callback();
            }
        };
        xhr.send('server=' + serverId + '&command=' + encodeURIComponent(command));
    }
    // Function to run when the page loads
    window.onload = function() {
        var serverSelect = document.getElementById('server');
        if (serverSelect.options.length > 0) {
            queryServerInfo(serverSelect.value); // Query info for the first server
            populateMapSelect();
            populateGametypeSelect();
            populateMapRotationSelect(); // Add this line to populate new dropdown
        }

        serverSelect.addEventListener('change', function() {
            // Update session via AJAX when server changes
            var serverId = this.value;
            var xhr = new XMLHttpRequest();
            xhr.open('POST', '<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>', true);
            xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
            xhr.onload = function() {
                if (this.status == 200) {
                    //console.log('Server ID updated in session');  //debug success so no need to spam console
                } else {
                    console.error('Failed to update server ID in session');
                }
            };
            xhr.send('update_session_server=1&server=' + serverId);

            queryServerInfo(serverId); // Query info for the newly selected server
            populateMapSelect();
            populateGametypeSelect();
            populateMapRotationSelect(); // Refresh map rotation options
            // If auto refresh is active, restart it with the new server
            if (document.getElementById('auto_refresh').checked) {
                toggleAutoRefresh();
            }
        });
    };

    function updateFastRestartButton() {
        var serverSelect = document.getElementById('server');
        var serverType = serverSelect.options[serverSelect.selectedIndex].getAttribute('data-server-type');
        var placeholder = document.getElementById('fastRestartPlaceholder');
        var button = placeholder.querySelector('button');

        if (serverType === "cod") {
            if (button) {
                button.remove(); // Remove the button if it exists and type is 'cod'
            }
        } else {
            if (!button) {
                // Create button if it doesn't exist and type is not 'cod'
                button = document.createElement('button');
                button.textContent = 'Fast Restart';
                button.onclick = function() { confirmAndSendRCON('fast_restart'); };
                button.style.marginRight = '10px';
                placeholder.appendChild(button);
            }
        }
    }

    document.addEventListener('DOMContentLoaded', function() {
        updateFastRestartButton(); // Initial check when page loads

        // Listen for changes in server selection
        document.getElementById('server').addEventListener('change', updateFastRestartButton);

        // Other DOMContentLoaded event listeners
        var serverSelect = document.getElementById('server');
        if (serverSelect.options.length > 0) {
            queryServerInfo(serverSelect.value); // Query info for the first server
            populateMapSelect();
            populateGametypeSelect();
            populateMapRotationSelect(); // Add this line to populate new dropdown
        }


    });
    function toggleOutputBoxSize() {
        var outputBox = document.getElementById('output_box');
        outputBox.classList.toggle('expanded');
    }
</script>

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Call of Duty Simple RCON - Map Manager</title>
    <link rel="stylesheet" type="text/css" href="codrs.css?v=<?php echo time(); ?>">
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
                        ?>
                    </select>
                </form>
                <div class="refresh-controls">
                    <button onclick="refreshData()">Refresh</button>
                    <label for="refresh_time">Refresh Time:</label>
                    <select id="refresh_time">
                        <option value="30">30</option>
                        <option value="15">15</option>
                        <option value="45">45</option>
                        <option value="60">60</option>
                    </select>
                    <input type="checkbox" id="auto_refresh" onclick="toggleAutoRefresh()">
                    <label for="auto_refresh">Auto Refresh</label>
                </div>
                <div id="output_box"></div>
                <div class="controls">
                    <button onclick="clearOutput()">Clear</button>
                    <button onclick="toggleOutputBoxSize()">🡹 🡻</button>
                    <form method="post" onsubmit="sendRconCommand(event)" style="display: flex; align-items: center;">
                        <input type="hidden" name="server" id="hidden_server">
                        <label for="command">RCON Command:</label>
                        <input type="text" name="command" id="command" required>
                        <input type="submit" value="Send Command">
                    </form>
                </div>
            </div>
            <div class="container-players" style="float: left;">
                <div class="kick-controls">
                    <label for="kick_player_select">Kick Player:</label>
                    <select id="kick_player_select">
                        <option value="">Select Player</option>
                    </select>
                    <button onclick="kickPlayer()">Kick</button>
                </div>
                <table id="player_table">
                    <thead>
                        <tr>
                            <th>I.D.</th>
                            <th>Name</th>
                            <th>Score</th>
                            <th>Ping</th>
                        </tr>
                    </thead>
                    <tbody id="player_table_body">
                    </tbody>
                </table>
            </div>
            <div class="container-players-right" style="text-align: center; position: relative;">
                <div id="server_name_display" style="background-color: #7b7a7a; border-style: solid; border-width: 1px; border-color: darkslategrey; border-radius: 5px; padding: 5px; box-shadow: 0 0 10px rgba(0, 0, 0, 0.1);"></div>
                <br>
                <div class="map-image-container" style="position: relative; width: 128px; height: 128px; background-image: url('gamevars/fallback_map.png'); background-size: cover; background-position: center; margin: 0 auto;">
                    <img src="gamevars/fallback_map.png" alt="Current Map Image" style="max-width: 100%; display: block; margin: 0 auto;">
                </div>
                <div class="row">
                    <div class="row">
                        <p class="text">Game: <span id="current_game_name">Loading</span></p>
                    </div>
                    <div class="row">
                        <p class="text">Current Map: <span id="current_map">Loading</span></p>
                    </div>
                    <div class="row">
                        <p class="text">Current Gametype: <span id="current_gametype">Loading</span></p>
                    </div>
                </div>
                <hr class="separator">
                <div class="row" style="display: flex; justify-content: center;">
                    <button onclick="confirmAndSendRCON('map_restart')" style="margin-right: 10px;">Restart Map</button>
                    <span id="fastRestartPlaceholder"></span>
                    <button onclick="confirmAndSendRCON('map_rotate')">Map Rotate</button>
                </div>
                <div class="row" style="margin-top: 0px;">
                    <p class="text" style="padding-left: 5px;">Change map:</p>
                    <div style="display: flex; align-items: center; margin-top: 10px;">
                        <select id="map_select" style="margin-right: 10px; width:200px;"></select>
                        <button onclick="changeMap()">Apply</button>
                    </div>
                </div>
                <div class="row" style="margin-top: 10px;">
                    <p class="text" style="padding-left: 5px;">Change gametype:</p>
                    <div style="display: flex; align-items: center; margin-top: 10px;">
                        <select id="gametype_select" style="margin-right: 10px; width:200px;"></select>
                        <button onclick="changeGametype()">Apply</button>
                    </div>
                </div>
                <div class="row" style="margin-top: 10px;">
                    <p class="text" style="padding-left: 5px;">Change Map Rotation:</p>
                    <div style="display: flex; align-items: center; margin-top: 10px;">
                        <select id="map_rotation_select" style="margin-right: 10px; width:200px;"></select>
                        <button onclick="applyMapRotation()">Apply</button>
                    </div>
                    <div class="editor-button-under-table">
                        <p>&nbsp;&nbsp;Map Rotation Editor:
                            <a href="map_rot_ed.php">
                                <button style="margin-left: 84px;">Editor</button>
                            </a>
                    </div>
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
        </main>
        <footer>
            <?php include 'footer.php'; ?>
        </footer>
    </div>
</body>

</html>