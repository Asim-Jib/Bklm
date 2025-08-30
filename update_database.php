<?php
require_once 'config/database.php';

echo "<h2>Database Update (MySQL Compatible Version)</h2>";

try {
    // Check if kyc_status column exists before adding it
    $stmt = $pdo->prepare("SHOW COLUMNS FROM dsa_details LIKE 'kyc_status'");
    $stmt->execute();
    $kycStatusExists = $stmt->rowCount() > 0;
    
    if (!$kycStatusExists) {
        $pdo->exec("ALTER TABLE dsa_details ADD COLUMN kyc_status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending'");
        echo "<p>Added kyc_status column to dsa_details table.</p>";
    } else {
        echo "<p>kyc_status column already exists.</p>";
    }
    
    // Check if kyc_approved_by column exists before adding it
    $stmt = $pdo->prepare("SHOW COLUMNS FROM dsa_details LIKE 'kyc_approved_by'");
    $stmt->execute();
    $kycApprovedByExists = $stmt->rowCount() > 0;
    
    if (!$kycApprovedByExists) {
        $pdo->exec("ALTER TABLE dsa_details ADD COLUMN kyc_approved_by INT NULL");
        echo "<p>Added kyc_approved_by column to dsa_details table.</p>";
    } else {
        echo "<p>kyc_approved_by column already exists.</p>";
    }
    
    // Check if kyc_approved_at column exists before adding it
    $stmt = $pdo->prepare("SHOW COLUMNS FROM dsa_details LIKE 'kyc_approved_at'");
    $stmt->execute();
    $kycApprovedAtExists = $stmt->rowCount() > 0;
    
    if (!$kycApprovedAtExists) {
        $pdo->exec("ALTER TABLE dsa_details ADD COLUMN kyc_approved_at TIMESTAMP NULL");
        echo "<p>Added kyc_approved_at column to dsa_details table.</p>";
    } else {
        echo "<p>kyc_approved_at column already exists.</p>";
    }
    
    // Check if foreign key constraint already exists
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as count 
            FROM information_schema.table_constraints 
            WHERE table_name = 'dsa_details' 
            AND constraint_name = 'dsa_details_ibfk_1' 
            AND constraint_type = 'FOREIGN KEY'
        ");
        $stmt->execute();
        $foreignKeyExists = $stmt->fetch(PDO::FETCH_ASSOC)['count'] > 0;
        
        if (!$foreignKeyExists) {
            $pdo->exec("ALTER TABLE dsa_details ADD FOREIGN KEY (kyc_approved_by) REFERENCES users(id)");
            echo "<p>Added foreign key constraint.</p>";
        } else {
            echo "<p>Foreign key constraint already exists.</p>";
        }
    } catch (Exception $e) {
        echo "<p>Could not check/add foreign key constraint: " . $e->getMessage() . "</p>";
    }
    
    // Update existing DSAs to have pending KYC status
    $pdo->exec("UPDATE dsa_details SET kyc_status = 'pending' WHERE kyc_status IS NULL OR kyc_status = ''");
    echo "<p>Updated existing DSAs to have pending KYC status.</p>";
    
    echo "<div class='alert alert-success'>Database update completed successfully!</div>";
    
} catch (Exception $e) {
    echo "<div class='alert alert-danger'>Error updating database: " . $e->getMessage() . "</div>";
}

echo "<p><a href='check_database.php'>Check Database Again</a></p>";
echo "<p><a href='super_admin/index.php'>Return to Dashboard</a></p>";
?>