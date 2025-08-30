<?php
require_once '../config/database.php';
require_once '../includes/auth.php';
redirectIfNotSuperAdmin();

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_loan_type'])) {
        $name = $_POST['name'];
        $description = $_POST['description'] ?? '';
        
        $stmt = $pdo->prepare("INSERT INTO loan_types (name, description) VALUES (?, ?)");
        $stmt->execute([$name, $description]);
        $success = "Loan type added successfully!";
    } elseif (isset($_POST['add_loan_slab'])) {
        $loan_type_id = $_POST['loan_type_id'];
        $min_amount = $_POST['min_amount'];
        $max_amount = $_POST['max_amount'];
        $interest_rate = $_POST['interest_rate'];
        $tenure_min = $_POST['tenure_min'];
        $tenure_max = $_POST['tenure_max'];
        
        $stmt = $pdo->prepare("INSERT INTO loan_slabs (loan_type_id, min_amount, max_amount, interest_rate, tenure_min, tenure_max) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$loan_type_id, $min_amount, $max_amount, $interest_rate, $tenure_min, $tenure_max]);
        $success = "Loan slab added successfully!";
    } elseif (isset($_POST['add_commission'])) {
        $role = $_POST['role'];
        $loan_type_id = $_POST['loan_type_id'];
        $calculation_type = $_POST['calculation_type'];
        $value = $_POST['value'];
        
        // FIXED: Use the new table name commission_rate_settings instead of commissions
        $stmt = $pdo->prepare("INSERT INTO commission_rate_settings (role, loan_type_id, calculation_type, value) VALUES (?, ?, ?, ?)");
        $stmt->execute([$role, $loan_type_id, $calculation_type, $value]);
        $success = "Commission added successfully!";
    }
}

// Get all loan types
$stmt = $pdo->prepare("SELECT * FROM loan_types WHERE status = 'active'");
$stmt->execute();
$loan_types = $stmt->fetchAll();

// Get all loan slabs
$stmt = $pdo->prepare("SELECT ls.*, lt.name as loan_type_name FROM loan_slabs ls JOIN loan_types lt ON ls.loan_type_id = lt.id WHERE ls.status = 'active'");
$stmt->execute();
$loan_slabs = $stmt->fetchAll();

// FIXED: Get all commissions from the new table name
$stmt = $pdo->prepare("SELECT crs.*, lt.name as loan_type_name FROM commission_rate_settings crs JOIN loan_types lt ON crs.loan_type_id = lt.id WHERE crs.status = 'active'");
$stmt->execute();
$commissions = $stmt->fetchAll();
?>

<?php include '../includes/header.php'; ?>
<div class="row">
    <div class="col-md-12">
        <h2>Loan Management</h2>
        
        <?php if (isset($success)): ?>
            <div class="alert alert-success"><?php echo $success; ?></div>
        <?php endif; ?>
        
        <ul class="nav nav-tabs" id="loanTabs" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link active" id="types-tab" data-bs-toggle="tab" data-bs-target="#types" type="button">Loan Types</button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="slabs-tab" data-bs-toggle="tab" data-bs-target="#slabs" type="button">Loan Slabs</button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="commissions-tab" data-bs-toggle="tab" data-bs-target="#commissions" type="button">Commissions</button>
            </li>
        </ul>
        
        <div class="tab-content mt-3" id="loanTabsContent">
            <!-- Loan Types Tab -->
            <div class="tab-pane fade show active" id="types" role="tabpanel">
                <div class="card">
                    <div class="card-header">
                        <h4>Add Loan Type</h4>
                    </div>
                    <div class="card-body">
                        <form method="POST">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="name" class="form-label">Loan Type Name</label>
                                        <input type="text" class="form-control" id="name" name="name" required>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="description" class="form-label">Description</label>
                                        <textarea class="form-control" id="description" name="description"></textarea>
                                    </div>
                                </div>
                            </div>
                            <button type="submit" name="add_loan_type" class="btn btn-primary">Add Loan Type</button>
                        </form>
                    </div>
                </div>
                
                <div class="card mt-4">
                    <div class="card-header">
                        <h4>Loan Types</h4>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-striped">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Name</th>
                                        <th>Description</th>
                                        <th>Created At</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($loan_types as $type): ?>
                                        <tr>
                                            <td><?php echo $type['id']; ?></td>
                                            <td><?php echo $type['name']; ?></td>
                                            <td><?php echo $type['description']; ?></td>
                                            <td><?php echo date('d M Y', strtotime($type['created_at'])); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Loan Slabs Tab -->
            <div class="tab-pane fade" id="slabs" role="tabpanel">
                <div class="card">
                    <div class="card-header">
                        <h4>Add Loan Slab</h4>
                    </div>
                    <div class="card-body">
                        <form method="POST">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="loan_type_id" class="form-label">Loan Type</label>
                                        <select class="form-control" id="loan_type_id" name="loan_type_id" required>
                                            <option value="">Select Loan Type</option>
                                            <?php foreach ($loan_types as $type): ?>
                                                <option value="<?php echo $type['id']; ?>"><?php echo $type['name']; ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="interest_rate" class="form-label">Interest Rate (%)</label>
                                        <input type="number" step="0.01" class="form-control" id="interest_rate" name="interest_rate" required>
                                    </div>
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="min_amount" class="form-label">Minimum Amount</label>
                                        <input type="number" step="0.01" class="form-control" id="min_amount" name="min_amount" required>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="max_amount" class="form-label">Maximum Amount</label>
                                        <input type="number" step="0.01" class="form-control" id='max_amount' name='max_amount' required>
                                    </div>
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="tenure_min" class="form-label">Minimum Tenure (months)</label>
                                        <input type="number" class="form-control" id="tenure_min" name="tenure_min" required>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="tenure_max" class="form-label">Maximum Tenure (months)</label>
                                        <input type="number" class="form-control" id="tenure_max" name="tenure_max" required>
                                    </div>
                                </div>
                            </div>
                            <button type="submit" name="add_loan_slab" class="btn btn-primary">Add Loan Slab</button>
                        </form>
                    </div>
                </div>
                
                <div class="card mt-4">
                    <div class="card-header">
                        <h4>Loan Slabs</h4>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-striped">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Loan Type</th>
                                        <th>Amount Range</th>
                                        <th>Interest Rate</th>
                                        <th>Tenure Range</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($loan_slabs as $slab): ?>
                                        <tr>
                                            <td><?php echo $slab['id']; ?></td>
                                            <td><?php echo $slab['loan_type_name']; ?></td>
                                            <td><?php echo number_format($slab['min_amount'], 2); ?> - <?php echo number_format($slab['max_amount'], 2); ?></td>
                                            <td><?php echo $slab['interest_rate']; ?>%</td>
                                            <td><?php echo $slab['tenure_min']; ?>-<?php echo $slab['tenure_max']; ?> months</td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Commissions Tab -->
            <div class="tab-pane fade" id="commissions" role="tabpanel">
                <div class="card">
                    <div class="card-header">
                        <h4>Add Commission</h4>
                    </div>
                    <div class="card-body">
                        <form method="POST">
                            <div class="row">
                                <div class="col-md-4">
                                    <div class="mb-3">
                                        <label for="role" class="form-label">Role</label>
                                        <select class="form-control" id="role" name="role" required>
                                            <option value="">Select Role</option>
                                            <option value="rm">RM</option>
                                            <option value="dsa">DSA</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="mb-3">
                                        <label for="loan_type_id" class="form-label">Loan Type</label>
                                        <select class="form-control" id="loan_type_id" name="loan_type_id" required>
                                            <option value="">Select Loan Type</option>
                                            <?php foreach ($loan_types as $type): ?>
                                                <option value="<?php echo $type['id']; ?>"><?php echo $type['name']; ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="mb-3">
                                        <label for="calculation_type" class="form-label">Calculation Type</label>
                                        <select class="form-control" id="calculation_type" name="calculation_type" required>
                                            <option value="">Select Type</option>
                                            <option value="percentage">Percentage</option>
                                            <option value="fixed">Fixed Amount</option>
                                        </select>
                                    </div>
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="value" class="form-label">Value</label>
                                        <input type="number" step="0.01" class="form-control" id="value" name="value" required>
                                    </div>
                                </div>
                            </div>
                            <button type="submit" name="add_commission" class="btn btn-primary">Add Commission</button>
                        </form>
                    </div>
                </div>
                
                <div class="card mt-4">
                    <div class="card-header">
                        <h4>Commissions</h4>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-striped">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Role</th>
                                        <th>Loan Type</th>
                                        <th>Calculation Type</th>
                                        <th>Value</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($commissions as $commission): ?>
                                        <tr>
                                            <td><?php echo $commission['id']; ?></td>
                                            <td><?php echo strtoupper($commission['role']); ?></td>
                                            <td><?php echo $commission['loan_type_name']; ?></td>
                                            <td><?php echo ucfirst($commission['calculation_type']); ?></td>
                                            <td>
                                                <?php if ($commission['calculation_type'] === 'percentage'): ?>
                                                    <?php echo $commission['value']; ?>%
                                                <?php else: ?>
                                                    ₹<?php echo number_format($commission['value'], 2); ?>
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
        </div>
    </div>
</div>
<?php include '../includes/footer.php'; ?>