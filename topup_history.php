<?php
session_start();
require_once 'include/config.php';

// Ensure the user is logged in
if (!isset($_SESSION['CUSTOMER_ID'])) {
    header("Location: login.php");
    exit();
}

$customer_id = $_SESSION['CUSTOMER_ID'];

// Fetch wallet transaction history for the logged-in user, ordered by date
$query = "SELECT * FROM wallet_transaction 
          WHERE CUSTOMER_ID = ? 
          ORDER BY CREATED_AT DESC";

$stmt = $conn->prepare($query);
$stmt->bind_param("i", $customer_id);
$stmt->execute();
$result = $stmt->get_result();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Topup History Page</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="css/header.css?v=6.0">
    <link rel="stylesheet" href="css/footer.css?v=6.0">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@500;600;700;800&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">

    <style>
        /* ===== Design tokens aligned with checkout.php / payment.php ===== */
        :root {
            --main-color: #80b8d2;
            --font-color: #1B2A3C;
            --secondary-color: #F4F8FC;
            --bg-color: #FFFFFF;
            --font2-color: #52708A;
            --card-bg-color: #EBF4FC;
            --search-border-color: #C9DCEE;
            --btn-hover: #3c8cb1;
            --transition: 0.35s cubic-bezier(0.25, 0.46, 0.45, 0.94);
        }

        body {
            background-color: var(--bg-color);
            color: var(--font-color);
            font-family: 'Inter', sans-serif;
            margin: 0;
            padding: 0;
        }

        .history-section {
            padding: 60px 0;
            min-height: 600px;
        }

        .history-container {
            background: white;
            padding: 40px;
            border-radius: 25px;
            border: 1px solid var(--search-border-color);
            max-width: 1000px;
            margin: 40px auto;
            box-sizing: border-box;
        }

        h2 {
            color: var(--main-color);
            font-weight: 700;
            margin-bottom: 30px;
            text-align: center;
            font-family: 'Poppins', sans-serif;
            font-size: 22px;
        }

        .table {
            color: var(--font-color);
            font-family: 'Inter', sans-serif;
            font-size: 14px;
        }

        .table thead th {
            background-color: var(--secondary-color);
            color: var(--font2-color);
            border-bottom: 2px solid var(--search-border-color);
            padding: 14px 16px;
            font-weight: 700;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .table tbody td {
            padding: 14px 16px;
            vertical-align: middle;
            border-bottom: 1px solid var(--search-border-color);
            color: var(--font2-color);
        }

        .table-hover tbody tr:hover {
            background-color: var(--secondary-color);
        }

        /* Type badges */
        .type-topup {
            background-color: #e8f5e9;
            color: #2e7d32;
            padding: 4px 12px;
            border-radius: 50px;
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 0.4px;
        }

        .type-payment {
            background-color: #ffebee;
            color: #c62828;
            padding: 4px 12px;
            border-radius: 50px;
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 0.4px;
        }

        /* Back button */
        .btn-cake {
            background-color: var(--main-color);
            color: #FFFFFF;
            border-radius: 25px;
            padding: 10px 25px;
            font-weight: 600;
            border: none;
            transition: var(--transition);
            text-decoration: none;
            display: inline-block;
            font-family: 'Inter', sans-serif;
        }

        .btn-cake:hover {
            background-color: var(--btn-hover);
            color: #FFFFFF;
            transform: translateY(-2px);
        }

        .btn.btn-outline-primary {
            border: 1px solid var(--font2-color);
            color: var(--font2-color);
            border-radius: 20px;
            padding: 8px 22px;
            font-weight: 600;
            font-family: 'Inter', sans-serif;
            font-size: 13px;
            background: transparent;
            transition: var(--transition);
        }

        .btn.btn-outline-primary:hover {
            background-color: var(--main-color);
            border-color: var(--main-color);
            color: #FFFFFF;
            transform: translateY(-2px);
        }

        /* Amount colors */
        .text-success {
            color: #27ae60;
            font-weight: 700;
        }

        .text-danger {
            color: #c0392b;
            font-weight: 700;
        }

        .text-muted {
            color: var(--font2-color);
            opacity: 0.8;
            font-size: 13px;
        }

        .balance-arrow {
            color: var(--main-color);
            font-weight: 700;
        }
    </style>
</head>
<body>
<?php include_once 'include/header.php'; ?>
<div class="history-container">
    <h2>My Wallet History</h2>
    <table class="table table-hover">
        <thead class="table-light">
            <tr>
                <th>Date</th>
                <th>Type</th>
                <th>Method</th>
                <th>Amount (RM)</th>
                <th>Balance Change</th>
            </tr>
        </thead>
        <tbody>
            <?php while ($row = $result->fetch_assoc()): ?>
            <tr>
                <td><?php echo date('d M Y, H:i', strtotime($row['CREATED_AT'])); ?></td>
                <td>
                    <span class="<?php echo ($row['TYPE'] == 'TOPUP') ? 'type-topup' : 'type-payment'; ?>">
                        <?php echo $row['TYPE']; ?>
                    </span>
                </td>
                <td><?php echo $row['TOPUP_METHODS'] ?? '-'; ?></td>
                <td>
                <strong class="<?php echo in_array($row['TYPE'], ['TOPUP', 'REFUND']) ? 'text-success' : 'text-danger'; ?>">
                   <?php echo number_format($row['AMOUNT'], 2); ?>
                </strong>
                </td>
                <td>
                    <small class="text-muted">
                        <?php echo number_format($row['BEFORE_BALANCE'], 2); ?> 
                        &rarr; 
                        <?php echo number_format($row['AFTER_BALANCE'], 2); ?>
                    </small>
                </td>
            </tr>
            <?php endwhile; ?>
        </tbody>
    </table>
    
    <div class="mt-4">
        <a href="topup.php" class="btn btn-outline-primary">Back to Top-up</a>
    </div>
</div>
 <?php include_once 'include/footer.php'; ?>
</body>
</html>