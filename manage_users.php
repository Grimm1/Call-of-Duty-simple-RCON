<?php
session_start();

error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING);
ini_set('display_errors', 0);

if (!isset($_SESSION['permissions']) || !isset($_SESSION['permissions']['manage_users'])) {
    echo "<div class='error'>You do not have permission to access this page.</div>";
    header("Refresh:2; url=index.php");
    exit();
}

include 'config/database.php';
if (!isset($conn) || !$conn->ping()) {
    die("Database connection failed!");
}

// Fetch user data including roles
$users = [];
$stmt = $conn->prepare("SELECT users.id, users.username, users.email, roles.name AS role FROM users JOIN roles ON users.role_id = roles.id");
if ($stmt) {
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $users[] = $row;
    }
    $stmt->close();
} else {
    die("Failed to prepare statement for fetching users.");
}

// Fetch roles for the dropdown
$roles = [];
$stmt = $conn->prepare("SELECT name FROM roles");
if ($stmt) {
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $roles[] = $row['name'];
    }
    $stmt->close();
} else {
    die("Failed to prepare statement for fetching roles.");
}

// Fetch permissions
$permissions = [];
$stmt = $conn->prepare("SELECT id, name FROM permissions");
if ($stmt) {
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $permissions[] = $row;
    }
    $stmt->close();
} else {
    die("Failed to prepare statement for fetching permissions.");
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'add_user':
                addUser($conn, $_POST['username'], $_POST['email'], $_POST['password'], $_POST['role']);
                break;
            case 'edit_user':
                editUser($conn, $_POST['user_id'], $_POST['new_username'] ?? '', $_POST['new_password'] ?? '', $_POST['new_email'] ?? '', $_POST['new_role'] ?? '');
                break;
            case 'get_users':
                getUsers($conn);
                break;
            case 'delete_user':
                $user_id = $_POST['user_id'];
                $result = deleteUser($conn, $user_id);
                header('Content-Type: application/json');
                echo json_encode($result);
                exit();
            case 'get_role_permissions':
                $roleName = $_POST['role_name'];
                $permissions = getRolePermissions($conn, $roleName);
                header('Content-Type: application/json');
                echo json_encode(['success' => true, 'permissions' => $permissions]);
                exit();
            case 'add_role':
                addRole($conn, $_POST['name'], $_POST['description'], $_POST['permissions'] ?? []);
                break;
            case 'edit_role':
                editRole($conn, $_POST['name'], $_POST['description'], $_POST['existing_role'], $_POST['permissions'] ?? []);
                break;
            case 'delete_role':
                deleteRole($conn, $_POST['existing_role']);
                break;
            case 'get_role_details':
                $roleName = $_POST['role_name'];
                $stmt = $conn->prepare("SELECT name, description FROM roles WHERE name = ?");
                if ($stmt) {
                    $stmt->bind_param("s", $roleName);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    $roleDetails = $result->fetch_assoc();
                    $stmt->close();
                    if ($roleDetails) {
                        header('Content-Type: application/json');
                        echo json_encode(['success' => true, 'name' => $roleDetails['name'], 'description' => $roleDetails['description']]);
                    } else {
                        header('Content-Type: application/json');
                        echo json_encode(['success' => false, 'message' => 'Role not found.']);
                    }
                } else {
                    header('Content-Type: application/json');
                    echo json_encode(['success' => false, 'message' => 'Failed to prepare statement.']);
                }
                exit();
            case 'get_roles':
                $stmt = $conn->prepare("SELECT name FROM roles");
                if ($stmt) {
                    $stmt->execute();
                    $result = $stmt->get_result();
                    $roles = [];
                    while ($row = $result->fetch_assoc()) {
                        $roles[] = $row['name'];
                    }
                    $stmt->close();
                    header('Content-Type: application/json');
                    echo json_encode(['success' => true, 'roles' => $roles]);
                } else {
                    header('Content-Type: application/json');
                    echo json_encode(['success' => false, 'message' => 'Failed to prepare statement for fetching roles.']);
                }
                exit();
        }
    }
    exit();
}


function getRolePermissions($conn, $roleName)
{
    $stmt = $conn->prepare("SELECT p.id FROM permissions p 
                            JOIN role_permissions rp ON p.id = rp.permission_id 
                            JOIN roles r ON rp.role_id = r.id 
                            WHERE r.name = ?");
    if ($stmt === false) {
        error_log("Failed to prepare statement: " . $conn->error);
        return []; // Return empty array or handle error differently
    }
    $stmt->bind_param("s", $roleName);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result === false) {
        error_log("Failed to get result: " . $stmt->error);
        return []; // Return empty array or handle error differently
    }
    $permissions = [];
    while ($row = $result->fetch_assoc()) {
        $permissions[] = $row['id'];
    }
    $stmt->close();
    return $permissions;
}

function addUser($conn, $username, $email, $password, $role, $permissions = [])
{
    try {
        $password = password_hash($password, PASSWORD_BCRYPT);
        $sql = "INSERT INTO users (username, password, email, role_id) SELECT ?, ?, ?, id FROM roles WHERE name = ?";
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $emailToUse = $email ?: NULL;
            $stmt->bind_param("ssss", $username, $password, $emailToUse, $role);
            if ($stmt->execute()) {
                $user_id = $stmt->insert_id;
                $stmt->close(); // Close the insert statement immediately after its use

                foreach ($permissions as $perm_id) {
                    $insert_perm_stmt = $conn->prepare("INSERT INTO user_permissions (user_id, permission_id) VALUES (?, ?)");
                    if ($insert_perm_stmt) {
                        $insert_perm_stmt->bind_param("ii", $user_id, $perm_id);
                        $insert_perm_stmt->execute();
                        $insert_perm_stmt->close();
                    } else {
                        throw new Exception('Failed to prepare statement for adding user permissions.');
                    }
                }

                // Prepare a new statement for fetching updated users list
                $users = [];
                $stmt = $conn->prepare("SELECT users.id, users.username, roles.name AS role FROM users JOIN roles ON users.role_id = roles.id");
                if ($stmt) {
                    $stmt->execute();
                    $result = $stmt->get_result();
                    while ($row = $result->fetch_assoc()) {
                        $users[] = $row;
                    }
                    $stmt->close(); // Now this close is for the new statement, not the one used for insertion
                    header('Content-Type: application/json');
                    echo json_encode(['success' => true, 'message' => 'User added successfully!', 'users' => $users]);
                } else {
                    throw new Exception('Failed to prepare statement for fetching updated users list.');
                }
            } else {
                throw new Exception('Failed to add user: ' . htmlspecialchars($stmt->error));
            }
        } else {
            throw new Exception('Failed to prepare statement for adding user.');
        }
    } catch (Exception $e) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'An error occurred: ' . $e->getMessage()]);
    }
    exit();
}

function editUser($conn, $user_id, $new_username, $new_password, $new_email, $new_role)
{
    try {
        // Check if the user is currently an admin
        $stmt = $conn->prepare("SELECT roles.name FROM users JOIN roles ON users.role_id = roles.id WHERE users.id = ?");
        if ($stmt) {
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $row = $result->fetch_assoc();
            $stmt->close();

            if ($row['name'] === 'Admin' && $new_role !== 'Admin') {
                // Check if this is the last admin
                if (countAdminUsers($conn) <= 1) {
                    header('Content-Type: application/json');
                    echo json_encode(['success' => false, 'message' => 'Cannot change the role of the last Admin account.']);
                    exit();
                }
            }
        }

        $sql = "UPDATE users SET ";
        $params = [];
        $types = "";

        if ($new_username !== null) {
            $sql .= "username = ?, ";
            $params[] = $new_username;
            $types .= "s";
        }

        if ($new_password !== null) {
            $new_password = password_hash($new_password, PASSWORD_BCRYPT);
            $sql .= "password = ?, ";
            $params[] = $new_password;
            $types .= "s";
        }

        if ($new_email !== null) {
            $sql .= "email = ?, ";
            $params[] = $new_email;
            $types .= "s";
        }

        $sql .= "role_id = (SELECT id FROM roles WHERE name = ?) WHERE id = ?";
        $params[] = $new_role;
        $params[] = $user_id;
        $types .= "si";

        $stmt = $conn->prepare(rtrim($sql, ", "));
        if ($stmt) {
            $stmt->bind_param($types, ...$params);
            if ($stmt->execute()) {
                $stmt->close();
                header('Content-Type: application/json');
                echo json_encode(['success' => true, 'message' => 'User updated successfully!']);
            } else {
                throw new Exception('Failed to update user: ' . htmlspecialchars($stmt->error));
            }
        } else {
            throw new Exception('Failed to prepare statement: ' . htmlspecialchars($conn->error));
        }
    } catch (Exception $e) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'An error occurred: ' . $e->getMessage()]);
    }
    exit();
}
function countAdminUsers($conn)
{
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM users JOIN roles ON users.role_id = roles.id WHERE roles.name = 'Admin'");
    if ($stmt) {
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stmt->close();
        return $row['count'];
    }
    return 0;
}

function deleteUser($conn, $user_id)
{
    // Check if the user is an admin
    $stmt = $conn->prepare("SELECT roles.name FROM users JOIN roles ON users.role_id = roles.id WHERE users.id = ?");
    if ($stmt) {
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stmt->close();

        if ($row['name'] === 'Admin') {
            // Check if this is the last admin
            if (countAdminUsers($conn) <= 1) {
                return ['success' => false, 'message' => 'Cannot delete the last Admin account.'];
            }
        }
    }

    $stmt = $conn->prepare("DELETE FROM users WHERE id = ?");
    if ($stmt) {
        $stmt->bind_param("i", $user_id);
        if ($stmt->execute()) {
            return ['success' => true, 'message' => 'User deleted successfully!'];
        } else {
            return ['success' => false, 'message' => 'Error deleting user: ' . htmlspecialchars($stmt->error)];
        }
        $stmt->close();
    } else {
        return ['success' => false, 'message' => 'Failed to prepare statement for deleting user.'];
    }
}

function getUsers($conn)
{
    $users = [];
    $stmt = $conn->prepare("SELECT users.id, users.username, users.email, roles.name AS role FROM users JOIN roles ON users.role_id = roles.id");
    if ($stmt) {
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $users[] = $row;
        }
        $stmt->close();
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'users' => $users]);
    } else {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Failed to prepare statement for fetching users.']);
    }
    exit();
}

function addRole($conn, $name, $description, $permissions)
{
    try {
        error_log("Attempting to add role with name: " . $name);
        // Check if the role already exists
        $check_stmt = $conn->prepare("SELECT id FROM roles WHERE name = ?");
        $check_stmt->bind_param("s", $name);
        $check_stmt->execute();
        $check_stmt->store_result();
        $count = $check_stmt->num_rows;
        $check_stmt->close();

        if ($count > 0) {
            error_log("Role '$name' already exists, throwing exception");
            throw new Exception('Role name already exists.');
        }

        error_log("Role '$name' does not exist, proceeding with insert");
        $insert_stmt = $conn->prepare("INSERT INTO roles (name, description) VALUES (?, ?)");
        if ($insert_stmt) {
            $insert_stmt->bind_param("ss", $name, $description);
            if ($insert_stmt->execute()) {
                $role_id = $insert_stmt->insert_id;
                error_log("Role '$name' added successfully, role_id: $role_id");
                $insert_stmt->close(); // Close the insert statement

                foreach ($permissions as $perm_id) {
                    $perm_stmt = $conn->prepare("INSERT INTO role_permissions (role_id, permission_id) VALUES (?, ?)");
                    if ($perm_stmt) {
                        $perm_stmt->bind_param("ii", $role_id, $perm_id);
                        if (!$perm_stmt->execute()) {
                            error_log("Failed to add permission '$perm_id' for role '$name': " . htmlspecialchars($perm_stmt->error));
                            throw new Exception('Failed to add permissions: ' . htmlspecialchars($perm_stmt->error));
                        }
                        $perm_stmt->close(); // Close each permission statement
                    } else {
                        error_log("Failed to prepare statement for adding permissions for role '$name'");
                        throw new Exception('Failed to prepare statement for adding permissions.');
                    }
                }

                // Fetch updated roles
                $fetch_stmt = $conn->prepare("SELECT name FROM roles");
                if ($fetch_stmt) {
                    $fetch_stmt->execute();
                    $result = $fetch_stmt->get_result();
                    $roles = [];
                    while ($row = $result->fetch_assoc()) {
                        $roles[] = $row['name'];
                    }
                    $fetch_stmt->close(); // Close the fetch statement
                }
                header('Content-Type: application/json');
                echo json_encode(['success' => true, 'message' => 'Role added successfully!', 'roles' => $roles]);
            } else {
                error_log("Failed to add role '$name': " . htmlspecialchars($insert_stmt->error));
                throw new Exception('Failed to add role: ' . htmlspecialchars($insert_stmt->error));
            }
        } else {
            error_log("Failed to prepare statement for adding role '$name'");
            throw new Exception('Failed to prepare statement for adding role.');
        }
    } catch (Exception $e) {
        error_log("Caught exception in addRole: " . $e->getMessage());
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'An error occurred: ' . $e->getMessage()]);
    }
    exit();
}

function editRole($conn, $name, $description, $role_name, $permissions = null)
{
    try {
        // First, fetch the role_id for the existing role
        $fetch_role_id_stmt = $conn->prepare("SELECT id FROM roles WHERE name = ?");
        $fetch_role_id_stmt->bind_param("s", $role_name);
        $fetch_role_id_stmt->execute();
        $role_id_result = $fetch_role_id_stmt->get_result();

        if ($role_id_result && $role_id_row = $role_id_result->fetch_assoc()) {
            $role_id = $role_id_row['id'];
            $fetch_role_id_stmt->close(); // Close the statement used to fetch role ID

            // Update the role name and description
            $update_role_stmt = $conn->prepare("UPDATE roles SET name = ?, description = ? WHERE id = ?");
            if ($update_role_stmt) {
                $update_role_stmt->bind_param("ssi", $name, $description, $role_id);
                if ($update_role_stmt->execute()) {
                    // Clear existing permissions for this role
                    $delete_permissions_stmt = $conn->prepare("DELETE FROM role_permissions WHERE role_id = ?");
                    $delete_permissions_stmt->bind_param("i", $role_id);
                    $delete_permissions_stmt->execute();
                    $delete_permissions_stmt->close(); // Close the statement after deleting permissions

                    // Add new permissions only if permissions are provided
                    if (is_array($permissions) && !empty($permissions)) {
                        foreach ($permissions as $perm_id) {
                            $insert_permission_stmt = $conn->prepare("INSERT INTO role_permissions (role_id, permission_id) VALUES (?, ?)");
                            if ($insert_permission_stmt) {
                                $insert_permission_stmt->bind_param("ii", $role_id, $perm_id);
                                if (!$insert_permission_stmt->execute()) {
                                    throw new Exception('Failed to add permission ' . $perm_id . ': ' . htmlspecialchars($insert_permission_stmt->error));
                                }
                                $insert_permission_stmt->close(); // Close each insert statement
                            } else {
                                throw new Exception('Failed to prepare statement for adding permission ' . $perm_id);
                            }
                        }
                    }

                    // Fetch updated roles
                    $fetch_roles_stmt = $conn->prepare("SELECT name FROM roles");
                    if ($fetch_roles_stmt) {
                        $fetch_roles_stmt->execute();
                        $result = $fetch_roles_stmt->get_result();
                        $roles = [];
                        while ($row = $result->fetch_assoc()) {
                            $roles[] = $row['name'];
                        }
                        $fetch_roles_stmt->close(); // Close the statement after fetching roles
                    }

                    header('Content-Type: application/json');
                    echo json_encode([
                        'success' => true,
                        'message' => 'Role updated successfully!',
                        'roles' => $roles
                    ]);
                } else {
                    throw new Exception('Failed to update role: ' . htmlspecialchars($update_role_stmt->error));
                }
                $update_role_stmt->close(); // Close the update statement
            } else {
                throw new Exception('Failed to prepare statement for updating role.');
            }
        } else {
            throw new Exception('Role not found.');
        }
    } catch (Exception $e) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'An error occurred: ' . $e->getMessage()]);
    }
    exit();
}

function deleteRole($conn, $role_name)
{
    try {
        // Check if the role has any users assigned to it
        $check_user_stmt = $conn->prepare("SELECT COUNT(*) as count FROM users WHERE role_id = (SELECT id FROM roles WHERE name = ?)");
        $check_user_stmt->bind_param("s", $role_name);
        $check_user_stmt->execute();
        $result = $check_user_stmt->get_result();
        $row = $result->fetch_assoc();
        $check_user_stmt->close();

        if ($row['count'] > 0) {
            throw new Exception('Cannot delete role. There are still users assigned to this role.');
        }

        // Start transaction to ensure data integrity
        $conn->begin_transaction();

        // First, delete from role_permissions to remove associations
        $stmt = $conn->prepare("DELETE FROM role_permissions WHERE role_id = (SELECT id FROM roles WHERE name = ?)");
        $stmt->bind_param("s", $role_name);
        if (!$stmt->execute()) {
            throw new Exception("Failed to delete role permissions: " . $stmt->error);
        }
        $stmt->close();

        // Then, delete the role itself
        $stmt = $conn->prepare("DELETE FROM roles WHERE name = ?");
        $stmt->bind_param("s", $role_name);
        if (!$stmt->execute()) {
            throw new Exception("Failed to delete role: " . $stmt->error);
        }
        $stmt->close();

        // Fetch updated roles
        $stmt = $conn->prepare("SELECT name FROM roles");
        if ($stmt) {
            $stmt->execute();
            $result = $stmt->get_result();
            $roles = [];
            while ($row = $result->fetch_assoc()) {
                $roles[] = $row['name'];
            }
            $stmt->close();
        }

        // If both queries are successful, commit the transaction
        $conn->commit();
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'message' => 'Role deleted successfully!', 'roles' => $roles]);
    } catch (Exception $e) {
        // If any part fails, roll back the transaction
        $conn->rollback();
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'An error occurred: ' . $e->getMessage()]);
    }
    exit();
}

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Call of Duty Simple RCON - Manage Users</title>
    <link rel="stylesheet" type="text/css" href="codrs.css">
</head>

<body>
    <div class="page-container">
        <header>
            <?php include 'header.php'; ?>
        </header>
        <main>
            <div class="container">
                <div class="row">
                    <h1>Manage Users</h1>
                    <div class="container message" id="message_container" style="display: none;"></div>

                    <!-- Users Table -->
                    <table id="users_table">
                        <thead>
                            <tr>
                                <th>Username</th>
                                <th>Email</th>
                                <th>Role</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($users as $user): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($user['username']); ?></td>
                                    <td><?php echo htmlspecialchars($user['email']); ?></td>
                                    <td><?php echo htmlspecialchars($user['role']); ?></td>
                                    <td>
                                        <button onclick="editUser(<?php echo $user['id']; ?>)">Edit</button>
                                        <button onclick="confirmDelete(<?php echo $user['id']; ?>)">Delete</button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>

                    <!-- Form for adding/editing user -->
                    <form id="userForm" class="common-form">
                        <p id="formTitle">Add New User</p>
                        <div class="form-group">
                            <label for="username">Username:</label>
                            <input type="text" id="username" name="username" required>
                        </div>
                        <div class="form-group">
                            <label for="email">Email:</label>
                            <input type="email" id="email" name="email">
                        </div>
                        <div class="form-group">
                            <label for="password">Password:</label>
                            <div class="form-group">
                                <input type="password" id="password" name="password" placeholder="Password">
                            </div>
                        </div>
                        <div class="form-group">
                            <label for="role">Role:</label>
                            <select id="role" name="role" required>
                                <option value="">Select Role</option>
                                <?php foreach ($roles as $role): ?>
                                    <option value="<?php echo htmlspecialchars($role); ?>"><?php echo htmlspecialchars($role); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <input type="hidden" id="user_id" name="user_id" value="">
                        <input type="submit" id="userFormSubmit" value="Add User">
                        <div class="loader" id="userLoader"></div>
                    </form>

                    <form id="manageRolesForm" class="common-form">
                        <p>Manage Roles</p>
                        <div class="manage_roles_wrapper">
                            <div class="manage_roles_left">
                                <div class="form-group-roles">
                                    <label for="role_action">Action:</label>
                                    <select id="role_action" name="role_action" required>
                                        <option value="">Select Action</option>
                                        <option value="add">Add Role</option>
                                        <option value="edit">Edit Role</option>
                                        <option value="delete">Delete Role</option>
                                    </select>
                                </div>
                                <div id="roleDetails" class="form-group-roles">
                                    <div id="existingRoleGroup" style="display: none;">
                                        <label for="existing_role">Select Role:</label>
                                        <select id="existing_role" name="existing_role">
                                            <option value="">Select Role</option>
                                            <?php foreach ($roles as $role): ?>
                                                <?php if ($role != 'Admin'): ?>
                                                    <option value="<?php echo htmlspecialchars($role); ?>"><?php echo htmlspecialchars($role); ?></option>
                                                <?php endif; ?>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div id="roleNameGroup" style="display: none;">
                                        <label for="new_role_name">Role Name:</label>
                                        <input type="text" id="new_role_name" name="new_role_name" placeholder="New Role Name">
                                    </div>
                                    <div id="roleDescriptionGroup" style="display: none;">
                                        <label for="new_role_description">Role Description:</label>
                                        <input type="text" id="new_role_description" name="new_role_description" placeholder="Role Description">
                                    </div>
                                </div>
                            </div>
                            <div class="manage_roles_right">
                                <div id="permissionsCheckboxes" style="display: none;">
                                    <?php foreach ($permissions as $perm): ?>
                                        <div class="permission-checkbox">
                                            <label>
                                                <input type="checkbox" name="permissions[]" value="<?php echo $perm['id']; ?>" id="perm_<?php echo $perm['id']; ?>" <?php if ($perm['name'] == 'accessrcon') echo 'checked disabled'; ?>>
                                                <?php echo htmlspecialchars($perm['name']); ?>
                                            </label>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                                <input type="submit" value="Apply Changes" id="manageRolesSubmit">
                            </div>
                        </div>
                    </form>
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


    <script src="https://ajax.googleapis.com/ajax/libs/jquery/3.5.1/jquery.min.js"></script>
    <script type="text/javascript" src="functions/modal.js"></script>
    <script>
        function displayMessage(message, type) {
            var messageContainer = document.getElementById('message_container');
            messageContainer.innerHTML = message;
            messageContainer.className = 'container message ' + type;
            messageContainer.style.display = 'block';
            setTimeout(function() {
                messageContainer.style.display = 'none';
            }, 5000);
        }

        function editUser(userId) {
            $.ajax({
                url: 'functions/get_user_details.php',
                type: 'POST',
                data: {
                    user_id: userId
                },
                success: function(response) {
                    if (response.success) {
                        document.getElementById('formTitle').textContent = 'Edit User';
                        document.getElementById('username').value = response.username;
                        document.getElementById('email').value = response.email || '';
                        document.getElementById('password').value = ''; // Clear password for security reasons
                        document.getElementById('password').placeholder = 'Enter new password to change (leave blank to keep current)';
                        document.getElementById('role').value = response.role;
                        document.getElementById('user_id').value = userId;
                        document.getElementById('userFormSubmit').value = 'Save Changes';
                        document.getElementById('role').name = "new_role";
                        document.getElementById('username').name = "new_username";
                        document.getElementById('email').name = "new_email";
                        document.getElementById('password').name = "new_password";
                        // Enable all fields for editing
                        ['username', 'email', 'password', 'role'].forEach(field => {
                            document.querySelector(`#userForm input[name="${field}"], #userForm select[name="${field}"]`).disabled = false;
                        });
                    } else {
                        displayMessage(response.message, 'error');
                    }
                },
                error: function(xhr, status, error) {
                    console.error("Error fetching user details:", status, error);
                    displayMessage('Error loading user details.', 'error');
                }
            });
        }

        function resetUserForm() {
            document.getElementById('userForm').reset();
            document.getElementById('user_id').value = '';
            document.getElementById('formTitle').textContent = 'Add New User';
            document.getElementById('userFormSubmit').value = 'Add User';
            document.getElementById('role').name = "role";
            document.getElementById('username').name = "username";
            document.getElementById('email').name = "email";
            document.getElementById('password').name = "password";
            document.getElementById('password').placeholder = "Password"; // Reset placeholder for adding new user
            document.getElementById('password').disabled = false; // Password might be required for new users
        }

        $('#userForm').submit(function(e) {
            e.preventDefault();
            var formData = $(this).serialize();
            var action = document.getElementById('user_id').value ? 'edit_user' : 'add_user';
            var loader = document.getElementById('userLoader');
            loader.style.display = 'inline-block';
            $.ajax({
                url: 'manage_users.php',
                type: 'POST',
                data: formData + '&action=' + action,
                success: function(response) {
                    loader.style.display = 'none';
                    if (response.success) {
                        displayMessage(response.message, 'success');
                        refreshUsersTable();
                        resetUserForm();
                    } else {
                        displayMessage(response.message, 'error');
                    }
                },
                error: function() {
                    displayMessage('Error processing user data.', 'error');
                    loader.style.display = 'none';
                }
            });
        });

        function refreshUsersTable() {
            $.ajax({
                url: 'manage_users.php',
                type: 'POST',
                data: {
                    action: 'get_users'
                },
                success: function(response) {
                    if (response.success) {
                        var tbody = document.querySelector('#users_table tbody');
                        tbody.innerHTML = ''; // Clear existing rows
                        response.users.forEach(user => {
                            // Ensure email is displayed, use empty string if null/undefined
                            const email = user.email || '';
                            tbody.insertAdjacentHTML('beforeend', `
                        <tr>
                            <td>${user.username}</td>
                            <td>${email}</td>
                            <td>${user.role}</td>
                            <td>
                                <button onclick="editUser(${user.id})">Edit</button>
                                <button onclick="confirmDelete(${user.id})">Delete</button>
                            </td>
                        </tr>
                    `);
                        });
                    } else {
                        displayMessage('Failed to refresh user list: ' + response.message, 'error');
                    }
                },
                error: function() {
                    displayMessage('Error refreshing user list.', 'error');
                }
            });
        }



        function confirmDelete(userId) {
            customConfirm("Are you sure you want to delete this user?", function(confirmed) {
                if (confirmed) {
                    var loader = document.getElementById('userLoader');
                    loader.style.display = 'inline-block';
                    $.ajax({
                        url: 'manage_users.php',
                        type: 'POST',
                        data: {
                            action: 'delete_user',
                            user_id: userId
                        },
                        success: function(response) {
                            loader.style.display = 'none';
                            if (response.success) {
                                displayMessage(response.message, 'success');
                                refreshUsersTable();
                            } else {
                                displayMessage(response.message, 'error');
                            }
                        },
                        error: function() {
                            displayMessage('Error deleting user.', 'error');
                            loader.style.display = 'none';
                        }
                    });
                }
            });
        }

        document.addEventListener('DOMContentLoaded', function() {
            document.getElementById('role_action').addEventListener('change', function() {
                onRoleActionChange(this.value);
            });

            function onRoleActionChange(value) {
                toggleRoleInputs(value);
            }

            function toggleRoleInputs(action) {
                document.getElementById('roleNameGroup').style.display = 'none';
                document.getElementById('roleDescriptionGroup').style.display = 'none';
                document.getElementById('existingRoleGroup').style.display = 'none';
                document.getElementById('permissionsCheckboxes').style.display = 'none';

                if (action === 'add') {
                    document.getElementById('roleNameGroup').style.display = 'block';
                    document.getElementById('roleDescriptionGroup').style.display = 'block';
                    document.getElementById('permissionsCheckboxes').style.display = 'block';
                    clearPermissions();
                } else if (action === 'edit') {
                    document.getElementById('existingRoleGroup').style.display = 'block';
                    document.getElementById('roleNameGroup').style.display = 'block';
                    document.getElementById('roleDescriptionGroup').style.display = 'block';
                    document.getElementById('permissionsCheckboxes').style.display = 'block';
                } else if (action === 'delete') {
                    document.getElementById('existingRoleGroup').style.display = 'block';
                }
            }

            function clearPermissions() {
                document.querySelectorAll('input[name="permissions[]"]').forEach(checkbox => {
                    if (!checkbox.disabled) {
                        checkbox.checked = false;
                    }
                });
            }

            document.getElementById('existing_role').addEventListener('change', function() {
                var selectedRole = this.value;
                if (selectedRole) {
                    loadRoleDetails(selectedRole);
                    loadRolePermissions(selectedRole);
                }
            });

            function loadRoleDetails(roleName) {
                $.ajax({
                    url: 'manage_users.php',
                    type: 'POST',
                    data: {
                        action: 'get_role_details',
                        role_name: roleName
                    },
                    success: function(response) {
                        if (response.success) {
                            document.getElementById('new_role_name').value = response.name;
                            document.getElementById('new_role_description').value = response.description;
                        } else {
                            console.error('Failed to load role details:', response.message);
                        }
                    },
                    error: function(xhr, status, error) {
                        console.error('Error loading role details:', error);
                    }
                });
            }

            function loadRolePermissions(roleName) {
                clearPermissions();
                $.ajax({
                    url: 'manage_users.php',
                    type: 'POST',
                    data: {
                        action: 'get_role_permissions',
                        role_name: roleName
                    },
                    success: function(response) {
                        if (response.success) {
                            response.permissions.forEach(function(permId) {
                                var checkbox = document.getElementById('perm_' + permId);
                                if (checkbox) {
                                    checkbox.checked = true;
                                }
                            });
                        } else {
                            console.error('Failed to load permissions:', response.message);
                        }
                    },
                    error: function(xhr, status, error) {
                        console.error('Error loading role permissions:', error);
                    }
                });
            }

            document.getElementById('manageRolesForm').addEventListener('submit', function(event) {
                event.preventDefault();
                var action = document.getElementById('role_action').value;
                var roleName = document.getElementById('new_role_name').value.trim();
                var roleDescription = document.getElementById('new_role_description').value.trim();
                var existingRole = document.getElementById('existing_role').value;
                var permissions = Array.from(document.querySelectorAll('input[name="permissions[]"]:checked:not([disabled])')).map(checkbox => checkbox.value);

                if (action === 'add') {
                    if (roleName === '' || roleDescription === '') {
                        displayMessage('Please provide both a name and description for the new role.', 'error');
                        return;
                    }
                    $.ajax({
                        url: 'manage_users.php',
                        type: 'POST',
                        data: {
                            action: 'add_role',
                            name: roleName,
                            description: roleDescription,
                            permissions: permissions
                        },
                        success: function(response) {
                            if (response.success) {
                                displayMessage(response.message, 'success');
                                refreshRolesDropdown();
                            } else {
                                displayMessage(response.message, 'error');
                            }
                        },
                        error: function(xhr, status, error) {
                            console.error('Error adding role:', error);
                            displayMessage('An error occurred while adding the role. Please try again or contact support.', 'error');
                        }
                    });
                } else if (action === 'edit') {
                    if (!existingRole || roleName === '' || roleDescription === '') {
                        displayMessage('Please select a role and provide both a name and description.', 'error');
                        return;
                    }
                    $.ajax({
                        url: 'manage_users.php',
                        type: 'POST',
                        data: {
                            action: 'edit_role',
                            name: roleName,
                            description: roleDescription,
                            existing_role: existingRole,
                            permissions: permissions
                        },
                        success: function(response) {
                            if (response.success) {
                                displayMessage(response.message, 'success');
                                refreshRolesDropdown();
                            } else {
                                displayMessage(response.message, 'error');
                            }
                        },
                        error: function(xhr, status, error) {
                            console.error('Error editing role:', error);
                            displayMessage('An error occurred while editing the role. Please try again or contact support.', 'error');
                        }
                    });
                } else if (action === 'delete') {
                    if (!existingRole) {
                        displayMessage('Please select a role to delete.', 'error');
                        return;
                    }
                    if (confirm('Are you sure you want to delete this role?')) {
                        $.ajax({
                            url: 'manage_users.php',
                            type: 'POST',
                            data: {
                                action: 'delete_role',
                                existing_role: existingRole
                            },
                            success: function(response) {
                                if (response.success) {
                                    displayMessage(response.message, 'success');
                                    refreshRolesDropdown();
                                } else {
                                    displayMessage(response.message, 'error');
                                }
                            },
                            error: function(xhr, status, error) {
                                console.error('Error deleting role:', error);
                                displayMessage('An error occurred while deleting the role. Please try again or contact support.', 'error');
                            }
                        });
                    }
                }
            });

            function refreshRolesDropdown() {
                $.ajax({
                    url: 'manage_users.php',
                    type: 'POST',
                    data: {
                        action: 'get_roles'
                    },
                    success: function(response) {
                        if (response.success) {
                            var roleSelect = document.getElementById('existing_role');
                            roleSelect.innerHTML = '<option value="">Select Role</option>';
                            response.roles.forEach(function(role) {
                                if (role !== 'Admin') {
                                    var option = document.createElement('option');
                                    option.value = role;
                                    option.text = role;
                                    roleSelect.appendChild(option);
                                }
                            });
                        } else {
                            console.error('Failed to refresh roles:', response.message);
                        }
                    },
                    error: function(xhr, status, error) {
                        console.error('Error refreshing roles:', error);
                    }
                });
            }

            // Initial refresh for both dropdown and table
            refreshUsersTable();
            refreshRolesDropdown();
        });
    </script>
</body>

</html>
