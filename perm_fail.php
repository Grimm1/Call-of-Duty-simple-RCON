<?php
session_start();
if (!isset($_SESSION['username'])) {
    header("Location: login.php");
    exit();
}

$error_message = isset($_SESSION['error_message']) ? $_SESSION['error_message'] : "Unknown error.";
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Call of Duty Simple RCON - Permission Failure</title>
    <link rel="stylesheet" type="text/css" href="codrs.css?v=<?php echo time(); ?>">
</head>

<body>
    <div class="page-container">
        <header>
            <?php include 'header.php'; ?>
        </header>
        <main class="content">
            <div class="container">
                <div class="error" style="text-align:center;"><?php echo $error_message; ?></div>
                <div style="font-size: smaller; text-align:center;">
                    If you believe this is an error, please contact the admin for assistance.
                </div>
            </div>
        </main>
        <footer>
            <?php include 'footer.php'; ?>
        </footer>
    </div>
</body>

</html>
