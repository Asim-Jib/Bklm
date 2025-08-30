<?php
require_once 'config/database.php';

echo "<h3>Checking for duplicate usernames...</h3>";

// Check for duplicate usernames
$stmt = $pdo->prepare("
    SELECT username, COUNT(*) as count 
    FROM users 
    GROUP BY username 
    HAVING COUNT(*) > 1
");
$stmt->execute();
$duplicates = $stmt->fetchAll();

if (count($duplicates) > 0) {
    echo "<p>Found duplicate usernames:</p>";
    echo "<ul>";
    foreach ($duplicates as $dup) {
        echo "<li>{$dup['username']} (count: {$dup['count']})</li>";
    }
    echo "</ul>";
    
    // Show all users with the problematic username
    $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
    $stmt->execute(['Test DSA']);
    $users = $stmt->fetchAll();
    
    echo "<p>Users with username 'Test DSA':</p>";
    echo "<table border='1' cellpadding='5'>";
    echo "<tr><th>ID</th><th>Username</th><th>Status</th><th>Action</th></tr>";
    foreach ($users as $user) {
        echo "<tr>";
        echo "<td>{$user['id']}</td>";
        echo "<td>{$user['username']}</td>";
        echo "<td>{$user['status']}</td>";
        echo "<td><a href='fix_usernames.php?delete={$user['id']}'>Delete</a></td>";
        echo "</tr>";
    }
    echo "</table>";
} else {
    echo "<p>No duplicate usernames found.</p>";
}

// Handle deletion
if (isset($_GET['delete'])) {
    $user_id = $_GET['delete'];
    $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
    if ($stmt->execute([$user_id])) {
        echo "<p>User ID {$user_id} deleted successfully.</p>";
        echo "<meta http-equiv='refresh' content='2;url=fix_usernames.php'>";
    } else {
        echo "<p>Error deleting user.</p>";
    }
}

echo "<p><a href='super_admin/dsa_management.php'>Return to DSA Management</a></p>";
?>