<?php
session_start(); // Start session to check permissions if needed

error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING);
ini_set('display_errors', 0);

include '../config/database.php';
if (!isset($conn) || !$conn->ping()) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Database connection failed!']);
    exit();
}

// Check if user has permission to access this script
if (!isset($_SESSION['permissions']) || !isset($_SESSION['permissions']['manage_users'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'You do not have permission to access this page.']);
    exit();
}

if (isset($_POST['user_id']) && is_numeric($_POST['user_id'])) {
    $user_id = $_POST['user_id'];
    $stmt = $conn->prepare("SELECT users.id, users.username, users.email, roles.name AS role FROM users JOIN roles ON users.role_id = roles.id WHERE users.id = ?");
    
    if ($stmt) {
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($row = $result->fetch_assoc()) {
            header('Content-Type: application/json');
            echo json_encode([
                'success' => true,
                'id' => $row['id'], // Include ID in response
                'username' => $row['username'],
                'email' => $row['email'],
                'role' => $row['role']
            ]);
        } else {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'User not found.']);
        }
        $stmt->close();
    } else {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Failed to prepare statement.']);
    }
} else {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'User ID not provided or invalid.']);
}
?>
