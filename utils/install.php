<?php

session_start();

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $host = filter_var($_POST['db_host'], FILTER_SANITIZE_SPECIAL_CHARS);
    $user = filter_var($_POST['db_user'], FILTER_SANITIZE_SPECIAL_CHARS);
    $pass = $_POST['db_pass'];  // No sanitization for password, but use prepared statements
    $name = filter_var($_POST['db_name'], FILTER_SANITIZE_SPECIAL_CHARS);
    $admin_user = filter_var($_POST['admin_user'], FILTER_SANITIZE_SPECIAL_CHARS);

    $admin_pass = $_POST['admin_pass'];
    $admin_pass_confirm = $_POST['admin_pass_confirm'];
    $admin_email = filter_input(INPUT_POST, 'admin_email', FILTER_SANITIZE_EMAIL);

    // Check if passwords match
    if ($admin_pass !== $admin_pass_confirm) {
        $errorMessage = "<div class='error'>Passwords do not match. Please try again.</div>";
    } else {
        $admin_pass = password_hash($admin_pass, PASSWORD_BCRYPT);

        try {
            // Use session to check if installation has already been done during this session
            if (!isset($_SESSION['installation_done'])) {
                $testConn = new mysqli($host, $user, $pass);

                if ($testConn->connect_error) {
                    throw new Exception("Connection failed: " . $testConn->connect_error);
                }
                $testConn->close();

                $conn = new mysqli($host, $user, $pass);

                // Database creation and selection
                if (!$conn->query("CREATE DATABASE IF NOT EXISTS `$name`")) {
                    throw new Exception("Error creating database: " . $conn->error);
                }
                $conn->select_db($name);

                // Helper functions for schema management
                function tableExists($conn, $tableName)
                {
                    $result = $conn->query("SHOW TABLES LIKE '$tableName'");
                    return $result->num_rows > 0;
                }

                function columnExists($conn, $tableName, $columnName)
                {
                    $result = $conn->query("SHOW COLUMNS FROM `$tableName` LIKE '$columnName'");
                    return $result->num_rows > 0;
                }

                // Define table schemas and checks
                $tables = [
                    'users' => [
                        'sql' => "CREATE TABLE IF NOT EXISTS users (
                            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                            username VARCHAR(30) NOT NULL,
                            password VARCHAR(255) NOT NULL,
                            email VARCHAR(100) NULL,
                            role_id INT UNSIGNED DEFAULT NULL,
                            last_login TIMESTAMP NULL DEFAULT NULL,
                            status ENUM('active', 'inactive') DEFAULT 'active',
                            PRIMARY KEY (`id`),
                            UNIQUE KEY `username` (`username`)
                        ) ENGINE=InnoDB AUTO_INCREMENT=2",
                        'updates' => []
                    ],
                    'roles' => [
                        'sql' => "CREATE TABLE IF NOT EXISTS roles (
                            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                            name VARCHAR(50) NOT NULL,
                            description VARCHAR(255),
                            PRIMARY KEY (`id`),
                            UNIQUE KEY `name` (`name`)
                        ) ENGINE=InnoDB",
                        'updates' => []
                    ],
                    'permissions' => [
                        'sql' => "CREATE TABLE IF NOT EXISTS permissions (
                            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                            name VARCHAR(50) NOT NULL,
                            description VARCHAR(255),
                            PRIMARY KEY (`id`),
                            UNIQUE KEY `name` (`name`)
                        ) ENGINE=InnoDB",
                        'updates' => []
                    ],
                    'role_permissions' => [
                        'sql' => "CREATE TABLE IF NOT EXISTS role_permissions (
                            role_id INT UNSIGNED NOT NULL,
                            permission_id INT UNSIGNED NOT NULL,
                            PRIMARY KEY (`role_id`, `permission_id`),
                            KEY `role_id` (`role_id`),
                            KEY `permission_id` (`permission_id`),
                            CONSTRAINT `fk_role_permissions_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE,
                            CONSTRAINT `fk_role_permissions_permission` FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`id`) ON DELETE CASCADE
                        ) ENGINE=InnoDB",
                        'updates' => []
                    ],
                    'game_servers' => [
                        'sql' => "CREATE TABLE IF NOT EXISTS game_servers (
                            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                            server_name VARCHAR(50) NOT NULL,
                            ip_or_hostname VARCHAR(50) NOT NULL,
                            port INT NOT NULL,
                            rcon_password VARCHAR(255) NOT NULL,
                            server_type ENUM('cod','coduo','cod2','cod4','codwaw') NOT NULL,
                            PRIMARY KEY (`id`)
                        ) ENGINE=InnoDB AUTO_INCREMENT=7",
                        'updates' => []
                    ],
                    'map_aliases' => [
                        'sql' => "CREATE TABLE IF NOT EXISTS map_aliases (
                            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                            map_name VARCHAR(100) NOT NULL,
                            alias VARCHAR(50) NOT NULL,
                            server_id INT UNSIGNED NOT NULL,
                            PRIMARY KEY (`id`),
                            UNIQUE KEY `unique_map_server` (`map_name`, `server_id`)
                        ) ENGINE=InnoDB AUTO_INCREMENT=417",
                        'updates' => []
                    ],
                    'server_maps' => [
                        'sql' => "CREATE TABLE IF NOT EXISTS server_maps (
                            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                            server_id INT UNSIGNED NOT NULL,
                            map_id INT UNSIGNED NOT NULL,
                            PRIMARY KEY (`id`),
                            UNIQUE KEY `unique_server_map` (`server_id`, `map_id`),
                            KEY `server_id` (`server_id`),
                            KEY `map_id` (`map_id`)
                        ) ENGINE=InnoDB AUTO_INCREMENT=412",
                        'updates' => []
                    ],
                    'default_maps' => [
                        'sql' => "CREATE TABLE IF NOT EXISTS `default_maps` (
                          `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
                          `mp_name` varchar(100) NOT NULL COMMENT 'Unique identifier for the map, e.g., mp_bocage',
                          `map_alias` varchar(100) NOT NULL COMMENT 'Alias or common name of the map, e.g., Bocage',
                          `server_type` enum('cod','coduo','cod2','cod4','codwaw') NOT NULL COMMENT 'Identifies which CoD game this map belongs to',
                          PRIMARY KEY (`id`),
                          UNIQUE KEY `unique_mp_name` (`mp_name`, `server_type`)
                        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci",
                        'updates' => []
                    ],
                    'default_gametypes' => [
                        'sql' => "CREATE TABLE IF NOT EXISTS `default_gametypes` (
                            `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
                            `game_type` varchar(100) NOT NULL COMMENT 'Gametype used in the command line',
                            `gamet_alias` varchar(100) NOT NULL COMMENT 'Human readable gametype',
                            `server_type` enum('cod','coduo','cod2','cod4','codwaw') NOT NULL COMMENT 'Identifies which CoD game this map belongs to',
                            PRIMARY KEY (`id`),
                            UNIQUE KEY `unique_gametype` (`gamet_alias`, `server_type`)
                        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci",
                        'updates' => []
                    ],
                    'available_gametypes' => [
                        'sql' => "CREATE TABLE IF NOT EXISTS `available_gametypes` (
                            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                            `game_type` VARCHAR(100) NOT NULL COMMENT 'Gametype command line or identifier',
                            `gamet_alias` VARCHAR(100) NOT NULL COMMENT 'Alias or name for gametype',
                            `server_id` INT UNSIGNED NOT NULL COMMENT 'Server this gametype is available for',
                            PRIMARY KEY (`id`),
                            UNIQUE KEY `unique_gametype_server` (`gamet_alias`, `server_id`),
                            FOREIGN KEY (`server_id`) REFERENCES `game_servers`(`id`) ON DELETE CASCADE
                        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci",
                        'updates' => []
                    ],
                    'server_gametypes' => [
                        'sql' => "CREATE TABLE IF NOT EXISTS `server_gametypes` (
                            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                            `server_id` INT UNSIGNED NOT NULL,
                            `available_gametype_id` INT UNSIGNED NOT NULL,
                            PRIMARY KEY (`id`),
                            INDEX `idx_server_id` (`server_id`),
                            INDEX `idx_available_gametype_id` (`available_gametype_id`),
                            FOREIGN KEY (`server_id`) REFERENCES `game_servers`(`id`) ON DELETE CASCADE,
                            FOREIGN KEY (`available_gametype_id`) REFERENCES `available_gametypes`(`id`) ON DELETE CASCADE
                        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci",
                        'updates' => []
                    ],
                    'server_map_rotation' => [
                        'sql' => "CREATE TABLE IF NOT EXISTS `server_map_rotation` (
                            `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
                            `server_id` int UNSIGNED NOT NULL,
                            `rotation_string` text CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci,
                            `name` varchar(255) DEFAULT NULL,
                            PRIMARY KEY (`id`),
                            KEY `unique_server_id` (`server_id`) USING BTREE
                        ) ENGINE=InnoDB AUTO_INCREMENT=38 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
                        
                        ALTER TABLE `server_map_rotation`
                            ADD CONSTRAINT `server_map_rotation_ibfk_1` FOREIGN KEY (`server_id`) REFERENCES `game_servers` (`id`) ON DELETE CASCADE;",
                        'updates' => []
                    ]
                ];

                foreach ($tables as $tableName => $tableInfo) {
                    if (!tableExists($conn, $tableName)) {
                        if ($conn->multi_query($tableInfo['sql'])) {
                            do {
                                if ($result = $conn->store_result()) {
                                    $result->free_result();
                                }
                            } while ($conn->next_result());
                        } else {
                            throw new Exception("Error creating/updating table $tableName: " . $conn->error);
                        }
                    } else {
                        // Apply updates if any
                        foreach ($tableInfo['updates'] as $update) {
                            if (!$conn->query($update)) {
                                throw new Exception("Error updating table $tableName: " . $conn->error);
                            }
                        }
                    }
                }

                // Insert roles if not exist
                $roles = ['Admin' => 'System Administrator', 'Minion' => 'Regular user with limited access'];
                foreach ($roles as $roleName => $description) {
                    $stmt = $conn->prepare("INSERT INTO roles (name, description) VALUES (?, ?) ON DUPLICATE KEY UPDATE description=VALUES(description)");
                    $stmt->bind_param("ss", $roleName, $description);
                    $stmt->execute();
                    $stmt->close();
                }

                // Insert permissions if not exist
                $permissions = [
                    ['edit_servers', 'Can view and edit server information'],
                    ['edit_maps', 'Can edit map aliases and mappings'],
                    ['manage_users', 'Can add, edit, or delete users'],
                    ['accessrcon', 'Access to the index page']
                ];

                foreach ($permissions as $perm) {
                    $stmt = $conn->prepare("INSERT INTO permissions (name, description) VALUES (?, ?) ON DUPLICATE KEY UPDATE description=VALUES(description)");
                    $stmt->bind_param("ss", $perm[0], $perm[1]);
                    $stmt->execute();
                    $stmt->close();
                }

                // Assign permissions to Admin role if not already assigned
                $permissionsToAssign = ['edit_servers', 'edit_maps', 'manage_users', 'accessrcon'];
                $adminRoleId = $conn->query("SELECT id FROM roles WHERE name = 'Admin'")->fetch_assoc()['id'];
                foreach ($permissionsToAssign as $permission) {
                    $permId = $conn->query("SELECT id FROM permissions WHERE name = '$permission'")->fetch_assoc()['id'];
                    $stmt = $conn->prepare("INSERT INTO role_permissions (role_id, permission_id) VALUES (?, ?) ON DUPLICATE KEY UPDATE role_id=role_id");
                    $stmt->bind_param("ii", $adminRoleId, $permId);
                    $stmt->execute();
                    $stmt->close();
                }

                // Insert data into default_maps table
                $defaultMapsData = [
                    // Call of Duty (cod)
                    ['mp_bocage', 'Bocage', 'cod'],
                    ['mp_brecourt', 'Brecourt', 'cod'],
                    ['mp_carentan', 'Carentan', 'cod'],
                    ['mp_chateau', 'Chateau', 'cod'],
                    ['mp_dawnville', 'Dawnville', 'cod'],
                    ['mp_depot', 'Depot', 'cod'],
                    ['mp_harbor', 'Harbor', 'cod'],
                    ['mp_hurtgen', 'Hurtgen', 'cod'],
                    ['mp_neuville', 'Neuville', 'cod'],
                    ['mp_pavlov', 'Pavlov', 'cod'],
                    ['mp_powcamp', 'POW Camp', 'cod'],
                    ['mp_railyard', 'Railyard', 'cod'],
                    ['mp_rocket', 'Rocket', 'cod'],
                    ['mp_ship', 'Ship', 'cod'],
                    ['mp_stalingrad', 'Stalingrad', 'cod'],
                    ['mp_tigertown', 'Tigertown', 'cod'],

                    // Call of Duty uo(coduo)
                    ['mp_bocage', 'Bocage', 'coduo'],
                    ['mp_brecourt', 'Brecourt', 'coduo'],
                    ['mp_carentan', 'Carentan', 'coduo'],
                    ['mp_chateau', 'Chateau', 'coduo'],
                    ['mp_dawnville', 'Dawnville', 'coduo'],
                    ['mp_depot', 'Depot', 'coduo'],
                    ['mp_harbor', 'Harbor', 'coduo'],
                    ['mp_hurtgen', 'Hurtgen', 'coduo'],
                    ['mp_neuville', 'Neuville', 'coduo'],
                    ['mp_pavlov', 'Pavlov', 'coduo'],
                    ['mp_powcamp', 'POW Camp', 'coduo'],
                    ['mp_railyard', 'Railyard', 'coduo'],
                    ['mp_rocket', 'Rocket', 'coduo'],
                    ['mp_ship', 'Ship', 'coduo'],
                    ['mp_stalingrad', 'Stalingrad', 'coduo'],
                    ['mp_tigertown', 'Tigertown', 'coduo'],
                    ['mp_arnhem', 'Arnhem', 'coduo'],
                    ['mp_berlin', 'Berlin', 'coduo'],
                    ['mp_cassino', 'Cassino', 'coduo'],
                    ['mp_foy', 'Foy', 'coduo'],
                    ['mp_italy', 'Italy', 'coduo'],
                    ['mp_kharkov', 'Kharkov', 'coduo'],
                    ['mp_kursk', 'Kursk', 'coduo'],
                    ['mp_ponyri', 'Ponyri', 'coduo'],
                    ['mp_rhinevalley', 'Rhine Valley', 'coduo'],
                    ['mp_sicily', 'Sicily', 'coduo'],
                    ['mp_uo_stanjel', 'Stanjel', 'coduo'],


                    // Call of Duty 2 (cod2)
                    ['mp_farmhouse', 'Beltot, France', 'cod2'],
                    ['mp_burgundy', 'Burgundy, France', 'cod2'],
                    ['mp_decoy', 'El Alamein, Egypt', 'cod2'],
                    ['mp_downtown', 'Moscow, Russia', 'cod2'],
                    ['mp_leningrad', 'Leningrad, Russia', 'cod2'],
                    ['mp_matmata', 'Matmata, Tunisia', 'cod2'],
                    ['mp_breakout', 'Villers-Bocage, France', 'cod2'],
                    ['mp_toujane', 'Toujane, Tunisia', 'cod2'],
                    ['mp_trainstation', 'Caen, France', 'cod2'],
                    ['mp_carentan', 'Carentan, France', 'cod2'],
                    ['mp_brecourt', 'Brecourt, France', 'cod2'],
                    ['mp_dawnville', 'St. Mere Eglise, France', 'cod2'],
                    ['mp_railyard', 'Stalingrad, Russia', 'cod2'],
                    ['mp_harbor', 'Rostov, Russia', 'cod2'],
                    ['mp_rhine', 'Wallendar, Germany', 'cod2'],

                    // Call of Duty 4 (cod4)
                    ['mp_backlot', 'Backlot', 'cod4'],
                    ['mp_bloc', 'Bloc', 'cod4'],
                    ['mp_bog', 'Bog', 'cod4'],
                    ['mp_broadcast', 'Broadcast', 'cod4'],
                    ['mp_carentan', 'Chinatown', 'cod4'],
                    ['mp_cargoship', 'Wet Work', 'cod4'],
                    ['mp_citystreets', 'District', 'cod4'],
                    ['mp_convoy', 'Ambush', 'cod4'],
                    ['mp_countdown', 'Countdown', 'cod4'],
                    ['mp_crash', 'Crash', 'cod4'],
                    ['mp_crash_snow', 'Winter Crash', 'cod4'],
                    ['mp_creek', 'Creek', 'cod4'],
                    ['mp_crossfire', 'Crossfire', 'cod4'],
                    ['mp_farm', 'Downpour', 'cod4'],
                    ['mp_killhouse', 'Killhouse', 'cod4'],
                    ['mp_overgrown', 'Overgrown', 'cod4'],
                    ['mp_pipeline', 'Pipeline', 'cod4'],
                    ['mp_shipment', 'Shipment', 'cod4'],
                    ['mp_showdown', 'Showdown', 'cod4'],
                    ['mp_strike', 'Strike', 'cod4'],
                    ['mp_vacant', 'Vacant', 'cod4'],

                    // Call of Duty: World at War (codwaw)
                    ['mp_airfield', 'Airfield', 'codwaw'],
                    ['mp_asylum', 'Asylum', 'codwaw'],
                    ['mp_bgate', 'Breach', 'codwaw'],
                    ['mp_castle', 'Castle', 'codwaw'],
                    ['mp_courtyard', 'Courtyard', 'codwaw'],
                    ['mp_dome', 'Dome', 'codwaw'],
                    ['mp_drum', 'Battery', 'codwaw'],
                    ['mp_hangar', 'Hangar', 'codwaw'],
                    ['mp_kneedeep', 'Knee Deep', 'codwaw'],
                    ['mp_kwai', 'Banzai', 'codwaw'],
                    ['mp_makin', 'Makin', 'codwaw'],
                    ['mp_makin_day', 'Makin Day', 'codwaw'],
                    ['mp_nachtfeuer', 'Nightfire', 'codwaw'],
                    ['mp_outskirts', 'Outskirts', 'codwaw'],
                    ['mp_roundhouse', 'Roundhouse', 'codwaw'],
                    ['mp_seelow', 'Seelow', 'codwaw'],
                    ['mp_shrine', 'Cliffside', 'codwaw'],
                    ['mp_stalingrad', 'Corrosion', 'codwaw'],
                    ['mp_suburban', 'Upheaval', 'codwaw'],
                    ['mp_subway', 'Station', 'codwaw'],
                    ['mp_vodka', 'Revolution', 'codwaw'],
                    ['mp_downfall', 'Downfall', 'codwaw'],
                    ['mp_docks', 'Sub Pens', 'codwaw'],
                ];

                foreach ($defaultMapsData as $map) {
                    $stmt = $conn->prepare("INSERT INTO default_maps (mp_name, map_alias, server_type) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE map_alias=VALUES(map_alias)");
                    $stmt->bind_param("sss", $map[0], $map[1], $map[2]);
                    $stmt->execute();
                    $stmt->close();
                }

                // Insert data into default_gametypes table
                $defaultGametypesData = [
                    ['dm', 'Deathmatch', 'cod'],
                    ['tdm', 'Team Deathmatch', 'cod'],
                    ['hq', 'Headquarters', 'cod'],
                    ['sd', 'Search and Destroy', 'cod'],
                    ['re', 'Retrieval', 'cod'],
                    ['bel', 'Behind Enemy Lines', 'cod'],
                    ['dm', 'Deathmatch', 'coduo'],
                    ['tdm', 'Team Deathmatch', 'coduo'],
                    ['hq', 'Headquarters', 'coduo'],
                    ['sd', 'Search and Destroy', 'coduo'],
                    ['re', 'Retrieval', 'coduo'],
                    ['bel', 'Behind Enemy Lines', 'coduo'],
                    ['bas', 'Base Assault', 'coduo'],
                    ['ctf', 'Capture the Flag', 'coduo'],
                    ['dm', 'Deathmatch', 'cod2'],
                    ['tdm', 'Team Deathmatch', 'cod2'],
                    ['hq', 'Headquarters', 'cod2'],
                    ['sd', 'Search and Destroy', 'cod2'],
                    ['ctf', 'Capture the Flag', 'cod2'],
                    ['dm', 'Free for All', 'cod4'],
                    ['war', 'Team Deathmatch', 'cod4'],
                    ['koth', 'Headquarters', 'cod4'],
                    ['sab', 'Sabotage', 'cod4'],
                    ['sd', 'Search and Destroy', 'cod4'],
                    ['dom', 'Domination', 'cod4'],
                    ['dm', 'Deathmatch', 'codwaw'],
                    ['tdm', 'Team Deathmatch', 'codwaw'],
                    ['koth', 'Headquarters', 'codwaw'],
                    ['sd', 'Search and Destroy', 'codwaw'],
                    ['sab', 'Sabotage', 'codwaw'],
                    ['ctf', 'Capture the Flag', 'codwaw'],
                    ['twar', 'war', 'codwaw']
                ];
                foreach ($defaultGametypesData as $gametype) {
                    $stmt = $conn->prepare("INSERT INTO default_gametypes (game_type, gamet_alias, server_type) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE gamet_alias=VALUES(gamet_alias)");
                    $stmt->bind_param("sss", $gametype[0], $gametype[1], $gametype[2]);
                    $stmt->execute();
                    $stmt->close();
                }

                // Check for admin user
                $checkUserSql = "SELECT * FROM users WHERE username = ?";
                $stmt = $conn->prepare($checkUserSql);
                $stmt->bind_param("s", $admin_user);
                $stmt->execute();
                $result = $stmt->get_result();

                if ($result->num_rows == 0) {
                    // Insert new admin user only if not exists
                    $insertUserSql = "INSERT INTO users (username, password, email, role_id, status) VALUES (?, ?, ?, ?, 'active')";
                    $stmt = $conn->prepare($insertUserSql);
                    $getAdminRoleId = "SELECT id FROM roles WHERE name = 'Admin'";
                    $adminRoleIdResult = $conn->query($getAdminRoleId);
                    $adminRoleId = $adminRoleIdResult->fetch_assoc()['id'];
                    $stmt->bind_param("ssss", $admin_user, $admin_pass, $admin_email, $adminRoleId);
                    if (!$stmt->execute()) {
                        throw new Exception("Error creating admin user: " . $stmt->error);
                    }
                }

                // Write config file
                $config = "<?php\n";
                $config .= "\$servername = \"$host\"; // Ensure this is set to 'localhost'\n";
                $config .= "\$username = \"$user\"; // Update with the database user from install.php\n";
                $config .= "\$password = \"$pass\"; // Update with the database password from install.php\n";
                $config .= "\$dbname = \"$name\"; // Update with the database name from install.php\n\n";
                $config .= "\$conn = new mysqli(\$servername, \$username, \$password, \$dbname);\n\n";
                $config .= "if (\$conn->connect_error) {\n";
                $config .= "    die(\"Connection failed: \" . \$conn->connect_error);\n";
                $config .= "}\n";
                $config .= "?>";

                $configDir = '../config';
                if (!file_exists($configDir)) {
                    if (!mkdir($configDir, 0755, true)) {
                        throw new Exception("Failed to create config directory.");
                    }
                }

                $configFile = $configDir . '/database.php';
                if (!file_put_contents($configFile, $config)) {
                    throw new Exception("Failed to write to config file.");
                }

                $_SESSION['installation_done'] = true;
                echo "Installation or update successful!";
                header("Location: ../index.php");
                exit;
            } else {
                throw new Exception("Installation has already been completed. Refreshing won't rerun the installation.");
            }
        } catch (Exception $e) {
            $errorMessage = "<div class='error'>" . htmlspecialchars($e->getMessage()) . "</div>";
        } finally {
            if (isset($conn)) {
                $conn->close();
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Call of Duty Simple RCON - Map Manager</title>
    <link rel="stylesheet" type="text/css" href="../codrs.css?v=<?php echo time(); ?>">
</head>

<body>
    <div class="page-container">
        <div class="container">
            <div id="branding">

            </div>
        </div>

        <div class="container">
            <h1>Install/Update</h1>
            <?php
            if (isset($errorMessage)) {
                echo "<span style='color: red;'>" . $errorMessage . "</span>";
            }
            ?>
            <br>
            <form method="post">
                <div style="display: flex; flex-direction: column; align-items: flex-start;">
                    <small>
                        Enter your database details. You'll need to set up a database user with your hosting provider. Ensure this user has the following permissions:
                        <ul style="padding-left: 20px;">
                            <li>CREATE</li>
                            <li>SELECT</li>
                            <li>INSERT</li>
                            <li>ALTER</li>
                        </ul>
                        If you haven't yet created a database, one will be created using the name you provide here.
                        <br><br>
                        Typically, the host is <code>localhost</code>, but please verify with your hosting provider.
                    </small>
                </div>
                <br>

                <div style="display: flex; align-items: center;">
                    <label style="width: 150px;">Database Host:</label>
                    <input type="text" name="db_host" required style="width: 200px;">
                    <small>e.g., localhost</small>
                </div>
                <br>
                <div style="display: flex; align-items: center;">
                    <label style="width: 150px;">Database User:</label>
                    <input type="text" name="db_user" required style="width: 200px;">
                    <small>e.g., root</small>
                </div>
                <br>
                <div style="display: flex; align-items: center;">
                    <label style="width: 150px;">Database Password:</label>
                    <input type="password" name="db_pass" required style="width: 200px;">
                    <small>e.g., your database password</small>
                </div>
                <br>
                <div style="display: flex; align-items: center;">
                    <label style="width: 150px;">Database Name:</label>
                    <input type="text" name="db_name" required style="width: 200px;">
                    <small>e.g., rcon_db</small>
                </div>
                <br>
                <div style="display: flex; align-items: center;">
                    <small>Create the login details for the initial admin account.</small>
                </div>
                <br>
                <div style="display: flex; align-items: center;">
                    <label style="width: 150px;">Admin Username:</label>
                    <input type="text" name="admin_user" required style="width: 200px;">
                </div>
                <br>
                <div style="display: flex; align-items: center;">
                    <label style="width: 150px;">Admin Email:</label>
                    <input type="email" name="admin_email" required style="width: 200px;">
                </div>
                <br>
                <div style="display: flex; align-items: center;">
                    <label style="width: 150px;">Admin Password:</label>
                    <input type="password" name="admin_pass" required style="width: 200px;">
                </div>
                <br>
                <div style="display: flex; align-items: center;">
                    <label style="width: 150px;">Confirm Admin Password:</label>
                    <input type="password" name="admin_pass_confirm" required style="width: 200px;">
                </div>
                <br>

                <input type="submit" value="Install">
            </form>
        </div>
    </div>
</body>

</html>
