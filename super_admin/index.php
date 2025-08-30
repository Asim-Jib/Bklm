<?php
require_once '../config/database.php';
require_once '../includes/auth.php';
redirectIfNotSuperAdmin();
?>

<?php include '../includes/header.php'; ?>
<div class="row">
    <div class="col-md-12">
        <h2>Super Admin Dashboard</h2>
        <div class="row mt-4">
            <div class="col-md-4 mb-4">
                <div class="card">
                    <div class="card-body text-center">
                        <i class="fas fa-users fa-3x mb-3"></i>
                        <h5 class="card-title">RM Management</h5>
                        <p class="card-text">Manage Relationship Managers</p>
                        <a href="rm_management.php" class="btn btn-primary">Go to RM Management</a>
                    </div>
                </div>
            </div>
            <!-- Add this card to the dashboard -->
<div class="col-md-4 mb-4">
    <div class="card">
        <div class="card-body text-center">
            <i class="fas fa-id-card fa-3x mb-3"></i>
            <h5 class="card-title">RM KYC Management</h5>
            <p class="card-text">Review and approve RM KYC documents</p>
            <a href="rm_kyc_management.php" class="btn btn-primary">Go to RM KYC</a>
        </div>
    </div>
</div>
            <div class="col-md-4 mb-4">
                <div class="card">
                    <div class="card-body text-center">
                        <i class="fas fa-user-tie fa-3x mb-3"></i>
                        <h5 class="card-title">DSA Management</h5>
                        <p class="card-text">Manage Direct Selling Agents</p>
                        <a href="dsa_management.php" class="btn btn-primary">Go to DSA Management</a>
                    </div>
                </div>
            </div>
            <!-- Add this card to the dashboard -->
<div class="col-md-4 mb-4">
    <div class="card">
        <div class="card-body text-center">
            <i class="fas fa-id-card fa-3x mb-3"></i>
            <h5 class="card-title">DSA KYC Management</h5>
            <p class="card-text">Review and approve DSA KYC documents</p>
            <a href="dsa_kyc_management.php" class="btn btn-primary">Go to DSA KYC</a>
        </div>
    </div>
</div>
            <div class="col-md-4 mb-4">
                <div class="card">
                    <div class="card-body text-center">
                        <i class="fas fa-money-bill-wave fa-3x mb-3"></i>
                        <h5 class="card-title">Loan Management</h5>
                        <p class="card-text">Manage Loans, EMI, Commissions</p>
                        <a href="loan_management.php" class="btn btn-primary">Go to Loan Management</a>
                    </div>
                </div>
            </div>
            <!-- Add this card to the dashboard -->
<div class="col-md-4 mb-4">
    <div class="card">
        <div class="card-body text-center">
            <i class="fas fa-money-bill-wave fa-3x mb-3"></i>
            <h5 class="card-title">Loan Servicing</h5>
            <p class="card-text">Loan Applications, EMI Collection, Settlement & NOC</p>
            <a href="loan_servicing.php" class="btn btn-primary">Go to Loan Servicing</a>
        </div>
    </div>
</div>
<!-- Add this card to the dashboard -->
<div class="col-md-4 mb-4">
    <div class="card">
        <div class="card-body text-center">
            <i class="fas fa-money-bill-wave fa-3x mb-3"></i>
            <h5 class="card-title">Commission Management</h5>
            <p class="card-text">Manage commissions for RM, DSA & Connector</p>
            <a href="commission_management.php" class="btn btn-primary">Manage Commissions</a>
        </div>
    </div>
</div>
            <div class="col-md-4 mb-4">
                <div class="card">
                    <div class="card-body text-center">
                        <i class="fas fa-wrench fa-3x mb-3"></i>
                        <h5 class="card-title">Custom Fields</h5>
                        <p class="card-text">Manage dynamic form fields</p>
                        <a href="custom_fields.php" class="btn btn-primary">Go to Custom Fields</a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<?php include '../includes/footer.php'; ?>