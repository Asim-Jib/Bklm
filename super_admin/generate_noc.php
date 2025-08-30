<?php
require_once '../config/database.php';
require_once '../includes/auth.php';
redirectIfNotSuperAdmin();

$certificate_id = $_GET['certificate_id'] ?? null;

if (!$certificate_id) {
    die("Certificate ID is required");
}

// Get certificate details
$stmt = $pdo->prepare("
    SELECT nc.*, l.principal_amount, l.start_date, l.end_date, u.username as customer_name, 
           u.id as customer_id, lt.name as loan_type_name, a.username as issued_by_name
    FROM noc_certificates nc
    LEFT JOIN loans l ON nc.loan_id = l.id
    LEFT JOIN users u ON l.user_id = u.id
    LEFT JOIN loan_types lt ON l.loan_type_id = lt.id
    LEFT JOIN users a ON nc.issued_by = a.id
    WHERE nc.id = ?
");
$stmt->execute([$certificate_id]);
$certificate = $stmt->fetch();

if (!$certificate) {
    die("Certificate not found");
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>NOC Certificate - <?php echo $certificate['certificate_number']; ?></title>
    <style>
        body { font-family: Arial, sans-serif; margin: 0; padding: 20px; background-color: #f8f9fa; }
        .certificate { background: white; padding: 30px; border: 2px solid #000; max-width: 800px; margin: 0 auto; box-shadow: 0 0 10px rgba(0,0,0,0.1); }
        .header { text-align: center; margin-bottom: 30px; }
        .header h1 { color: #2c3e50; margin-bottom: 5px; }
        .header .logo { font-size: 24px; font-weight: bold; color: #3498db; }
        .content { margin: 20px 0; }
        .footer { margin-top: 40px; text-align: right; }
        .signature { margin-top: 60px; border-top: 1px solid #000; padding-top: 10px; }
        .text-center { text-align: center; }
        .mb-4 { margin-bottom: 1.5rem; }
        @media print {
            body { background: white; }
            .certificate { border: none; box-shadow: none; }
            .no-print { display: none; }
        }
    </style>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
    <div class="certificate">
        <div class="header">
            <div class="logo">Loan Management System</div>
            <h1>NO OBJECTION CERTIFICATE</h1>
            <p>Certificate Number: <?php echo $certificate['certificate_number']; ?></p>
        </div>
        
        <div class="content">
            <p class="text-center mb-4">This is to certify that</p>
            
            <h2 class="text-center mb-4"><?php echo $certificate['customer_name']; ?></h2>
            
            <p class="text-center mb-4">(Customer ID: C<?php echo str_pad($certificate['customer_id'], 6, '0', STR_PAD_LEFT); ?>)</p>
            
            <p>has successfully completed the loan agreement for the following loan:</p>
            
            <table style="width: 100%; margin: 20px 0;">
                <tr>
                    <td><strong>Loan Type:</strong></td>
                    <td><?php echo $certificate['loan_type_name']; ?></td>
                </tr>
                <tr>
                    <td><strong>Principal Amount:</strong></td>
                    <td>₹<?php echo number_format($certificate['principal_amount'], 2); ?></td>
                </tr>
                <tr>
                    <td><strong>Loan Period:</strong></td>
                    <td><?php echo date('d M Y', strtotime($certificate['start_date'])); ?> to <?php echo date('d M Y', strtotime($certificate['end_date'])); ?></td>
                </tr>
                <tr>
                    <td><strong>Issue Date:</strong></td>
                    <td><?php echo date('d M Y', strtotime($certificate['issue_date'])); ?></td>
                </tr>
            </table>
            
            <p>All dues have been cleared and we have no objection regarding the settlement of this loan account.</p>
            
            <?php if (!empty($certificate['notes'])): ?>
                <p><strong>Notes:</strong> <?php echo $certificate['notes']; ?></p>
            <?php endif; ?>
        </div>
        
        <div class="footer">
            <div class="signature">
                <p><strong>Authorized Signature</strong></p>
                <p><?php echo $certificate['issued_by_name']; ?></p>
                <p>Loan Management System</p>
                <p>Date: <?php echo date('d M Y', strtotime($certificate['issue_date'])); ?></p>
            </div>
        </div>
    </div>
    
    <div class="no-print" style="text-align: center; margin-top: 20px;">
        <button onclick="window.print()" class="btn btn-primary">Print Certificate</button>
        <button onclick="window.close()" class="btn btn-secondary">Close</button>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>