<?php

//sample code only
include 'functions/menu.php';
?>
<div class="container">
    <div id="branding">
        
    </div>
    <div class="buttons">
        <?php
        // Assuming the current page is determined by the script name
        $currentPage = basename($_SERVER['SCRIPT_NAME']);
        generateMenu($currentPage);
        ?>
    </div>
</div>