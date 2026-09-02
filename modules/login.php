<?php
session_start();
include 'db_connect.php';

$error = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $email = $_POST['email'];
    $password = $_POST['password'];

    $stmt = $conn->prepare("SELECT user_id, name, role, password_hash FROM users WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();

    if ($user && password_verify($password, $user['password_hash'])) {
        $_SESSION['user_id'] = $user['user_id'];
        $_SESSION['name'] = $user['name'];
        $_SESSION['role'] = $user['role'];
        header("Location: home.php");
        exit;
    } else {
        $error = "Wrong email or password.";
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Login</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <div class="top-header">
    <img src="images/UResearch_logo.png" alt="UResearch 2.0" class="uresearch-logo">
    <img src="images/UTP_logo.png" alt="UTP Logo" class="utp-logo">
    </div>

    <div class="card-container">
        <div class="card">
            <h2>Log In</h2>
            <div class="card-divider"></div>

            <?php if ($error): ?>
                <p class="message-error"><?php echo $error; ?></p>
            <?php endif; ?>

            <form method="POST">
                <label>Email</label>
                <input type="email" name="email" required>

                <label>Password</label>
                <input type="password" name="password" required>

                <button type="submit">Log In</button>
            </form>
        </div>
    </div>
</body>
</html>
