<?php
require_once 'config/database.php';

echo "<h2>Complete DSA Fix</h2>";

try {
    // Get super admin ID
    $stmt = $pdo->prepare("SELECT id FROM users WHERE username = 'superadmin' AND role = 'super_admin' LIMIT 1");
    $stmt->execute();
    $super_admin = $stmt->fetch();
    
    if (!$super_admin) {
        throw new Exception("Super admin not found");
    }
    
    // Get DSA user ID
    $stmt = $pdo->prepare("SELECT id FROM users WHERE username = 'dsa_agent' AND role = 'dsa' LIMIT 1");
    $stmt->execute();
    $dsa = $stmt->fetch();
    
    if (!$dsa) {
        throw new Exception("DSA agent not found");
    }
    
    echo "<p>DSA found: ID " . $dsa['id'] . " - dsa_agent</p>";
    
    // Check if dsa_details record exists
    $stmt = $pdo->prepare("SELECT * FROM dsa_details WHERE user_id = ?");
    $stmt->execute([$dsa['id']]);
    $dsa_details = $stmt->fetch();
    
    if (!$dsa_details) {
        echo "<p>No dsa_details record found. Creating one...</p>";
        
        // Create dsa_details record
        $stmt = $pdo->prepare("INSERT INTO dsa_details (user_id, kyc_status, kyc_approved_by, kyc_approved_at) VALUES (?, 'approved', ?, NOW())");
        $stmt->execute([$dsa['id'], $super_admin['id']]);
        
        echo "<p>dsa_details record created successfully.</p>";
    } else {
        echo "<p>dsa_details record found. Updating KYC status...</p>";
        
        // Update existing dsa_details record
        $stmt = $pdo->prepare("UPDATE dsa_details SET kyc_status = 'approved', kyc_approved_by = ?, kyc_approved_at = NOW() WHERE user_id = ?");
        $stmt->execute([$super_admin['id'], $dsa['id']]);
        
        echo "<p>dsa_details record updated successfully.</p>";
    }
    
    // Update user status to active
    $stmt = $pdo->prepare("UPDATE users SET status = 'active' WHERE id = ?");
    $stmt->execute([$dsa['id']]);
    
    echo "<p>User status updated to active.</p>";
    
    echo "<div class='alert alert-success'>DSA completely fixed successfully!</div>";
    
    // Verify the update
    $stmt = $pdo->prepare("
        SELECT u.username, u.status, dd.kyc_status, dd.kyc_approved_at, a.username as approved_by
        FROM users u
        LEFT JOIN dsa_details dd ON u.id = dd.user_id
        LEFT JOIN users a ON dd.kyc_approved_by = a.id
        WHERE u.id = ?
    ");
    $stmt->execute([$dsa['id']]);
    $result = $stmt->fetch();
    
    echo "<h3>Verification</h3>";
    echo "<table class='table table-bordered'>";
    echo "<tr><th>Username</th><td>" . htmlspecialchars($result['username'] ?? 'N/A') . "</td></tr>";
    echo "<tr><th>Status</th><td>" . htmlspecialchars($result['status'] ?? 'N/A') . "</td></tr>";
    echo "<tr><th>KYC Status</th><td>" . htmlspecialchars($result['kyc_status'] ?? 'N/A') . "</td></tr>";
    echo "<tr><th>KYC Approved At</th><td>" . htmlspecialchars($result['kyc_approved_at'] ?? 'N/A') . "</td></tr>";
    echo "<tr><th>Approved By</th><td>" . htmlspecialchars($result['approved_by'] ?? 'N/A') . "</td></tr>";
    echo "</table>";
    
} catch (Exception $e) {
    echo "<div class='alert alert-danger'>Error: " . $e->getMessage() . "</div>";
}

echo "<p><a href='super_admin/dsa_management.php'>Go to DSA Management</a></p>";
echo "<p><a href='super_admin/rm_management.php'>Go to RM Management</a></p>";
?>