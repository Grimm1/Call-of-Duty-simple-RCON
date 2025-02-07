<?php
session_start();

if (!isset($_SESSION['permissions']) || !isset($_SESSION['permissions']['manage_users'])) {
    echo "<div class='error'>You do not have permission to access this page.</div>";
    header("Refresh:2; url=index.php"); // Optionally, delay the redirect to show the message
    exit();
}

include 'config/database.php';
if (!isset($conn) || !$conn->ping()) {
    die("Database connection failed!");
}

// Fetch user data including roles
$users = [];
$stmt = $conn->prepare("SELECT users.id, users.username, roles.name AS role FROM users JOIN roles ON users.role_id = roles.id WHERE roles.name = 'minion' OR roles.name = 'admin'");
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

$permissions = []; // This should be populated with data from your permissions table
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

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'add_user':
                addUser($conn, $_POST['username'], $_POST['email'], $_POST['password'], $_POST['role']);
                break;
            case 'edit_user':
                editUser($conn, $_POST['user_id'], $_POST['new_username'], $_POST['new_password'], $_POST['new_role']);
                break;
            case 'get_users':
                getUsers($conn);
                break;
            case 'delete_user': // Existing case for deleting a user
                deleteUser($conn, $_POST['user_id']);
                break;
            case 'get_role_permissions': // New case for fetching role permissions
                $roleName = $_POST['role_name'];
                $permissions = getRolePermissions($conn, $roleName);
                echo json_encode(['success' => true, 'permissions' => $permissions]);
                break;
            case 'add_role':
                addRole($conn, $_POST['name'], $_POST['description'], $_POST['permissions']);
                break;
            case 'edit_role':
                editRole($conn, $_POST['name'], $_POST['description'], $_POST['existing_role'], $_POST['permissions']);
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
                        echo json_encode(['success' => true, 'name' => $roleDetails['name'], 'description' => $roleDetails['description']]);
                    } else {
                        echo json_encode(['success' => false, 'message' => 'Role not found.']);
                    }
                } else {
                    echo json_encode(['success' => false, 'message' => 'Failed to prepare statement.']);
                }
                break;
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
                    echo json_encode(['success' => true, 'roles' => $roles]);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Failed to prepare statement for fetching roles.']);
                }
                break;
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
        // Log the error or handle it appropriately
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
            // If email is not provided, use NULL for the database insertion
            $emailToUse = $email ?: NULL;
            $stmt->bind_param("ssss", $username, $password, $emailToUse, $role);
            if ($stmt->execute()) {
                $user_id = $stmt->insert_id;
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
                echo json_encode(['success' => true, 'message' => 'User added successfully!']);
            } else {
                throw new Exception('Failed to add user: ' . htmlspecialchars($stmt->error));
            }
            $stmt->close();
        } else {
            throw new Exception('Failed to prepare statement for adding user.');
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'An error occurred: ' . $e->getMessage()]);
    }
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
                echo json_encode(['success' => false, 'message' => 'Cannot delete the last Admin account.']);
                return;
            }
        }
    }

    $stmt = $conn->prepare("DELETE FROM users WHERE id = ?");
    if ($stmt) {
        $stmt->bind_param("i", $user_id);
        if ($stmt->execute()) {
            echo json_encode(['success' => true, 'message' => 'User deleted successfully!']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Error deleting user: ' . htmlspecialchars($stmt->error)]);
        }
        $stmt->close();
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to prepare statement for deleting user.']);
    }
}

function editUser($conn, $user_id, $new_username, $new_password, $new_role)
{
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
                echo json_encode(['success' => false, 'message' => 'Cannot change the role of the last Admin account.']);
                return;
            }
        }
    }

    $sql = "UPDATE users SET ";
    $params = [];
    $types = "";

    if ($new_username !== "") {
        $sql .= "username = ?, ";
        $params[] = $new_username;
        $types .= "s";
    }

    if ($new_password !== "") {
        $new_password = password_hash($new_password, PASSWORD_BCRYPT);
        $sql .= "password = ?, ";
        $params[] = $new_password;
        $types .= "s";
    }

    $sql .= "role_id = (SELECT id FROM roles WHERE name = ?) WHERE id = ?";
    $params[] = $new_role;
    $params[] = $user_id;
    $types .= "si";

    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $stmt->close();
        echo json_encode(['success' => true, 'message' => 'User updated successfully!']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Statement preparation failed: ' . htmlspecialchars($conn->error)]);
    }
}

function getUsers($conn)
{
    $users = [];
    $stmt = $conn->prepare("SELECT users.id, users.username, roles.name AS role FROM users JOIN roles ON users.role_id = roles.id WHERE roles.name = 'minion' OR roles.name = 'admin'");
    if ($stmt) {
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $users[] = $row;
        }
        $stmt->close();
        echo json_encode(['success' => true, 'users' => $users]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to prepare statement for fetching users.']);
    }
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
        $stmt = $conn->prepare("INSERT INTO roles (name, description) VALUES (?, ?)");
        if ($stmt) {
            $stmt->bind_param("ss", $name, $description);
            if ($stmt->execute()) {
                $role_id = $stmt->insert_id;
                error_log("Role '$name' added successfully, role_id: $role_id");
                foreach ($permissions as $perm_id) {
                    $insert_stmt = $conn->prepare("INSERT INTO role_permissions (role_id, permission_id) VALUES (?, ?)");
                    if ($insert_stmt) {
                        $insert_stmt->bind_param("ii", $role_id, $perm_id);
                        if (!$insert_stmt->execute()) {
                            error_log("Failed to add permission '$perm_id' for role '$name': " . htmlspecialchars($insert_stmt->error));
                            throw new Exception('Failed to add permissions: ' . htmlspecialchars($insert_stmt->error));
                        }
                        $insert_stmt->close();
                    } else {
                        error_log("Failed to prepare statement for adding permissions for role '$name'");
                        throw new Exception('Failed to prepare statement for adding permissions.');
                    }
                }
                echo json_encode(['success' => true, 'message' => 'Role added successfully!']);
            } else {
                error_log("Failed to add role '$name': " . htmlspecialchars($stmt->error));
                throw new Exception('Failed to add role: ' . htmlspecialchars($stmt->error));
            }
            $stmt->close();
        } else {
            error_log("Failed to prepare statement for adding role '$name'");
            throw new Exception('Failed to prepare statement for adding role.');
        }
    } catch (Exception $e) {
        error_log("Caught exception in addRole: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'An error occurred: ' . $e->getMessage()]);
    }
}

function editRole($conn, $name, $description, $role_name, $permissions = null)
{
    try {
        // First, fetch the role_id for the existing role
        $role_id_stmt = $conn->prepare("SELECT id FROM roles WHERE name = ?");
        $role_id_stmt->bind_param("s", $role_name);
        $role_id_stmt->execute();
        $role_id_result = $role_id_stmt->get_result();

        if ($role_id_result && $role_id_row = $role_id_result->fetch_assoc()) {
            $role_id = $role_id_row['id'];
            $role_id_stmt->close();

            // Update the role name and description
            $stmt = $conn->prepare("UPDATE roles SET name = ?, description = ? WHERE id = ?");
            if ($stmt) {
                $stmt->bind_param("ssi", $name, $description, $role_id);
                if ($stmt->execute()) {
                    // Clear existing permissions for this role
                    $delete_stmt = $conn->prepare("DELETE FROM role_permissions WHERE role_id = ?");
                    $delete_stmt->bind_param("i", $role_id);
                    $delete_stmt->execute();
                    $delete_stmt->close();

                    // Add new permissions only if permissions are provided
                    if (is_array($permissions) && !empty($permissions)) {
                        foreach ($permissions as $perm_id) {
                            $insert_stmt = $conn->prepare("INSERT INTO role_permissions (role_id, permission_id) VALUES (?, ?)");
                            if ($insert_stmt) {
                                $insert_stmt->bind_param("ii", $role_id, $perm_id);
                                if (!$insert_stmt->execute()) {
                                    throw new Exception('Failed to add permission ' . $perm_id . ': ' . htmlspecialchars($insert_stmt->error));
                                }
                                $insert_stmt->close();
                            } else {
                                throw new Exception('Failed to prepare statement for adding permission ' . $perm_id);
                            }
                        }
                        echo json_encode(['success' => true, 'message' => 'Role updated successfully!']);
                    } else {
                        // If no permissions are sent, still consider the role update successful since we've cleared permissions
                        echo json_encode(['success' => true, 'message' => 'Role updated successfully!']);
                    }
                } else {
                    throw new Exception('Failed to update role: ' . htmlspecialchars($stmt->error));
                }
                $stmt->close();
            } else {
                throw new Exception('Failed to prepare statement for updating role.');
            }
        } else {
            throw new Exception('Role not found.');
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'An error occurred: ' . $e->getMessage()]);
    }
}

function deleteRole($conn, $role_name)
{
    try {
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

        // If both queries are successful, commit the transaction
        $conn->commit();
        echo json_encode(['success' => true, 'message' => 'Role deleted successfully!']);
    } catch (Exception $e) {
        // If any part fails, roll back the transaction
        $conn->rollback();
        echo json_encode(['success' => false, 'message' => 'An error occurred: ' . $e->getMessage()]);
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Call of Duty Simple RCON - Manage Users</title>
    <link rel="stylesheet" type="text/css" href="codrs.css?v=<?php echo time(); ?>">
    <style>
        .manage_roles_wrapper {
            display: flex;
            flex-direction: row;
            gap: 20px;
            /* Space between left and right sections */
        }

        .manage_roles_left {
            flex: 2;
        }

        .manage_roles_right {
            flex: 1;
        }

        .manage_roles_right {
            display: flex;
            flex-direction: column;
            justify-content: flex-end;
            /* Aligns content to the bottom */
        }

        .form-group-roles {
            display: flex;
            flex-direction: column;
            margin-bottom: 15px;
        }

        .form-group-roles label,
        .form-group-roles input,
        .form-group-roles select {
            width: 100%;
            margin-bottom: 5px;
            /* Space between label and input/select */
        }

        #roleDetails {
            display: flex;
            flex-direction: column;
        }

        #permissionsCheckboxes {
            display: flex;
            flex-direction: column;
            flex-grow: 1;
            /* Allows this div to grow and push submit button to bottom */
        }

        .permission-checkbox {
            margin-bottom: 10px;
        }

        /* Ensure checkboxes don't push to the right */
        .permission-checkbox label {
            display: flex;
            align-items: center;
        }

        #manageRolesSubmit {
            margin-top: 10px;
        }
    </style>
</head>

<body>
    <div class="page-container">
        <header>
            <?php include 'header.php'; ?>
        </header>

        <main>
            <div class=container>
                <div class="row">
                    <h1>Manage Users</h1>
                    <div class="imp_message" id="message"></div>
                    <!-- Add User Form -->
                    <form id="addUserForm" class="common-form">
                        <p>Add New User</p>
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
                            <input type="password" id="password" name="password" required>
                        </div>
                        <div class="form-group">
                            <label for="role">Role:</label>
                            <select id="role" name="role" required>
                                <option value="">Select Role</option>
                                <?php
                                if (is_array($roles)) {
                                    foreach ($roles as $index => $role) {
                                        // Fetch the description for this role
                                        $stmt = $conn->prepare("SELECT description FROM roles WHERE name = ?");
                                        if ($stmt) {
                                            $stmt->bind_param("s", $role);
                                            $stmt->execute();
                                            $result = $stmt->get_result();
                                            $roleDescription = $result->fetch_assoc()['description'] ?? 'No description available';
                                            $stmt->close();

                                            echo '<option value="' . htmlspecialchars($role) . '" title="' . htmlspecialchars($roleDescription) . '">' . htmlspecialchars($role) . '</option>';
                                        }
                                    }
                                } else {
                                    echo '<option value="">Error: Roles not available</option>';
                                }
                                ?>
                            </select>
                        </div>
                        <input type="submit" value="Add User">
                        <div class="loader" id="addLoader"></div>
                    </form>

                    <!-- Edit User Form -->

                    <form id="editUserForm" class="common-form">
                        <p>Edit Users</p>
                        <div class="form-group">
                            <label for="user_id">Select User:</label>
                            <select id="user_select" name="user_id">
                                <option value="">Select User</option>
                                <?php foreach ($users as $user): ?>
                                    <option value="<?php echo $user['id']; ?>"><?php echo htmlspecialchars($user['username']); ?> (<?php echo htmlspecialchars($user['role']); ?>)</option>
                                <?php endforeach; ?>
                            </select>
                            <div class="loader" id="editLoader"></div>
                        </div>
                        <div class="form-group" id="usernameGroup">
                            <label for="new_username">New Username:</label>
                            <input type="text" id="new_username" name="new_username">
                        </div>
                        <div class="form-group" id="passwordGroup">
                            <label for="new_password">New Password:</label>
                            <input type="password" id="new_password" name="new_password">
                        </div>
                        <div class="form-group" id="roleGroup">
                            <label for="new_role">Role:</label>
                            <select id="new_role" name="new_role" required>
                                <?php foreach ($roles as $role): ?>
                                    <?php
                                    $stmt = $conn->prepare("SELECT description FROM roles WHERE name = ?");
                                    if ($stmt) {
                                        $stmt->bind_param("s", $role);
                                        $stmt->execute();
                                        $result = $stmt->get_result();
                                        $roleDescription = $result->fetch_assoc()['description'] ?? 'No description available';
                                        $stmt->close();
                                    }
                                    ?>
                                    <option value="<?php echo htmlspecialchars($role); ?>" title="<?php echo htmlspecialchars($roleDescription); ?>"><?php echo htmlspecialchars($role); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <input type="submit" value="Edit User">
                        <input type="button" id="deleteUserBtn" value="Delete User">
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
        <footer>
            <?php include 'footer.php'; ?>
        </footer>
    </div>
    <script src="https://ajax.googleapis.com/ajax/libs/jquery/3.5.1/jquery.min.js"></script>
    <script type="text/javascript" src="functions/modal.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            document.getElementById('role_action').addEventListener('change', function() {
                onRoleActionChange(this.value);
            });
            console.log('Script loaded and running');

            function onRoleActionChange(value) {
                console.log('onRoleActionChange called with value:', value);
                toggleRoleInputs(value);
            }

            function toggleRoleInputs(action) {
                console.log('toggleRoleInputs called with action:', action);

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

            function loadRoleDetails(roleName) {
                $.ajax({
                    url: 'manage_users.php',
                    type: 'POST',
                    data: {
                        action: 'get_role_details',
                        role_name: roleName
                    },
                    success: function(response) {
                        var data = JSON.parse(response);
                        if (data.success) {
                            document.getElementById('new_role_name').value = data.name || '';
                            document.getElementById('new_role_description').value = data.description || '';
                        } else {
                            console.error('Failed to load role details:', data.message);
                        }
                    },
                    error: function(xhr, status, error) {
                        console.error('Error loading role details:', error);
                    }
                });
            }

            document.getElementById('user_select').addEventListener('change', function() {
                loadUserDetails(this.value);
            });

            function loadUserDetails(userId) {
                if (userId) {
                    var loader = document.getElementById('editLoader');
                    loader.style.display = 'inline-block';
                    $.ajax({
                        url: 'functions/get_user_details.php',
                        type: 'POST',
                        data: {
                            user_id: userId
                        },
                        success: function(response) {
                            var data = JSON.parse(response);
                            if (data.success) {
                                $('#new_username').val(data.username);
                                $('#new_role').val(data.role);
                            } else {
                                $('#message').html('<div class="error">' + data.message + '</div>');
                            }
                            loader.style.display = 'none';
                        },
                        error: function() {
                            $('#message').html('<div class="error">Error loading user details.</div>');
                            loader.style.display = 'none';
                        }
                    });
                }
            }

            function refreshUserDropdown() {
                $.ajax({
                    url: 'manage_users.php',
                    type: 'POST',
                    data: {
                        action: 'get_users'
                    },
                    success: function(response) {
                        var data = JSON.parse(response);
                        if (data.success) {
                            var $dropdown = $('#user_select');
                            $dropdown.empty();
                            $dropdown.append($('<option>', {
                                value: "",
                                text: "Select User"
                            }));
                            data.users.forEach(user => {
                                $dropdown.append($('<option>', {
                                    value: user.id,
                                    text: `${user.username} (${user.role})`
                                }));
                            });
                        } else {
                            $('#message').html('<div class="error">Failed to refresh user list.</div>');
                        }
                    },
                    error: function() {
                        $('#message').html('<div class="error">Error refreshing user list.</div>');
                    }
                });
            }

            function refreshRolesDropdown() {
                $.ajax({
                    url: 'manage_users.php',
                    type: 'POST',
                    data: {
                        action: 'get_roles'
                    },
                    success: function(response) {
                        var data = JSON.parse(response);
                        if (data.success) {
                            var roleSelect = document.getElementById('existing_role');
                            roleSelect.innerHTML = '<option value="">Select Role</option>';
                            data.roles.forEach(function(role) {
                                if (role !== 'Admin') {
                                    var option = document.createElement('option');
                                    option.value = role;
                                    option.text = role;
                                    roleSelect.appendChild(option);
                                }
                            });
                        } else {
                            console.error('Failed to refresh roles:', data.message);
                        }
                    },
                    error: function(xhr, status, error) {
                        console.error('Error refreshing roles:', error);
                    }
                });
            }

            function deleteUser(userId) {
                if (userId) {
                    customConfirm("Are you sure you want to delete this user?", function(confirmed) {
                        if (confirmed) {
                            var loader = document.getElementById('editLoader');
                            loader.style.display = 'inline-block';
                            $.ajax({
                                url: 'manage_users.php',
                                type: 'POST',
                                data: {
                                    action: 'delete_user',
                                    user_id: userId
                                },
                                success: function(response) {
                                    var data = JSON.parse(response);
                                    showAjaxMessage(data.message, function() {
                                        loader.style.display = 'none';
                                        refreshUserDropdown();
                                    });
                                },
                                error: function() {
                                    showAjaxMessage("Error deleting user.", function() {
                                        loader.style.display = 'none';
                                    });
                                }
                            });
                        }
                    });
                } else {
                    showAjaxMessage("Please select a user to delete.");
                }
            }

            function clearRoleFormFields() {
                document.getElementById('new_role_name').value = '';
                document.getElementById('new_role_description').value = '';
                document.getElementById('existing_role').selectedIndex = 0;
                clearPermissions();
            }

            $(document).ready(function() {
                $('#addUserForm').submit(function(e) {
                    e.preventDefault();
                    var loader = document.getElementById('addLoader');
                    loader.style.display = 'inline-block';
                    $.ajax({
                        url: 'manage_users.php',
                        type: 'POST',
                        data: $(this).serialize() + '&action=add_user',
                        success: function(response) {
                            var data = JSON.parse(response);
                            $('#message').html('<div class="' + (data.success ? 'success' : 'error') + '">' + data.message + '</div>');
                            loader.style.display = 'none';
                            refreshUserDropdown();
                        },
                        error: function() {
                            $('#message').html('<div class="error">Error adding user.</div>');
                            loader.style.display = 'none';
                        }
                    });
                });

                $('#editUserForm').submit(function(e) {
                    e.preventDefault();
                    var formData = $(this).serialize() + '&action=edit_user';
                    var loader = document.getElementById('editLoader');
                    loader.style.display = 'inline-block';
                    $.ajax({
                        url: 'manage_users.php',
                        type: 'POST',
                        data: formData,
                        success: function(response) {
                            var data = JSON.parse(response);
                            $('#message').html('<div class="' + (data.success ? 'success' : 'error') + '">' + data.message + '</div>');
                            loader.style.display = 'none';
                            refreshUserDropdown(); // Refresh user dropdown after edit
                        },
                        error: function() {
                            $('#message').html('<div class="error">Error updating user.</div>');
                            loader.style.display = 'none';
                        }
                    });
                });

                // Delete user button click handler
                $('#deleteUserBtn').click(function() {
                    var userId = $('#user_select').val();
                    if (userId) {
                        deleteUser(userId);
                    } else {
                        $('#message').html('<div class="error">Please select a user to delete.</div>');
                    }
                });

                // Trigger refresh when the page loads to ensure the dropdown shows current data
                refreshUserDropdown();
            });

            let isSubmitting = false;
            document.getElementById('manageRolesForm').addEventListener('submit', function(event) {
                event.preventDefault();
                if (isSubmitting) {
                    console.error('Attempted to submit while another submission is in progress');
                    return;
                }
                isSubmitting = true;
                console.log('Submitting form with action:', document.getElementById('role_action').value);

                let action = document.getElementById('role_action').value;
                let roleName = document.getElementById('new_role_name').value.trim();
                let roleDescription = document.getElementById('new_role_description').value.trim();
                let existingRole = document.getElementById('existing_role').value;

                // Define permissions here
                let permissions = Array.from(document.querySelectorAll('input[name="permissions[]"]:checked:not([disabled])')).map(checkbox => checkbox.value);
                let accessrconCheckbox = document.querySelector('input[name="permissions[]"][value="4"]');
                if (accessrconCheckbox) {
                    permissions.push(accessrconCheckbox.value);
                    console.log('accessrcon permission added to array');
                }
                console.log('Permissions to be sent:', permissions);

                // If no other permissions are checked, accessrcon will be the only one in the array
                console.log('Permissions to be sent:', permissions);

                if (action === 'add') {
                    if (roleName === '' || roleDescription === '') {
                        customMessage('Please provide both a name and description for the new role.');
                        isSubmitting = false;
                        return;
                    }
                    document.getElementById('manageRolesSubmit').disabled = true; // Disable button
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
                            document.getElementById('manageRolesSubmit').disabled = false;
                            var data = JSON.parse(response);
                            if (data.success) {
                                console.log('Role Added:', response);
                                refreshRolesDropdown();
                                customMessage('Role added successfully');
                            } else {
                                console.error('Role Addition Failed:', data.message);
                                customMessage(data.message);
                            }
                        },
                        error: function(xhr, status, error) {
                            document.getElementById('manageRolesSubmit').disabled = false;
                            console.error('Error adding role:', error);
                            if (xhr.responseText) {
                                console.error('Server Response:', xhr.responseText);
                            }
                            customMessage('An error occurred while adding the role. Please try again or contact support.');
                        },
                        complete: function() {
                            isSubmitting = false; // Reset flag
                            console.log('AJAX call completed, isSubmitting flag reset');
                        }
                    });
                } else if (action === 'edit') {
                    if (!existingRole) {
                        customMessage('Please select a role to edit.');
                        isSubmitting = false;
                        return;
                    }
                    if (roleName === '' || roleDescription === '') {
                        customMessage('Please provide both a name and description for the role.');
                        isSubmitting = false;
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
                            var data = JSON.parse(response);
                            if (data.success) {
                                console.log('Role Edited:', response);
                                refreshRolesDropdown();
                                customMessage('Role edited successfully');
                            } else {
                                console.error('Role Edit Failed:', data.message);
                                customMessage(data.message);
                            }
                        },
                        error: function(xhr, status, error) {
                            console.error('Error editing role:', error);
                            if (xhr.responseText) {
                                console.error('Server Response:', xhr.responseText);
                            }
                            customMessage('An error occurred while editing the role. Please try again or contact support.');
                        },
                        complete: function() {
                            isSubmitting = false;
                        }
                    });
                } else if (action === 'delete') {
                    if (!existingRole) {
                        customMessage('Please select a role to delete.');
                        isSubmitting = false;
                        return;
                    }
                    customConfirm('Are you sure you want to delete this role?', function(confirmed) {
                        if (confirmed) {
                            $.ajax({
                                url: 'manage_users.php',
                                type: 'POST',
                                data: {
                                    action: 'delete_role',
                                    existing_role: existingRole
                                },
                                success: function(response) {
                                    var data = JSON.parse(response);
                                    if (data.success) {
                                        console.log('Role Deleted:', response);
                                        refreshRolesDropdown();
                                        customMessage('Role deleted successfully');
                                    } else {
                                        console.error('Role Deletion Failed:', data.message);
                                        customMessage(data.message);
                                    }
                                },
                                error: function(xhr, status, error) {
                                    console.error('Error deleting role:', error);
                                    if (xhr.responseText) {
                                        console.error('Server Response:', xhr.responseText);
                                    }
                                    customMessage('An error occurred while deleting the role. Please try again or contact support.');
                                },
                                complete: function() {
                                    isSubmitting = false;
                                }
                            });
                        } else {
                            isSubmitting = false; // Reset flag if user cancels deletion
                        }
                    });
                }
            });

            // Bind the change event for the existing_role dropdown in JavaScript
            document.getElementById('existing_role').addEventListener('change', function() {
                var selectedRole = this.value;
                loadRolePermissions(selectedRole);
                loadRoleDetails(selectedRole);
            });

            function loadRolePermissions(roleName) {
                clearPermissions(); // Clear any previously checked permissions
                $.ajax({
                    url: 'manage_users.php',
                    type: 'POST',
                    data: {
                        action: 'get_role_permissions',
                        role_name: roleName
                    },
                    success: function(response) {
                        var data = JSON.parse(response);
                        if (data.success) {
                            data.permissions.forEach(function(permId) {
                                var checkbox = document.getElementById('perm_' + permId);
                                if (checkbox) {
                                    checkbox.checked = true;
                                }
                            });
                        } else {
                            console.error('Failed to load permissions:', data.message);
                        }
                    },
                    error: function(xhr, status, error) {
                        console.error('Error loading role permissions:', error);
                    }
                });
            }

            function clearPermissions() {
                document.querySelectorAll('input[name="permissions[]"]').forEach(checkbox => {
                    checkbox.checked = false;
                });
            }

            function populateRoleDropdowns() {
                var existingRoleSelect = document.getElementById('existing_role');
                existingRoleSelect.innerHTML = '<option value="">Select Role</option>';
                roles.forEach(function(role) {
                    if (role !== 'Admin') { // Assuming you don't want to edit 'Admin'
                        var option = document.createElement('option');
                        option.value = role;
                        option.text = role;
                        existingRoleSelect.appendChild(option);
                    }
                });
            }

            // Fetch roles and populate dropdowns when the page loads
            function fetchAndPopulateRoles() {
                $.ajax({
                    url: 'manage_users.php',
                    type: 'POST',
                    data: {
                        action: 'get_roles'
                    },
                    success: function(response) {
                        var data = JSON.parse(response);
                        if (data.success) {
                            roles = data.roles;
                            populateRoleDropdowns();
                        } else {
                            console.error('Failed to fetch roles:', data.message);
                        }
                    },
                    error: function(xhr, status, error) {
                        console.error('Error fetching roles:', error);
                    }
                });
            }
            fetchAndPopulateRoles();
        });
    </script>
</body>

</html>
