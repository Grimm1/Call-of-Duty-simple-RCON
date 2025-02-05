<?php
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

if (isset($_POST['update_session_server'])) {
    $_SESSION['selected_server_id'] = $_POST['server'];
    exit; // Just to ensure no further processing occurs
}

require_once 'config/database.php';
require_once 'functions/server_map_handler.php';

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

ob_start(); // Start output buffering to prevent headers already sent errors

// Fetch all servers
$sql = "SELECT id, server_name, server_type FROM game_servers";
$result = $conn->query($sql);
$selectedServerId = isset($_SESSION['selected_server_id']) ? intval($_SESSION['selected_server_id']) : ($result->num_rows > 0 ? $result->fetch_assoc()['id'] : 0);
$result->data_seek(0); // Reset result pointer
$serverMapData = "";

if (!$result) {
    // Handle error, maybe log it or show a user-friendly message
    echo "Error executing query: " . $conn->error;
    exit;
}

function parseSRAText($content)
{
    $data = [];
    $lines = explode("\n", trim($content));
    $currentSection = null;
    $rotation = [];

    foreach ($lines as $line) {
        $line = trim($line);
        if (strpos($line, 'server_type =') === 0) {
            $data['server_type'] = trim(substr($line, strlen('server_type =')));
        } elseif (strpos($line, '=') !== false) { // Map data
            list($key, $value) = explode('=', $line, 2);
            $data['maps'][trim($key)] = trim($value);
        } elseif (strpos($line, '# Rotation:') === 0) { // Start of new rotation
            $currentSection = trim(substr($line, strlen('# Rotation:')));
            $rotation = [];
        } elseif (!empty($line) && $currentSection) { // Rotation data
            $rotation[] = $line;
        }

        // Here we ensure that even if there's no rotation data, we still add the section if a name was found
        if ($currentSection && empty($data['rotations'][$currentSection])) {
            $data['rotations'][$currentSection] = $rotation;
        }
    }

    // If there were no rotations found, we should initialize an empty array instead of leaving it undefined
    if (!isset($data['rotations'])) {
        $data['rotations'] = [];
    }

    return $data;
}

function updateMaps($serverId, $maps)
{
    global $conn;
    $mapData = '';
    foreach ($maps as $mapKey => $mapValue) {
        $mapData .= "$mapKey=$mapValue\n";
    }
    handleServerMaps($serverId, $mapData);
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

function fetchServerType($serverId)
{
    global $conn;
    $query = "SELECT server_type FROM game_servers WHERE id = ?";
    $stmt = $conn->prepare($query);
    if (!$stmt) {
        throw new Exception("Prepare failed: " . $conn->error);
    }
    $stmt->bind_param("i", $serverId);
    if (!$stmt->execute()) {
        throw new Exception("Execute failed: " . $stmt->error);
    }
    $stmt->bind_result($server_type);
    if (!$stmt->fetch()) {
        return null; // No server type found
    }
    $stmt->close();
    return $server_type;
}

function sanitizeFileName($str)
{
    $str = preg_replace('/[^a-zA-Z0-9._-]/', '_', $str);
    $str = rtrim($str, '_');
    return substr($str, 0, 200); // Limiting to 200 chars to prevent too long filenames
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['get_data'])) {
        $serverId = $_POST['server'] ?? $selectedServerId;
        $serverMapData = handleServerMaps($serverId, true);
        $rotations = fetchServerMapRotations($serverId);
        $serverMapData .= "\n\n# Map Rotations:\n" . implode("\n", $rotations);

        $server_type = fetchServerType($serverId);
        $serverMapData = "server_type = " . $server_type . "\n" . $serverMapData;

        $response = array(
            'success' => true,
            'map_data' => $serverMapData
        );
        echo json_encode($response);
        exit;
    }
    if (isset($_POST['update_data'])) {
        $serverId = $_POST['server'];
        $mapData = $_POST['map_data'];

        // Remove server_type before updating
        $mapData = preg_replace('/^server_type\s*=.*\n?/', '', $mapData);

        // Split data into map data and rotation data
        $parts = explode("\n\n# Map Rotations:\n", $mapData);
        $mapDataOnly = $parts[0];
        $rotationData = isset($parts[1]) ? $parts[1] : '';

        // Update map data
        $success = handleServerMaps($serverId, $mapDataOnly);

        // Update rotations
        $parsedRotations = parseSRAText($rotationData);
        if (isset($parsedRotations['rotations'])) {
            updateServerMapRotations($serverId, $parsedRotations['rotations']);
        } else {
            // If no rotations are found, clear existing ones for this server
            $conn->query("DELETE FROM server_map_rotation WHERE server_id = $serverId");
        }

        if (!$success) {
            echo json_encode(['success' => false, 'error' => 'Some entries were skipped due to duplicates. Please check the server log for details.']);
        } else {
            echo json_encode(['success' => true, 'error' => '']);
        }
        exit;
    }

    // Handle AJAX request for server type
    if (isset($_POST['get_server_type'])) {
        ob_clean();
        $serverId = $_POST['server'];
        $server_type = fetchServerType($serverId);
        echo $server_type; // Just echo the server_type as plain text
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['download_button'])) {
        $sql = "SELECT server_name FROM game_servers WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $_POST['server']);
        $stmt->execute();
        $stmt->bind_result($server_name);
        $stmt->fetch();
        $stmt->close();

        $serverMapData = htmlspecialchars_decode($_POST['map_data']);

        // Adjust the format of rotation data
        $parts = explode("\n\n# Map Rotations:\n", $serverMapData);
        $mapData = $parts[0];
        $rotationData = isset($parts[1]) ? $parts[1] : '';

        // Parse and reformat rotation data
        $reformattedRotations = '';
        $rotations = explode("\n# Rotation:", $rotationData);
        foreach ($rotations as $rotation) {
            $rotation = trim($rotation);
            if (empty($rotation)) continue; // Skip if empty

            // Split the rotation into name and entries
            $lines = explode("\n", $rotation);
            $rotationName = trim($lines[0]);
            $rotationEntries = array_slice($lines, 1);

            // Ensure we only have one # Rotation: prefix
            $rotationName = preg_replace('/^# Rotation:/', '', $rotationName, 1);

            // Join rotation entries without semicolons
            $rotationEntriesString = implode(' ', array_map('trim', $rotationEntries));

            $reformattedRotations .= "# Rotation: " . $rotationName . "\n";
            $reformattedRotations .= $rotationEntriesString . "\n";
        }

        // Combine map data and reformatted rotation data
        $serverMapData = $mapData . "\n\n# Map Rotations:\n" . $reformattedRotations;

        $filename = sanitizeFileName($server_name) . '.sra';
        header('Content-Type: text/plain');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        echo $serverMapData;
        exit;
    }
    if (isset($_POST['import_file'])) {
        $fileContent = $_POST['map_data'];
        $parsedData = parseSRAText($fileContent);
        $serverId = $_POST['server'];

        // Check server type match
        if ($parsedData['server_type'] === fetchServerType($serverId)) {
            // Update maps
            updateMaps($serverId, $parsedData['maps'] ?? []);

            // Update rotations
            if (isset($parsedData['rotations']) && !empty($parsedData['rotations'])) {
                updateServerMapRotations($serverId, $parsedData['rotations']);
            } else {
                // If there are no rotations, clear existing ones for this server
                $conn->query("DELETE FROM server_map_rotation WHERE server_id = $serverId");
            }

            $_SESSION['message'] = 'Data imported successfully!';
        } else {
            $_SESSION['message'] = 'Server type mismatch!';
        }

        header("Location: " . $_SERVER['PHP_SELF']);
        exit;
    }
}

// Helper function to fetch server rotations
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
        $rotations[] = "# Rotation: " . $row['name'] . "\n" . $row['rotation_string'];
    }
    $stmt->close();
    return $rotations;
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
                        <button type="submit" name="get_data">Get Data</button>
                        <button type="button" id="clear_data">Clear Data</button>
                        <button type="button" id="update_data">Update Database</button>
                    </div>
                    <textarea id="map_data" name="map_data" placeholder="map data will appear here!" readonly></textarea>
                    <p style="text-align: center;">Verify data is correct before exporting or updating database!</p>
                    <div class="file-input-container">
                        <button type="submit" id="download_sra" name="download_button">Export SRA File</button>
                        <div class="custom-file-input">
                            <input type="file" id="import_file" name="import_file" accept=".sra" class="file-input-style" onchange="updateFileName(this)">
                            <label for="import_file" class="file-input-label">Choose File</label>
                            <span class="file-chosen" id="file_name_display">No file chosen</span>
                        </div>
                        <button type="button" id="process_sra">Import SRA File</button>
                    </div>
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

    </div>
    <script>
        $(document).ready(function() {
            if (typeof jQuery === 'undefined') {
                alert('jQuery is not loaded');
            }

            // Handle 'Get Data' button
            $('#server_form').submit(function(e) {
                e.preventDefault();
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
                            $('#map_data').val(data.map_data);
                        } else {
                            customMessage('Error fetching data: ' + data.error);
                        }
                    },
                    error: function(xhr, status, error) {
                        console.error('An AJAX error occurred: ' + error);
                        customMessage('Failed to fetch data. Please try again.');
                    }
                });
            });

            // Handle 'Download SRA File' button
            $('#download_sra').click(function(e) {
                e.preventDefault();

                var serverId = $('#server').val();
                var mapData = $('#map_data').val();

                if (mapData.trim() === "") {
                    customMessage('Please hit the "Get Data" button to populate the map data before exporting.', function() {});
                    return;
                }

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
                    error: function(xhr, status, error) {
                        console.error('An error occurred while downloading: ' + error);
                        customMessage('Error downloading file. Please try again.');
                    }
                });
            });

            // Handle 'Clear Data' button
            $('#clear_data').click(function() {
                $('#map_data').val('');
            });

            // Clear data when server selection changes
            $('#server').change(function() {
                $('#map_data').val('');
                var serverId = $(this).val();
                $.ajax({
                    type: 'POST',
                    url: '<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>',
                    data: {
                        'update_session_server': true,
                        'server': serverId
                    }
                });
            });

            $('#process_sra').click(function() {
                var fileInput = document.getElementById('import_file');
                var file = fileInput.files[0];
                if (!file) {
                    customMessage('Please select an SRA file first.');
                } else {
                    var reader = new FileReader();
                    var serverId = $('#server').val();

                    reader.onload = function(e) {
    var content = e.target.result;
    var serverId = $('#server').val();

    // Extract server type from content
    var fileServerTypeMatch = content.match(/^server_type\s*=\s*([a-zA-Z0-9_]+)/);
    var fileServerType = fileServerTypeMatch ? fileServerTypeMatch[1] : null;

    if (fileServerType) {
        $.ajax({
            type: 'POST',
            url: '<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>',
            data: {
                'get_server_type': true,
                'server': serverId
            },
            success: function(serverTypeFromDB) {
                serverTypeFromDB = serverTypeFromDB.trim();
                if (serverTypeFromDB === fileServerType) {
                    var contentWithoutServerType = content.replace(/^server_type\s*=.*\n/, '').trim();

                    // Split content into map data and rotation data
                    var parts = contentWithoutServerType.split("\n\n# Map Rotations:\n");
                    if (parts.length >= 1) {
                        var mapData = parts[0].trim();
                        var rotationData = parts[1] ? parts[1].trim() : ''; // Handle case where rotation might not exist

                        // Check map data format
                        var mapLines = mapData.split("\n").filter(Boolean);
                        var allMapsValid = mapLines.every(line => {
                            // Only check lines that match the map data format
                            if (line.startsWith('#')) {
                                return true; // Skip comments or headers like # Map Rotations:
                            }
                            return line.split(';').every(pair => /^[a-zA-Z0-9_]+=[a-zA-Z0-9_ :\s,.-]+$/.test(pair.trim()));
                        });

                        // Check rotation data format
                        if (rotationData) {
                            var rotations = rotationData.split("\n# Rotation:");
                            var isValidRotation = rotations.every(rotation => {
                                var rotationParts = rotation.split("\n").filter(part => part.trim() !== '');
                                if (rotationParts.length < 2) {
                                    console.log('Rotation parts length less than 2:', rotationParts);
                                    return false;
                                }

                                // Check if the first line starts with "# Rotation:" followed by any name
                                var rotationName = rotationParts[0].trim().replace(/^# Rotation:/, '').trim();
                                if (rotationName.length === 0) {
                                    console.log('Rotation name is empty:', rotationParts[0]);
                                    return false;
                                }

                                // Check the rotation entries - now they are space-separated
                                var rotationString = rotationParts[1].trim();
                                // Split by 'gametype' to handle concatenated entries
                                var rotationEntries = rotationString.split(/\s*(?:gametype)\s*/).filter(Boolean);

                                var regex = /^(\w+)\s+map\s+(\w+)$/;
                                var allEntriesValid = rotationEntries.every(entry => {
                                    var match = entry.match(regex);
                                    return match !== null && match[1] && match[2]; // Ensure gametype and map name are present
                                });
                                return allEntriesValid;
                            });

                            if (allMapsValid && isValidRotation) {
                                $('#map_data').val(content);
                            } else {
                                if (!allMapsValid) {
                                    customMessage('The map data format is invalid. Each map entry should be "map_name=alias", multiple entries can be on one line separated by semicolons.');
                                } else if (!isValidRotation) {
                                    customMessage('Invalid data. Rotation entries should follow the pattern "gametype game_type map map_name" ');
                                } else {
                                    customMessage('Invalid data. Check both map data and rotation formats.');
                                }
                            }
                        } else {
                            // If there's no rotation data, just validate map data
                            if (allMapsValid) {
                                $('#map_data').val(content);
                            } else {
                                customMessage('The map data format is invalid. Each map entry should be "map_name=alias", multiple entries can be on one line separated by semicolons.');
                            }
                        }
                    } else {
                        customMessage('Invalid data. Expected at least a section for map data.');
                    }
                } else {
                    customMessage('Invalid data. File server type is ' + fileServerType + ', but database has ' + serverTypeFromDB);
                }
            },
            error: function() {
                customMessage('Could not fetch server type from database. Please try again.');
            }
        });
    } else {
        customMessage('Server type not found in the SRA file.');
    }
};
                    reader.readAsText(file);
                }
            });

            $('#update_data').click(function(e) {
                e.preventDefault(); // Prevent default action if it's in a form

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
                                    customMessage('Database updated successfully.', function() {
                                        // Optional: Do something after closing the message
                                    });
                                } else {
                                    customMessage('Error updating database: ' + data.error);
                                }
                            },
                            error: function(xhr, status, error) {
                                console.error('An AJAX error occurred: ' + error);
                                customMessage('Failed to update database. Please try again.');
                            }
                        });
                    }
                });
            });
        });


        function updateFileName(input) {
            var fileName = input.files[0] ? input.files[0].name : 'No file chosen';
            document.getElementById('file_name_display').textContent = fileName;
        }
    </script>
</body>

</html>