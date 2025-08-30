<?php
require_once '../config/database.php';
require_once '../includes/auth.php';
redirectIfNotSuperAdmin();

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_rm'])) {
        // Add new RM
        $username = $_POST['username'];
        $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
        
        try {
            $pdo->beginTransaction();
            
            // Create user
            $stmt = $pdo->prepare("INSERT INTO users (username, password, role) VALUES (?, ?, 'rm')");
            $stmt->execute([$username, $password]);
            $user_id = $pdo->lastInsertId();
            
            // Add to rm_details
            $stmt = $pdo->prepare("INSERT INTO rm_details (user_id) VALUES (?)");
            $stmt->execute([$user_id]);
            
            // Handle custom fields
            $stmt = $pdo->prepare("SELECT * FROM custom_fields WHERE form_type = 'rm_add'");
            $stmt->execute();
            $custom_fields = $stmt->fetchAll();
            
            foreach ($custom_fields as $field) {
                $field_id = $field['id'];
                $field_name = 'custom_'.$field_id;
                
                if ($field['field_type'] === 'file' && isset($_FILES[$field_name])) {
                    // Handle file upload
                    $file = $_FILES[$field_name];
                    
                    if ($file['error'] === UPLOAD_ERR_OK) {
                        // Check file size
                        if ($file['size'] > $field['max_file_size'] * 1024) {
                            throw new Exception("File size exceeds maximum allowed size of {$field['max_file_size']}KB");
                        }
                        
                        // Check file type
                        $file_ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                        $allowed_types = explode(',', $field['allowed_file_types']);
                        $allowed_types = array_map('trim', $allowed_types);
                        
                        if (!in_array($file_ext, $allowed_types)) {
                            throw new Exception("File type not allowed. Allowed types: " . $field['allowed_file_types']);
                        }
                        
                        // Create upload directory if it doesn't exist
                        $upload_dir = '../uploads/';
                        if (!file_exists($upload_dir)) {
                            mkdir($upload_dir, 0777, true);
                        }
                        
                        // Generate unique filename
                        $new_filename = uniqid() . '_' . $file['name'];
                        $upload_path = $upload_dir . $new_filename;
                        
                        // Move uploaded file
                        if (move_uploaded_file($file['tmp_name'], $upload_path)) {
                            // Save file info to database
                            $stmt = $pdo->prepare("INSERT INTO uploaded_files (field_id, user_id, file_name, file_path, file_size) VALUES (?, ?, ?, ?, ?)");
                            $stmt->execute([$field_id, $user_id, $file['name'], $upload_path, $file['size']]);
                        } else {
                            throw new Exception("Failed to upload file");
                        }
                    } elseif ($file['error'] !== UPLOAD_ERR_NO_FILE && $field['is_required']) {
                        throw new Exception("File upload is required for {$field['field_name']}");
                    }
                } elseif (isset($_POST[$field_name])) {
                    $value = $_POST[$field_name];
                    $stmt = $pdo->prepare("INSERT INTO custom_field_values (field_id, user_id, value) VALUES (?, ?, ?)");
                    $stmt->execute([$field_id, $user_id, $value]);
                } elseif ($field['is_required']) {
                    throw new Exception("Field {$field['field_name']} is required");
                }
            }
            
            $pdo->commit();
            $success = "RM added successfully!";
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = "Error adding RM: " . $e->getMessage();
        }
    } elseif (isset($_POST['delete_rm'])) {
        // Delete RM
        $user_id = $_POST['user_id'];
        $stmt = $pdo->prepare("UPDATE users SET status = 'inactive' WHERE id = ?");
        $stmt->execute([$user_id]);
        $success = "RM deleted successfully!";
    } elseif (isset($_POST['assign_dsa'])) {
        // Assign RM to DSA
        $rm_id = $_POST['rm_id'];
        $dsa_id = $_POST['dsa_id'];
        $stmt = $pdo->prepare("UPDATE rm_details SET assigned_dsa_id = ? WHERE user_id = ?");
        $stmt->execute([$dsa_id, $rm_id]);
        $success = "RM assigned to DSA successfully!";
    } elseif (isset($_POST['collect_emi'])) {
        // Collect EMI
        $emi_schedule_id = $_POST['emi_schedule_id'];
        $amount_paid = $_POST['amount_paid'];
        $payment_date = $_POST['payment_date'];
        $payment_method = $_POST['payment_method'];
        $reference_number = $_POST['reference_number'] ?? '';
        
        try {
            $pdo->beginTransaction();
            
            // Get EMI details
            $stmt = $pdo->prepare("SELECT * FROM emi_schedule WHERE id = ?");
            $stmt->execute([$emi_schedule_id]);
            $emi = $stmt->fetch();
            
            if (!$emi) {
                throw new Exception("EMI schedule not found");
            }
            
            // Record payment
            $stmt = $pdo->prepare("
                INSERT INTO emi_payments (loan_id, emi_schedule_id, amount_paid, payment_date, payment_method, reference_number, collected_by) 
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$emi['loan_id'], $emi_schedule_id, $amount_paid, $payment_date, $payment_method, $reference_number, $_SESSION['user_id']]);
            
            // Update EMI schedule status
            $stmt = $pdo->prepare("UPDATE emi_schedule SET status = 'paid', paid_date = ? WHERE id = ?");
            $stmt->execute([$payment_date, $emi_schedule_id]);
            
            // Check if loan is fully paid
            $stmt = $pdo->prepare("SELECT COUNT(*) as pending_count FROM emi_schedule WHERE loan_id = ? AND status != 'paid'");
            $stmt->execute([$emi['loan_id']]);
            $pending = $stmt->fetch();
            
            if ($pending['pending_count'] == 0) {
                $stmt = $pdo->prepare("UPDATE loans SET status = 'closed' WHERE id = ?");
                $stmt->execute([$emi['loan_id']]);
            }
            
            $pdo->commit();
            $success = "EMI collected successfully!";
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = "Error collecting EMI: " . $e->getMessage();
        }
    } elseif (isset($_POST['settle_loan'])) {
        // Settle Loan
        $loan_id = $_POST['loan_id'];
        $settlement_amount = $_POST['settlement_amount'];
        $settlement_date = $_POST['settlement_date'];
        $settlement_type = $_POST['settlement_type'];
        $reason = $_POST['reason'] ?? '';
        
        try {
            $pdo->beginTransaction();
            
            // Record settlement
            $stmt = $pdo->prepare("
                INSERT INTO loan_settlements (loan_id, settlement_amount, settlement_date, settlement_type, reason, approved_by) 
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$loan_id, $settlement_amount, $settlement_date, $settlement_type, $reason, $_SESSION['user_id']]);
            
            // Update loan status
            $stmt = $pdo->prepare("UPDATE loans SET status = 'settled' WHERE id = ?");
            $stmt->execute([$loan_id]);
            
            // Mark all pending EMIs as paid with settlement
            $stmt = $pdo->prepare("UPDATE emi_schedule SET status = 'paid' WHERE loan_id = ? AND status = 'pending'");
            $stmt->execute([$loan_id]);
            
            $pdo->commit();
            $success = "Loan settled successfully!";
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = "Error settling loan: " . $e->getMessage();
        }
    } elseif (isset($_POST['issue_noc'])) {
        // Issue NOC
        $loan_id = $_POST['loan_id'];
        $issue_date = $_POST['issue_date'];
        $notes = $_POST['notes'] ?? '';
        
        try {
            $pdo->beginTransaction();
            
            // Generate certificate number
            $certificate_number = 'NOC-' . date('Ymd') . '-' . str_pad($loan_id, 6, '0', STR_PAD_LEFT);
            
            // Issue NOC certificate
            $stmt = $pdo->prepare("
                INSERT INTO noc_certificates (loan_id, certificate_number, issue_date, issued_by, notes) 
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->execute([$loan_id, $certificate_number, $issue_date, $_SESSION['user_id'], $notes]);
            
            $pdo->commit();
            $success = "NOC certificate issued successfully! Certificate #: " . $certificate_number;
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = "Error issuing NOC: " . $e->getMessage();
        }
    } elseif (isset($_POST['approve_application'])) {
        // Approve Loan Application
        $application_id = $_POST['application_id'];
        $loan_type_id = $_POST['loan_type_id'];
        $principal_amount = $_POST['principal_amount'];
        $interest_rate = $_POST['interest_rate'];
        $tenure_months = $_POST['tenure_months'];
        $start_date = $_POST['start_date'];
        
        try {
            $pdo->beginTransaction();
            
            // Update application status
            $stmt = $pdo->prepare("UPDATE loan_applications SET status = 'approved', reviewed_by = ?, reviewed_at = NOW() WHERE id = ?");
            $stmt->execute([$_SESSION['user_id'], $application_id]);
            
            // Create loan record
            $end_date = date('Y-m-d', strtotime($start_date . " + $tenure_months months"));
            
            $stmt = $pdo->prepare("
                INSERT INTO loans (application_id, user_id, loan_type_id, principal_amount, interest_rate, tenure_months, start_date, end_date) 
                VALUES (?, (SELECT user_id FROM loan_applications WHERE id = ?), ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$application_id, $application_id, $loan_type_id, $principal_amount, $interest_rate, $tenure_months, $start_date, $end_date]);
            $loan_id = $pdo->lastInsertId();
            
            // Generate EMI schedule (simplified version)
            $monthly_interest_rate = $interest_rate / 12 / 100;
            $emi = $principal_amount * $monthly_interest_rate * pow(1 + $monthly_interest_rate, $tenure_months) / (pow(1 + $monthly_interest_rate, $tenure_months) - 1);
            
            for ($i = 1; $i <= $tenure_months; $i++) {
                $emi_date = date('Y-m-d', strtotime($start_date . " + $i months"));
                $stmt = $pdo->prepare("
                    INSERT INTO emi_schedule (loan_id, emi_date, principal_amount, interest_amount, total_emi) 
                    VALUES (?, ?, ?, ?, ?)
                ");
                // Simplified calculation
                $principal_component = $principal_amount / $tenure_months;
                $interest_component = $emi - $principal_component;
                $stmt->execute([$loan_id, $emi_date, $principal_component, $interest_component, $emi]);
            }
            
            $pdo->commit();
            $success = "Loan application approved successfully! Loan #LN" . str_pad($loan_id, 6, '0', STR_PAD_LEFT) . " created.";
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = "Error approving application: " . $e->getMessage();
        }
    } elseif (isset($_POST['reject_application'])) {
        // Reject Loan Application
        $application_id = $_POST['application_id'];
        $rejection_reason = $_POST['rejection_reason'] ?? '';
        
        try {
            $pdo->beginTransaction();
            
            // Update application status
            $stmt = $pdo->prepare("UPDATE loan_applications SET status = 'rejected', reviewed_by = ?, reviewed_at = NOW(), rejection_reason = ? WHERE id = ?");
            $stmt->execute([$_SESSION['user_id'], $rejection_reason, $application_id]);
            
            $pdo->commit();
            $success = "Loan application rejected successfully!";
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = "Error rejecting application: " . $e->getMessage();
        }
    }
}

// Get active loans for EMI collection
$stmt = $pdo->prepare("
    SELECT l.*, lt.name as loan_type_name, u.username as customer_name,
           (SELECT COUNT(*) FROM emi_schedule es WHERE es.loan_id = l.id AND es.status = 'pending') as pending_emis
    FROM loans l
    LEFT JOIN loan_types lt ON l.loan_type_id = lt.id
    LEFT JOIN users u ON l.user_id = u.id
    WHERE l.status = 'active'
    ORDER BY l.created_at DESC
");
$stmt->execute();
$active_loans = $stmt->fetchAll();

// Get pending EMIs
$stmt = $pdo->prepare("
    SELECT es.*, l.principal_amount, l.interest_rate, u.username as customer_name, lt.name as loan_type_name
    FROM emi_schedule es
    LEFT JOIN loans l ON es.loan_id = l.id
    LEFT JOIN users u ON l.user_id = u.id
    LEFT JOIN loan_types lt ON l.loan_type_id = lt.id
    WHERE es.status = 'pending' AND es.emi_date <= CURDATE()
    ORDER BY es.emi_date ASC
");
$stmt->execute();
$pending_emis = $stmt->fetchAll();

// Get loans eligible for settlement
$stmt = $pdo->prepare("
    SELECT l.*, lt.name as loan_type_name, u.username as customer_name,
           (SELECT SUM(total_emi) FROM emi_schedule WHERE loan_id = l.id AND status = 'pending') as pending_amount
    FROM loans l
    LEFT JOIN loan_types lt ON l.loan_type_id = lt.id
    LEFT JOIN users u ON l.user_id = u.id
    WHERE l.status = 'active'
    ORDER BY l.created_at DESC
");
$stmt->execute();
$settlement_loans = $stmt->fetchAll();

// Get loans eligible for NOC
$stmt = $pdo->prepare("
    SELECT l.*, lt.name as loan_type_name, u.username as customer_name
    FROM loans l
    LEFT JOIN loan_types lt ON l.loan_type_id = lt.id
    LEFT JOIN users u ON l.user_id = u.id
    WHERE l.status IN ('closed', 'settled') 
    AND NOT EXISTS (SELECT 1 FROM noc_certificates nc WHERE nc.loan_id = l.id)
    ORDER BY l.created_at DESC
");
$stmt->execute();
$noc_loans = $stmt->fetchAll();

// Get pending loan applications
$stmt = $pdo->prepare("
    SELECT la.*, u.username as customer_name, u.id as customer_id, lt.name as loan_type_name,
           (SELECT COUNT(*) FROM custom_field_values cfv WHERE cfv.application_id = la.id) as field_count
    FROM loan_applications la
    LEFT JOIN users u ON la.user_id = u.id
    LEFT JOIN loan_types lt ON la.loan_type_id = lt.id
    WHERE la.status = 'pending'
    ORDER BY la.created_at DESC
");
$stmt->execute();
$pending_applications = $stmt->fetchAll();

// Get loan types for approval form
$stmt = $pdo->prepare("SELECT * FROM loan_types WHERE status = 'active' ORDER BY name");
$stmt->execute();
$loan_types = $stmt->fetchAll();

// Get processed applications
$stmt = $pdo->prepare("
    SELECT la.*, u.username as customer_name, lt.name as loan_type_name, 
           a.username as reviewed_by_name, la.reviewed_at,
           l.id as loan_id
    FROM loan_applications la
    LEFT JOIN users u ON la.user_id = u.id
    LEFT JOIN loan_types lt ON la.loan_type_id = lt.id
    LEFT JOIN users a ON la.reviewed_by = a.id
    LEFT JOIN loans l ON la.id = l.application_id
    WHERE la.status != 'pending'
    ORDER BY la.reviewed_at DESC
    LIMIT 10
");
$stmt->execute();
$processed_applications = $stmt->fetchAll();
?>

<?php include '../includes/header.php'; ?>
<div class="row">
    <div class="col-md-12">
        <h2>Loan Servicing</h2>
        
        <?php if (isset($success)): ?>
            <div class="alert alert-success"><?php echo $success; ?></div>
        <?php endif; ?>
        <?php if (isset($error)): ?>
            <div class="alert alert-danger"><?php echo $error; ?></div>
        <?php endif; ?>
        
        <ul class="nav nav-tabs" id="servicingTabs" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link active" id="emi-tab" data-bs-toggle="tab" data-bs-target="#emi" type="button">EMI Collection</button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="settlement-tab" data-bs-toggle="tab" data-bs-target="#settlement" type="button">Loan Settlement</button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="noc-tab" data-bs-toggle="tab" data-bs-target="#noc" type="button">NOC Certificates</button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="applications-tab" data-bs-toggle="tab" data-bs-target="#applications" type="button">Loan Applications</button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="reports-tab" data-bs-toggle="tab" data-bs-target="#reports" type="button">Reports</button>
            </li>
        </ul>
        
        <div class="tab-content mt-3" id="servicingTabsContent">
            <!-- EMI Collection Tab -->
            <div class="tab-pane fade show active" id="emi" role="tabpanel">
                <div class="card">
                    <div class="card-header">
                        <h4>Pending EMI Collection</h4>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-striped">
                                <thead>
                                    <tr>
                                        <th>Loan ID</th>
                                        <th>Customer</th>
                                        <th>Loan Type</th>
                                        <th>EMI Date</th>
                                        <th>EMI Amount</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($pending_emis as $emi): ?>
                                        <tr>
                                            <td>LN<?php echo str_pad($emi['loan_id'], 6, '0', STR_PAD_LEFT); ?></td>
                                            <td><?php echo $emi['customer_name']; ?></td>
                                            <td><?php echo $emi['loan_type_name']; ?></td>
                                            <td><?php echo date('d M Y', strtotime($emi['emi_date'])); ?></td>
                                            <td>₹<?php echo number_format($emi['total_emi'], 2); ?></td>
                                            <td>
                                                <span class="badge bg-<?php echo $emi['status'] === 'paid' ? 'success' : 'warning'; ?>">
                                                    <?php echo ucfirst($emi['status']); ?>
                                                </span>
                                                <?php if (strtotime($emi['emi_date']) < strtotime('today')): ?>
                                                    <span class="badge bg-danger">Overdue</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#collectEmiModal<?php echo $emi['id']; ?>">
                                                    Collect EMI
                                                </button>
                                            </td>
                                        </tr>
                                        
                                        <!-- Collect EMI Modal -->
                                        <div class="modal fade" id="collectEmiModal<?php echo $emi['id']; ?>" tabindex="-1">
                                            <div class="modal-dialog">
                                                <div class="modal-content">
                                                    <div class="modal-header">
                                                        <h5 class="modal-title">Collect EMI</h5>
                                                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                    </div>
                                                    <form method="POST">
                                                        <div class="modal-body">
                                                            <input type="hidden" name="emi_schedule_id" value="<?php echo $emi['id']; ?>">
                                                            <div class="mb-3">
                                                                <label class="form-label">Loan: LN<?php echo str_pad($emi['loan_id'], 6, '0', STR_PAD_LEFT); ?></label>
                                                            </div>
                                                            <div class="mb-3">
                                                                <label class="form-label">Customer: <?php echo $emi['customer_name']; ?></label>
                                                            </div>
                                                            <div class="mb-3">
                                                                <label class="form-label">EMI Amount: ₹<?php echo number_format($emi['total_emi'], 2); ?></label>
                                                            </div>
                                                            <div class="mb-3">
                                                                <label for="amount_paid<?php echo $emi['id']; ?>" class="form-label">Amount Paid</label>
                                                                <input type="number" step="0.01" class="form-control" id="amount_paid<?php echo $emi['id']; ?>" name="amount_paid" value="<?php echo $emi['total_emi']; ?>" required>
                                                            </div>
                                                            <div class="mb-3">
                                                                <label for="payment_date<?php echo $emi['id']; ?>" class="form-label">Payment Date</label>
                                                                <input type="date" class="form-control" id="payment_date<?php echo $emi['id']; ?>" name="payment_date" value="<?php echo date('Y-m-d'); ?>" required>
                                                            </div>
                                                            <div class="mb-3">
                                                                <label for="payment_method<?php echo $emi['id']; ?>" class="form-label">Payment Method</label>
                                                                <select class="form-control" id="payment_method<?php echo $emi['id']; ?>" name="payment_method" required>
                                                                    <option value="cash">Cash</option>
                                                                    <option value="bank_transfer">Bank Transfer</option>
                                                                    <option value="cheque">Cheque</option>
                                                                    <option value="online">Online Payment</option>
                                                                </select>
                                                            </div>
                                                            <div class="mb-3">
                                                                <label for="reference_number<?php echo $emi['id']; ?>" class="form-label">Reference Number</label>
                                                                <input type="text" class="form-control" id="reference_number<?php echo $emi['id']; ?>" name="reference_number">
                                                            </div>
                                                        </div>
                                                        <div class="modal-footer">
                                                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                            <button type="submit" name="collect_emi" class="btn btn-primary">Collect Payment</button>
                                                        </div>
                                                    </form>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                            <?php if (count($pending_emis) === 0): ?>
                                <p class="text-center text-muted">No pending EMIs for collection.</p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <div class="card mt-4">
                    <div class="card-header">
                        <h4>Active Loans</h4>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-striped">
                                <thead>
                                    <tr>
                                        <th>Loan ID</th>
                                        <th>Customer</th>
                                        <th>Loan Type</th>
                                        <th>Principal Amount</th>
                                        <th>Interest Rate</th>
                                        <th>Tenure</th>
                                        <th>Pending EMIs</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($active_loans as $loan): ?>
                                        <tr>
                                            <td>LN<?php echo str_pad($loan['id'], 6, '0', STR_PAD_LEFT); ?></td>
                                            <td><?php echo $loan['customer_name']; ?></td>
                                            <td><?php echo $loan['loan_type_name']; ?></td>
                                            <td>₹<?php echo number_format($loan['principal_amount'], 2); ?></td>
                                            <td><?php echo $loan['interest_rate']; ?>%</td>
                                            <td><?php echo $loan['tenure_months']; ?> months</td>
                                            <td><?php echo $loan['pending_emis']; ?></td>
                                            <td>
                                                <span class="badge bg-success">Active</span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Loan Settlement Tab -->
            <div class="tab-pane fade" id="settlement" role="tabpanel">
                <div class="card">
                    <div class="card-header">
                        <h4>Loan Settlement</h4>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-striped">
                                <thead>
                                    <tr>
                                        <th>Loan ID</th>
                                        <th>Customer</th>
                                        <th>Loan Type</th>
                                        <th>Principal Amount</th>
                                        <th>Pending Amount</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($settlement_loans as $loan): ?>
                                        <tr>
                                            <td>LN<?php echo str_pad($loan['id'], 6, '0', STR_PAD_LEFT); ?></td>
                                            <td><?php echo $loan['customer_name']; ?></td>
                                            <td><?php echo $loan['loan_type_name']; ?></td>
                                            <td>₹<?php echo number_format($loan['principal_amount'], 2); ?></td>
                                            <td>₹<?php echo number_format($loan['pending_amount'], 2); ?></td>
                                            <td>
                                                <button class="btn btn-sm btn-warning" data-bs-toggle="modal" data-bs-target="#settleLoanModal<?php echo $loan['id']; ?>">
                                                    Settle Loan
                                                </button>
                                            </td>
                                        </tr>
                                        
                                        <!-- Settle Loan Modal -->
                                        <div class="modal fade" id="settleLoanModal<?php echo $loan['id']; ?>" tabindex="-1">
                                            <div class="modal-dialog">
                                                <div class="modal-content">
                                                    <div class="modal-header">
                                                        <h5 class="modal-title">Settle Loan</h5>
                                                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                    </div>
                                                    <form method="POST">
                                                        <div class="modal-body">
                                                            <input type="hidden" name="loan_id" value="<?php echo $loan['id']; ?>">
                                                            <div class="mb-3">
                                                                <label class="form-label">Loan: LN<?php echo str_pad($loan['id'], 6, '0', STR_PAD_LEFT); ?></label>
                                                            </div>
                                                            <div class="mb-3">
                                                                <label class="form-label">Customer: <?php echo $loan['customer_name']; ?></label>
                                                            </div>
                                                            <div class="mb-3">
                                                                <label class="form-label">Pending Amount: ₹<?php echo number_format($loan['pending_amount'], 2); ?></label>
                                                            </div>
                                                            <div class="mb-3">
                                                                <label for="settlement_amount<?php echo $loan['id']; ?>" class="form-label">Settlement Amount</label>
                                                                <input type="number" step="0.01" class="form-control" id="settlement_amount<?php echo $loan['id']; ?>" name="settlement_amount" value="<?php echo $loan['pending_amount']; ?>" required>
                                                            </div>
                                                                                                            <div class="mb-3">
                                                    <label for="settlement_date<?php echo $loan['id']; ?>" class="form-label">Settlement Date</label>
                                                    <input type="date" class="form-control" id="settlement_date<?php echo $loan['id']; ?>" name="settlement_date" value="<?php echo date('Y-m-d'); ?>" required>
                                                </div>
                                                <div class="mb-3">
                                                    <label for="settlement_type<?php echo $loan['id']; ?>" class="form-label">Settlement Type</label>
                                                    <select class="form-control" id="settlement_type<?php echo $loan['id']; ?>" name="settlement_type" required>
                                                        <option value="full">Full Settlement</option>
                                                        <option value="partial">Partial Settlement</option>
                                                    </select>
                                                </div>
                                                <div class="mb-3">
                                                    <label for="reason<?php echo $loan['id']; ?>" class="form-label">Reason (Optional)</label>
                                                    <textarea class="form-control" id="reason<?php echo $loan['id']; ?>" name="reason" rows="3"></textarea>
                                                </div>
                                            </div>
                                            <div class="modal-footer">
                                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                <button type="submit" name="settle_loan" class="btn btn-warning">Settle Loan</button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- NOC Certificates Tab -->
<div class="tab-pane fade" id="noc" role="tabpanel">
    <div class="card">
        <div class="card-header">
            <h4>Issue NOC Certificates</h4>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-striped">
                    <thead>
                        <tr>
                            <th>Loan ID</th>
                            <th>Customer</th>
                            <th>Loan Type</th>
                            <th>Principal Amount</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($noc_loans as $loan): ?>
                            <tr>
                                <td>LN<?php echo str_pad($loan['id'], 6, '0', STR_PAD_LEFT); ?></td>
                                <td><?php echo $loan['customer_name']; ?></td>
                                <td><?php echo $loan['loan_type_name']; ?></td>
                                <td>₹<?php echo number_format($loan['principal_amount'], 2); ?></td>
                                <td>
                                    <span class="badge bg-<?php echo $loan['status'] === 'closed' ? 'success' : 'info'; ?>">
                                        <?php echo ucfirst($loan['status']); ?>
                                    </span>
                                </td>
                                <td>
                                    <button class="btn btn-sm btn-info" data-bs-toggle="modal" data-bs-target="#issueNocModal<?php echo $loan['id']; ?>">
                                        Issue NOC
                                    </button>
                                </td>
                            </tr>
                            
                            <!-- Issue NOC Modal -->
                            <div class="modal fade" id="issueNocModal<?php echo $loan['id']; ?>" tabindex="-1">
                                <div class="modal-dialog">
                                    <div class="modal-content">
                                        <div class="modal-header">
                                            <h5 class="modal-title">Issue NOC Certificate</h5>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                        </div>
                                        <form method="POST">
                                            <div class="modal-body">
                                                <input type="hidden" name="loan_id" value="<?php echo $loan['id']; ?>">
                                                <div class="mb-3">
                                                    <label class="form-label">Loan: LN<?php echo str_pad($loan['id'], 6, '0', STR_PAD_LEFT); ?></label>
                                                </div>
                                                <div class="mb-3">
                                                    <label class="form-label">Customer: <?php echo $loan['customer_name']; ?></label>
                                                </div>
                                                <div class="mb-3">
                                                    <label class="form-label">Status: <?php echo ucfirst($loan['status']); ?></label>
                                                </div>
                                                <div class="mb-3">
                                                    <label for="issue_date<?php echo $loan['id']; ?>" class="form-label">Issue Date</label>
                                                    <input type="date" class="form-control" id="issue_date<?php echo $loan['id']; ?>" name="issue_date" value="<?php echo date('Y-m-d'); ?>" required>
                                                </div>
                                                <div class="mb-3">
                                                    <label for="notes<?php echo $loan['id']; ?>" class="form-label">Notes (Optional)</label>
                                                    <textarea class="form-control" id="notes<?php echo $loan['id']; ?>" name="notes" rows="3"></textarea>
                                                </div>
                                            </div>
                                            <div class="modal-footer">
                                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                <button type="submit" name="issue_noc" class="btn btn-info">Issue NOC</button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php if (count($noc_loans) === 0): ?>
                    <p class="text-center text-muted">No loans eligible for NOC certificates.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <div class="card mt-4">
        <div class="card-header">
            <h4>Issued NOC Certificates</h4>
        </div>
        <div class="card-body">
            <?php
            $stmt = $pdo->prepare("
                SELECT nc.*, l.principal_amount, u.username as customer_name, lt.name as loan_type_name, a.username as issued_by_name
                FROM noc_certificates nc
                LEFT JOIN loans l ON nc.loan_id = l.id
                LEFT JOIN users u ON l.user_id = u.id
                LEFT JOIN loan_types lt ON l.loan_type_id = lt.id
                LEFT JOIN users a ON nc.issued_by = a.id
                ORDER BY nc.issue_date DESC
                LIMIT 10
            ");
            $stmt->execute();
            $issued_nocs = $stmt->fetchAll();
            ?>
            
            <div class="table-responsive">
                <table class="table table-striped">
                    <thead>
                        <tr>
                            <th>Certificate #</th>
                            <th>Loan ID</th>
                            <th>Customer</th>
                            <th>Issue Date</th>
                            <th>Issued By</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($issued_nocs as $noc): ?>
                            <tr>
                                <td><?php echo $noc['certificate_number']; ?></td>
                                <td>LN<?php echo str_pad($noc['loan_id'], 6, '0', STR_PAD_LEFT); ?></td>
                                <td><?php echo $noc['customer_name']; ?></td>
                                <td><?php echo date('d M Y', strtotime($noc['issue_date'])); ?></td>
                                <td><?php echo $noc['issued_by_name']; ?></td>
                                <td>
                                    <a href="generate_noc.php?certificate_id=<?php echo $noc['id']; ?>" target="_blank" class="btn btn-sm btn-success">
                                        View Certificate
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Loan Applications Tab -->
<div class="tab-pane fade" id="applications" role="tabpanel">
    <div class="card">
        <div class="card-header">
            <h4>Pending Loan Applications</h4>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-striped">
                    <thead>
                        <tr>
                            <th>App ID</th>
                            <th>Customer</th>
                            <th>Loan Type</th>
                            <th>Submitted</th>
                            <th>Fields</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($pending_applications as $application): ?>
                            <tr>
                                <td>APP<?php echo str_pad($application['id'], 6, '0', STR_PAD_LEFT); ?></td>
                                <td><?php echo $application['customer_name']; ?> (C<?php echo str_pad($application['customer_id'], 6, '0', STR_PAD_LEFT); ?>)</td>
                                <td><?php echo $application['loan_type_name']; ?></td>
                                <td><?php echo date('d M Y', strtotime($application['created_at'])); ?></td>
                                <td><?php echo $application['field_count']; ?> fields</td>
                                <td>
                                    <button class="btn btn-sm btn-info" data-bs-toggle="modal" data-bs-target="#viewApplicationModal<?php echo $application['id']; ?>">
                                        View Details
                                    </button>
                                    <button class="btn btn-sm btn-success" data-bs-toggle="modal" data-bs-target="#approveApplicationModal<?php echo $application['id']; ?>">
                                        Approve
                                    </button>
                                    <button class="btn btn-sm btn-danger" data-bs-toggle="modal" data-bs-target="#rejectApplicationModal<?php echo $application['id']; ?>">
                                        Reject
                                    </button>
                                </td>
                            </tr>
                            
                            <!-- View Application Modal -->
                            <div class="modal fade" id="viewApplicationModal<?php echo $application['id']; ?>" tabindex="-1">
                                <div class="modal-dialog modal-lg">
                                    <div class="modal-content">
                                        <div class="modal-header">
                                            <h5 class="modal-title">Application Details - APP<?php echo str_pad($application['id'], 6, '0', STR_PAD_LEFT); ?></h5>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                        </div>
                                        <div class="modal-body">
                                            <div class="row mb-3">
                                                <div class="col-md-6">
                                                    <strong>Customer:</strong> <?php echo $application['customer_name']; ?><br>
                                                    <strong>Customer ID:</strong> C<?php echo str_pad($application['customer_id'], 6, '0', STR_PAD_LEFT); ?><br>
                                                    <strong>Submitted:</strong> <?php echo date('d M Y H:i', strtotime($application['created_at'])); ?>
                                                </div>
                                                <div class="col-md-6">
                                                    <strong>Loan Type:</strong> <?php echo $application['loan_type_name']; ?><br>
                                                    <strong>Status:</strong> <span class="badge bg-warning">Pending</span>
                                                </div>
                                            </div>
                                            
                                            <h6>Application Data:</h6>
                                            <?php
                                            // Get custom field values for this application
                                            $stmt = $pdo->prepare("
                                                SELECT cf.field_name, cfv.value, uf.file_name, uf.file_path 
                                                FROM custom_fields cf 
                                                LEFT JOIN custom_field_values cfv ON cf.id = cfv.field_id AND cfv.application_id = ?
                                                LEFT JOIN uploaded_files uf ON cf.id = uf.field_id AND uf.application_id = ?
                                                WHERE cf.form_type = 'loan_application' 
                                                AND (cf.loan_type_id = ? OR cf.loan_type_id IS NULL)
                                                ORDER BY cf.display_order
                                            ");
                                            $stmt->execute([$application['id'], $application['id'], $application['loan_type_id']]);
                                            $field_values = $stmt->fetchAll();
                                            
                                            if (count($field_values) > 0): ?>
                                                <div class="table-responsive">
                                                    <table class="table table-bordered">
                                                        <thead>
                                                            <tr>
                                                                <th>Field Name</th>
                                                                <th>Value</th>
                                                            </tr>
                                                        </thead>
                                                        <tbody>
                                                            <?php foreach ($field_values as $value): ?>
                                                                <tr>
                                                                    <td><strong><?php echo $value['field_name']; ?></strong></td>
                                                                    <td>
                                                                        <?php if (!empty($value['file_path'])): ?>
                                                                            <a href="../<?php echo $value['file_path']; ?>" target="_blank"><?php echo $value['file_name']; ?></a>
                                                                        <?php else: ?>
                                                                            <?php echo $value['value'] ?? 'Not provided'; ?>
                                                                        <?php endif; ?>
                                                                    </td>
                                                                </tr>
                                                            <?php endforeach; ?>
                                                        </tbody>
                                                    </table>
                                                </div>
                                            <?php else: ?>
                                                <p>No application data found.</p>
                                            <?php endif; ?>
                                        </div>
                                        <div class="modal-footer">
                                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Approve Application Modal -->
                            <div class="modal fade" id="approveApplicationModal<?php echo $application['id']; ?>" tabindex="-1">
                                <div class="modal-dialog">
                                    <div class="modal-content">
                                        <div class="modal-header">
                                            <h5 class="modal-title">Approve Loan Application</h5>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                        </div>
                                        <form method="POST">
                                            <div class="modal-body">
                                                <input type="hidden" name="application_id" value="<?php echo $application['id']; ?>">
                                                <input type="hidden" name="loan_type_id" value="<?php echo $application['loan_type_id']; ?>">
                                                
                                                <div class="mb-3">
                                                    <label class="form-label">Application: APP<?php echo str_pad($application['id'], 6, '0', STR_PAD_LEFT); ?></label>
                                                </div>
                                                <div class="mb-3">
                                                    <label class="form-label">Customer: <?php echo $application['customer_name']; ?></label>
                                                </div>
                                                <div class="mb-3">
                                                    <label class="form-label">Loan Type: <?php echo $application['loan_type_name']; ?></label>
                                                </div>
                                                <div class="mb-3">
                                                    <label for="principal_amount<?php echo $application['id']; ?>" class="form-label">Principal Amount (₹)</label>
                                                    <input type="number" step="0.01" class="form-control" id="principal_amount<?php echo $application['id']; ?>" name="principal_amount" required>
                                                </div>
                                                <div class="mb-3">
                                                    <label for="interest_rate<?php echo $application['id']; ?>" class="form-label">Interest Rate (%)</label>
                                                    <input type="number" step="0.01" class="form-control" id="interest_rate<?php echo $application['id']; ?>" name="interest_rate" required>
                                                </div>
                                                <div class="mb-3">
                                                    <label for="tenure_months<?php echo $application['id']; ?>" class="form-label">Tenure (Months)</label>
                                                    <input type="number" class="form-control" id="tenure_months<?php echo $application['id']; ?>" name="tenure_months" required>
                                                </div>
                                                                                                            <div class="mb-3">
                                                                <label for="start_date<?php echo $application['id']; ?>" class="form-label">Start Date</label>
                                                                <input type="date" class="form-control" id="start_date<?php echo $application['id']; ?>" name="start_date" value="<?php echo date('Y-m-d'); ?>" required>
                                                            </div>
                                                        </div>
                                                        <div class="modal-footer">
                                                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                            <button type="submit" name="approve_application" class="btn btn-success">Approve Application</button>
                                                        </div>
                                                    </form>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <!-- Reject Application Modal -->
                                        <div class="modal fade" id="rejectApplicationModal<?php echo $application['id']; ?>" tabindex="-1">
                                            <div class="modal-dialog">
                                                <div class="modal-content">
                                                    <div class="modal-header">
                                                        <h5 class="modal-title">Reject Loan Application</h5>
                                                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                    </div>
                                                    <form method="POST">
                                                        <div class="modal-body">
                                                            <input type="hidden" name="application_id" value="<?php echo $application['id']; ?>">
                                                            
                                                            <div class="mb-3">
                                                                <label class="form-label">Application: APP<?php echo str_pad($application['id'], 6, '0', STR_PAD_LEFT); ?></label>
                                                            </div>
                                                            <div class="mb-3">
                                                                <label class="form-label">Customer: <?php echo $application['customer_name']; ?></label>
                                                            </div>
                                                            <div class="mb-3">
                                                                <label class="form-label">Loan Type: <?php echo $application['loan_type_name']; ?></label>
                                                            </div>
                                                            <div class="mb-3">
                                                                <label for="rejection_reason<?php echo $application['id']; ?>" class="form-label">Reason for Rejection</label>
                                                                <textarea class="form-control" id="rejection_reason<?php echo $application['id']; ?>" name="rejection_reason" rows="3" required></textarea>
                                                            </div>
                                                        </div>
                                                        <div class="modal-footer">
                                                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                            <button type="submit" name="reject_application" class="btn btn-danger">Reject Application</button>
                                                        </div>
                                                    </form>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                            <?php if (count($pending_applications) === 0): ?>
                                <p class="text-center text-muted">No pending loan applications.</p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <div class="card mt-4">
                    <div class="card-header">
                        <h4>Processed Applications</h4>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-striped">
                                <thead>
                                    <tr>
                                        <th>App ID</th>
                                        <th>Customer</th>
                                        <th>Loan Type</th>
                                        <th>Status</th>
                                        <th>Reviewed By</th>
                                        <th>Reviewed At</th>
                                        <th>Loan ID</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($processed_applications as $app): ?>
                                        <tr>
                                            <td>APP<?php echo str_pad($app['id'], 6, '0', STR_PAD_LEFT); ?></td>
                                            <td><?php echo $app['customer_name']; ?></td>
                                            <td><?php echo $app['loan_type_name']; ?></td>
                                            <td>
                                                <span class="badge bg-<?php echo $app['status'] === 'approved' ? 'success' : 'danger'; ?>">
                                                    <?php echo ucfirst($app['status']); ?>
                                                </span>
                                            </td>
                                            <td><?php echo $app['reviewed_by_name']; ?></td>
                                            <td><?php echo date('d M Y H:i', strtotime($app['reviewed_at'])); ?></td>
                                            <td>
                                                <?php if ($app['loan_id']): ?>
                                                    LN<?php echo str_pad($app['loan_id'], 6, '0', STR_PAD_LEFT); ?>
                                                <?php else: ?>
                                                    -
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Reports Tab -->
            <div class="tab-pane fade" id="reports" role="tabpanel">
                <div class="card">
                    <div class="card-header">
                        <h4>Loan Servicing Reports</h4>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="card">
                                    <div class="card-body text-center">
                                        <h5>Total EMI Collected Today</h5>
                                        <?php
                                        $stmt = $pdo->prepare("
                                            SELECT SUM(amount_paid) as total_collected 
                                            FROM emi_payments 
                                            WHERE payment_date = CURDATE() AND status = 'success'
                                        ");
                                        $stmt->execute();
                                        $today_collection = $stmt->fetch();
                                        ?>
                                        <h3>₹<?php echo number_format($today_collection['total_collected'] ?? 0, 2); ?></h3>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="card">
                                    <div class="card-body text-center">
                                        <h5>Pending EMIs</h5>
                                        <?php
                                        $stmt = $pdo->prepare("
                                            SELECT COUNT(*) as pending_count 
                                            FROM emi_schedule 
                                            WHERE status = 'pending' AND emi_date <= CURDATE()
                                        ");
                                        $stmt->execute();
                                        $pending_count = $stmt->fetch();
                                        ?>
                                        <h3><?php echo $pending_count['pending_count'] ?? 0; ?></h3>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="row mt-4">
                            <div class="col-md-12">
                                <h5>Recent EMI Collections</h5>
                                <?php
                                $stmt = $pdo->prepare("
                                    SELECT ep.*, es.emi_date, u.username as customer_name, l.id as loan_id
                                    FROM emi_payments ep
                                    LEFT JOIN emi_schedule es ON ep.emi_schedule_id = es.id
                                    LEFT JOIN loans l ON ep.loan_id = l.id
                                    LEFT JOIN users u ON l.user_id = u.id
                                    ORDER BY ep.payment_date DESC, ep.created_at DESC
                                    LIMIT 10
                                ");
                                $stmt->execute();
                                $recent_collections = $stmt->fetchAll();
                                ?>
                                
                                <div class="table-responsive">
                                    <table class="table table-striped">
                                        <thead>
                                            <tr>
                                                <th>Date</th>
                                                <th>Loan ID</th>
                                                <th>Customer</th>
                                                <th>Amount</th>
                                                <th>Method</th>
                                                <th>Collected By</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($recent_collections as $collection): ?>
                                                <tr>
                                                    <td><?php echo date('d M Y', strtotime($collection['payment_date'])); ?></td>
                                                    <td>LN<?php echo str_pad($collection['loan_id'], 6, '0', STR_PAD_LEFT); ?></td>
                                                    <td><?php echo $collection['customer_name']; ?></td>
                                                    <td>₹<?php echo number_format($collection['amount_paid'], 2); ?></td>
                                                    <td><?php echo ucfirst($collection['payment_method']); ?></td>
                                                    <td>
                                                        <?php
                                                        $stmt = $pdo->prepare("SELECT username FROM users WHERE id = ?");
                                                        $stmt->execute([$collection['collected_by']]);
                                                        $collector = $stmt->fetch();
                                                        echo $collector['username'] ?? 'Unknown';
                                                        ?>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<?php include '../includes/footer.php'; ?>