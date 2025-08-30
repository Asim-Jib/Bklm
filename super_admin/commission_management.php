<?php
require_once '../config/database.php';
require_once '../includes/auth.php';

// Initialize database connection
if (!isset($pdo)) {
    try {
        $pdo = new PDO("mysql:host=localhost;dbname=bklm_db", "root", "");
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    } catch (PDOException $e) {
        die("Database connection failed: " . $e->getMessage());
    }
}

redirectIfNotSuperAdmin();

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['approve_commission'])) {
        $commission_id = $_POST['commission_id'];
        
        try {
            $pdo->beginTransaction();
            
            // Update commission status to approved
            $stmt = $pdo->prepare("UPDATE commissions SET status = 'approved', approved_by = ?, approved_at = NOW() WHERE id = ?");
            $stmt->execute([$_SESSION['user_id'], $commission_id]);
            
            // Get commission details to update user balance
            $stmt = $pdo->prepare("SELECT user_id, commission_amount FROM commissions WHERE id = ?");
            $stmt->execute([$commission_id]);
            $commission = $stmt->fetch();
            
            if ($commission) {
                // Update user balance
                $stmt = $pdo->prepare("UPDATE users SET balance = COALESCE(balance, 0) + ? WHERE id = ?");
                $stmt->execute([$commission['commission_amount'], $commission['user_id']]);
                
                // Create a transaction record
                try {
                    $stmt = $pdo->prepare("INSERT INTO transactions (user_id, amount, type, description, reference_id) VALUES (?, ?, 'commission', 'Commission approved', ?)");
                    $stmt->execute([$commission['user_id'], $commission['commission_amount'], $commission_id]);
                } catch (Exception $e) {
                    // If transactions table doesn't exist, just continue
                }
            }
            
            $pdo->commit();
            $success = "Commission approved successfully and balance updated!";
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = "Error approving commission: " . $e->getMessage();
        }
    } elseif (isset($_POST['reject_commission'])) {
        $commission_id = $_POST['commission_id'];
        $rejection_reason = $_POST['rejection_reason'] ?? '';
        
        try {
            $stmt = $pdo->prepare("UPDATE commissions SET status = 'rejected', rejection_reason = ?, approved_by = ?, approved_at = NOW() WHERE id = ?");
            $stmt->execute([$rejection_reason, $_SESSION['user_id'], $commission_id]);
            $success = "Commission rejected successfully!";
        } catch (Exception $e) {
            $error = "Error rejecting commission: " . $e->getMessage();
        }
    } elseif (isset($_POST['mark_paid'])) {
        $commission_id = $_POST['commission_id'];
        
        try {
            $stmt = $pdo->prepare("UPDATE commissions SET status = 'paid', paid_at = NOW() WHERE id = ?");
            $stmt->execute([$commission_id]);
            $success = "Commission marked as paid successfully!";
        } catch (Exception $e) {
            $error = "Error marking commission as paid: " . $e->getMessage();
        }
    }
}

// Get all commissions with filters
$status_filter = $_GET['status'] ?? 'pending';
$role_filter = $_GET['role'] ?? '';
$page = max(1, $_GET['page'] ?? 1);
$limit = 20;
$offset = ($page - 1) * $limit;

$where_conditions = [];
$params = [];

if ($status_filter && $status_filter !== 'all') {
    $where_conditions[] = "c.status = ?";
    $params[] = $status_filter;
}

if ($role_filter) {
    $where_conditions[] = "c.role = ?";
    $params[] = $role_filter;
}

$where_sql = $where_conditions ? "WHERE " . implode(" AND ", $where_conditions) : "";

// Get commissions
try {
    $stmt = $pdo->prepare("
        SELECT c.*, u.username, u.role as user_role,
               a.username as approved_by_name
        FROM commissions c
        LEFT JOIN users u ON c.user_id = u.id
        LEFT JOIN users a ON c.approved_by = a.id
        $where_sql
        ORDER BY c.created_at DESC
        LIMIT $limit OFFSET $offset
    ");
    $stmt->execute($params);
    $commissions = $stmt->fetchAll();
} catch (Exception $e) {
    $commissions = [];
    $error = "Error loading commissions: " . $e->getMessage();
}

// Get total count for pagination
try {
    $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM commissions c $where_sql");
    $stmt->execute($params);
    $total_result = $stmt->fetch();
    $total_commissions = $total_result ? $total_result['total'] : 0;
    $total_pages = ceil($total_commissions / $limit);
} catch (Exception $e) {
    $total_commissions = 0;
    $total_pages = 1;
}

// Get summary statistics
try {
    $stmt = $pdo->prepare("
        SELECT 
            status,
            COUNT(*) as count,
            COALESCE(SUM(commission_amount), 0) as total_amount
        FROM commissions
        GROUP BY status
        ORDER BY status
    ");
    $stmt->execute();
    $stats = $stmt->fetchAll();
} catch (Exception $e) {
    $stats = [];
}
?>

<?php include '../includes/header.php'; ?>
<div class="row">
    <div class="col-md-12">
        <h2>Commission Management</h2>
        
        <?php if (isset($success)): ?>
            <div class="alert alert-success"><?php echo $success; ?></div>
        <?php endif; ?>
        <?php if (isset($error)): ?>
            <div class="alert alert-danger"><?php echo $error; ?></div>
        <?php endif; ?>

        <!-- Statistics Cards -->
        <div class="row mb-4">
            <?php foreach ($stats as $stat): ?>
                <div class="col-md-3">
                    <div class="card text-white bg-<?php 
                        switch($stat['status']) {
                            case 'approved': echo 'success'; break;
                            case 'pending': echo 'warning'; break;
                            case 'rejected': echo 'danger'; break;
                            case 'paid': echo 'info'; break;
                            default: echo 'secondary';
                        }
                    ?> mb-3">
                        <div class="card-body">
                            <h5 class="card-title"><?php echo ucfirst($stat['status']); ?></h5>
                            <p class="card-text">
                                Count: <?php echo $stat['count']; ?><br>
                                Amount: ₹<?php echo number_format($stat['total_amount'], 2); ?>
                            </p>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <!-- Filter Tabs -->
        <ul class="nav nav-tabs mb-3">
            <li class="nav-item">
                <a class="nav-link <?= $status_filter === 'pending' ? 'active' : '' ?>" href="?status=pending">Pending</a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?= $status_filter === 'approved' ? 'active' : '' ?>" href="?status=approved">Approved</a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?= $status_filter === 'paid' ? 'active' : '' ?>" href="?status=paid">Paid</a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?= $status_filter === 'rejected' ? 'active' : '' ?>" href="?status=rejected">Rejected</a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?= $status_filter === 'all' ? 'active' : '' ?>" href="?status=all">All</a>
            </li>
        </ul>

        <div class="card">
            <div class="card-header">
                <h4>Commission Approvals</h4>
                <form method="GET" class="row g-3">
                    <div class="col-md-3">
                        <select class="form-select" name="role" onchange="this.form.submit()">
                            <option value="">All Roles</option>
                            <option value="rm" <?= $role_filter === 'rm' ? 'selected' : '' ?>>RM</option>
                            <option value="dsa" <?= $role_filter === 'dsa' ? 'selected' : '' ?>>DSA</option>
                            <option value="connector" <?= $role_filter === 'connector' ? 'selected' : '' ?>>Connector</option>
                        </select>
                    </div>
                </form>
            </div>
            <div class="card-body">
                <?php if (empty($commissions)): ?>
                    <div class="alert alert-info">No commissions found.</div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-striped">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>User</th>
                                <th>Role</th>
                                <th>Amount</th>
                                <th>Rate</th>
                                <th>Status</th>
                                <th>Created</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($commissions as $commission): ?>
                                <tr>
                                    <td><?php echo $commission['id'] ?? 'N/A'; ?></td>
                                    <td>
                                        <?php 
                                        if (isset($commission['username']) && !empty($commission['username'])) {
                                            echo $commission['username'];
                                        } else {
                                            // Try to get username from users table
                                            try {
                                                $user_stmt = $pdo->prepare("SELECT username FROM users WHERE id = ?");
                                                $user_stmt->execute([$commission['user_id']]);
                                                $user = $user_stmt->fetch();
                                                echo $user ? $user['username'] : 'User #' . $commission['user_id'];
                                            } catch (Exception $e) {
                                                echo 'User #' . ($commission['user_id'] ?? 'N/A');
                                            }
                                        }
                                        ?>
                                    </td>
                                    <td><?php echo strtoupper($commission['role'] ?? 'N/A'); ?></td>
                                    <td>₹<?php echo number_format($commission['commission_amount'] ?? 0, 2); ?></td>
                                    <td><?php echo $commission['commission_rate'] ?? 0; ?>%</td>
                                    <td>
                                        <span class="badge bg-<?php 
                                            switch ($commission['status'] ?? '') {
                                                case 'approved': echo 'success'; break;
                                                case 'pending': echo 'warning'; break;
                                                case 'rejected': echo 'danger'; break;
                                                case 'paid': echo 'info'; break;
                                                default: echo 'secondary';
                                            }
                                        ?>">
                                            <?php echo ucfirst($commission['status'] ?? 'unknown'); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php 
                                        if (isset($commission['created_at']) && !empty($commission['created_at']) && $commission['created_at'] != '0000-00-00 00:00:00') {
                                            echo date('M d, Y', strtotime($commission['created_at']));
                                        } else {
                                            echo 'N/A';
                                        }
                                        ?>
                                    </td>
                                    <td>
                                        <div class="btn-group">
                                            <?php if (($commission['status'] ?? '') === 'pending'): ?>
                                                <button class="btn btn-sm btn-success" data-bs-toggle="modal" data-bs-target="#approveModal<?php echo $commission['id']; ?>">Approve</button>
                                                <button class="btn btn-sm btn-danger" data-bs-toggle="modal" data-bs-target="#rejectModal<?php echo $commission['id']; ?>">Reject</button>
                                            <?php elseif (($commission['status'] ?? '') === 'approved'): ?>
                                                <button class="btn btn-sm btn-info" data-bs-toggle="modal" data-bs-target="#payModal<?php echo $commission['id']; ?>">Mark Paid</button>
                                            <?php else: ?>
                                                <span class="text-muted">No actions</span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                                
                                <!-- Approve Modal -->
                                <div class="modal fade" id="approveModal<?php echo $commission['id']; ?>" tabindex="-1">
                                    <div class="modal-dialog">
                                        <div class="modal-content">
                                            <div class="modal-header">
                                                <h5 class="modal-title">Approve Commission</h5>
                                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                            </div>
                                            <form method="POST">
                                                <div class="modal-body">
                                                    <input type="hidden" name="commission_id" value="<?php echo $commission['id']; ?>">
                                                    <p>Are you sure you want to approve this commission?</p>
                                                    <p><strong>User:</strong> 
                                                        <?php 
                                                        if (isset($commission['username']) && !empty($commission['username'])) {
                                                            echo $commission['username'];
                                                        } else {
                                                            echo 'User #' . $commission['user_id'];
                                                        }
                                                        ?>
                                                    </p>
                                                    <p><strong>Amount:</strong> ₹<?php echo number_format($commission['commission_amount'] ?? 0, 2); ?></p>
                                                    <div class="alert alert-warning">
                                                        This will add the commission amount to the user's balance.
                                                    </div>
                                                </div>
                                                <div class="modal-footer">
                                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                    <button type="submit" name="approve_commission" class="btn btn-success">Approve</button>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Reject Modal -->
                                <div class="modal fade" id="rejectModal<?php echo $commission['id']; ?>" tabindex="-1">
                                    <div class="modal-dialog">
                                        <div class="modal-content">
                                            <div class="modal-header">
                                                <h5 class="modal-title">Reject Commission</h5>
                                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                            </div>
                                            <form method="POST">
                                                <div class="modal-body">
                                                    <input type="hidden" name="commission_id" value="<?php echo $commission['id']; ?>">
                                                    <p><strong>User:</strong> 
                                                        <?php 
                                                        if (isset($commission['username']) && !empty($commission['username'])) {
                                                            echo $commission['username'];
                                                        } else {
                                                            echo 'User #' . $commission['user_id'];
                                                        }
                                                        ?>
                                                    </p>
                                                    <p><strong>Amount:</strong> ₹<?php echo number_format($commission['commission_amount'] ?? 0, 2); ?></p>
                                                    <div class="mb-3">
                                                        <label class="form-label">Reason for Rejection</label>
                                                        <textarea class="form-control" name="rejection_reason" rows="3" required></textarea>
                                                    </div>
                                                </div>
                                                <div class="modal-footer">
                                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                    <button type="submit" name="reject_commission" class="btn btn-danger">Reject</button>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Pay Modal -->
                                <div class="modal fade" id="payModal<?php echo $commission['id']; ?>" tabindex="-1">
                                    <div class="modal-dialog">
                                        <div class="modal-content">
                                            <div class="modal-header">
                                                <h5 class="modal-title">Mark as Paid</h5>
                                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                            </div>
                                            <form method="POST">
                                                <div class="modal-body">
                                                    <input type="hidden" name="commission_id" value="<?php echo $commission['id']; ?>">
                                                    <p>Are you sure you want to mark this commission as paid?</p>
                                                    <p><strong>User:</strong> 
                                                        <?php 
                                                        if (isset($commission['username']) && !empty($commission['username'])) {
                                                            echo $commission['username'];
                                                        } else {
                                                            echo 'User #' . $commission['user_id'];
                                                        }
                                                        ?>
                                                    </p>
                                                    <p><strong>Amount:</strong> ₹<?php echo number_format($commission['commission_amount'] ?? 0, 2); ?></p>
                                                </div>
                                                <div class="modal-footer">
                                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                    <button type="submit" name="mark_paid" class="btn btn-info">Mark as Paid</button>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                
                <!-- Pagination -->
                <?php if ($total_pages > 1): ?>
                <nav>
                    <ul class="pagination">
                        <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                            <li class="page-item <?php echo $i == $page ? 'active' : ''; ?>">
                                <a class="page-link" href="?page=<?php echo $i; ?>&status=<?php echo $status_filter; ?>&role=<?php echo $role_filter; ?>">
                                    <?php echo $i; ?>
                                </a>
                            </li>
                        <?php endfor; ?>
                    </ul>
                </nav>
                <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php include '../includes/footer.php'; ?>