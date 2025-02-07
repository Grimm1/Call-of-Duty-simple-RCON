<?php

function generateMenu($currentPage) {
    $menuItems = [
        'log out' => 'logout.php',
        'home' => 'index.php',
        'Map manager' => 'map_manager.php',
        'server manager' => 'serverManager.php',
    ];

    // Check if the user has admin permissions
    $showManageUsers = false;
    if (isset($_SESSION['role_id']) && $_SESSION['role_id'] == 1) { // Assuming 1 is the admin role ID
        $showManageUsers = true;
        $menuItems['User Manager'] = 'manage_users.php';
    }

    echo '<div class="menu">';
    echo '<ul>';
    foreach ($menuItems as $name => $url) {
        if ($url !== $currentPage) {
            echo '<li><a href="' . $url . '">' . ucfirst($name) . '</a></li>';
        } else {
            echo '<li><a class="active" href="' . $url . '">' . ucfirst($name) . '</a></li>';
        }
    }
    echo '</ul>';
    echo '</div>';
}
?>
