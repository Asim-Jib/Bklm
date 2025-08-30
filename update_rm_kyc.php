<?php
require_once 'config/database.php';

echo "<h2>RM KYC Database Update</h2>";

try {
    // Check if kyc_status column exists in rm_details
    $stmt = $pdo->prepare("SHOW COLUMNS FROM rm_details LIKE 'kyc_status'");
    $stmt->execute();
    $kycStatusExists = $stmt->rowCount() > 0;
    
    if (!$kycStatusExists) {
        $pdo->exec("ALTER TABLE rm_details ADD COLUMN kyc_status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending'");
        echo "<p>Added kyc_status column to rm_details table.</p>";
    } else {
        echo "<p>kyc_status column already exists.</p>";
    }
    
    // Check if kyc_approved_by column exists
    $stmt = $pdo->prepare("SHOW COLUMNS FROM rm_details LIKE 'kyc_approved_by'");
    $stmt->execute();
    $kycApprovedByExists = $stmt->rowCount() > 0;
    
    if (!$kycApprovedByExists) {
        $pdo->exec("ALTER TABLE rm_details ADD COLUMN kyc_approved_by INT NULL");
        echo "<p>Added kyc_approved_by column to rm_details table.</p>";
    } else {
        echo "<p>kyc_approved_by column already exists.</p>";
    }
    
    // Check if kyc_approved_at column exists
    $stmt = $pdo->prepare("SHOW COLUMNS FROM rm_details LIKE 'kyc_approved_at'");
    $stmt->execute();
    $kycApprovedAtExists = $stmt->rowCount() > 0;
    
    if (!$kycApprovedAtExists) {
        $pdo->exec("ALTER TABLE rm_details ADD COLUMN kyc_approved_at TIMESTAMP NULL");
        echo "<p>Added kyc_approved_at column to rm_details table.</p>";
    } else {
        echo "<p>kyc_approved_at column already exists.</p>";
    }
    
    // Update existing RMs to have pending KYC status
    $pdo->exec("UPDATE rm_details SET kyc_status = 'pending' WHERE kyc_status IS NULL OR kyc_status = ''");
    echo "<p>Updated existing RMs to have pending KYC status.</p>";
    
    echo "<div class='alert alert-success'>RM KYC database update completed successfully!</div>";
    
} catch (Exception $e) {
    echo "<div class='alert alert-danger'>Error updating database: " . $e->getMessage() . "</div>";
}

echo "<p><a href='super_admin/index.php'>Return to Dashboard</a></p>";
?>