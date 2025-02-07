<?php
session_start();
if (!isset($_SESSION['permissions']) || !isset($_SESSION['permissions']['edit_maps'])) {
    $_SESSION['error_message'] = "You do not have permission to access this page.";
    header("Location: perm_fail.php");
    exit();
}

require_once 'config/database.php';
require_once 'functions/server_map_handler.php';

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

ob_start(); // Start output buffering to prevent headers already sent errors

$sql = "SELECT id, server_name, server_type FROM game_servers";
$result = $conn->query($sql);
$selectedServerId = isset($_SESSION['selected_server_id']) ? intval($_SESSION['selected_server_id']) : ($result->num_rows > 0 ? $result->fetch_assoc()['id'] : 0);
$result->data_seek(0); // Reset result pointer

function parseSRAText($content)
{
    $data = [
        'server_type' => '',
        'maps' => [],
        'rotations' => [],
        'gametypes' => []
    ];
    $lines = explode("\n", trim($content));
    $currentRotation = null;
    $currentSection = null;

    foreach ($lines as $line) {
        $line = trim($line);
        if (empty($line)) continue;

        if (strpos($line, 'server_type =') === 0) {
            $data['server_type'] = trim(substr($line, strlen('server_type =')));
        } elseif (strpos($line, '# Map Rotations:') === 0) {
            $currentSection = 'rotations';
        } elseif (strpos($line, '# Available Gametypes:') === 0) {
            $currentSection = 'gametypes';
        } elseif (strpos($line, '# Rotation:') === 0) {
            $currentRotation = trim(substr($line, strlen('# Rotation:')));
            $data['rotations'][$currentRotation] = [];
        } elseif (strpos($line, '=') !== false) {
            list($key, $value) = explode('=', $line, 2);
            if ($currentSection == 'gametypes') {
                $data['gametypes'][trim($key)] = trim($value);
            } else {
                $data['maps'][trim($key)] = trim($value);
            }
        } elseif ($currentSection == 'rotations' && !empty($currentRotation)) {
            $data['rotations'][$currentRotation][] = $line;
        }
    }
    return $data;
}

function updateMaps($serverId, $maps)
{
    global $conn;
    foreach ($maps as $mapKey => $mapValue) {
        handleServerMaps($serverId, "$mapKey=$mapValue");
    }
}

function updateServerMapRotations($serverId, $rotations)
{
    global $conn;
    $conn->query("DELETE FROM server_map_rotation WHERE server_id = $serverId");
    foreach ($rotations as $name => $rotation) {
        $rotationString = implode("\n", $rotation);
        $insertSql = "INSERT INTO server_map_rotation (server_id, name, rotation_string) VALUES (?, ?, ?)";
        $stmt = $conn->prepare($insertSql);
        $stmt->bind_param("iss", $serverId, $name, $rotationString);
        $stmt->execute();
    }
}

function updateServerGametypes($serverId, $gametypes)
{
    global $conn;
    $conn->query("DELETE FROM server_gametypes WHERE server_id = $serverId");

    foreach ($gametypes as $game_type => $gamet_alias) {
        $checkQuery = "SELECT id FROM available_gametypes WHERE game_type = ? AND server_id = ?";
        $checkStmt = $conn->prepare($checkQuery);
        $checkStmt->bind_param("si", $game_type, $serverId);
        $checkStmt->execute();
        $checkStmt->store_result();
        $checkStmt->bind_result($id);

        if (!$checkStmt->fetch()) {
            $insertQuery = "INSERT INTO available_gametypes (game_type, gamet_alias, server_id) VALUES (?, ?, ?)";
            $insertStmt = $conn->prepare($insertQuery);
            $insertStmt->bind_param("ssi", $game_type, $gamet_alias, $serverId);
            $insertStmt->execute();
            $availableGametypeId = $insertStmt->insert_id;
        } else {
            $availableGametypeId = $id;
        }
        $checkStmt->close();

        $linkQuery = "INSERT INTO server_gametypes (server_id, available_gametype_id) VALUES (?, ?)";
        $linkStmt = $conn->prepare($linkQuery);
        $linkStmt->bind_param("ii", $serverId, $availableGametypeId);
        $linkStmt->execute();
        $linkStmt->close();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['get_data'])) {
        $serverId = $_POST['server'] ?? $selectedServerId;
        $serverMapData = handleServerMaps($serverId, true);
        $rotations = fetchServerMapRotations($serverId);
        $gametypes = fetchServerGametypes($serverId);

        $parsedMapData = parseSRAText($serverMapData);

        $responseData = [
            'server_type' => fetchServerType($serverId),
            'maps' => $parsedMapData['maps'],
            'rotations' => $rotations,
            'gametypes' => $gametypes
        ];

        $response = [
            'success' => true,
            'map_data' => $responseData
        ];
        echo json_encode($response);
        exit;
    }

    if (isset($_POST['update_data'])) {
        $serverId = $_POST['server'];
        $parsedData = parseSRAText($_POST['map_data']);

        updateMaps($serverId, $parsedData['maps']);
        updateServerMapRotations($serverId, $parsedData['rotations']);
        updateServerGametypes($serverId, $parsedData['gametypes']);

        echo json_encode(['success' => true, 'error' => '']);
        exit;
    }

    if (isset($_POST['download_button'])) {
        $serverId = $_POST['server'];
        $mapData = $_POST['map_data'];
        $parsedData = parseSRAText($mapData);

        $sraContent = "server_type = " . $parsedData['server_type'] . "\n";
        foreach ($parsedData['maps'] as $key => $value) {
            $sraContent .= "$key=$value\n";
        }
        $sraContent .= "\n# Map Rotations:\n";
        foreach ($parsedData['rotations'] as $name => $rotation) {
            $sraContent .= "# Rotation: $name\n" . implode("\n", $rotation) . "\n";
        }
        $sraContent .= "\n# Available Gametypes:\n";
        foreach ($parsedData['gametypes'] as $key => $value) {
            $sraContent .= "$key=$value\n";
        }

        $filename = sanitizeFileName("server_" . $serverId) . '.sra';
        header('Content-Type: text/plain');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        echo $sraContent;
        exit;
    }

    if (isset($_POST['import_file'])) {
        $fileContent = $_POST['map_data'];
        $parsedData = parseSRAText($fileContent);
        $_SESSION['parsed_data'] = $parsedData;

        echo json_encode(['success' => true, 'data' => $parsedData]);
        exit;
    }
}

function fetchServerMapRotations($serverId)
{
    global $conn;
    $query = "SELECT name, rotation_string FROM server_map_rotation WHERE server_id = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $serverId);
    $stmt->execute();
    $result = $stmt->get_result();
    $rotations = [];
    while ($row = $result->fetch_assoc()) {
        $rotations[$row['name']] = explode("\n", $row['rotation_string']);
    }
    $stmt->close();
    return $rotations;
}

function fetchServerGametypes($serverId)
{
    global $conn;
    $query = "SELECT gt.game_type, gt.gamet_alias 
              FROM server_gametypes sg 
              JOIN available_gametypes gt ON sg.available_gametype_id = gt.id 
              WHERE sg.server_id = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $serverId);
    $stmt->execute();
    $result = $stmt->get_result();
    $gametypes = [];
    while ($row = $result->fetch_assoc()) {
        $gametypes[$row['game_type']] = $row['gamet_alias'];
    }
    $stmt->close();
    return $gametypes;
}

function fetchServerType($serverId)
{
    global $conn;
    $query = "SELECT server_type FROM game_servers WHERE id = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $serverId);
    $stmt->execute();
    $stmt->bind_result($server_type);
    $stmt->fetch();
    $stmt->close();
    return $server_type;
}

function sanitizeFileName($str)
{
    $str = preg_replace('/[^a-zA-Z0-9._-]/', '_', $str);
    $str = rtrim($str, '_');
    return substr($str, 0, 200); // Limiting to 200 chars to prevent too long filenames
}

ob_end_flush();
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
</head>

<body>
    <div class="page-container">
        <header>
            <?php include 'header.php'; ?>
        </header>
        <main class="content">
            <div class="container">
                <form method="post" id="server_form" enctype="multipart/form-data">
                    <div class="control-group">
                        <label for="server">Select Server:</label>
                        <select name="server" id="server" required>
                            <?php
                            if ($result->num_rows > 0) {
                                while ($row = $result->fetch_assoc()) {
                                    $selected = $row['id'] == $selectedServerId ? 'selected' : '';
                                    echo "<option value='{$row['id']}' $selected>{$row['server_name']}</option>";
                                }
                            } else {
                                echo "<option value=''>No servers available</option>";
                            }
                            ?>
                        </select>
                    </div>
                    <p>The sra file contains map lists and rotations for the server. Verify before actions.</p>
                    <div class="button-group">
                        <button type="button" id="get_data">Get Data</button>
                        <button type="button" id="clear_data">Clear Data</button>
                        <button type="button" id="update_data">Update Database</button>
                    </div>
                    <textarea id="map_data" name="map_data" placeholder="map data will appear here!" readonly></textarea>
                    <p style="text-align: center;">Verify data is correct before exporting or updating database!</p>
                    <div class="file-input-container">
                        <button type="button" id="download_sra">Export SRA File</button>
                        <div class="custom-file-input">
                            <script>
                                function updateFileName(input) {
                                    var fileName = input.files[0].name;
                                    document.getElementById('file_name_display').textContent = fileName;
                                }
                            </script>
                            <input type="file" id="import_file" name="import_file" accept=".sra" class="file-input-style" onchange="updateFileName(this)">
                            <label for="import_file" class="file-input-label">Choose File</label>
                            <span class="file-chosen" id="file_name_display">No file chosen</span>
                        </div>
                        <button type="button" id="process_sra">Import SRA File</button>
                    </div>
                </form>
                <div class="output-window">
                    <?php
                    if (!empty($_SESSION['message'])) {
                        echo htmlspecialchars($_SESSION['message']);
                        unset($_SESSION['message']);
                    }
                    ?>
                </div>
            </div>
            <footer>
                <?php include 'footer.php'; ?>
            </footer>
            </div>
        </main>
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



    <script>
        $(document).ready(function() {
            if (typeof jQuery === 'undefined') {
                alert('jQuery is not loaded');
            }


            // Custom modal functions
            function customMessage(message, callback) {
                $('#message-text').text(message);
                $('#message-dialog').show();
                $('#message-ok').one('click', function() {
                    $('#message-dialog').hide();
                    if (callback) callback();
                });
            }

            function customConfirm(message, callback) {
                $('#confirm-message').text(message);
                $('#confirm-dialog').show();
                $('#confirm-yes').one('click', function() {
                    $('#confirm-dialog').hide();
                    callback(true);
                });
                $('#confirm-no').one('click', function() {
                    $('#confirm-dialog').hide();
                    callback(false);
                });
            }

            // Handle 'Get Data' button
            $('#get_data').click(function() {
                var serverId = $('#server').val();
                $.ajax({
                    type: 'POST',
                    url: '<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>',
                    data: {
                        'get_data': true,
                        'server': serverId
                    },
                    success: function(response) {
                        var data = JSON.parse(response);
                        if (data.success) {
                            var mapData = "server_type = " + data.map_data.server_type + "\n";
                            for (var key in data.map_data.maps) {
                                mapData += key + "=" + data.map_data.maps[key] + "\n";
                            }
                            mapData += "\n# Map Rotations:\n";
                            for (var rotationName in data.map_data.rotations) {
                                mapData += "# Rotation: " + rotationName + "\n";
                                data.map_data.rotations[rotationName].forEach(function(entry) {
                                    mapData += entry + "\n";
                                });
                            }
                            mapData += "\n# Available Gametypes:\n";
                            for (var gametype in data.map_data.gametypes) {
                                mapData += gametype + "=" + data.map_data.gametypes[gametype] + "\n";
                            }
                            $('#map_data').val(mapData);
                        } else {
                            customMessage('Error fetching data.');
                        }
                    },
                    error: function() {
                        customMessage('Failed to fetch data. Please try again.');
                    }
                });
            });

            // Handle 'Clear Data' button
            $('#clear_data').click(function() {
                $('#map_data').val('');
            });

            // Handle 'Update Database' button
            $('#update_data').click(function() {
                var serverId = $('#server').val();
                var mapData = $('#map_data').val();

                if (mapData.trim() === "") {
                    customMessage("No data to update. Please ensure the output box contains data.");
                    return;
                }

                customConfirm('Are you sure you want to update the database with this data?', function(confirmed) {
                    if (confirmed) {
                        $.ajax({
                            type: 'POST',
                            url: '<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>',
                            data: {
                                'update_data': true,
                                'server': serverId,
                                'map_data': mapData
                            },
                            success: function(response) {
                                var data = JSON.parse(response);
                                if (data.success) {
                                    customMessage('Database updated successfully.');
                                } else {
                                    customMessage('Error updating database: ' + data.error);
                                }
                            },
                            error: function() {
                                customMessage('Failed to update database. Please try again.');
                            }
                        });
                    }
                });
            });

            // Handle 'Export SRA File' button
            $('#download_sra').click(function() {
                var serverId = $('#server').val();
                var mapData = $('#map_data').val();

                if (mapData.trim() === "") {
                    customMessage('Please get data first before exporting.');
                } else {
                    $.ajax({
                        type: 'POST',
                        url: '<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>',
                        data: {
                            'download_button': true,
                            'server': serverId,
                            'map_data': mapData
                        },
                        xhrFields: {
                            responseType: 'blob'
                        },
                        success: function(blob, status, xhr) {
                            var a = document.createElement('a');
                            var url = window.URL.createObjectURL(blob);
                            a.href = url;

                            var disposition = xhr.getResponseHeader('Content-Disposition');
                            var filename = '';
                            if (disposition && disposition.indexOf('attachment') !== -1) {
                                var filenameRegex = /filename[^;=\n]*=((['"]).*?\2|[^;\n]*)/;
                                var matches = filenameRegex.exec(disposition);
                                if (matches != null && matches[1]) {
                                    filename = matches[1].replace(/['"]/g, '');
                                }
                            }
                            a.download = filename;

                            document.body.append(a);
                            a.click();
                            a.remove();
                            window.URL.revokeObjectURL(url);
                        },
                        error: function() {
                            customMessage('Error downloading file. Please try again.');
                        }
                    });
                }
            });

            // Handle file import
            $('#process_sra').click(function() {
                var fileInput = document.getElementById('import_file');
                var file = fileInput.files[0];
                if (!file) {
                    customMessage('Please select an SRA file first.');
                } else {
                    var reader = new FileReader();
                    reader.onload = function(e) {
                        var content = e.target.result;
                        $.ajax({
                            type: 'POST',
                            url: '<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>',
                            data: {
                                'import_file': true,
                                'map_data': content
                            },
                            success: function(response) {
                                var data = JSON.parse(response);
                                if (data.success) {
                                    var mapData = "server_type = " + data.data.server_type + "\n";
                                    for (var key in data.data.maps) {
                                        mapData += key + "=" + data.data.maps[key] + "\n";
                                    }
                                    mapData += "\n# Map Rotations:\n";
                                    for (var rotationName in data.data.rotations) {
                                        mapData += "# Rotation: " + rotationName + "\n";
                                        data.data.rotations[rotationName].forEach(function(entry) {
                                            mapData += entry + "\n";
                                        });
                                    }
                                    mapData += "\n# Available Gametypes:\n";
                                    for (var gametype in data.data.gametypes) {
                                        mapData += gametype + "=" + data.data.gametypes[gametype] + "\n";
                                    }
                                    $('#map_data').val(mapData);
                                } else {
                                    customMessage('Error importing data.');
                                }
                            },
                            error: function() {
                                customMessage('Failed to import data. Please try again.');
                            }
                        });
                    };
                    reader.readAsText(file);
                }
            });


        });
    </script>
</body>

</html>
