<?php include_once 'include/config.php'; 

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['CUSTOMER_ID'])) {
    header("Location: login.php");
    exit();
}

$customer_id = intval($_SESSION['CUSTOMER_ID']);

//auto expire quoted requests past response ddl
$expire_query = "UPDATE custom 
                 SET STATUS = 'Expired', CAPACITY_RELEASED = 1 
                 WHERE CUSTOMER_ID = $customer_id 
                 AND STATUS = 'Quoted' 
                 AND RESPONSE_DEADLINE IS NOT NULL 
                 AND RESPONSE_DEADLINE < NOW()
                 AND IS_DELETED = 0
                 AND CAPACITY_RELEASED = 0";
mysqli_query($conn, $expire_query);


//reject the custom quoted
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] === 'reject'){

  $custom_id  = intval($_POST['custom_id']);

  $custom_query = "SELECT CUSTOM_ID, DELIVERY_DATE FROM custom
  WHERE CUSTOM_ID = $custom_id AND CUSTOMER_ID = $customer_id AND IS_DELETED = 0";
  $custom_result = mysqli_query($conn, $custom_query);

  if (mysqli_num_rows($custom_result)) {
    //retrieve delivery date
    $custom_row = mysqli_fetch_assoc($custom_result);
    //update custom status
    $update_query = "UPDATE custom SET STATUS = 'Rejected', REJECTED_BY = 'Customer' 
    WHERE CUSTOM_ID = $custom_id";
    mysqli_query($conn, $update_query);

    //production capacity management: if user reject request, already booked -1
     if (!empty($custom_row['DELIVERY_DATE']) && !$custom_row['CAPACITY_RELEASED']) {
        $delivery_date = mysqli_real_escape_string($conn, $custom_row['DELIVERY_DATE']);
        $release_query = "UPDATE production_capacity 
                          SET ALREADY_BOOKED = GREATEST(0, ALREADY_BOOKED - 1)
                          WHERE PRODUCTION_DATE =  DATE('$delivery_date')";
        mysqli_query($conn, $release_query);

       mysqli_query($conn, "UPDATE custom SET CAPACITY_RELEASED = 1 WHERE CUSTOM_ID = $custom_id"); 
    }
  }

  header("Location: CustomiseRequest.php");
  exit();
}

// Retrieve all custom requests for this customer
$sql = "SELECT CUSTOM_ID, STYLE_NAME_SNAPSHOT, CREATED_AT, STATUS, DELIVERY_DATE, DELIVERY_SLOT, QUOTED_PRICE, SIZE, QUANTITY, IDEAL_FLAVOUR, CATER_COUNT, BUDGET, CUSTOM_DES, REF_IMAGE, RECIPIENT_NAME, RECIPIENT_EMAIL, RECIPIENT_PHONE, RECIPIENT_ADDR, RESPONSE_DEADLINE
        FROM custom
        WHERE CUSTOMER_ID = $customer_id AND COALESCE(IS_DELETED, 0) = 0
        ORDER BY CREATED_AT DESC";

$result = mysqli_query($conn, $sql);
$all_requests_count = mysqli_num_rows($result);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Customise Request</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="css/header.css?v=5.0">
    <link rel="stylesheet" href="css/footer.css?v=6.0">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@500;600;700;800&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">

    <style>
      :root {
        --main-color: #80b8d2;
        --font-color: #1B2A3C;
        --secondary-color: #F4F8FC;
        --accent-blue: #3c8cb1;
        --rating-color: #F5A623;
        --search-border-color: #C9DCEE;
        --bg-color: #FFFFFF;
        --font2-color: #52708A;
        --card-bg-color: #EBF4FC;
        --btn-hover: #3c8cb1;
        --edit-blue: #2E86DE;
        --delete-red: #E74C3C;
        --transition: 0.35s cubic-bezier(0.25, 0.46, 0.45, 0.94);
      }

      body {
        font-family: 'Inter', sans-serif;
        background-color: var(--bg-color);
        color: var(--font-color);
        margin: 0;
        padding: 0;
      }
      
      /* Back section positioning */
      .back-section {
        display: flex;
        align-items: center;
        margin: 30px 0 20px 50px;
      }

      .back-link {
        text-decoration: none;
        color: var(--font2-color);
        font-size: 16px;
        font-weight: 500;
        display: flex;
        align-items: center;
        gap: 8px;
        transition: var(--transition);
      }

      .back-link:hover {
        color: var(--accent-blue);
        text-decoration: none;
      }

      .page-title {
        color: var(--font-color); 
        font-weight: 700;
        font-family: 'Poppins', sans-serif;
        font-size: 32px;
        text-align: center;
        margin-bottom: 25px;
      }

      /* Filter Tabs */
      .filter-tabs {
        display: flex;
        justify-content: center;
        gap: 12px;
        margin-bottom: 20px;
        flex-wrap: wrap;
        
      }

      .tab-btn {
        position: relative;
        padding: 9px 24px;
        background-color: #FFFFFF;
        color: var(--font2-color);
        cursor: pointer;
        transition: color var(--transition);
        font-weight: 600;
        font-family: 'Inter', sans-serif;
        font-size: 14px;
        text-decoration: none;
        display: inline-block;
        border: none;
      }

      /* Silky underline that grows from the center outward instead of popping in */
      .tab-btn::after {
        content: '';
        position: absolute;
        left: 50%;
        right: 50%;
        bottom: 0;
        height: 2px;
        background-color: var(--main-color);
        border-radius: 2px;
        opacity: 0;
        transition: left 0.35s cubic-bezier(0.25, 0.46, 0.45, 0.94),
                    right 0.35s cubic-bezier(0.25, 0.46, 0.45, 0.94),
                    height 0.35s cubic-bezier(0.25, 0.46, 0.45, 0.94),
                    background-color 0.35s ease,
                    opacity 0.25s ease;
      }

      .tab-btn:hover {
        color: var(--font-color);
      }

      .tab-btn:hover::after {
        left: 0;
        right: 0;
        opacity: 1;
      }

      .tab-btn.active::after {
        left: 0;
        right: 0;
        height: 3px;
        background-color: var(--accent-blue);
        opacity: 1;
      }

      /* Sort control */
      .sort-bar {
        display: flex;
        justify-content: center;
        align-items: center;
        gap: 10px;
        margin-bottom: 35px;
        flex-wrap: wrap;
      }

      .sort-bar label {
        font-size: 13.5px;
        font-weight: 600;
        color: var(--font2-color);
        font-family: 'Inter', sans-serif;
      }

      .sort-select {
        padding: 7px 16px;
        border-radius: 20px;
        border: 1px solid var(--search-border-color);
        background-color: #FFFFFF;
        color: var(--font-color);
        font-size: 13.5px;
        font-weight: 600;
        font-family: 'Inter', sans-serif;
        cursor: pointer;
        outline: none;
        transition: var(--transition);
      }


      .sort-select:hover,
      .sort-select:focus {
        border-color: var(--main-color);
      }

      /* Empty state */
      .empty-state {
        text-align: center;
        padding: 70px 20px;
        color: var(--font2-color);
        background: #FFFFFF;
       
        border-radius: 20px;
        max-width: 800px;
        margin: 0 auto;
        
      }
 
      .empty-state i {
        font-size: 55px;
        color: var(--main-color);
        margin-bottom: 15px;
        display: block;
      }
 
      .empty-state p {
        font-size: 16px;
        margin-bottom: 22px;
        color: var(--font2-color);
      }
 
      .btn-make-request {
        background: var(--accent-blue);
        color: #ffffff;
        padding: 11px 28px;
        border-radius: 50px;
        text-decoration: none;
        font-weight: 600;
        font-family: 'Inter', sans-serif;
        transition: var(--transition);
        box-shadow: 0 4px 12px rgba(60, 140, 177, 0.25);
        display: inline-block;
      }
 
      .btn-make-request:hover {
        background: #2b7091;
        color: #ffffff;
        transform: translateY(-2px);
        box-shadow: 0 6px 16px rgba(60, 140, 177, 0.35);
      }

      /* Compact request card styling */
      .request-card {
        background: #FFFFFF;
        border-radius: 18px;
        padding: 22px 26px;
        margin: 0 auto 20px auto;
        max-width: 1100px;
        box-shadow: 0 4px 18px rgba(27, 42, 60, 0.05);
        border: 1px solid var(--search-border-color);
        transition: transform 0.3s, box-shadow 0.3s, border-color 0.3s;
      }

      .request-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 10px 25px rgba(60, 140, 177, 0.12);
        border-color: var(--main-color);
      }

      /* Card header row */
      .request-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        border-bottom: 1px solid var(--search-border-color);
        padding-bottom: 12px;
        margin-bottom: 14px;
      }

      .header-left {
        display: flex;
        align-items: center;
        gap: 15px;
        font-size: 13.5px;
        color: var(--font2-color);
      }

      .cake-title-badge {
        font-weight: 700;
        font-family: 'Poppins', sans-serif;
        color: var(--font-color);
        font-size: 16px;
        display: inline-flex;
        align-items: center;
        gap: 6px;
      }

      .cake-title-badge i {
        color: var(--accent-blue);
      }

      .status-badge {
        padding: 4px 12px;
        border-radius: 20px;
        font-size: 12px;
        font-weight: 600;
        font-family: 'Inter', sans-serif;
      }
      .status-badge.quoted   { background: #e1f0fa; color: #0288d1; border: 1px solid #b3e5fc; }
      .status-badge.pending  { background: #fff4e5; color: #d97706; border: 1px solid #ffe0b2; }
      .status-badge.accepted { background: #e8f8ed; color: #1e7e34; border: 1px solid #c8e6c9; }
      .status-badge.rejected { background: #fee2e2; color: #dc2626; border: 1px solid #ffcdd2; }
      .status-badge.expired  { background: #f3f4f6; color: #6b7280; border: 1px solid #e5e7eb; }

      /* Main content row */
      .request-main-row {
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 15px;
      }

      .price-summary-box {
        display: flex;
        align-items: flex-end;
        gap: 20px;
        flex-wrap: wrap;
      }

      .delivery-summary {
        font-size: 13.5px;
        color: var(--font2-color);
      }
      .delivery-summary strong {
        color: var(--font-color);
      }

      .final-quote {
        display: flex;
        align-items: baseline;
      }

      .price-label {
        font-size: 13.5px;
        color: var(--font2-color);
        font-weight: 600;
      }

      .price {
        font-size: 16px;
        color: var(--accent-blue);
        font-weight: 800;
        font-family: 'Poppins', sans-serif;
        margin-left: 6px;
      }

      .price-pending {
        font-size: 13.5px;
        color: #b0acac;
        font-style: italic;
        margin-left: 6px;
      }

      /* Action buttons styles */
      .action-buttons {
        display: flex;
        gap: 10px;
        align-items: center;
      }

      .btn-proceed {
        background: var(--main-color);
        color: #FFFFFF;
        padding: 8px 22px;
        border-radius: 20px;
        text-decoration: none;
        font-size: 13.5px;
        font-weight: 600;
        font-family: 'Inter', sans-serif;
        transition: var(--transition);
        border: none;
        cursor: pointer;
        
      }

      .btn-proceed:hover { 
        background-color:var(--accent-blue); 
        color: #FFFFFF;
      }

      .btn-reject {
        background: transparent;
        color: #e57373;
        border: 1.5px solid #e57373;
        padding: 7px 18px;
        border-radius: 20px;
        font-size: 13.5px;
        font-weight: 600;
        font-family: 'Inter', sans-serif;
        transition: var(--transition);
        cursor: pointer;
      }

      .btn-reject:hover { 
        background-color: #e57373; 
        color: #FFFFFF; 
      }

      /* Status labels */
      .status-accepted {
        font-size: 13px;
        color: #1e7e34;
        font-weight: 600;
      }
      .status-rejected {
        font-size: 13px;
        color: #dc2626;
        font-weight: 600;
      }
      .status-pending {
        font-size: 13px;
        color: #b0acac;
        font-weight: 600;
      }

      /* Accordion master details */
      .master-details {
        margin-top: 15px;
        
        padding-top: 12px;
      }

      .master-details summary {
        cursor: pointer;
        color: var(--main-color);
        font-size: 13px;
        font-weight: 600;
        user-select: none;
        outline: none;
        transition: color 0.2s;
      }
      .master-details summary:hover {
        color: var(--accent-blue);
      }

      .expanded-content {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 25px;
        padding: 15px 5px 5px 5px;
        animation: fadeIn 0.25s ease-out;
      }

      @keyframes fadeIn {
        from { opacity: 0; transform: translateY(-4px); }
        to { opacity: 1; transform: translateY(0); }
      }

      .spec-title {
        font-size: 14px;
        font-weight: 700;
        font-family: 'Poppins', sans-serif;
        color: var(--font-color);
        border-left: 3px solid var(--accent-blue);
        padding-left: 8px;
        margin-bottom: 10px;
      }

      .info-sub-grid {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 8px 15px;
        font-size: 13px;
      }

      .info-sub-grid label, .recipient-info-list label {
        color: var(--font2-color);
        font-weight: 600;
      }

      .info-sub-grid span {
        color: var(--font-color);
      }

      .recipient-info-list {
        display: flex;
        flex-direction: column;
        gap: 6px;
        font-size: 13px;
      }

      .required-image {
        max-height: 160px;
        width: auto;
        object-fit: cover;
        border-radius: 12px;
        border: 1px solid var(--search-border-color);
        margin-top: 8px;
      }

      .required-details {
        font-size: 13.5px;
      }

      /* Pagination */
      .pagination {
        display: flex;
        justify-content: center;
        align-items: center;
        gap: 12px;
        margin-top: 40px;
      }

      .page-btn {
        width: 36px;
        height: 36px;
        display: flex;
        justify-content: center;
        align-items: center;
        border-radius: 50%; 
        background-color: #FFFFFF;
        color: var(--font-color);
        border: 1px solid var(--search-border-color);
        cursor: pointer;
        text-decoration: none;
        font-weight: 600;
        font-size: 13.5px;
        font-family: 'Inter', sans-serif;
        transition: var(--transition);
      }

      .page-btn.active {
        background-color: var(--main-color);
        color: #FFFFFF;
        border-color: var(--main-color);
      }

      .page-btn:hover:not(.active) {
        background-color: var(--secondary-color);
        color: var(--font-color);
        border-color: var(--main-color);
      }

      .page-arrow {
        color: var(--accent-blue);
        text-decoration: none;
        font-size: 16px;
        padding: 4px 8px;
        transition: color 0.2s;
      }

      .page-arrow:hover {
        color: #1B5FB0;
      }

      /* Reject confirm modal */
      .modal-overlay {
        display: none;
        position: fixed;
        inset: 0;
        background: rgba(27, 42, 60, 0.45);
        z-index: 9999;
        justify-content: center;
        align-items: center;
        backdrop-filter: blur(2px);
      }
      .modal-overlay.active {
        display: flex;
      }
      .modal-box {
        background: #FFFFFF;
        border-radius: 20px;
        padding: 35px 30px;
        max-width: 380px;
        width: 90%;
        text-align: center;
        box-shadow: 0 20px 60px rgba(27, 42, 60, 0.18);
        border: 1px solid var(--search-border-color);
      }
      .modal-box i {
        font-size: 40px;
        color: var(--delete-red);
        margin-bottom: 15px;
        display: block;
      }
      .modal-box h5 {
        color: var(--font-color);
        font-weight: 700;
        font-family: 'Poppins', sans-serif;
        margin-bottom: 10px;
      }
      .modal-box p {
        color: var(--font2-color);
        font-size: 13.5px;
        margin-bottom: 25px;
        line-height: 1.5;
      }
      .modal-actions {
        display: flex;
        gap: 12px;
        justify-content: center;
      }
      .btn-modal-cancel {
        background: var(--secondary-color);
        color: var(--font2-color);
        border: 1px solid var(--search-border-color);
        padding: 9px 22px;
        border-radius: 20px;
        font-weight: 600;
        font-family: 'Inter', sans-serif;
        cursor: pointer;
        transition: var(--transition);
      }
      .btn-modal-cancel:hover { background: #e2eff8; color: var(--font-color); }
      .btn-modal-confirm {
        background: var(--delete-red);
        color: white;
        border: none;
        padding: 9px 22px;
        border-radius: 20px;
        font-weight: 600;
        font-family: 'Inter', sans-serif;
        cursor: pointer;
        transition: var(--transition);
        
      }
      .btn-modal-confirm:hover { background: #c0392b; }

      @media (max-width: 768px) {
        .request-main-row { flex-direction: column; align-items: flex-start; }
        .action-buttons { width: 100%; justify-content: flex-end; }
        .expanded-content { grid-template-columns: 1fr; gap: 20px; }
        .back-section { margin: 20px 0 15px 20px; }
      }
    </style>
</head>

<body>
    <?php include 'include/header.php'; ?>
    
    <div class="back-section">
        <a href="index.php" class="back-link">
            <i class="bi bi-chevron-left"></i>Back
        </a>
    </div>

    <div class="container content-container">
        <h2 class="page-title">My Custom Inquiries</h2>

        <!-- Filter tabs -->
        <div class="filter-tabs">
            <button type="button" class="tab-btn active" data-tab="all" onclick="filterTab('all', this)">All</button>
            <button type="button" class="tab-btn" data-tab="pending" onclick="filterTab('pending', this)">Pending</button>
            <button type="button" class="tab-btn" data-tab="accepted" onclick="filterTab('accepted', this)">Accepted</button>
            <button type="button" class="tab-btn" data-tab="rejected" onclick="filterTab('rejected', this)">Rejected</button>
            <button type="button" class="tab-btn" data-tab="expired" onclick="filterTab('expired', this)">Expired</button>
        </div>

        <!-- Sort control (applies within the currently selected tab) -->
        <div class="sort-bar">
            <label for="sortSelect"><i class="bi bi-arrow-down-up"></i> Sort by:</label>
            <select id="sortSelect" class="sort-select" onchange="changeSort(this.value)">
                <option value="newest">Newest Submitted First</option>
                <option value="oldest">Oldest Submitted First</option>
            </select>
        </div>

        <!-- Dynamic Empty state -->
        <div class="empty-state" id="emptyStateBox" style="<?= ($all_requests_count === 0) ? 'display:block;' : 'display:none;' ?>">
            <i class="bi bi-inbox"></i>
            <p id="emptyStateText">You haven't made any custom requests yet.</p>
            <a href="Customise.php" class="btn-make-request" id="emptyStateBtn">Make a Request</a>
        </div>

        <div id="cardsContainer">
        <?php if ($all_requests_count > 0): ?>
            <?php while ($row = mysqli_fetch_assoc($result)):
                $status       = $row['STATUS'];
                $custom_id    = $row['CUSTOM_ID'];
                $style_name   = htmlspecialchars($row['STYLE_NAME_SNAPSHOT'] ?? 'Custom Request');
                $created_at   = date('d/m/Y', strtotime($row['CREATED_AT']));
                $created_raw  = date('c', strtotime($row['CREATED_AT']));
                $delivery_dt  = date('d/m/Y', strtotime($row['DELIVERY_DATE']));
                $delivery_raw = date('c', strtotime($row['DELIVERY_DATE']));
                $delivery_slot = htmlspecialchars($row['DELIVERY_SLOT'] ?? '');
                $quoted_price = $row['QUOTED_PRICE'];
                $budget       = number_format($row['BUDGET'], 2);
                $size         = htmlspecialchars($row['SIZE'] ?? '-');
                $flavour      = htmlspecialchars($row['IDEAL_FLAVOUR'] ?? '-');
                $pax          = htmlspecialchars($row['CATER_COUNT']);
                $description  = htmlspecialchars($row['CUSTOM_DES']);
                $ref_image    = htmlspecialchars($row['REF_IMAGE'] ?? '');
                $recip_name   = htmlspecialchars($row['RECIPIENT_NAME']);
                $recip_email  = htmlspecialchars($row['RECIPIENT_EMAIL']);
                $recip_phone  = htmlspecialchars($row['RECIPIENT_PHONE']);
                $recip_addr   = htmlspecialchars($row['RECIPIENT_ADDR']);
                $quantity     = htmlspecialchars($row['QUANTITY']);
                $response_ddl = $row['RESPONSE_DEADLINE'];

                //map status to badge class
                $badge_class = match(strtolower($status)) {
                  'quoted' => 'quoted',
                  'accepted' => 'accepted',
                  'rejected' => 'rejected',
                  'expired' => 'expired',
                  default => 'pending'
                };

                //verify if the custom request has been placed
                $paid_query = "SELECT oi.ORDER_ITEM_ID
                FROM order_item oi
                JOIN orders o ON oi.ORDER_ID = o.ORDER_ID
                WHERE oi.CUSTOM_ID = $custom_id
                AND o.ORDER_TYPE = 'Custom'
                LIMIT 1";
                $paid_result = mysqli_query($conn, $paid_query);
                $is_paid = (mysqli_num_rows($paid_result)>0);

              ?>

        <div class="request-card" data-status="<?= strtolower($status) ?>" data-created="<?= $created_raw ?>" data-delivery="<?= $delivery_raw ?>">
            <div class="request-header">
                <div class="header-left">
                    <span class="cake-title-badge"><?= $style_name ?></span>
                    <span class="submit-date text-muted"><i class="bi bi-calendar3"></i> Submitted on: <?= $created_at ?></span>
                </div>
                <span class="status-badge <?= $badge_class ?>"><?= $status ?></span>
            </div>

            <div class="request-main-row">
                <div class="price-summary-box">
                    <div class="delivery-summary">
                        <span>Delivery Date: <strong><?= $delivery_dt ?><?= $delivery_slot ? ' · ' . $delivery_slot : '' ?></strong></span>
                    </div>
                    <div class="final-quote">
                        <span class="price-label">Admin Quote:</span>
                        <?php if ($quoted_price !== null):?>
                        <span class="price">RM <?= number_format($quoted_price, 2); ?></span>
                        <?php else: ?>
                        <span class= "price-pending">Awaiting quote...</span>
                        <?php endif;?>
                    </div>
                </div>
                

                <!-- action button -->
                <div class="action-buttons">

                  <!-- quoted -->
                  <?php if ($status === 'Quoted' && !$is_paid):?>
                    <div style="font-size:12px; color:#e57373; text-align:right; margin-bottom:6px;">
                      <?php if (!empty($response_ddl)): ?>
                        <i class="bi bi-clock"></i> Respond by: <strong><?= date('d/m/Y h:i A', strtotime($response_ddl)) ?></strong>
                      <?php endif; ?>
                    </div>
                    <!-- show reject and proceed only when admin has quoted -->
                    <button class="btn-reject" onclick = "openRejectModal(<?= $custom_id ?>)">Reject</button>
                    <!-- proceed to checkout -->
                    <form method="POST" action="checkout.php" style="display:inline;">
                      <input type="hidden" name="custom_ids[]" value="<?= $custom_id ?>">
                      <input type="hidden" name="delivery_date" value="<?= htmlspecialchars($row['DELIVERY_DATE']) ?>">
                      <input type="hidden" name="delivery_time" value="<?= htmlspecialchars($row['DELIVERY_SLOT'] ?? '') ?>">
                      <button type="submit" class="btn-proceed">Proceed to Checkout</button>
                    </form>
                    <!-- pending -->
                  <?php elseif ($status === 'Pending'):?>
                    <span class="status-pending " style="font-size:13px;">
                      <i class="bi bi-hourglass-split"></i> Waiting for admin quote...
                    </span>
                  
                    <!-- accepted -->
                  <?php elseif ($status === 'Accepted' && $is_paid):?>
                    <span class="status-accepted" style="font-size:13px;">
                      <i class="bi bi-check-circle"></i> Payment Received. 
                    </span>
                  
                    <!-- rejected -->
                  <?php elseif ($status === 'Rejected'):?>
                    <span class="status-rejected" style="font-size:13px;">
                      <i class="bi bi-x-circle"></i> Quote rejected. You may contact support for more details.
                    </span>
                  
                    <!-- expired -->
                    <?php elseif ($status === 'Expired'):?>
                      <span class="text-muted" style="font-size:13px;">
                        <i class="bi bi-clock-history"></i> Quote expired. Please submit a new request.
                      </span>
                  <?php endif;?>
                </div>
            </div>
            
            <details class="master-details">
                <summary> View Requirements & Recipient Details</summary>
                
                <div class="expanded-content">
                    <div class="content-col-left">
                        <div class="spec-title">Custom Specifications</div>
                        <div class="info-sub-grid mb-3">
                            <div><label>Size / Dimension:</label> <span><?= $size ?></span></div>
                            <div><label>Flavour / Variant:</label> <span><?= $flavour ?></span></div>
                            <div><label>Pax / Units:</label> <span><?= $pax ?></span></div>
                            <div><label>Budget:</label> <span>RM <?= $budget ?></span></div>
                            <div><label>Quantity:</label> <span><?= $quantity ?></span></div>
                        </div>
                        
                        <div class="required-details">
                            <label class="mt-2 text-muted required-details">Description:</label>
                            <span class="text-muted required-details"> <?= $description ?> </span>
                        </div>

                        <?php if ($ref_image):?>
                        <div class="mt-2">
                            <label class="required-details text-muted">Reference Image:</label>
                            <img class="required-image d-block" src="<?= $ref_image ?>" alt="Reference">
                        </div>
                      <?php endif;?>
                    </div>

                    <!-- recipient details -->
                    <div class="content-col-right">
                        <div class="spec-title">Recipient Details</div>
                        <div class="recipient-info-list">
                            <div><label class="text-muted">Name:</label> <span class="text-muted"><?= $recip_name ?></span></div>
                            <div><label class="text-muted">Email:</label> <span class="text-muted"><?= $recip_email ?></span></div>
                            <div><label class="text-muted">Phone:</label> <span class="text-muted"><?= $recip_phone ?></span></div>
                            <div><label class="text-muted">Delivery Address:</label> <span class="text-muted d-block mt-1 bg-light p-2 rounded" style="font-size:13px;"><?= $recip_addr ?></span></div>
                        </div>
                    </div>
                </div>
            </details>
        </div>
        <?php endwhile; ?>
      <?php endif;?>
        </div>
    </div>

    <!-- pagination container -->
    <div class="pagination" id="customPagination" style="display: none;"></div>

    <!-- COMFIRMATION MODAL -->
     <div class="modal-overlay" id="rejectModal">
        <div class="modal-box">
            <i class="bi bi-exclamation-triangle-fill"></i>
            <h5>Reject this quote?</h5>
            <p>Once rejected, you won't be able to proceed with this order. Are you sure?</p>
            <div class="modal-actions">
                <button class="btn-modal-cancel" onclick="closeRejectModal()">Cancel</button>
                <form method="POST" id="rejectForm">
                    <input type="hidden" name="action"    value="reject">
                    <input type="hidden" name="custom_id" id="rejectCustomId" value="">
                    <button type="submit" class="btn-modal-confirm">Yes, Reject</button>
                </form>
            </div>
        </div>
    </div>

    <?php include 'include/footer.php'; ?>

    <script>
      let currentTab = 'all';
      let currentPage = 1;
      let currentSort = 'newest';
      const pageSize = 5;

      // Sort an array of card elements according to currentSort
      function sortCards(cards) {
          const sorted = [...cards];
          sorted.sort((a, b) => {
              const aCreated = new Date(a.dataset.created).getTime();
              const bCreated = new Date(b.dataset.created).getTime();
              const aDelivery = new Date(a.dataset.delivery).getTime();
              const bDelivery = new Date(b.dataset.delivery).getTime();

              switch (currentSort) {
                  case 'oldest':
                      return aCreated - bCreated;
                  case 'delivery_asc':
                      return aDelivery - bDelivery;
                  case 'delivery_desc':
                      return bDelivery - aDelivery;
                  case 'newest':
                  default:
                      return bCreated - aCreated;
              }
          });
          return sorted;
      }

      function changeSort(sortValue) {
          currentSort = sortValue;
          currentPage = 1;
          renderCustomList();
      }

      function renderCustomList() {
          const cardsContainer = document.getElementById('cardsContainer');
          const allCards = Array.from(document.querySelectorAll('.request-card'));
          const emptyState = document.getElementById('emptyStateBox');
          const emptyStateText = document.getElementById('emptyStateText');
          const emptyStateBtn = document.getElementById('emptyStateBtn');
          const paginationContainer = document.getElementById('customPagination');

          // Filter cards based on tab
          let visibleCards = [];
          if (currentTab === 'all') {
              visibleCards = allCards;
          } else if (currentTab === 'pending') {
              visibleCards = allCards.filter(card => {
                  const st = (card.getAttribute('data-status') || '').toLowerCase();
                  return st === 'pending' || st === 'quoted';
              });
          } else {
              visibleCards = allCards.filter(card => {
                  return (card.getAttribute('data-status') || '').toLowerCase() === currentTab;
              });
          }

          // Apply the chosen sort order to whichever tab is active
          visibleCards = sortCards(visibleCards);

          // Re-append cards to the DOM in the sorted order so layout follows the chosen order
          visibleCards.forEach(card => cardsContainer.appendChild(card));

          // Hide all cards initially
          allCards.forEach(card => card.style.display = 'none');

          // Check if no cards match
          if (visibleCards.length === 0) {
              if (emptyState) {
                  emptyState.style.display = 'block';
                  if (currentTab === 'all') {
                      emptyStateText.textContent = "You haven't made any custom requests yet.";
                      emptyStateBtn.textContent = "Make a Request";
                      emptyStateBtn.onclick = null;
                      emptyStateBtn.href = "Customise.php";
                  } else {
                      const tabCap = currentTab.charAt(0).toUpperCase() + currentTab.slice(1);
                      emptyStateText.textContent = `No ${tabCap} custom requests found.`;
                      emptyStateBtn.textContent = "View All Requests";
                      emptyStateBtn.href = "javascript:void(0)";
                      emptyStateBtn.onclick = function() { filterTab('all'); };
                  }
              }
              if (paginationContainer) paginationContainer.style.display = 'none';
              return;
          }

          if (emptyState) emptyState.style.display = 'none';

          // Paginate by 5 per page whenever a tab has more than pageSize records
          const totalRecords = visibleCards.length;
          if (totalRecords > pageSize) {
              const totalPages = Math.ceil(totalRecords / pageSize);
              if (currentPage > totalPages) currentPage = totalPages;
              if (currentPage < 1) currentPage = 1;

              const startIndex = (currentPage - 1) * pageSize;
              const endIndex = startIndex + pageSize;

              visibleCards.forEach((card, index) => {
                  if (index >= startIndex && index < endIndex) {
                      card.style.display = 'block';
                  } else {
                      card.style.display = 'none';
                  }
              });

              // Render pagination
              if (paginationContainer) {
                  paginationContainer.style.display = 'flex';
                  let html = '';
                  if (currentPage > 1) {
                      html += `<a href="javascript:void(0)" class="page-arrow" onclick="goToPage(${currentPage - 1})"><i class="bi bi-chevron-left"></i></a>`;
                  }
                  for (let i = 1; i <= totalPages; i++) {
                      html += `<a href="javascript:void(0)" class="page-btn ${i === currentPage ? 'active' : ''}" onclick="goToPage(${i})">${i}</a>`;
                  }
                  if (currentPage < totalPages) {
                      html += `<a href="javascript:void(0)" class="page-arrow" onclick="goToPage(${currentPage + 1})"><i class="bi bi-chevron-right"></i></a>`;
                  }
                  paginationContainer.innerHTML = html;
              }
          } else {
              // 5 or fewer records in this tab: show them all, no pagination needed
              visibleCards.forEach(card => card.style.display = 'block');
              if (paginationContainer) paginationContainer.style.display = 'none';
          }
      }

      function filterTab(tabName, btnElement) {
          currentTab = tabName.toLowerCase();
          currentPage = 1;

          document.querySelectorAll('.filter-tabs .tab-btn').forEach(btn => btn.classList.remove('active'));
          if (btnElement) {
              btnElement.classList.add('active');
          } else {
              const target = document.querySelector(`.filter-tabs .tab-btn[data-tab="${currentTab}"]`);
              if (target) target.classList.add('active');
          }

          renderCustomList();
      }

      function goToPage(pageNum) {
          currentPage = pageNum;
          renderCustomList();
          window.scrollTo({ top: 120, behavior: 'smooth' });
      }

      // Initialize on load
      document.addEventListener('DOMContentLoaded', function() {
          const urlParams = new URLSearchParams(window.location.search);
          const initialTab = urlParams.get('tab');
          if (initialTab && ['all', 'pending', 'accepted', 'rejected', 'expired'].includes(initialTab.toLowerCase())) {
              filterTab(initialTab.toLowerCase());
          } else {
              renderCustomList();
          }
      });

      // Reject modal
      function openRejectModal(customId) {
          document.getElementById('rejectCustomId').value = customId;
          document.getElementById('rejectModal').classList.add('active');
      }

      function closeRejectModal() {
          document.getElementById('rejectModal').classList.remove('active');
      }

      document.getElementById('rejectModal').addEventListener('click', function(e) {
          if (e.target === this) closeRejectModal();
      });
    </script>
</body>
</html>