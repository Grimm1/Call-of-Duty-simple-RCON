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

include __DIR__ . '/config/database.php';

$sql = "SELECT id, server_name, server_type FROM game_servers";
$result = $conn->query($sql);

// If no server ID from session, try to get the first server ID
if ($selectedServerId === 0 && $result->num_rows > 0) {
    $selectedServerId = $result->fetch_assoc()['id'];
    $result->data_seek(0); // Reset result pointer
}
if ($selectedServerId === 0 && $result->num_rows > 0) {
    $selectedServerId = $result->fetch_assoc()['id'];
    $result->data_seek(0); // Reset result pointer
}
// Fetch map aliases for the selected server



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
                <!-- Add any other content or functionality here -->
            </div>
        </main>
        <script>
            document.addEventListener('DOMContentLoaded', function() {
                document.getElementById('server').addEventListener('change', function() {
                    var serverId = this.value;
                    // AJAX call to update session server ID
                    var xhr = new XMLHttpRequest();
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
                    
                    // You can add here any other functions that should run when the server changes, 
                    // e.g., updating page content based on the new server selection
                });
            });
        </script>
        <footer>
            <?php include 'footer.php'; ?>
        </footer>
    </div>
</body>

</html>