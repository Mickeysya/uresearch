<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$role = $_SESSION['role'];
?>
<!DOCTYPE html>
<html>
<head>
    <title>Home</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <div class="top-header">
        <img src="images/UResearch_logo.png" alt="UResearch 2.0" class="uresearch-logo">
        <img src="images/UTP_logo.png" alt="UTP Logo" class="utp-logo">
    </div>

    <div class="dashboard-wrapper">
        <?php include 'sidebar.php'; ?>

        <div class="main-content">
            <div class="welcome-banner">
                <h2>Welcome, <?php echo $_SESSION['name']; ?></h2>
                <p><?php echo ucfirst(str_replace('_', ' ', $role)); ?></p>
            </div>
            <p style="color: var(--text-grey);">Select an item from the sidebar to get started.</p>
        </div>
    </div>
</body>
</html>
