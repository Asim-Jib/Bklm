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
        // Assign DSA to RM
        $rm_id = $_POST['rm_id'];
        $dsa_id = $_POST['dsa_id'];
        
        try {
            $stmt = $pdo->prepare("INSERT INTO rm_dsa_assignments (rm_id, dsa_id, assigned_by) VALUES (?, ?, ?)");
            $stmt->execute([$rm_id, $dsa_id, $_SESSION['user_id']]);
            $success = "DSA assigned to RM successfully!";
        } catch (Exception $e) {
            if ($e->getCode() == 23000) { // Duplicate entry
                $error = "This DSA is already assigned to this RM.";
            } else {
                $error = "Error assigning DSA: " . $e->getMessage();
            }
        }
    } elseif (isset($_POST['remove_assignment'])) {
        // Remove DSA assignment from RM
        $assignment_id = $_POST['assignment_id'];
        $stmt = $pdo->prepare("DELETE FROM rm_dsa_assignments WHERE id = ?");
        $stmt->execute([$assignment_id]);
        $success = "DSA assignment removed successfully!";
    }
}

// Get all RMs
$stmt = $pdo->prepare("
    SELECT u.*, rd.kyc_status
    FROM users u 
    LEFT JOIN rm_details rd ON u.id = rd.user_id 
    WHERE u.role = 'rm' AND u.status != 'inactive'
");
$stmt->execute();
$rms = $stmt->fetchAll();

// Get all DSAs for assignment
$stmt = $pdo->prepare("
    SELECT u.* 
    FROM users u 
    LEFT JOIN dsa_details dd ON u.id = dd.user_id 
    WHERE u.role = 'dsa' AND u.status = 'active' 
    AND dd.kyc_status = 'approved'
");
$stmt->execute();
$dsas = $stmt->fetchAll();

// Get RM assignments
$assignments = [];
foreach ($rms as $rm) {
    $stmt = $pdo->prepare("
        SELECT rda.*, d.username as dsa_name 
        FROM rm_dsa_assignments rda
        LEFT JOIN users d ON rda.dsa_id = d.id
        WHERE rda.rm_id = ? AND rda.status = 'active'
    ");
    $stmt->execute([$rm['id']]);
    $assignments[$rm['id']] = $stmt->fetchAll();
}

// Get custom fields for RM add form
$stmt = $pdo->prepare("SELECT * FROM custom_fields WHERE form_type = 'rm_add' ORDER BY display_order");
$stmt->execute();
$custom_fields = $stmt->fetchAll();
?>

<?php include '../includes/header.php'; ?>
<div class="row">
    <div class="col-md-12">
        <h2>RM Management</h2>
        
        <?php if (isset($success)): ?>
            <div class="alert alert-success"><?php echo $success; ?></div>
        <?php endif; ?>
        <?php if (isset($error)): ?>
            <div class="alert alert-danger"><?php echo $error; ?></div>
        <?php endif; ?>
        
        <div class="card mt-4">
            <div class="card-header">
                <h4>Add New RM</h4>
            </div>
            <div class="card-body">
                <form method="POST" enctype="multipart/form-data">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="username" class="form-label">Username</label>
                                <input type="text" class="form-control" id="username" name="username" required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="password" class="form-label">Password</label>
                                <input type="password" class="form-control" id="password" name="password" required>
                            </div>
                        </div>
                    </div>
                    
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
                    
                    <button type="submit" name="add_rm" class="btn btn-primary">Add RM</button>
                </form>
            </div>
        </div>
        
        <div class="card mt-4">
            <div class="card-header">
                <h4>RM List</h4>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-striped">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Username</th>
                                <th>KYC Status</th>
                                <th>Assigned DSAs</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($rms as $rm): ?>
                                <tr>
                                    <td><?php echo $rm['id']; ?></td>
                                    <td><?php echo $rm['username']; ?></td>
                                    <td>
                                        <span class="badge bg-<?php 
                                            echo $rm['kyc_status'] === 'approved' ? 'success' : 
                                                ($rm['kyc_status'] === 'rejected' ? 'danger' : 'warning'); 
                                        ?>">
                                            <?php echo ucfirst($rm['kyc_status']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if (!empty($assignments[$rm['id']])): ?>
                                            <ul class="list-unstyled mb-0">
                                                <?php foreach ($assignments[$rm['id']] as $assignment): ?>
                                                    <li>
                                                        <?php echo $assignment['dsa_name']; ?>
                                                        <form method="POST" style="display:inline;">
                                                            <input type="hidden" name="assignment_id" value="<?php echo $assignment['id']; ?>">
                                                            <button type="submit" name="remove_assignment" class="btn btn-sm btn-link text-danger" onclick="return confirm('Remove this DSA assignment?')">
                                                                <i class="fas fa-times"></i>
                                                            </button>
                                                        </form>
                                                    </li>
                                                <?php endforeach; ?>
                                            </ul>
                                        <?php else: ?>
                                            <span class="text-muted">No DSAs assigned</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <button class="btn btn-sm btn-info" data-bs-toggle="modal" data-bs-target="#assignModal<?php echo $rm['id']; ?>">Assign DSA</button>
                                        <form method="POST" style="display:inline;">
                                            <input type="hidden" name="user_id" value="<?php echo $rm['id']; ?>">
                                            <button type="submit" name="delete_rm" class="btn btn-sm btn-danger" onclick="return confirm('Are you sure?')">Delete</button>
                                        </form>
                                    </td>
                                </tr>
                                
                                <!-- Assign DSA Modal -->
                                <div class="modal fade" id="assignModal<?php echo $rm['id']; ?>" tabindex="-1">
                                    <div class="modal-dialog">
                                        <div class="modal-content">
                                            <div class="modal-header">
                                                <h5 class="modal-title">Assign DSA to <?php echo $rm['username']; ?></h5>
                                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                            </div>
                                            <form method="POST">
                                                <div class="modal-body">
                                                    <input type="hidden" name="rm_id" value="<?php echo $rm['id']; ?>">
                                                    <div class="mb-3">
                                                        <label for="dsa_id" class="form-label">Select DSA</label>
                                                        <select class="form-control" id="dsa_id" name="dsa_id" required>
                                                            <option value="">Select DSA</option>
                                                            <?php if (count($dsas) > 0): ?>
                                                                <?php foreach ($dsas as $dsa): ?>
                                                                    <option value="<?php echo $dsa['id']; ?>"><?php echo $dsa['username']; ?></option>
                                                                <?php endforeach; ?>
                                                            <?php else: ?>
                                                                <option value="" disabled>No approved DSAs available</option>
                                                            <?php endif; ?>
                                                        </select>
                                                        <?php if (count($dsas) === 0): ?>
                                                            <small class="form-text text-muted">You need to approve at least one DSA's KYC before you can assign RMs.</small>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                                <div class="modal-footer">
                                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                    <button type="submit" name="assign_dsa" class="btn btn-primary" <?php echo (count($dsas) === 0) ? 'disabled' : ''; ?>>Assign</button>
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
</div>
<?php include '../includes/footer.php'; ?>