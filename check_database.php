<?php
require_once 'config/database.php';

function checkTableExists($pdo, $tableName) {
    $stmt = $pdo->prepare("SHOW TABLES LIKE ?");
    $stmt->execute([$tableName]);
    return $stmt->rowCount() > 0;
}

function checkColumnExists($pdo, $tableName, $columnName) {
    $stmt = $pdo->prepare("SHOW COLUMNS FROM $tableName LIKE ?");
    $stmt->execute([$columnName]);
    return $stmt->rowCount() > 0;
}

function checkEnumValues($pdo, $tableName, $columnName, $expectedValues) {
    $stmt = $pdo->prepare("SHOW COLUMNS FROM $tableName WHERE Field = ?");
    $stmt->execute([$columnName]);
    $column = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$column) return false;
    
    // Extract enum values from the Type field
    preg_match("/^enum\(\'(.*)\'\)$/", $column['Type'], $matches);
    if (!isset($matches[1])) return false;
    
    $currentValues = explode("','", $matches[1]);
    sort($currentValues);
    sort($expectedValues);
    
    return $currentValues == $expectedValues;
}

echo "<h2>Database Structure Check</h2>";

// Check if all required tables exist
$requiredTables = ['users', 'custom_fields', 'dsa_details', 'rm_details', 'loan_types', 'loan_slabs', 'commissions', 'custom_field_values', 'uploaded_files'];
echo "<h3>Table Check</h3>";
echo "<table border='1' cellpadding='5'>";
echo "<tr><th>Table Name</th><th>Exists</th></tr>";

foreach ($requiredTables as $table) {
    $exists = checkTableExists($pdo, $table) ? "Yes" : "No";
    echo "<tr><td>$table</td><td>$exists</td></tr>";
}
echo "</table>";

// Check dsa_details table structure
echo "<h3>DSA Details Table Check</h3>";
if (checkTableExists($pdo, 'dsa_details')) {
    $requiredColumns = [
        'kyc_status' => ['type' => 'enum', 'values' => ['pending', 'approved', 'rejected']],
        'kyc_approved_by' => ['type' => 'int'],
        'kyc_approved_at' => ['type' => 'timestamp']
    ];
    
    echo "<table border='1' cellpadding='5'>";
    echo "<tr><th>Column Name</th><th>Exists</th><th>Type Correct</th></tr>";
    
    foreach ($requiredColumns as $column => $requirements) {
        $exists = checkColumnExists($pdo, 'dsa_details', $column) ? "Yes" : "No";
        
        if ($exists === "Yes" && $requirements['type'] === 'enum') {
            $typeCorrect = checkEnumValues($pdo, 'dsa_details', $column, $requirements['values']) ? "Yes" : "No";
        } else {
            $typeCorrect = "N/A";
        }
        
        echo "<tr><td>$column</td><td>$exists</td><td>$typeCorrect</td></tr>";
    }
    echo "</table>";
} else {
    echo "<p>dsa_details table does not exist.</p>";
}

// Check custom_fields table for DSA KYC support
echo "<h3>Custom Fields Check</h3>";
if (checkTableExists($pdo, 'custom_fields')) {
    // Check if DSA KYC form type exists
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM custom_fields WHERE form_type = 'dsa_kyc'");
    $stmt->execute();
    $count = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
    
    echo "<p>DSA KYC form fields found: $count</p>";
    
    // Show all form types in the system
    $stmt = $pdo->prepare("SELECT form_type, COUNT(*) as count FROM custom_fields GROUP BY form_type");
    $stmt->execute();
    $formTypes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<h4>Existing Form Types</h4>";
    echo "<ul>";
    foreach ($formTypes as $type) {
        echo "<li>{$type['form_type']}: {$type['count']} fields</li>";
    }
    echo "</ul>";
}

// Check for any existing DSA KYC data
echo "<h3>DSA KYC Data Check</h3>";
$stmt = $pdo->prepare("
    SELECT u.username, dd.kyc_status, dd.kyc_approved_at, a.username as approved_by 
    FROM users u 
    LEFT JOIN dsa_details dd ON u.id = dd.user_id 
    LEFT JOIN users a ON dd.kyc_approved_by = a.id 
    WHERE u.role = 'dsa'
");
$stmt->execute();
$dsas = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "<p>DSAs found: " . count($dsas) . "</p>";

if (count($dsas) > 0) {
    echo "<table border='1' cellpadding='5'>";
    echo "<tr><th>Username</th><th>KYC Status</th><th>Approved By</th><th>Approved At</th></tr>";
    foreach ($dsas as $dsa) {
        echo "<tr>";
        echo "<td>{$dsa['username']}</td>";
        echo "<td>{$dsa['kyc_status']}</td>";
        echo "<td>{$dsa['approved_by']}</td>";
        echo "<td>{$dsa['kyc_approved_at']}</td>";
        echo "</tr>";
    }
    echo "</table>";
}

// Provide SQL fixes if needed
echo "<h3>SQL Fixes (If Needed)</h3>";
echo "<p>If any tables or columns are missing, you may need to run these SQL commands:</p>";
echo "<textarea rows='10' cols='80' readonly>
-- Add KYC columns to dsa_details table if they don't exist
ALTER TABLE dsa_details 
ADD COLUMN IF NOT EXISTS kyc_status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
ADD COLUMN IF NOT EXISTS kyc_approved_by INT NULL,
ADD COLUMN IF NOT EXISTS kyc_approved_at TIMESTAMP NULL;

-- Add foreign key constraint if it doesn't exist
ALTER TABLE dsa_details 
ADD FOREIGN KEY IF NOT EXISTS (kyc_approved_by) REFERENCES users(id);

-- Ensure custom_fields table supports dsa_kyc form type
-- (No structural changes needed, just use 'dsa_kyc' as form_type when creating fields)
</textarea>";

echo "<p><a href='super_admin/index.php'>Return to Dashboard</a></p>";
?>