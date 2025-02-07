<?php
include 'functions/menu.php';
?>

<div class="container">
    <div id="branding">
    <?php
    // Check if the user is logged in
    if (isset($_SESSION['username']) && isset($_SESSION['role_name'])) {
        $username = htmlspecialchars($_SESSION['username']);
        $roleName = htmlspecialchars($_SESSION['role_name']);
        echo "<div class='welcome-message' style='color: #4CAF50; font-size: 14px; float: right;'>Welcome, $username <span style='color: blue; font-size: 10px;'>($roleName)</span> </div>";



    }
    ?>   
    </div>
    <div class="buttons">
        <?php
        // Assuming the current page is determined by the script name
        $currentPage = basename($_SERVER['SCRIPT_NAME']);
        generateMenu($currentPage);
        ?>
    </div>
</div>
