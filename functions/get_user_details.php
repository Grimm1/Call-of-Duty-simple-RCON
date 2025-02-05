<?php
session_start();

if (!isset($_SESSION['username']) || $_SESSION['role'] != 1) {
    echo json_encode(['success' => false, 'message' => 'You do not have permission to access this page.']);
    exit();
}

include '../config/database.php'; // Adjusted path to go up one directory level to reach config
if (!isset($conn) || !$conn->ping()) {
    echo json_encode(['success' => false, 'message' => 'Database connection failed!']);
    exit();
}

if (isset($_POST['user_id'])) {
    $user_id = $_POST['user_id'];
    $stmt = $conn->prepare("SELECT users.username, roles.name AS role FROM users JOIN roles ON users.role_id = roles.id WHERE users.id = ?");
    if ($stmt) {
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($row = $result->fetch_assoc()) {
            echo json_encode(['success' => true, 'username' => $row['username'], 'role' => $row['role']]);
        } else {
            echo json_encode(['success' => false, 'message' => 'User not found.']);
        }
        $stmt->close();
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to prepare statement.']);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'User ID not provided.']);
}
?>