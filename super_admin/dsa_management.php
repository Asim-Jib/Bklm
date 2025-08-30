<?php
require_once '../config/database.php';
require_once '../includes/auth.php';
redirectIfNotSuperAdmin();

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_dsa'])) {
        // Add new DSA
        $username = $_POST['username'];
        $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
        
        // First check if username already exists
        $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ?");
        $stmt->execute([$username]);
        $existing_user = $stmt->fetch();
        
        if ($existing_user) {
            $error = "Username already exists. Please choose a different username.";
        } else {
            try {
                $pdo->beginTransaction();
                
                // Create user with pending status
                $stmt = $pdo->prepare("INSERT INTO users (username, password, role, status) VALUES (?, ?, 'dsa', 'pending')");
                $stmt->execute([$username, $password]);
                $user_id = $pdo->lastInsertId();
                
                // Add to dsa_details with pending KYC
                $stmt = $pdo->prepare("INSERT INTO dsa_details (user_id, kyc_status) VALUES (?, 'pending')");
                $stmt->execute([$user_id]);
                
                // Handle custom fields
                $stmt = $pdo->prepare("SELECT * FROM custom_fields WHERE form_type = 'dsa_add'");
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
                $success = "DSA added successfully! Status: Pending KYC Verification";
            } catch (Exception $e) {
                $pdo->rollBack();
                $error = "Error adding DSA: " . $e->getMessage();
            }
        }
    } elseif (isset($_POST['delete_dsa'])) {
        // Delete DSA
        $user_id = $_POST['user_id'];
        $stmt = $pdo->prepare("UPDATE users SET status = 'inactive' WHERE id = ?");
        $stmt->execute([$user_id]);
        $success = "DSA deleted successfully!";
    } elseif (isset($_POST['approve_kyc'])) {
        // Approve DSA KYC
        $user_id = $_POST['user_id'];
        
        $pdo->beginTransaction();
        try {
            // Update KYC status
            $stmt = $pdo->prepare("UPDATE dsa_details SET kyc_status = 'approved', kyc_approved_by = ?, kyc_approved_at = NOW() WHERE user_id = ?");
            $stmt->execute([$_SESSION['user_id'], $user_id]);
            
            // Activate user
            $stmt = $pdo->prepare("UPDATE users SET status = 'active' WHERE id = ?");
            $stmt->execute([$user_id]);
            
            $pdo->commit();
            $success = "DSA KYC approved successfully!";
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = "Error approving KYC: " . $e->getMessage();
        }
    } elseif (isset($_POST['reject_kyc'])) {
        // Reject DSA KYC
        $user_id = $_POST['user_id'];
        $reject_reason = $_POST['reject_reason'] ?? 'KYC verification failed';
        
        $pdo->beginTransaction();
        try {
            // Update KYC status
            $stmt = $pdo->prepare("UPDATE dsa_details SET kyc_status = 'rejected', kyc_approved_by = ?, kyc_approved_at = NOW() WHERE user_id = ?");
            $stmt->execute([$_SESSION['user_id'], $user_id]);
            
            $pdo->commit();
            $success = "DSA KYC rejected successfully!";
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = "Error rejecting KYC: " . $e->getMessage();
        }
    }
}

// Get all DSAs with their KYC status
$stmt = $pdo->prepare("
    SELECT u.*, dd.kyc_status, dd.kyc_approved_at, a.username as approved_by 
    FROM users u 
    LEFT JOIN dsa_details dd ON u.id = dd.user_id 
    LEFT JOIN users a ON dd.kyc_approved_by = a.id 
    WHERE u.role = 'dsa' AND u.status != 'inactive'
");
$stmt->execute();
$dsas = $stmt->fetchAll();

// Get custom fields for DSA add form
$stmt = $pdo->prepare("SELECT * FROM custom_fields WHERE form_type = 'dsa_add' ORDER BY display_order");
$stmt->execute();
$custom_fields = $stmt->fetchAll();
?>

<?php include '../includes/header.php'; ?>
<div class="row">
    <div class="col-md-12">
        <h2>DSA Management</h2>
        
        <?php if (isset($success)): ?>
            <div class="alert alert-success"><?php echo $success; ?></div>
        <?php endif; ?>
        <?php if (isset($error)): ?>
            <div class="alert alert-danger"><?php echo $error; ?></div>
        <?php endif; ?>
        
        <div class="card mt-4">
            <div class="card-header">
                <h4>Add New DSA</h4>
            </div>
            <div class="card-body">
                <form method="POST" enctype="multipart/form-data">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="username" class="form-label">Username</label>
                                <input type="text" class="form-control" id="username" name="username" required>
                                <small class="form-text text-muted">Choose a unique username that doesn't already exist in the system.</small>
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
                    
                    <button type="submit" name="add_dsa" class="btn btn-primary">Add DSA</button>
                </form>
            </div>
        </div>
        
        <div class="card mt-4">
            <div class="card-header">
                <h4>DSA List</h4>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-striped">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Username</th>
                                <th>Status</th>
                                <th>KYC Status</th>
                                <th>Approved By</th>
                                <th>Approved At</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($dsas as $dsa): ?>
                                <tr>
                                    <td><?php echo $dsa['id']; ?></td>
                                    <td><?php echo $dsa['username']; ?></td>
                                    <td>
                                        <span class="badge bg-<?php echo $dsa['status'] === 'active' ? 'success' : 'warning'; ?>">
                                            <?php echo ucfirst($dsa['status']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="badge bg-<?php 
                                            echo $dsa['kyc_status'] === 'approved' ? 'success' : 
                                                ($dsa['kyc_status'] === 'rejected' ? 'danger' : 'warning'); 
                                        ?>">
                                            <?php echo ucfirst($dsa['kyc_status'] ?? 'pending'); ?>
                                        </span>
                                    </td>
                                    <td><?php echo $dsa['approved_by'] ?? 'N/A'; ?></td>
                                    <td><?php echo $dsa['kyc_approved_at'] ? date('d M Y, h:i A', strtotime($dsa['kyc_approved_at'])) : 'N/A'; ?></td>
                                    <td>
                                        <?php if ($dsa['kyc_status'] === 'pending'): ?>
                                            <button class="btn btn-sm btn-success" data-bs-toggle="modal" data-bs-target="#approveKycModal<?php echo $dsa['id']; ?>">Approve KYC</button>
                                            <button class="btn btn-sm btn-danger" data-bs-toggle="modal" data-bs-target="#rejectKycModal<?php echo $dsa['id']; ?>">Reject KYC</button>
                                        <?php endif; ?>
                                        <form method="POST" style="display:inline;">
                                            <input type="hidden" name="user_id" value="<?php echo $dsa['id']; ?>">
                                            <button type="submit" name="delete_dsa" class="btn btn-sm btn-danger" onclick="return confirm('Are you sure?')">Delete</button>
                                        </form>
                                    </td>
                                </tr>
                                
                                <!-- Approve KYC Modal -->
                                <div class="modal fade" id="approveKycModal<?php echo $dsa['id']; ?>" tabindex="-1">
                                    <div class="modal-dialog">
                                        <div class="modal-content">
                                            <div class="modal-header">
                                                <h5 class="modal-title">Approve KYC for <?php echo $dsa['username']; ?></h5>
                                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                            </div>
                                            <form method="POST">
                                                <div class="modal-body">
                                                    <input type="hidden" name="user_id" value="<?php echo $dsa['id']; ?>">
                                                    <p>Are you sure you want to approve KYC for this DSA? This will activate their account.</p>
                                                </div>
                                                <div class="modal-footer">
                                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                    <button type="submit" name="approve_kyc" class="btn btn-success">Approve KYC</button>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Reject KYC Modal -->
                                <div class="modal fade" id="rejectKycModal<?php echo $dsa['id']; ?>" tabindex="-1">
                                    <div class="modal-dialog">
                                        <div class="modal-content">
                                            <div class="modal-header">
                                                <h5 class="modal-title">Reject KYC for <?php echo $dsa['username']; ?></h5>
                                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                            </div>
                                            <form method="POST">
                                                <div class="modal-body">
                                                    <input type="hidden" name="user_id" value="<?php echo $dsa['id']; ?>">
                                                    <div class="mb-3">
                                                        <label for="reject_reason<?php echo $dsa['id']; ?>" class="form-label">Reason for rejection</label>
                                                        <textarea class="form-control" id="reject_reason<?php echo $dsa['id']; ?>" name="reject_reason" rows="3"></textarea>
                                                    </div>
                                                </div>
                                                <div class="modal-footer">
                                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                    <button type="submit" name="reject_kyc" class="btn btn-danger">Reject KYC</button>
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