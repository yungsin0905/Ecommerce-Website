<?php
session_start();
require_once 'include/config.php';

//Ensure the user is logged in
if (!isset($_SESSION['CUSTOMER_ID'])) {
    header("Location: login.php");
    exit();
}

$customer_id = intval($_SESSION['CUSTOMER_ID']);

// Fetch order history, joining payment, refund, and shipping tables
$sql = "SELECT o.ORDER_ID, 
            o.ORDER_NO, 
            o.CREATED_AT, 
            o.ORDER_STATUS, 
            o.TOTAL_AMOUNT,
            s.DELIVERY_STATUS,
            o.DELIVERY_DATE, 
            p.PAYMENT_AMOUNT,
            r.REFUND_ID,
           (SELECT COUNT(DISTINCT oi.PRODUCT_ID) FROM order_item oi 
           WHERE oi.ORDER_ID = o.ORDER_ID 
           AND oi.CUSTOM_ID IS NULL) AS TOTAL_ITEMS,
           (SELECT COUNT(DISTINCT rev.PRODUCT_ID) FROM review rev 
           WHERE rev.ORDER_ID = o.ORDER_ID 
           AND rev.CUSTOMER_ID = $customer_id) AS REVIEWED_COUNT
        FROM orders o
        LEFT JOIN payment p ON o.PAYMENT_ID = p.PAYMENT_ID
        LEFT JOIN refund r ON o.ORDER_ID = r.ORDER_ID
        LEFT JOIN shipping s ON o.SHIPPING_ID = s.SHIPPING_ID
        WHERE o.CUSTOMER_ID = $customer_id 
        ORDER BY o.CREATED_AT DESC";

$result = mysqli_query($conn, $sql);
$orders = [];

if ($result) {
    // Populate the orders array with database records
    while ($row = mysqli_fetch_assoc($result)) {
        $orders[] = $row;
    }
} else {
    // Log database errors for debugging and initialize empty array
    error_log("Order History Query Failed: " . mysqli_error($conn));
    $orders = [];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Order History Page</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="css/header.css?v=6.0">
    <link rel="stylesheet" href="css/footer.css?v=6.0">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@500;600;700;800&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">

    
<style>
    /* ===== Design tokens aligned with checkout.php / payment.php ===== */
    :root{
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

    body{
        background-color: var(--bg-color);
        font-family: 'Inter', sans-serif;
        color: var(--font2-color);
        margin: 0;
        padding: 0;
    }

    /* back section */
      .back-section {
        display: flex;
        align-items: center;
        margin: 30px 0 10px 20px; 
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


    .main-container{
        width: 850px;
        margin: 50px auto;
        padding: 20px;
    }

    h2{
        text-align: center;
        color: var(--main-color);
        font-weight: 700;
        margin-bottom: 30px;
        font-family: 'Poppins', sans-serif;
    }

    .filter-tabs{
        display: flex;
        justify-content: center;
        gap: 15px;
        margin-bottom: 20px;
        flex-wrap: wrap;
    }

    .filter-tabs .tab-btn{
        position: relative !important;
        padding: 9px 24px !important;
        margin-right: 0 !important;
        background-color: #FFFFFF !important;
        background-image: none !important;
        color: var(--font2-color) !important;
        cursor: pointer;
        transition: color var(--transition);
        font-weight: 600;
        font-family: 'Inter', sans-serif;
        font-size: 14px;
        display: inline-block;
        border: none !important;
        border-radius: 0 !important;
        box-shadow: none !important;
        outline: none !important;
    }

    .filter-tabs .tab-btn:focus,
    .filter-tabs .tab-btn:focus-visible,
    .filter-tabs .tab-btn:active{
        box-shadow: none !important;
        outline: none !important;
        background-color: #FFFFFF !important;
    }

    /* Silky underline that grows from the center outward instead of popping in */
    .filter-tabs .tab-btn::after{
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

    .filter-tabs .tab-btn:hover{
        color: var(--font-color) !important;
    }

    .filter-tabs .tab-btn:hover::after{
        left: 0;
        right: 0;
        opacity: 1;
        height: 3px;
    }

    .filter-tabs .tab-btn.active{
        background-color: #FFFFFF !important;
        color: var(--font-color) !important;
    }

    .filter-tabs .tab-btn.active::after{
        left: 0;
        right: 0;
        height: 3px;
        background-color: var(--btn-hover);
        opacity: 1;
    }

    /* Sort control */
    .sort-bar{
        display: flex;
        justify-content: center;
        align-items: center;
        gap: 10px;
        margin-bottom: 30px;
        flex-wrap: wrap;
    }

    .sort-bar label{
        font-size: 13.5px;
        font-weight: 600;
        color: var(--font2-color);
        font-family: 'Inter', sans-serif;
    }

    .sort-select{
        padding: 7px 16px;
        border-radius: 20px;
        border: 1px solid var(--search-border-color);
        background-color: #fff;
        color: var(--font-color);
        font-size: 13.5px;
        font-weight: 600;
        font-family: 'Inter', sans-serif;
        cursor: pointer;
        outline: none;
        transition: var(--transition);
    }

    .sort-select:hover,
    .sort-select:focus{
        border-color: var(--main-color);
    }

    .history-container{
        width: 100%;
    }

    .order-card{
        background-color: #fff;
        border: 1px solid var(--search-border-color);
        position: relative;
        border-radius: 20px;
        padding: 25px;
        margin-bottom: 25px;
    }

    .order-card p{
        margin: 10px 0;
        border-bottom: 1px dashed var(--search-border-color);
        padding-bottom: 6px;
        font-size: 14px;
    }

    .label{
        font-weight: 700;
        color: var(--font2-color);
        display: block;
        width: auto;
        line-height: 1.5;
        font-family: 'Inter', sans-serif;
    }

    button{
        padding: 8px 20px;
        margin-right: 10px;
        cursor: pointer;
        border-radius: 25px;
        background-color: var(--main-color);
        border: none;
        color: #FFFFFF;
        font-weight: 600;
        font-family: 'Inter', sans-serif;
        font-size: 13px;
        transition: var(--transition);
    }

    button:hover{
        background-color: var(--btn-hover);
    }

    .btn-disabled {
        background-color: #E4EBF1;
        color: #9DB4C7;
        cursor: not-allowed;
        font-weight: 600;
    }

    .btn-disabled:hover {
        background-color: #E4EBF1;
        transform: none;
    }

    .btn-review {
        background-color: var(--main-color);
        color: #FFFFFF;
        padding: 8px 20px;
        border-radius: 25px;
        transition: var(--transition);
        border: none;
        font-weight: 600;
        font-family: 'Inter', sans-serif;
        font-size: 13px;
    }

    .btn-review:hover {
        background-color: var(--btn-hover);
        transform: translateY(-2px);
    }

    .btn-reviewed {
        background-color: #E4EBF1;
        color: #9DB4C7;
        border: none;
        padding: 8px 20px;
        border-radius: 25px;
        cursor: not-allowed;
        font-weight: 600;
        font-family: 'Inter', sans-serif;
        font-size: 13px;
    }

    .status-badge {
        position: absolute;
        top: 15px;
        right: 20px;
        padding: 4px 14px;
        border-radius: 50px;
        font-size: 11px;
        font-weight: 700;
        letter-spacing: 0.4px;
        font-family: 'Inter', sans-serif;
    }

    .status-PROCESSING {
        background-color: #fff6e6;
        color: #a07840;
    }

    .status-READY {
        background-color: var(--card-bg-color);
        color: #2d7a5e;
    }

    .status-COMPLETED {
        background-color: #e8f4e8;
        color: #2d6b3a;
    }

    .status-REFUNDED {
        background-color: #f9d9d9;
        color: rgb(101, 54, 31);
    }

    .order-status-text{
        color: rgb(208, 144, 153);
        font-style: bold;
    }

    /* Pagination */
    .pagination{
        display: flex;
        justify-content: center;
        align-items: center;
        gap: 12px;
        margin-top: 20px;
    }

    .page-btn{
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

    .page-btn.active{
        background-color: var(--main-color);
        color: #FFFFFF;
        border-color: var(--main-color);
    }

    .page-btn:hover:not(.active){
        background-color: var(--secondary-color);
        color: var(--font-color);
        border-color: var(--main-color);
    }

    .page-arrow{
        color: var(--btn-hover);
        text-decoration: none;
        font-size: 16px;
        padding: 4px 8px;
        transition: color 0.2s;
    }

    .page-arrow:hover{
        color: #1B5FB0;
    }
</style>
</head>
<body>
   <?php include_once 'include/header.php'; ?>

   <div class="container-fluid">
    <div class="back-section">
      <a href="index.php" class="back-link">
        <i class="bi bi-chevron-left"></i>Back
      </a>
    </div>
  </div>


   <div class="main-container">
    <h2>My Orders</h2>

    <!--filter tab-->
    <div class="filter-tabs">
       <button class="tab-btn active" onclick="filterOrders('all', this)">All</button>
       <button class="tab-btn" onclick="filterOrders('PROCESSING', this)">Processing</button>
       <button class="tab-btn" onclick="filterOrders('READY', this)">Ready</button>
       <button class="tab-btn" onclick="filterOrders('COMPLETED', this)">Completed</button>
       <button class="tab-btn" onclick="filterOrders('REFUNDED', this)">Refunded</button>
    </div>

    <!-- Sort control (applies within the currently selected tab) -->
    <div class="sort-bar">
        <label for="sortSelect"><i class="bi bi-arrow-down-up"></i> Sort by:</label>
        <select id="sortSelect" class="sort-select" onchange="changeSort(this.value)">
            <option value="newest">Newest Order First</option>
            <option value="oldest">Oldest Order First</option>
        </select>
    </div>

    <div class="history-container" id="cardsContainer">
    <?php if (empty($orders)): ?>
        <div class="alert alert-light text-center">You haven't placed any orders yet.</div>
    <?php else: ?>
        <?php foreach ($orders as $order): 
            // Check refund_request first
            $check_stmt = $conn->prepare("
            SELECT rr.REQUEST_ID, rr.REQUEST_STATUS, r.REFUND_ID, r.REFUND_STATUS
            FROM refund_request rr
            LEFT JOIN refund r ON rr.REQUEST_ID = r.REQUEST_ID
            WHERE rr.ORDER_ID = ?
            LIMIT 1
        ");
        $check_stmt->bind_param("i", $order['ORDER_ID']);
        $check_stmt->execute();
        $refund_res  = $check_stmt->get_result()->fetch_assoc();

            $has_request = !empty($refund_res);
            $request_status = $refund_res['REQUEST_STATUS'] ?? null;
            $refund_status = $refund_res['REFUND_STATUS'] ?? null;
            $is_pending = ($request_status === 'PENDING' || $refund_status === 'PENDING');
            $linked_refund_id  = intval($refund_res['REFUND_ID'] ?? 0);
            $linked_req_id = intval($refund_res['REQUEST_ID'] ?? 0);

            $is_completed = ($order['ORDER_STATUS'] === 'COMPLETED');
            $is_refunded  = ($order['ORDER_STATUS'] === 'REFUNDED');
            // Calculate 2-day refund deadline
            $refund_deadline = null;
            $refund_expired  = false;
            if ($is_completed) {
                $refund_deadline = strtotime($order['DELIVERY_DATE']) + (2 * 24 * 60 * 60);
                $refund_expired  = (time() > $refund_deadline);
            }

            // If all items are custom cakes (TOTAL_ITEMS = 0), no review needed
            $already_reviewed = ($order['TOTAL_ITEMS'] == 0) ? true : ($order['REVIEWED_COUNT'] >= $order['TOTAL_ITEMS']);

            $delivery_status = $order['DELIVERY_STATUS'] ?? 'Pending';

            // Use TOTAL_AMOUNT from orders table as the display amount
            $display_amount = $order['TOTAL_AMOUNT'] ?? $order['PAYMENT_AMOUNT'] ?? 0;

            // Raw ISO timestamp used purely for client-side sorting by order date
            $created_raw = date('c', strtotime($order['CREATED_AT']));
        ?>

            <!--order card-->
            <div class="order-card"
                 data-order-status="<?php echo htmlspecialchars($order['ORDER_STATUS']); ?>"
                 data-delivery-status="<?php echo htmlspecialchars($delivery_status); ?>"
                 data-created="<?php echo $created_raw; ?>">

                <!-- Top right badge -->
                <span class="status-badge status-<?php echo htmlspecialchars($order['ORDER_STATUS']); ?>">
                  <?php echo htmlspecialchars($order['ORDER_STATUS']); ?>
                </span>

                <p><span class="label">Order No: <?php echo htmlspecialchars($order['ORDER_NO']); ?></span></p>
                <p><span class="label">Order Date: <?php echo date("d M Y, H:i", strtotime($order['CREATED_AT'])); ?></span></p>
                <p><span class="label">Order Status: <span class="order-status-text"><?php echo htmlspecialchars($order['ORDER_STATUS']); ?></span></span></p>
                <p><span class="label">Delivery Date: <?php echo date("d M Y", strtotime($order['DELIVERY_DATE'])); ?></span></p>
                <!-- Display TOTAL_AMOUNT -->
                <p><span class="label">Total Payment: RM <?php echo number_format($display_amount, 2); ?></span></p>
                
                <!-- buttons -->
                <div class="action-buttons">
                    <a href="order.php?id=<?php echo $order['ORDER_ID']; ?>">
                        <button type="button">View Details</button>
                    </a>

                    <?php if ($is_completed || $is_refunded): ?>
                        <?php if ($has_request): ?>
                            <?php if ($is_pending): ?>
                            <button type="button" class="btn-disabled" disabled>Refund Pending</button>
                            <?php else: ?>
                                <a href="refund.php?refund_id=<?php echo $linked_refund_id; ?>&request_id=<?php echo $linked_req_id; ?>">
                                <button type="button">Refund Status</button>
                                </a>
                            <?php endif; ?>

                        <?php elseif ($refund_expired): ?>
                          <button type="button" disabled class="btn-disabled">Request Refund</button>

                        <?php else: ?>
                          <a href="refund_request.php?order_id=<?php echo $order['ORDER_ID']; ?>">
                            <button type="button">Request Refund</button>
                          </a>
                        <?php endif; ?>
                    <?php endif; ?>

                    <?php if ($is_completed): ?>
                        <?php if ($already_reviewed): ?>
                            <button class="btn-reviewed" disabled>Reviewed</button>
                        <?php else: ?>
                            <a href="review.php?order_id=<?php echo $order['ORDER_ID']; ?>">
                                <button class="btn-review">Review Order</button>
                            </a>
                        <?php endif; ?>
                    <?php endif; ?>               
                 </div> 
             </div> 
          <?php endforeach; ?> 
       <?php endif; ?> 
    </div>

    <!-- pagination container -->
    <div class="pagination" id="orderPagination" style="display: none;"></div>
</div>

<script>
    let currentStatus = 'all';
    let currentPage = 1;
    let currentSort = 'newest';
    const pageSize = 5;

    // Sort an array of order-card elements by order date only
    function sortCards(cards) {
        const sorted = [...cards];
        sorted.sort((a, b) => {
            const aCreated = new Date(a.dataset.created).getTime();
            const bCreated = new Date(b.dataset.created).getTime();
            return currentSort === 'oldest' ? (aCreated - bCreated) : (bCreated - aCreated);
        });
        return sorted;
    }

    function changeSort(sortValue) {
        currentSort = sortValue;
        currentPage = 1;
        renderOrderList();
    }

    // Updates active tab styles and toggles visibility/pagination of cards.
    function filterOrders(status, clickedBtn) {
        currentStatus = status;
        currentPage = 1;

        // Update active class for tab buttons
        document.querySelectorAll('.tab-btn').forEach(btn => btn.classList.remove('active'));
        if (clickedBtn) clickedBtn.classList.add('active');

        renderOrderList();
    }

    function renderOrderList() {
        const cardsContainer = document.getElementById('cardsContainer');
        const allCards = Array.from(document.querySelectorAll('.order-card'));
        const paginationContainer = document.getElementById('orderPagination');

        if (allCards.length === 0) {
            if (paginationContainer) paginationContainer.style.display = 'none';
            return;
        }

        // Filter cards based on selected status tab
        let visibleCards = (currentStatus === 'all')
            ? allCards
            : allCards.filter(card => card.getAttribute('data-order-status') === currentStatus);

        // Apply the chosen sort order (by order date only)
        visibleCards = sortCards(visibleCards);

        // Re-append cards to the DOM in the sorted order
        visibleCards.forEach(card => cardsContainer.appendChild(card));

        // Hide all cards initially
        allCards.forEach(card => card.style.display = 'none');

        if (visibleCards.length === 0) {
            if (paginationContainer) paginationContainer.style.display = 'none';
            return;
        }

        const totalRecords = visibleCards.length;

        // Paginate by 5 per page whenever this tab has more than pageSize records
        if (totalRecords > pageSize) {
            const totalPages = Math.ceil(totalRecords / pageSize);
            if (currentPage > totalPages) currentPage = totalPages;
            if (currentPage < 1) currentPage = 1;

            const startIndex = (currentPage - 1) * pageSize;
            const endIndex = startIndex + pageSize;

            visibleCards.forEach((card, index) => {
                card.style.display = (index >= startIndex && index < endIndex) ? 'block' : 'none';
            });

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

    function goToPage(pageNum) {
        currentPage = pageNum;
        renderOrderList();
        window.scrollTo({ top: 120, behavior: 'smooth' });
    }

    document.addEventListener('DOMContentLoaded', function() {
        renderOrderList();
    });
</script>
</body>
<?php include_once 'include/footer.php'; ?>
</html>