<?php
require_once '../config/database.php';
require_once '../includes/auth.php';
redirectIfNotSuperAdmin();

$form_types = [
    'rm_add' => 'RM Add Form',
    'rm_application' => 'RM Application Form',
    'rm_kyc' => 'RM KYC Form',
    'dsa_add' => 'DSA Add Form',
    'dsa_application' => 'DSA Application Form',
    'dsa_kyc' => 'DSA KYC Form',
    'loan_type' => 'Loan Type Form',
    'loan_application' => 'Loan Application Form'
];

$field_types = [
    'text' => 'Text',
    'number' => 'Number',
    'email' => 'Email',
    'date' => 'Date',
    'textarea' => 'Text Area',
    'select' => 'Dropdown',
    'file' => 'File Upload'
];

// Get all loan types for dropdown
$stmt = $pdo->prepare("SELECT * FROM loan_types WHERE status = 'active' ORDER BY name");
$stmt->execute();
$loan_types = $stmt->fetchAll();

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_field'])) {
        $form_type = $_POST['form_type'];
        $field_name = $_POST['field_name'];
        $field_type = $_POST['field_type'];
        $field_options = $_POST['field_options'] ?? '';
        $is_required = isset($_POST['is_required']) ? 1 : 0;
        $display_order = $_POST['display_order'] ?? 0;
        $max_file_size = $_POST['max_file_size'] ?? 2048;
        $allowed_file_types = $_POST['allowed_file_types'] ?? 'jpg,jpeg,png,pdf,doc,docx';
        $loan_type_id = ($form_type === 'loan_application' && !empty($_POST['loan_type_id'])) ? $_POST['loan_type_id'] : NULL;
        
        $stmt = $pdo->prepare("INSERT INTO custom_fields (form_type, loan_type_id, field_name, field_type, field_options, is_required, display_order, max_file_size, allowed_file_types) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$form_type, $loan_type_id, $field_name, $field_type, $field_options, $is_required, $display_order, $max_file_size, $allowed_file_types]);
        $success = "Field added successfully!";
    } elseif (isset($_POST['delete_field'])) {
        $field_id = $_POST['field_id'];
        $stmt = $pdo->prepare("DELETE FROM custom_fields WHERE id = ?");
        $stmt->execute([$field_id]);
        $success = "Field deleted successfully!";
    }
}

// Get all custom fields
$stmt = $pdo->prepare("
    SELECT cf.*, lt.name as loan_type_name 
    FROM custom_fields cf 
    LEFT JOIN loan_types lt ON cf.loan_type_id = lt.id 
    ORDER BY cf.form_type, cf.loan_type_id, cf.display_order
");
$stmt->execute();
$fields = $stmt->fetchAll();
?>

<?php include '../includes/header.php'; ?>
<div class="row">
    <div class="col-md-12">
        <h2>Custom Fields Management</h2>
        
        <?php if (isset($success)): ?>
            <div class="alert alert-success"><?php echo $success; ?></div>
        <?php endif; ?>
        
        <div class="card mt-4">
            <div class="card-header">
                <h4>Add New Field</h4>
            </div>
            <div class="card-body">
                <form method="POST">
                    <div class="row">
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label for="form_type" class="form-label">Form Type</label>
                                <select class="form-control" id="form_type" name="form_type" required>
                                    <option value="">Select Form Type</option>
                                    <?php foreach ($form_types as $key => $value): ?>
                                        <option value="<?php echo $key; ?>"><?php echo $value; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-3" id="loan_type_container" style="display: none;">
                                <label for="loan_type_id" class="form-label">Loan Type</label>
                                <select class="form-control" id="loan_type_id" name="loan_type_id">
                                    <option value="">All Loan Types (Generic)</option>
                                    <?php foreach ($loan_types as $type): ?>
                                        <option value="<?php echo $type['id']; ?>"><?php echo $type['name']; ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <small class="form-text text-muted">Select specific loan type or leave blank for all types</small>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label for="field_name" class="form-label">Field Name</label>
                                <input type="text" class="form-control" id="field_name" name="field_name" required>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label for="field_type" class="form-label">Field Type</label>
                                <select class="form-control" id="field_type" name="field_type" required>
                                    <option value="">Select Field Type</option>
                                    <?php foreach ($field_types as $key => $value): ?>
                                        <option value="<?php echo $key; ?>"><?php echo $value; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-8">
                            <div class="mb-3" id="options_container" style="display:none;">
                                <label for="field_options" class="form-label">Options (comma separated)</label>
                                <input type="text" class="form-control" id="field_options" name="field_options" placeholder="Option 1, Option 2, Option 3">
                            </div>
                            <div class="mb-3" id="file_options_container" style="display:none;">
                                <div class="row">
                                    <div class="col-md-6">
                                        <label for="max_file_size" class="form-label">Max File Size (KB)</label>
                                        <input type="number" class="form-control" id="max_file_size" name="max_file_size" value="2048">
                                    </div>
                                    <div class="col-md-6">
                                        <label for="allowed_file_types" class="form-label">Allowed File Types</label>
                                        <input type="text" class="form-control" id="allowed_file_types" name="allowed_file_types" value="jpg,jpeg,png,pdf,doc,docx">
                                        <small class="form-text text-muted">Comma separated (jpg,png,pdf,etc)</small>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-2">
                            <div class="mb-3">
                                <label for="display_order" class="form-label">Display Order</label>
                                <input type="number" class="form-control" id="display_order" name="display_order" value="0">
                            </div>
                        </div>
                        <div class="col-md-2">
                            <div class="mb-3 form-check" style="margin-top: 2.5rem;">
                                <input type="checkbox" class="form-check-input" id="is_required" name="is_required">
                                <label class="form-check-label" for="is_required">Required</label>
                            </div>
                        </div>
                    </div>
                    
                    <button type="submit" name="add_field" class="btn btn-primary">Add Field</button>
                </form>
            </div>
        </div>
        
        <div class="card mt-4">
            <div class="card-header">
                <h4>Existing Fields</h4>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-striped">
                        <thead>
                            <tr>
                                <th>Form Type</th>
                                <th>Loan Type</th>
                                <th>Field Name</th>
                                <th>Field Type</th>
                                <th>Required</th>
                                <th>Order</th>
                                <th>File Options</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($fields as $field): ?>
                                <tr>
                                    <td><?php echo $form_types[$field['form_type']] ?? $field['form_type']; ?></td>
                                    <td><?php echo $field['loan_type_name'] ?? 'All Types'; ?></td>
                                    <td><?php echo $field['field_name']; ?></td>
                                    <td><?php echo $field_types[$field['field_type']] ?? $field['field_type']; ?></td>
                                    <td><?php echo $field['is_required'] ? 'Yes' : 'No'; ?></td>
                                    <td><?php echo $field['display_order']; ?></td>
                                    <td>
                                        <?php if ($field['field_type'] === 'file'): ?>
                                            Size: <?php echo $field['max_file_size']; ?>KB<br>
                                            Types: <?php echo $field['allowed_file_types']; ?>
                                        <?php else: ?>
                                            -
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <form method="POST">
                                            <input type="hidden" name="field_id" value="<?php echo $field['id']; ?>">
                                            <button type="submit" name="delete_field" class="btn btn-sm btn-danger" onclick="return confirm('Are you sure?')">Delete</button>
                                        </form>
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

<script>
// Show options field only when select type is chosen
document.getElementById('field_type').addEventListener('change', function() {
    const optionsContainer = document.getElementById('options_container');
    const fileOptionsContainer = document.getElementById('file_options_container');
    
    if (this.value === 'select') {
        optionsContainer.style.display = 'block';
        fileOptionsContainer.style.display = 'none';
    } else if (this.value === 'file') {
        optionsContainer.style.display = 'none';
        fileOptionsContainer.style.display = 'block';
    } else {
        optionsContainer.style.display = 'none';
        fileOptionsContainer.style.display = 'none';
    }
});

// Show loan type dropdown only for loan application form
document.getElementById('form_type').addEventListener('change', function() {
    const loanTypeContainer = document.getElementById('loan_type_container');
    loanTypeContainer.style.display = (this.value === 'loan_application') ? 'block' : 'none';
});
</script>
<?php include '../includes/footer.php'; ?>