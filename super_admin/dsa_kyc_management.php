<?php
require_once '../config/database.php';
require_once '../includes/auth.php';
redirectIfNotSuperAdmin();

// Handle KYC approval/rejection
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['approve_kyc'])) {
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

// Get all DSAs with their KYC status and custom field values
$stmt = $pdo->prepare("
    SELECT u.*, dd.kyc_status, dd.kyc_approved_at, a.username as approved_by 
    FROM users u 
    LEFT JOIN dsa_details dd ON u.id = dd.user_id 
    LEFT JOIN users a ON dd.kyc_approved_by = a.id 
    WHERE u.role = 'dsa' AND u.status != 'inactive'
    ORDER BY dd.kyc_status, u.created_at DESC
");
$stmt->execute();
$dsas = $stmt->fetchAll();

// Get custom fields for DSA KYC form
$stmt = $pdo->prepare("SELECT * FROM custom_fields WHERE form_type = 'dsa_kyc' ORDER BY display_order");
$stmt->execute();
$kyc_fields = $stmt->fetchAll();
?>

<?php include '../includes/header.php'; ?>
<div class="row">
    <div class="col-md-12">
        <h2>DSA KYC Management</h2>
        
        <?php if (isset($success)): ?>
            <div class="alert alert-success"><?php echo $success; ?></div>
        <?php endif; ?>
        <?php if (isset($error)): ?>
            <div class="alert alert-danger"><?php echo $error; ?></div>
        <?php endif; ?>
        
        <div class="card mt-4">
            <div class="card-header">
                <h4>DSA KYC Verification</h4>
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
                                <th>Submitted Data</th>
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
                                    <td>
                                        <button class="btn btn-sm btn-info" data-bs-toggle="modal" data-bs-target="#viewKycModal<?php echo $dsa['id']; ?>">
                                            View KYC Details
                                        </button>
                                    </td>
                                    <td>
                                        <?php if ($dsa['kyc_status'] === 'pending'): ?>
                                            <button class="btn btn-sm btn-success" data-bs-toggle="modal" data-bs-target="#approveKycModal<?php echo $dsa['id']; ?>">Approve KYC</button>
                                            <button class="btn btn-sm btn-danger" data-bs-toggle="modal" data-bs-target="#rejectKycModal<?php echo $dsa['id']; ?>">Reject KYC</button>
                                        <?php else: ?>
                                            <span class="text-muted">Processed</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                
                                <!-- View KYC Details Modal -->
                                <div class="modal fade" id="viewKycModal<?php echo $dsa['id']; ?>" tabindex="-1">
                                    <div class="modal-dialog modal-lg">
                                        <div class="modal-content">
                                            <div class="modal-header">
                                                <h5 class="modal-title">KYC Details for <?php echo $dsa['username']; ?></h5>
                                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                            </div>
                                            <div class="modal-body">
                                                <?php
                                                // Get custom field values for this DSA
                                                $stmt = $pdo->prepare("
                                                    SELECT cf.field_name, cfv.value, uf.file_name, uf.file_path 
                                                    FROM custom_fields cf 
                                                    LEFT JOIN custom_field_values cfv ON cf.id = cfv.field_id AND cfv.user_id = ?
                                                    LEFT JOIN uploaded_files uf ON cf.id = uf.field_id AND uf.user_id = ?
                                                    WHERE cf.form_type = 'dsa_kyc'
                                                    ORDER BY cf.display_order
                                                ");
                                                $stmt->execute([$dsa['id'], $dsa['id']]);
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
                                                    <p>No KYC data submitted yet.</p>
                                                <?php endif; ?>
                                            </div>
                                            <div class="modal-footer">
                                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
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