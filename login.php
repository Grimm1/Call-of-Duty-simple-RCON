<?php
session_start();
include 'config/database.php';

function logMessage($message) {
    //error_log($message . "\n", 3, "access_log.txt");
}

// Check if install.php exists
$installFilePath = 'utils/install.php';
$installFileWarning = file_exists($installFilePath) ? "<p style='color: red;'>Warning: 'utils/install.php' exists. Please delete it for security reasons.</p>" : "";

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $username = $_POST['username'];
    $password = $_POST['password'];

    logMessage("Login attempt for user: " . $username);

    $sql = "SELECT id, username, password, role_id FROM users WHERE username = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        if (password_verify($password, $row['password'])) {
            logMessage("Login successful for user ID: " . $row['id']);

            $_SESSION['user_id'] = $row['id'];
            $_SESSION['username'] = $row['username'];
            $_SESSION['role_id'] = $row['role_id']; // Keep this for permission checks

            // Fetch the role name from the roles table
            $sql_role = "SELECT name FROM roles WHERE id = ?";
            $stmt_role = $conn->prepare($sql_role);
            $stmt_role->bind_param("i", $row['role_id']);
            $stmt_role->execute();
            $role_result = $stmt_role->get_result();

            if ($role_result->num_rows > 0) {
                $role_row = $role_result->fetch_assoc();
                $_SESSION['role_name'] = $role_row['name']; // Add role name to session
            } else {
                // Handle case where role does not exist
                logMessage("Error: Role not found for role_id: " . $row['role_id']);
                $_SESSION['role_name'] = "Unknown"; // or set to whatever default you prefer
            }

            // Fetch and set permissions
            $permissions = fetchUserPermissions($row['role_id']);
            $_SESSION['permissions'] = $permissions;

            session_regenerate_id(true);

            logMessage("Session variables set: user_id=" . $_SESSION['user_id'] . ", username=" . $_SESSION['username'] . ", role_id=" . $_SESSION['role_id'] . ", role_name=" . $_SESSION['role_name'] . ", permissions=" . implode(", ", array_keys($permissions)));
            header("Location: index.php");
            exit();
        } else {
            logMessage("Login failed: Incorrect password for user: " . $username);
            $error_message = "Invalid username or password.";
        }
    } else {
        logMessage("Login failed: User not found: " . $username);
        $error_message = "Invalid username or password.";
    }
}

// Function to fetch user permissions based on role_id
function fetchUserPermissions($role_id) {
    global $conn;
    $permissions = [];

    $sql = "SELECT p.name FROM permissions p
            JOIN role_permissions rp ON p.id = rp.permission_id
            WHERE rp.role_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $role_id);
    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $permissions[$row['name']] = true;
    }

    return $permissions;
}

?>
<!DOCTYPE html>
<html>
<head>
    <title>Call of Duty Simple RCON</title>
    <link rel="stylesheet" type="text/css" href="codrs.css">
</head>
<body>
    <div class="page-container">
        <header>
            <div class="container">
                <div id="branding">

                </div>
            </div>
        </header>
        <div class="container">
            <form method="post">
                <label>Username:</label><input type="text" name="username" required><br>
                <label>Password:</label><input type="password" name="password" required><br>
                <input type="submit" value="Login">
            </form>
            <?php 
            if (isset($error_message)) {
                echo "<p style='color: red;'>" . htmlspecialchars($error_message) . "</p>";
            }
            echo $installFileWarning; // Display warning if install.php exists
            ?>
        </div>
        <main></main>
        <footer>
            <?php include 'footer.php'; ?>
        </footer>
    </div>
</body>
</html>