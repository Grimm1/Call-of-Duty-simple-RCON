<?php
session_start();
include 'config/database.php';

function logMessage($message) {
    //error_log($message . "\n", 3, "access_log.txt");
}

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
            $_SESSION['role'] = $row['role_id']; 

            // Fetch and set permissions
            $permissions = fetchUserPermissions($row['role_id']); // Assuming you have a function to fetch permissions
            $_SESSION['permissions'] = $permissions;

            session_regenerate_id(true);

            logMessage("Session variables set: user_id=" . $_SESSION['user_id'] . ", username=" . $_SESSION['username'] . ", role=" . $_SESSION['role'] . ", permissions=" . implode(", ", array_keys($permissions)));
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

// HTML content starts here
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
            ?>
        </div>
        <main></main>
        <footer>
            <?php include 'footer.php'; ?>
        </footer>
    </div>
</body>
</html>