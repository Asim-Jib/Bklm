<?php
require_once 'config/database.php';

echo "<h2>Loan Servicing Database Update</h2>";

try {
    // Check and create tables if they don't exist
    $tables = [
        'loans' => "CREATE TABLE loans (
            id INT AUTO_INCREMENT PRIMARY KEY,
            application_id INT NOT NULL,
            user_id INT NOT NULL,
            loan_type_id INT NOT NULL,
            principal_amount DECIMAL(15,2) NOT NULL,
            interest_rate DECIMAL(5,2) NOT NULL,
            tenure_months INT NOT NULL,
            start_date DATE NOT NULL,
            end_date DATE NOT NULL,
            status ENUM('active', 'closed', 'settled', 'defaulted') DEFAULT 'active',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (application_id) REFERENCES loan_applications(id),
            FOREIGN KEY (user_id) REFERENCES users(id),
            FOREIGN KEY (loan_type_id) REFERENCES loan_types(id)
        )",
        
        'emi_schedule' => "CREATE TABLE emi_schedule (
            id INT AUTO_INCREMENT PRIMARY KEY,
            loan_id INT NOT NULL,
            emi_date DATE NOT NULL,
            principal_amount DECIMAL(15,2) NOT NULL,
            interest_amount DECIMAL(15,2) NOT NULL,
            total_emi DECIMAL(15,2) NOT NULL,
            status ENUM('pending', 'paid', 'overdue') DEFAULT 'pending',
            paid_date DATE NULL,
            late_fee DECIMAL(15,2) DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (loan_id) REFERENCES loans(id)
        )",
        
        'emi_payments' => "CREATE TABLE emi_payments (
            id INT AUTO_INCREMENT PRIMARY KEY,
            loan_id INT NOT NULL,
            emi_schedule_id INT NOT NULL,
            amount_paid DECIMAL(15,2) NOT NULL,
            payment_date DATE NOT NULL,
            payment_method ENUM('cash', 'bank_transfer', 'cheque', 'online') DEFAULT 'cash',
            reference_number VARCHAR(100) NULL,
            collected_by INT NOT NULL,
            status ENUM('success', 'failed', 'pending') DEFAULT 'success',
            commission_calculated BOOLEAN DEFAULT FALSE,
            commission_distributed BOOLEAN DEFAULT FALSE,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (loan_id) REFERENCES loans(id),
            FOREIGN KEY (emi_schedule_id) REFERENCES emi_schedule(id),
            FOREIGN KEY (collected_by) REFERENCES users(id)
        )",
        
        'loan_settlements' => "CREATE TABLE loan_settlements (
            id INT AUTO_INCREMENT PRIMARY KEY,
            loan_id INT NOT NULL,
            settlement_amount DECIMAL(15,2) NOT NULL,
            settlement_date DATE NOT NULL,
            settlement_type ENUM('full', 'partial') DEFAULT 'full',
            reason TEXT NULL,
            approved_by INT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (loan_id) REFERENCES loans(id),
            FOREIGN KEY (approved_by) REFERENCES users(id)
        )",
        
        'noc_certificates' => "CREATE TABLE noc_certificates (
            id INT AUTO_INCREMENT PRIMARY KEY,
            loan_id INT NOT NULL,
            certificate_number VARCHAR(50) NOT NULL UNIQUE,
            issue_date DATE NOT NULL,
            issued_by INT NOT NULL,
            notes TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (loan_id) REFERENCES loans(id),
            FOREIGN KEY (issued_by) REFERENCES users(id)
        )"
    ];
    
    foreach ($tables as $tableName => $createSQL) {
        $stmt = $pdo->prepare("SHOW TABLES LIKE ?");
        $stmt->execute([$tableName]);
        $tableExists = $stmt->rowCount() > 0;
        
        if (!$tableExists) {
            $pdo->exec($createSQL);
            echo "<p>Created table: $tableName</p>";
        } else {
            echo "<p>Table already exists: $tableName</p>";
        }
    }
    
    echo "<div class='alert alert-success'>Loan servicing database update completed successfully!</div>";
    
} catch (Exception $e) {
    echo "<div class='alert alert-danger'>Error updating database: " . $e->getMessage() . "</div>";
}

echo "<p><a href='super_admin/index.php'>Return to Dashboard</a></p>";
?>