<?php
require_once 'config/database.php';

// Reset the superadmin password
$password = password_hash('admin123', PASSWORD_DEFAULT);
$stmt = $pdo->prepare("UPDATE users SET password = ? WHERE username = 'superadmin'");
if ($stmt->execute([$password])) {
    echo "Password reset successfully!<br>";
    echo "Username: superadmin<br>";
    echo "Password: admin123";
} else {
    echo "Error resetting password.";
}
?>