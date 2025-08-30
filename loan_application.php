<?php
require_once 'config/database.php';
require_once 'includes/auth.php';

// This would be in the customer/application portal
if (!isLoggedIn()) {
    header('Location: login.php');
    exit();
}

// Get loan type from query parameter
$loan_type_id = $_GET['loan_type_id'] ?? null;
$principal_amount = $_POST['principal_amount'] ?? 0;
$tenure_months = $_POST['tenure_months'] ?? 0;
$referral_code = $_POST['referral_code'] ?? '';

// Function to validate referral code
function validateReferralCode($referral_code, $pdo) {
    if (empty($referral_code)) {
        return ['valid' => false, 'message' => 'Referral code is required'];
    }
    
    $stmt = $pdo->prepare("
        SELECT c.*, u.username as dsa_name 
        FROM connectors c
        LEFT JOIN users u ON c.dsa_id = u.id
        WHERE c.referral_code = ? AND c.status = 'active'
    ");
    $stmt->execute([$referral_code]);
    $connector = $stmt->fetch();
    
    if (!$connector) {
        return ['valid' => false, 'message' => 'Invalid referral code'];
    }
    
    return ['valid' => true, 'connector' => $connector];
}

// Function to calculate EMI
function calculateEMI($principal, $interest_rate, $tenure) {
    $monthly_interest = $interest_rate / 12 / 100;
    $emi = $principal * $monthly_interest * pow(1 + $monthly_interest, $tenure) / (pow(1 + $monthly_interest, $tenure) - 1);
    return round($emi, 2);
}

// Function to generate amortization schedule
function generateAmortizationSchedule($principal, $interest_rate, $tenure, $emi) {
    $schedule = [];
    $balance = $principal;
    $monthly_interest = $interest_rate / 12 / 100;
    
    for ($month = 1; $month <= $tenure; $month++) {
        $interest_component = $balance * $monthly_interest;
        $principal_component = $emi - $interest_component;
        $balance -= $principal_component;
        
        // Ensure balance doesn't go negative
        if ($balance < 0) {
            $principal_component += $balance; // Adjust principal component
            $balance = 0;
        }
        
        $schedule[] = [
            'month' => $month,
            'emi' => $emi,
            'principal' => round($principal_component, 2),
            'interest' => round($interest_component, 2),
            'balance' => round($balance, 2)
        ];
    }
    
    return $schedule;
}

// Function to validate loan against slabs
function validateLoanAgainstSlabs($loan_type_id, $principal_amount, $tenure_months, $pdo) {
    $errors = [];
    
    // Get all slabs for this loan type
    $stmt = $pdo->prepare("
        SELECT * FROM loan_slabs 
        WHERE loan_type_id = ? AND status = 'active'
        ORDER BY min_amount ASC
    ");
    $stmt->execute([$loan_type_id]);
    $slabs = $stmt->fetchAll();
    
    if (empty($slabs)) {
        $errors[] = "No loan slabs defined for this loan type. Please contact administrator.";
        return $errors;
    }
    
    // Check if amount is within any slab
    $amount_valid = false;
    $tenure_valid = false;
    $applicable_slabs = [];
    
    foreach ($slabs as $slab) {
        if ($principal_amount >= $slab['min_amount'] && $principal_amount <= $slab['max_amount']) {
            $amount_valid = true;
            $applicable_slabs[] = $slab;
            
            // Check if tenure is within slab limits
            if ($tenure_months >= $slab['tenure_min'] && $tenure_months <= $slab['tenure_max']) {
                $tenure_valid = true;
                break;
            }
        }
    }
    
    if (!$amount_valid) {
        $min_amount = min(array_column($slabs, 'min_amount'));
        $max_amount = max(array_column($slabs, 'max_amount'));
        $errors[] = "Loan amount must be between ₹" . number_format($min_amount, 2) . " and ₹" . number_format($max_amount, 2);
    }
    
    if ($amount_valid && !$tenure_valid) {
        // Find the applicable slab for tenure validation
        foreach ($applicable_slabs as $slab) {
            $errors[] = "For loan amount of ₹" . number_format($principal_amount, 2) . 
                       ", tenure must be between " . $slab['tenure_min'] . " and " . $slab['tenure_max'] . " months";
        }
    }
    
    return $errors;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_application'])) {
    // Handle loan application submission
    $loan_type_id = $_POST['loan_type_id'];
    $user_id = $_SESSION['user_id'];
    $principal_amount = $_POST['principal_amount'] ?? 0;
    $tenure_months = $_POST['tenure_months'] ?? 0;
    $referral_code = $_POST['referral_code'] ?? '';
    
    // Validate referral code
    $referral_validation = validateReferralCode($referral_code, $pdo);
    if (!$referral_validation['valid']) {
        $error = $referral_validation['message'];
    } else {
        // Validate against loan slabs
        $validation_errors = validateLoanAgainstSlabs($loan_type_id, $principal_amount, $tenure_months, $pdo);
        
        if (!empty($validation_errors)) {
            $error = implode("<br>", $validation_errors);
        } else {
            try {
                $pdo->beginTransaction();
                
                // Get connector info
                $connector = $referral_validation['connector'];
                
                // Create loan application record with pending status
                $stmt = $pdo->prepare("
                    INSERT INTO loan_applications (user_id, loan_type_id, referral_code, connector_id, status) 
                    VALUES (?, ?, ?, ?, 'pending')
                ");
                $stmt->execute([$user_id, $loan_type_id, $referral_code, $connector['id']]);
                $application_id = $pdo->lastInsertId();
                
                // Get custom fields for this loan type
                $stmt = $pdo->prepare("
                    SELECT * FROM custom_fields 
                    WHERE form_type = 'loan_application' 
                    AND (loan_type_id = ? OR loan_type_id IS NULL)
                    ORDER BY display_order
                ");
                $stmt->execute([$loan_type_id]);
                $custom_fields = $stmt->fetchAll();
                
                // Process custom fields
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
                            $upload_dir = 'uploads/';
                            if (!file_exists($upload_dir)) {
                                mkdir($upload_dir, 0777, true);
                            }
                            
                            // Generate unique filename
                            $new_filename = uniqid() . '_' . $file['name'];
                            $upload_path = $upload_dir . $new_filename;
                            
                            // Move uploaded file
                            if (move_uploaded_file($file['tmp_name'], $upload_path)) {
                                // Save file info to database
                                $stmt = $pdo->prepare("INSERT INTO uploaded_files (field_id, user_id, application_id, file_name, file_path, file_size) VALUES (?, ?, ?, ?, ?, ?)");
                                $stmt->execute([$field_id, $user_id, $application_id, $file['name'], $upload_path, $file['size']]);
                            } else {
                                throw new Exception("Failed to upload file");
                            }
                        } elseif ($file['error'] !== UPLOAD_ERR_NO_FILE && $field['is_required']) {
                            throw new Exception("File upload is required for {$field['field_name']}");
                        }
                    } elseif (isset($_POST[$field_name])) {
                        $value = $_POST[$field_name];
                        $stmt = $pdo->prepare("INSERT INTO custom_field_values (field_id, user_id, application_id, value) VALUES (?, ?, ?, ?)");
                        $stmt->execute([$field_id, $user_id, $application_id, $value]);
                    } elseif ($field['is_required']) {
                        throw new Exception("Field {$field['field_name']} is required");
                    }
                }
                
                $pdo->commit();
                $success = "Loan application submitted successfully! Your application ID: APP" . str_pad($application_id, 6, '0', STR_PAD_LEFT);
                
                // Reset form values
                $principal_amount = 0;
                $tenure_months = 0;
                $loan_type_id = null;
                $referral_code = '';
                
            } catch (Exception $e) {
                $pdo->rollBack();
                $error = "Error submitting application: " . $e->getMessage();
            }
        }
    }
}

// Get loan types for dropdown
$stmt = $pdo->prepare("SELECT * FROM loan_types WHERE status = 'active' ORDER BY name");
$stmt->execute();
$loan_types = $stmt->fetchAll();

// Get loan slabs for selected loan type
$loan_slabs = [];
if ($loan_type_id) {
    $stmt = $pdo->prepare("
        SELECT * FROM loan_slabs 
        WHERE loan_type_id = ? AND status = 'active'
        ORDER BY min_amount ASC
    ");
    $stmt->execute([$loan_type_id]);
    $loan_slabs = $stmt->fetchAll();
    
    // Get custom fields for selected loan type
    $stmt = $pdo->prepare("
        SELECT * FROM custom_fields 
        WHERE form_type = 'loan_application' 
        AND (loan_type_id = ? OR loan_type_id IS NULL)
        ORDER BY display_order
    ");
    $stmt->execute([$loan_type_id]);
    $custom_fields = $stmt->fetchAll();
    
    // Get interest rate for the selected loan amount and tenure
    $interest_rate = 0;
    if ($principal_amount > 0) {
        $stmt = $pdo->prepare("
            SELECT interest_rate, tenure_min, tenure_max 
            FROM loan_slabs 
            WHERE loan_type_id = ? 
            AND ? BETWEEN min_amount AND max_amount
            AND ? BETWEEN tenure_min AND tenure_max
            AND status = 'active'
            ORDER BY interest_rate ASC
            LIMIT 1
        ");
        $stmt->execute([$loan_type_id, $principal_amount, $tenure_months]);
        $loan_slab = $stmt->fetch();
        
        if ($loan_slab) {
            $interest_rate = $loan_slab['interest_rate'];
        } else {
            // If no exact match, find the best matching slab for amount only
            $stmt = $pdo->prepare("
                SELECT interest_rate 
                FROM loan_slabs 
                WHERE loan_type_id = ? 
                AND ? BETWEEN min_amount AND max_amount
                AND status = 'active'
                ORDER BY interest_rate ASC
                LIMIT 1
            ");
            $stmt->execute([$loan_type_id, $principal_amount]);
            $loan_slab = $stmt->fetch();
            $interest_rate = $loan_slab ? $loan_slab['interest_rate'] : 0;
        }
    }
    
    // Calculate EMI and details if principal and tenure are provided
    if ($principal_amount > 0 && $tenure_months > 0 && $interest_rate > 0) {
        $emi = calculateEMI($principal_amount, $interest_rate, $tenure_months);
        $total_payment = $emi * $tenure_months;
        $total_interest = $total_payment - $principal_amount;
        $amortization_schedule = generateAmortizationSchedule($principal_amount, $interest_rate, $tenure_months, $emi);
    }
} else {
    $custom_fields = [];
    $interest_rate = 0;
    $emi = 0;
    $total_payment = 0;
    $total_interest = 0;
    $amortization_schedule = [];
}
?>

<?php include 'includes/header.php'; ?>
<div class="row justify-content-center">
    <div class="col-md-10">
        <h2>Loan Application</h2>
        
        <?php if (isset($success)): ?>
            <div class="alert alert-success"><?php echo $success; ?></div>
        <?php endif; ?>
        <?php if (isset($error)): ?>
            <div class="alert alert-danger"><?php echo $error; ?></div>
        <?php endif; ?>
        
        <div class="card mt-4">
            <div class="card-header">
                <h4>Apply for a Loan</h4>
            </div>
            <div class="card-body">
                <form method="GET" class="mb-4">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="loan_type_id" class="form-label">Select Loan Type</label>
                                <select class="form-control" id="loan_type_id" name="loan_type_id" required onchange="this.form.submit()">
                                    <option value="">Select Loan Type</option>
                                    <?php foreach ($loan_types as $type): ?>
                                        <option value="<?php echo $type['id']; ?>" <?php echo ($type['id'] == $loan_type_id) ? 'selected' : ''; ?>>
                                            <?php echo $type['name']; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>
                </form>
                
                <?php if ($loan_type_id): ?>
                    <?php
                    $loan_type_name = '';
                    foreach ($loan_types as $type) {
                        if ($type['id'] == $loan_type_id) {
                            $loan_type_name = $type['name'];
                            break;
                        }
                    }
                    ?>
                    
                    <!-- Display Loan Slabs Information -->
                    <?php if (!empty($loan_slabs)): ?>
                        <div class="alert alert-info">
                            <h6>Available Loan Options for <?php echo $loan_type_name; ?>:</h6>
                            <div class="row">
                                <?php foreach ($loan_slabs as $slab): ?>
                                    <div class="col-md-6 mb-2">
                                        <strong>₹<?php echo number_format($slab['min_amount']); ?> - ₹<?php echo number_format($slab['max_amount']); ?></strong>
                                        <br>
                                        Interest Rate: <?php echo $slab['interest_rate']; ?>%
                                        <br>
                                        Tenure: <?php echo $slab['tenure_min']; ?> - <?php echo $slab['tenure_max']; ?> months
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="card mb-4">
                                <div class="card-header">
                                    <h5>Loan Details</h5>
                                </div>
                                <div class="card-body">
                                    <form method="POST" id="emiCalculatorForm">
                                        <input type="hidden" name="loan_type_id" value="<?php echo $loan_type_id; ?>">
                                        
                                        <div class="mb-3">
                                            <label for="principal_amount" class="form-label">Loan Amount (₹)</label>
                                            <input type="number" step="1000" min="0" class="form-control" id="principal_amount" name="principal_amount" value="<?php echo $principal_amount; ?>" required onchange="calculateEMI()">
                                            <small class="form-text text-muted">
                                                Must be within available loan slabs
                                            </small>
                                        </div>
                                        
                                        <div class="mb-3">
                                            <label for="tenure_months" class="form-label">Loan Tenure (Months)</label>
                                            <input type="number" min="1" max="360" class="form-control" id="tenure_months" name="tenure_months" value="<?php echo $tenure_months; ?>" required onchange="calculateEMI()">
                                            <small class="form-text text-muted">
                                                Must be within tenure range for selected amount
                                            </small>
                                        </div>
                                        
                                        <div class="mb-3">
                                            <label for="referral_code" class="form-label">Referral Code</label>
                                            <input type="text" class="form-control" id="referral_code" name="referral_code" value="<?php echo htmlspecialchars($referral_code); ?>" required>
                                            <small class="form-text text-muted">
                                                Enter the referral code provided by your connector
                                            </small>
                                        </div>
                                        
                                        <div class="mb-3">
                                            <label class="form-label">Interest Rate</label>
                                            <div class="form-control" style="background-color: #f8f9fa;">
                                                <?php echo $interest_rate; ?>% per annum
                                                <?php if ($principal_amount > 0 && $tenure_months > 0 && $interest_rate == 0): ?>
                                                    <span class="text-danger">* No matching loan slab found for this amount and tenure</span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        
                                        <?php if ($principal_amount > 0 && $tenure_months > 0 && $interest_rate > 0): ?>
                                            <div class="emi-details">
                                                <h6>EMI Calculation</h6>
                                                <table class="table table-sm">
                                                    <tr>
                                                        <td>Monthly EMI:</td>
                                                        <td class="text-end"><strong>₹<?php echo number_format($emi, 2); ?></strong></td>
                                                    </tr>
                                                    <tr>
                                                        <td>Total Interest Payable:</td>
                                                        <td class="text-end">₹<?php echo number_format($total_interest, 2); ?></td>
                                                    </tr>
                                                    <tr>
                                                        <td>Total Payment:</td>
                                                        <td class="text-end">₹<?php echo number_format($total_payment, 2); ?></td>
                                                    </tr>
                                                    <tr>
                                                        <td>Principal Amount:</td>
                                                        <td class="text-end">₹<?php echo number_format($principal_amount, 2); ?></td>
                                                    </tr>
                                                </table>
                                            </div>
                                        <?php endif; ?>
                                    </form>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-md-6">
                            <?php if ($principal_amount > 0 && $tenure_months > 0 && $interest_rate > 0): ?>
                                <div class="card mb-4">
                                    <div class="card-header">
                                        <h5>Amortization Schedule</h5>
                                    </div>
                                    <div class="card-body">
                                        <div style="max-height: 300px; overflow-y: auto;">
                                            <table class="table table-sm">
                                                <thead>
                                                    <tr>
                                                        <th>Month</th>
                                                        <th class="text-end">Principal</th>
                                                        <th class="text-end">Interest</th>
                                                        <th class="text-end">Balance</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ($amortization_schedule as $schedule): ?>
                                                        <tr>
                                                            <td><?php echo $schedule['month']; ?></td>
                                                            <td class="text-end">₹<?php echo number_format($schedule['principal'], 2); ?></td>
                                                            <td class="text-end">₹<?php echo number_format($schedule['interest'], 2); ?></td>
                                                            <td class="text-end">₹<?php echo number_format($schedule['balance'], 2); ?></td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                </div>
                            <?php endif; ?>
                            
                            <div class="card">
                                <div class="card-header">
                                    <h5>Application Form</h5>
                                </div>
                                <div class="card-body">
                                    <form method="POST" enctype="multipart/form-data" id="loanApplicationForm">
                                        <input type="hidden" name="loan_type_id" value="<?php echo $loan_type_id; ?>">
                                        <input type="hidden" name="principal_amount" value="<?php echo $principal_amount; ?>">
                                        <input type="hidden" name="tenure_months" value="<?php echo $tenure_months; ?>">
                                        <input type="hidden" name="referral_code" value="<?php echo htmlspecialchars($referral_code); ?>">
                                        
                                        <!-- Custom Fields -->
                                        <?php foreach ($custom_fields as $field): ?>
                                            <div class="mb-3">
                                                <label for="custom_<?php echo $field['id']; ?>" class="form-label">
                                                    <?php echo $field['field_name']; ?>
                                                    <?php if ($field['is_required']): ?>
                                                        <span class="text-danger">*</span>
                                                    <?php endif; ?>
                                                </label>
                                                
                                                <?php if ($field['field_type'] === 'textarea'): ?>
                                                    <textarea class="form-control" id="custom_<?php echo $field['id']; ?>" name="custom_<?php echo $field['id']; ?>" <?php echo $field['is_required'] ? 'required' : ''; ?>></textarea>
                                                <?php elseif ($field['field_type'] === 'select' && !empty($field['field_options'])): ?>
                                                    <?php $options = explode(',', $field['field_options']); ?>
                                                    <select class="form-control" id="custom_<?php echo $field['id']; ?>" name="custom_<?php echo $field['id']; ?>" <?php echo $field['is_required'] ? 'required' : ''; ?>>
                                                        <option value="">Select</option>
                                                        <?php foreach ($options as $option): ?>
                                                            <option value="<?php echo trim($option); ?>"><?php echo trim($option); ?></option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                <?php elseif ($field['field_type'] === 'file'): ?>
                                                    <input type="file" class="form-control" id="custom_<?php echo $field['id']; ?>" name="custom_<?php echo $field['id']; ?>" 
                                                           accept="<?php echo '.' . str_replace(',', ',.', $field['allowed_file_types']); ?>"
                                                           <?php echo $field['is_required'] ? 'required' : ''; ?>>
                                                    <small class="form-text text-muted">
                                                        Max size: <?php echo $field['max_file_size']; ?>KB. 
                                                        Allowed types: <?php echo $field['allowed_file_types']; ?>
                                                    </small>
                                                <?php else: ?>
                                                    <input type="<?php echo $field['field_type']; ?>" class="form-control" id="custom_<?php echo $field['id']; ?>" name="custom_<?php echo $field['id']; ?>" <?php echo $field['is_required'] ? 'required' : ''; ?>>
                                                <?php endif; ?>
                                            </div>
                                        <?php endforeach; ?>
                                        
                                        <div class="form-check mb-3">
                                            <input type="checkbox" class="form-check-input" id="terms_agree" required>
                                            <label class="form-check-label" for="terms_agree">
                                                I agree to the terms and conditions and have reviewed the EMI details
                                            </label>
                                        </div>
                                        
                                        <button type="submit" name="submit_application" class="btn btn-primary" <?php echo ($principal_amount <= 0 || $tenure_months <= 0 || $interest_rate <= 0) ? 'disabled' : ''; ?>>Submit Application</button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="alert alert-info">
                        Please select a loan type to see the application form and EMI calculator.
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
function calculateEMI() {
    document.getElementById('emiCalculatorForm').submit();
}

// Enable submit button only if EMI is calculated with valid interest rate
document.addEventListener('DOMContentLoaded', function() {
    const principal = parseFloat(document.getElementById('principal_amount').value);
    const tenure = parseInt(document.getElementById('tenure_months').value);
    const referralCode = document.getElementById('referral_code').value;
    const submitBtn = document.querySelector('button[name="submit_application"]');
    
    // Check if we have a valid interest rate (not 0)
    const interestRateElement = document.querySelector('.form-control[style*="background-color: #f8f9fa"]');
    const hasValidInterestRate = interestRateElement && !interestRateElement.textContent.includes('No matching loan slab');
    
    if (principal > 0 && tenure > 0 && hasValidInterestRate && referralCode.trim() !== '') {
        submitBtn.disabled = false;
    } else {
        submitBtn.disabled = true;
    }
});

// Validate referral code as user types
document.getElementById('referral_code').addEventListener('input', function() {
    const principal = parseFloat(document.getElementById('principal_amount').value);
    const tenure = parseInt(document.getElementById('tenure_months').value);
    const referralCode = this.value;
    const submitBtn = document.querySelector('button[name="submit_application"]');
    
    // Check if we have a valid interest rate (not 0)
    const interestRateElement = document.querySelector('.form-control[style*="background-color: #f8f9fa"]');
    const hasValidInterestRate = interestRateElement && !interestRateElement.textContent.includes('No matching loan slab');
    
    if (principal > 0 && tenure > 0 && hasValidInterestRate && referralCode.trim() !== '') {
        submitBtn.disabled = false;
    } else {
        submitBtn.disabled = true;
    }
});
</script>

<?php include 'includes/footer.php'; ?>