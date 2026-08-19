<?php
include 'db_connect.php';

$hashed = password_hash("test123", PASSWORD_DEFAULT);

$stmt = $conn->prepare("UPDATE users SET password_hash = ?");
$stmt->bind_param("s", $hashed);
$stmt->execute();

echo "Updated " . $stmt->affected_rows . " users. Every test user's password is now: test123";
?>
