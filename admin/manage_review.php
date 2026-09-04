<?php
require_once("config.php");
session_start();

if (!isset($_SESSION['admin_id'])) {
    header("Location: admin_login.php");
    exit();
} 
$admin_id = $_SESSION['admin_id'];

// page title
$pageTitle = "Manage Reviews";

// search, filter input
$search = $_GET['search'] ?? '';
$status = $_GET['status'] ?? '';
$replyStatus = $_GET['replyStatus'] ?? '';
$rating = $_GET['rating'] ?? '';
$fromDate = $_GET['fromDate'] ?? '';
$toDate = $_GET['toDate'] ?? '';

// sorting 
$sort = $_GET['sort'] ?? 'CREATED_AT';
$order = $_GET['order'] ?? 'DESC';
$allowedSort = [
    'CUSTOMER_NAME',
    'RATING',
    'CREATED_AT',
    'PRODUCT_NAME'
];
$allowedOrder = ['ASC', 'DESC'];
if (!in_array($sort, $allowedSort)) {
    $sort = 'CREATED_AT';
}
if (!in_array($order, $allowedOrder)) {
    $order = 'DESC';
}

// pagination
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$page = max(1, $page);
$allowedLimits = [5,10,30,50,100];
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 5;
if (!in_array($limit, $allowedLimits)) {
    $limit = 5;
}
$offset = ($page - 1) * $limit;

// summary cards
function getCount($conn, $sql, $type = "", $param = null) {
    $stmt = $conn->prepare($sql);
    if ($type && $param !== null) {
        $stmt->bind_param($type, $param);
    }
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc();
}

$totalUnreplied = getCount($conn, "
    SELECT COUNT(*) AS r
    FROM review r
    LEFT JOIN review_reply rr 
        ON r.REVIEW_ID = rr.REVIEW_ID 
        AND rr.IS_DELETED = 0
    WHERE rr.REVIEW_ID IS NULL
")['r'];

$totalHidden = getCount($conn, "
    SELECT COUNT(*) AS r
    FROM review
    WHERE REVIEW_STATUS = 'Hide'
")['r'];

// search, filter conditions
$where = [];
$params = [];
$types = "";

if ($search != '') {
    $where[] = "(c.CUSTOMER_NAME LIKE ? OR o.ORDER_NO LIKE ? OR p.PRODUCT_NAME LIKE ?)";

    $searchParam = "%$search%";

    $params[] = $searchParam;
    $params[] = $searchParam;
    $params[] = $searchParam;

    $types .= "sss";
}

if ($status != '') {
    $where[] = "r.REVIEW_STATUS = ?";
    $params[] = $status;
    $types .= "s";
}

if ($replyStatus == 'Reply') {
    $where[] = "EXISTS (
        SELECT 1 FROM review_reply rr 
        WHERE rr.REVIEW_ID = r.REVIEW_ID 
        AND rr.IS_DELETED = 0
    )";
} elseif ($replyStatus == 'Unreply') {
    $where[] = "NOT EXISTS (
        SELECT 1 FROM review_reply rr 
        WHERE rr.REVIEW_ID = r.REVIEW_ID 
        AND rr.IS_DELETED = 0
    )";
}

if ($rating != '') {
    $where[] = "r.RATING >= ?";
    $params[] = $rating;
    $types .= "i";
}

/* Date Range Filters */
if ($fromDate != '') {
    $where[] = "r.CREATED_AT >= ?";
    $params[] = $fromDate . " 00:00:00";
    $types .= "s";
}
if ($toDate != '') {
    $where[] = "r.CREATED_AT <= ?";
    $params[] = $toDate . " 23:59:59";
    $types .= "s";
}

$whereSql = "";
if (count($where) > 0) {
    $whereSql = " WHERE " . implode(" AND ", $where);
}

// get total rows for pagination
$countSql = "
SELECT COUNT(DISTINCT r.REVIEW_ID) AS total
FROM review r
JOIN customer c ON r.CUSTOMER_ID = c.CUSTOMER_ID
LEFT JOIN orders o ON r.ORDER_ID = o.ORDER_ID
LEFT JOIN product p ON r.PRODUCT_ID = p.PRODUCT_ID
LEFT JOIN (
    SELECT DISTINCT REVIEW_ID
    FROM review_reply
    WHERE IS_DELETED = 0
) rr ON r.REVIEW_ID = rr.REVIEW_ID
" . $whereSql;

$stmtCount = $conn->prepare($countSql);
if (!empty($params)) {
    $stmtCount->bind_param($types, ...$params);
}
$stmtCount->execute();
$totalRows = $stmtCount->get_result()->fetch_assoc()['total'];
$totalPages = max(1, ceil($totalRows / $limit));
if ($page > $totalPages) {
    $page = $totalPages;
    $offset = ($page - 1) * $limit;
}

// fetch review list (With Filter, Sort, Pagination)
$listSql = "
SELECT 
    r.*, 
    c.CUSTOMER_NAME, 
    o.ORDER_NO,
    p.PRODUCT_NAME,
    CASE 
        WHEN EXISTS (
            SELECT 1 
            FROM review_reply rr 
            WHERE rr.REVIEW_ID = r.REVIEW_ID 
            AND rr.IS_DELETED = 0
        ) THEN 'Reply'
        ELSE 'Unreply'
    END AS REPLY_STATUS
FROM review r
JOIN customer c ON r.CUSTOMER_ID = c.CUSTOMER_ID
LEFT JOIN orders o ON r.ORDER_ID = o.ORDER_ID
LEFT JOIN product p ON r.PRODUCT_ID = p.PRODUCT_ID
$whereSql
ORDER BY $sort $order
LIMIT ? OFFSET ?
";

$stmtList = $conn->prepare($listSql);
$params2 = $params;
$types2 = $types . "ii";
$params2[] = $limit;
$params2[] = $offset;
$stmtList->bind_param($types2, ...$params2);
$stmtList->execute();
$result = $stmtList->get_result();

// Build URL (or Pagination & Sorting Links)
function buildUrl($page, $search, $status, $replyStatus, $rating, $fromDate, $toDate, $limit, $sort, $order) {
    return "?" . http_build_query([
        "page" => $page,
        "search" => $search,
        "status" => $status,
        "replyStatus" => $replyStatus,
        "rating" => $rating,
        "fromDate" => $fromDate,
        "toDate" => $toDate,
        "limit" => $limit,
        "sort" => $sort,
        "order" => $order
    ]);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Reviews</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="admin_global.css">
    <link rel="stylesheet" href="global_manage.css">
</head>

<style>
.search-box {
    width: 385px;
}

.status-unhide {
    background: #e6ffed;
    color: #1f7a3f;
}

.status-hide {
    background: #ffe6e6;
    color: #b30000;
}

.reply-status-reply {
    background: #e6ffed;
    color: #1f7a3f;
    border: 1px solid #b7ebc6;
}

.reply-status-unreply {
    background: #fff4e5;
    color: #b26a00;
    border: 1px solid #ffd59e;
}

.reply-status-reply::before {
    content: "✔ ";
}

.reply-status-unreply::before {
    content: "⚠ ";
}

.review-image {
    width:40px; 
    height:40px;
    object-fit:cover;
    border-radius:6px;
}

/* Reason */
.comment {
    max-width: 200px;        
    white-space: nowrap;    
    overflow: hidden;       
    text-overflow: ellipsis; 
}

.comment:hover {
    cursor: pointer;
}

.modal {
    display: none;
    position: fixed;
    z-index: 999;
    left: 0;
    top: 0;
    width: 100%;
    height: 100%;
    background: rgba(0,0,0,0.4);
}

.modal-content {
    background: #fff;
    padding: 20px;
    width: 300px;
    margin: 10% auto;
    border-radius: 10px;
}

#modalReply {
    color: #111;
    background: #fff;
    font-size: 14px;
}

#replyModal .modal-content {
    width: 90%;
    max-width: 600px;
}

/* Reply Pop up*/
#replyListModal {
    display: none;
    position: fixed;
    z-index: 9999;
    inset: 0;
    background: rgba(0,0,0,0.45);
    justify-content: center;
    align-items: center;
}

#replyListModal .modal-content {
    background: #fff;
    width: 600px;
    max-width: 100%;
    max-height: 80vh;
    padding: 24px;
    border-radius: 16px;
    box-shadow: 0 10px 30px rgba(0,0,0,0.15);
    overflow-y: auto;
    animation: modalFade 0.2s ease;
}

#replyListModal h3 {
    margin-bottom: 18px;
    font-size: 22px;
    font-weight: 600;
    color: #111827;
}

/* reply container */
#replyListContainer {
    display: flex;
    flex-direction: column;
    gap: 14px;
}

/* each reply card */
.reply-card {
    padding: 14px 16px;
    border: 1px solid #e5e7eb;
    border-radius: 12px;
    background: #f9fafb;
}

.reply-card .reply-text {
    font-size: 14px;
    color: #374151;
    line-height: 1.6;
    margin-bottom: 8px;
}

.reply-card .reply-date {
    font-size: 12px;
    color: #9ca3af;
}

/* close button */
#replyListModal button {
    margin-top: 20px;
    padding: 10px 18px;
    border: none;
    border-radius: 10px;
    background: var(--primary-dark);
    color: var(--primary-white);
    font-size: 14px;
    font-weight: 500;
    cursor: pointer;
    transition: 0.2s;
}

#replyListModal button:hover {
    background: var(--primary-white);
    border:1px solid var(--primary-grey);
    color:var(--primary-dark);
}

.action-menu {
    min-width: 130px;
}

.product {
    max-width: 150px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.product:hover {
    cursor: pointer;
}
</style>

<body>
  <div class="global-layout">
    <?php include "global_layout_ctrl.php" ?>
      <div class="main">

        <div class="mgmt-main-cont">
            <div class="mgmt-sum-card-cont">
                <div class="mgmt-sum-card">
                    <p class="mgmt-card-title">Unreplied</p>
                    <p class="mgmt-card-value"><?= htmlspecialchars($totalUnreplied) ?></p>
                </div>

                <div class="mgmt-sum-card">
                    <p class="mgmt-card-title">Hidden</p>
                    <p class="mgmt-card-value"><?= htmlspecialchars($totalHidden) ?></p>
                </div>
            </div>

            <div class="mgmt-ctrl">
                <form method="GET">
                    <input type="hidden" name="limit" value="<?= htmlspecialchars($limit) ?>">
                    <input type="hidden" name="sort" value="<?= htmlspecialchars($sort) ?>">
                    <input type="hidden" name="order" value="<?= htmlspecialchars($order) ?>">

                    <div class="search-box">
                        <i class="bi bi-search search-icon"></i>
                        <input type="text" name="search" placeholder="Search by customer, product name, or order no(without '#')" class="mgmt-search" value="<?php echo htmlspecialchars($search); ?>">
                    </div>

                    <select name="status" class="mgmt-filter" onchange="this.form.submit()">
                      <option value="">All Review Status</option>
                      <option value="Hide" <?php echo ($status == 'Hide') ? 'selected' : ''; ?>>Hide</option>
                      <option value="Unhide" <?php echo ($status == 'Unhide') ? 'selected' : '' ?>>Unhide</option>
                    </select>

                    <select name="replyStatus" class="mgmt-filter" onchange="this.form.submit()">
                      <option value="">All Reply Status</option>
                      <option value="Reply" <?php echo ($replyStatus == 'Reply') ? 'selected' : ''; ?>>Reply</option>
                      <option value="Unreply" <?php echo ($replyStatus == 'Unreply') ? 'selected' : ''; ?>>Unreply</option>
                    </select>

                    <select name="rating" class="mgmt-filter" onchange="this.form.submit()">
                        <option value="">All Rating</option>
                        <option value="4" <?php echo ($rating == 4) ? 'selected' : '' ?>>4.0 & above</option>
                        <option value="3" <?php echo ($rating == 3) ? 'selected' : '' ?>>3.0 & above</option>
                        <option value="2" <?php echo ($rating == 2) ? 'selected' : '' ?>>2.0 & above</option>
                        <option value="1" <?php echo ($rating == 1) ? 'selected' : '' ?>>1.0 & above</option>
                    </select>

                    <!-- Date Range Filters -->
                    <label>Date Range:</label>
                    <input type="date" name="fromDate" class="mgmt-filter" 
                        value="<?= htmlspecialchars($fromDate) ?>" 
                        onchange="this.form.submit()">
                        <span>to</span>
                    <input type="date" name="toDate" class="mgmt-filter" 
                        value="<?= htmlspecialchars($toDate) ?>" 
                        onchange="this.form.submit()">

                    <?php if (!empty($search) || !empty($status) || !empty($replyStatus) || !empty($rating) || !empty($fromDate) || !empty($toDate)): ?>
                        <button type="button" id="clear-ctrl-btn" title="Clear Search and Filter Input">Clear</button>
                    <?php endif; ?>
                </form>

                <div class="toggle-col-cont">
                    <button id="toggle-col-btn">
                        <i class="bi bi-layout-three-columns"></i>
                    </button>

                    <div id="toggle-col-menu">
                        <label><input type="checkbox" checked data-col="orderNo">Order No</label>
                        <label><input type="checkbox" checked data-col="product">Product</label>
                        <label><input type="checkbox" checked data-col="customer">Customer</label>
                        <label><input type="checkbox" checked data-col="rating">Rating</label>
                        <label><input type="checkbox" checked data-col="comment">Comment</label>
                        <label><input type="checkbox" checked data-col="image">Image</label>
                        <label><input type="checkbox" checked data-col="status">Status</label>
                        <label><input type="checkbox" checked data-col="replyStatus">Reply Status</label>
                        <label><input type="checkbox" checked data-col="since">Since</label>
                        <button id="reset-col-btn" title="Reset Columns">Reset</button>
                    </div>
                </div>
            </div>

            <div class="mgmt-tbl">
                <table>
                    <thead>
                        <tr>
                            <th class="orderNo">Order No</th>

                            <th class="product">
                                <a class="sort-link <?= $sort=='PRODUCT_NAME' ? 'active-sort '.strtolower($order) : '' ?>"
                                   href="<?= buildUrl(1,$search,$status,$replyStatus,$rating,$fromDate,$toDate,$limit,'PRODUCT_NAME',
                                   ($sort=='PRODUCT_NAME' && $order=='ASC') ? 'DESC' : 'ASC') ?>">

                                    <span>Product</span>

                                    <span class="sort-icons">
                                        <span class="up"><i class="bi bi-chevron-up"></i></span>
                                        <span class="down"><i class="bi bi-chevron-down"></i></span>
                                    </span>
                                </a>
                            </th>

                            <th class="customer">
                                <a class="sort-link <?= $sort=='CUSTOMER_NAME' ? 'active-sort '.strtolower($order) : '' ?>"
                                    href="<?= buildUrl(1,$search,$status,$replyStatus,$rating,$fromDate,$toDate,$limit,'CUSTOMER_NAME',
                                   ($sort=='CUSTOMER_NAME' && $order=='ASC') ? 'DESC' : 'ASC') ?>">

                                   <span>Customer</span>

                                    <span class="sort-icons">
                                       <span class="up"><i class="bi bi-chevron-up"></i></span>
                                       <span class="down"><i class="bi bi-chevron-down"></i></span>
                                    </span>
                                </a>
                            </th>

                            <th class="rating">
                                <a class="sort-link <?= $sort=='RATING' ? 'active-sort '.strtolower($order) : '' ?>"
                                    href="<?= buildUrl(1,$search,$status,$replyStatus,$rating,$fromDate,$toDate,$limit,'RATING',
                                   ($sort=='RATING' && $order=='ASC') ? 'DESC' : 'ASC') ?>">

                                   <span>Rating</span>

                                    <span class="sort-icons">
                                       <span class="up"><i class="bi bi-chevron-up"></i></span>
                                       <span class="down"><i class="bi bi-chevron-down"></i></span>
                                    </span>
                                </a>
                            </th>

                            <th class="comment">Comment</th>

                            <th class="image">Image</th>

                            <th class="status">Status</th>

                            <th class="since">
                                <a class="sort-link <?= $sort=='CREATED_AT' ? 'active-sort '.strtolower($order) : '' ?>"
                                    href="<?= buildUrl(1,$search,$status,$replyStatus,$rating,$fromDate,$toDate,$limit,'CREATED_AT',
                                    ($sort=='CREATED_AT' && $order=='ASC') ? 'DESC' : 'ASC') ?>">

                                    <span>Since</span>

                                    <span class="sort-icons">
                                        <span class="up"><i class="bi bi-chevron-up"></i></span>
                                        <span class="down"><i class="bi bi-chevron-down"></i></span>
                                    </span>
                                </a>
                            </th>

                            <th class="replyStatus">Reply Status</th>

                            <th>Action</th>
                        </tr>
                    </thead>

                    <tbody>
                        <?php while($row = $result->fetch_assoc()): ?>
                            <tr>
                                <td class="orderNo">
                                    <a href="view_order.php?order_id=<?= $row['ORDER_ID'] ?>" class="order-link">
                                        #<?= htmlspecialchars($row['ORDER_NO']) ?>
                                    </a>
                                </td>

                                <td class="product" title="<?= htmlspecialchars($row['PRODUCT_NAME'], ENT_QUOTES) ?>">
                                    <?= htmlspecialchars($row['PRODUCT_NAME'] ?? 'N/A') ?>
                                </td>
                                
                                <td class="customer">
                                    <?php echo htmlspecialchars($row['CUSTOMER_NAME']); ?>
                                </td>

                                <td class="rating">
                                    <?php echo htmlspecialchars($row['RATING']); ?>
                                </td>

                                <td class="comment" title="<?= htmlspecialchars($row['COMMENTS'] ?? '', ENT_QUOTES) ?>">
                                    <?php if (!empty($row['COMMENTS'])): ?>
                                        <?= htmlspecialchars($row['COMMENTS']); ?>
                                    <?php else: ?>
                                        N/A
                                    <?php endif; ?>
                                </td>

                                <td class="image">
                                    <?php if (!empty($row['REVIEW_IMAGE'])): ?>
                                        <img src="../<?= htmlspecialchars($row['REVIEW_IMAGE']) ?>" alt="Review Image" class="review-image">
                                    <?php else: ?>
                                        <?php echo 'N/A'; ?>
                                    <?php endif; ?>
                                </td>

                                <td class="status">
                                    <span class="badge status-<?= strtolower($row['REVIEW_STATUS']) ?>">
                                        <?= htmlspecialchars($row['REVIEW_STATUS']); ?>
                                    </span>
                                </td>

                                <td class="since">
                                    <?= date("d M Y", strtotime($row['CREATED_AT'])); ?>
                                </td>

                                <td class="replyStatus">
                                    <span class="badge reply-status-<?= strtolower($row['REPLY_STATUS']) ?>">
                                        <?= htmlspecialchars($row['REPLY_STATUS']); ?>
                                    </span>
                                </td>

                                <td>
                                    <div class="action-wrapper">
                                        <button class="action-btn">
                                            <i class="bi bi-three-dots"></i>
                                        </button>

                                        <div class="action-menu">
                                            <a href="view_review.php?review_id=<?= $row['REVIEW_ID'] ?>" class="view-btn">
                                                <i class="bi bi-eye"></i>View
                                            </a>

                                            <button class="reply-btn" onclick="openReplyModal(<?= $row['REVIEW_ID'] ?>)">
                                                <i class="bi bi-reply"></i>Reply
                                            </button>

                                            <button class="update-btn" onclick="openStatusModal(<?= $row['REVIEW_ID'] ?>, '<?= $row['REVIEW_STATUS'] ?>')">
                                                <i class="bi bi-arrow-repeat"></i>Update Status
                                            </button>

                                            <button class="reply-btn" onclick="openReplyListModal(<?= $row['REVIEW_ID'] ?>)">
                                                <i class="bi bi-chat-dots"></i>View Replies
                                            </button> 
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>

            <!-- Reply to Review Pop Up -->
            <div id="replyModal" class="modal">
                <div class="modal-content">
                    <h3>Reply Review</h3>

                    <form id="replyForm">
                        <input type="hidden" name="review_id" id="replyReviewId">
                        <textarea name="reply" id="modalReply" maxlength="300" required style="width: 100%; height: 80px; margin-top: 5px; padding: 8px; border-radius: 5px; border: 1px solid #ccc;"></textarea>
                        <div><small>Maximum 300 characters</small></div>
                        <div>
                            <button type="submit" class="save-btn">Send</button>
                            <button type="button" onclick="closeReplyModal()">Cancel</button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Status Update Pop Up -->
            <div id="statusModal" class="modal">
                <div class="modal-content">
                    <h3>Update Review Status</h3>

                    <form id="statusForm">
                        <input type="hidden" name="review_id" id="statusReviewId">

                        <select name="status" id="modalStatus" required>
                            <option value="Hide">Hide</option>
                            <option value="Unhide">Unhide</option>
                        </select>

                        <div>
                            <button type="submit" class="save-btn">Save</button>
                            <button type="button" onclick="closeModal()">Cancel</button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- View Reply Pop Up -->
            <div id="replyListModal" class="modal">
                <div class="modal-content">
                   <h3>Reply List</h3>

                   <div id="replyListContainer"></div>

                   <button onclick="closeReplyListModal()">Close</button>
                </div>
            </div>

            <div class="mgmt-pagination">
                <div class="limit-selector">
                    <form method="GET">
                        <input type="hidden" name="search" value="<?= htmlspecialchars($search) ?>">
                        <input type="hidden" name="status" value="<?= htmlspecialchars($status) ?>">
                        <input type="hidden" name="replyStatus" value="<?= htmlspecialchars($replyStatus) ?>">
                        <input type="hidden" name="rating" value="<?= htmlspecialchars($rating) ?>">
                        <input type="hidden" name="page" value="1"> 
                        <label>Rows per page</label>
                        <select name="limit" id="page-limit-select" class="page-limit">
                            <option value="5" <?= $limit==5?'selected':'' ?>>5</option>
                            <option value="10" <?= $limit==10?'selected':'' ?>>10</option>
                            <option value="30" <?= $limit==30?'selected':'' ?>>30</option>
                            <option value="50" <?= $limit==50?'selected':'' ?>>50</option>
                            <option value="100" <?= $limit==100?'selected':'' ?>>100</option>
                        </select>
                    </form>
                </div>

                <?php
                    $range = 2; 

                    $start = max(1, $page - $range);
                    $end = min($totalPages, $page + $range);
                ?>
                <div class="pagination-left">
                    <div class="page-controls">
                    <!-- Prev -->
                    <?php if ($page > 1): ?>
                        <a class="page-btn" href="<?= buildUrl($page-1,$search,$status,$replyStatus,$rating,$fromDate,$toDate,$limit,$sort,$order) ?>">
                            ◀ Prev
                        </a>
                    <?php endif; ?>

                    <!-- First page -->
                    <?php if ($start > 1): ?>
                        <a class="page-num" href="<?= buildUrl(1,$search,$status,$replyStatus,$rating,$fromDate,$toDate,$limit,$sort,$order) ?>">1</a>

                        <?php if ($start > 2): ?>
                            <span class="page-ellipsis">...</span>
                        <?php endif; ?>
                    <?php endif; ?>

                    <!-- Middle pages -->
                    <?php for ($i = $start; $i <= $end; $i++): ?>
                        <a class="page-num <?= $i==$page ? 'active' : '' ?>" href="<?= buildUrl($i,$search,$status,$replyStatus,$rating,$fromDate,$toDate,$limit,$sort,$order) ?>">
                            <?= $i ?>
                        </a>
                    <?php endfor; ?>

                    <!-- Last page -->
                    <?php if ($end < $totalPages): ?>

                        <?php if ($end < $totalPages - 1): ?>
                            <span class="page-ellipsis">...</span>
                        <?php endif; ?>

                        <a class="page-num" href="<?= buildUrl($totalPages,$search,$status,$replyStatus,$rating,$fromDate,$toDate,$limit,$sort,$order) ?>">
                            <?= $totalPages ?>
                        </a>
                    <?php endif; ?>

                    <!-- Next -->
                    <?php if ($page < $totalPages): ?>
                        <a class="page-btn" href="<?= buildUrl($page+1,$search,$status,$replyStatus,$rating,$fromDate,$toDate,$limit,$sort,$order) ?>">
                            Next ▶
                        </a>
                    <?php endif; ?>
                    </div>
                </div>

                <div class="pagination-right">
                    <form method="GET" class="jump-page-form">
                    <input type="hidden" name="search" value="<?= htmlspecialchars($search) ?>">
                    <input type="hidden" name="status" value="<?= htmlspecialchars($status) ?>">
                    <input type="hidden" name="replyStatus" value="<?= htmlspecialchars($replyStatus) ?>">
                    <input type="hidden" name="rating" value="<?= htmlspecialchars($rating) ?>">
                    <input type="hidden" name="fromDate" value="<?= htmlspecialchars($fromDate) ?>">
                    <input type="hidden" name="toDate" value="<?= htmlspecialchars($toDate) ?>">
                    <input type="hidden" name="limit" value="<?= htmlspecialchars($limit) ?>">
                    <input type="hidden" name="sort" value="<?= htmlspecialchars($sort) ?>">
                    <input type="hidden" name="order" value="<?= htmlspecialchars($order) ?>">

                    <input type="number" name="page" min="1" max="<?= $totalPages ?>" value="<?= $page ?>" class="jump-input" placeholder="Page">

                    <button type="submit" class="jump-btn">Go</button>

                    <span class="jump-total">
                        / <?= $totalPages ?>
                    </span>
                    </form>
                </div>

            </div>
        </div>

      </div>
  </div>

<script>
// Search auto submit
const searchInput = document.querySelector(".mgmt-search");
let timeout = null;
if (searchInput) {
    searchInput.addEventListener("input", function () {
        clearTimeout(timeout);

        timeout = setTimeout(() => {
            document.querySelector(".mgmt-ctrl form").submit();
        }, 500);
    });
}

// Clear search + filters
const clearCtrlBtn = document.getElementById("clear-ctrl-btn");
if (clearCtrlBtn) {
    clearCtrlBtn.addEventListener("click", function () {
        const url = new URL(window.location.href);
        url.searchParams.delete("search");
        url.searchParams.delete("status");
        url.searchParams.delete("replyStatus");
        url.searchParams.delete("rating");
        url.searchParams.delete("fromDate");
        url.searchParams.delete("toDate");
        window.location.href = url.toString();
    });
}

// Toggle column menu
const toggleColBtn = document.getElementById("toggle-col-btn");
const toggleColMenu = document.getElementById("toggle-col-menu");

if (toggleColBtn && toggleColMenu) {
    toggleColBtn.addEventListener("click", function (e) {
        e.stopPropagation();
        toggleColMenu.classList.toggle("show");
    });

    toggleColMenu.addEventListener("click", function (e) {
        e.stopPropagation();
    });
}

// apply column visibility
function applyColumn(colClass, show) {
    document.querySelectorAll("." + colClass).forEach(el => {
        el.style.display = show ? "" : "none";
    });
}

// initialize column visibility based on localStorage
document.querySelectorAll("#toggle-col-menu input").forEach(cb => {
    const key = "col_" + cb.dataset.col;
    const saved = localStorage.getItem(key);

    if (saved !== null) {
        cb.checked = saved === "true";
    }

    applyColumn(cb.dataset.col, cb.checked);

    cb.addEventListener("change", function () {
        localStorage.setItem(key, this.checked);
        applyColumn(this.dataset.col, this.checked);
    });
});

// reset columns
const resetColBtn = document.getElementById("reset-col-btn");

if (resetColBtn) {
    resetColBtn.addEventListener("click", function () {
        document.querySelectorAll("#toggle-col-menu input").forEach(cb => {
            cb.checked = true;
            localStorage.removeItem("col_" + cb.dataset.col);
            applyColumn(cb.dataset.col, true);
        });
    });
}

// action menu toggle
document.querySelectorAll(".action-btn").forEach(btn => {
    btn.addEventListener("click", function (e) {
        e.stopPropagation();

        const menu = this.nextElementSibling;
        const isShowing = menu.classList.contains("show");

        document.querySelectorAll(".action-menu").forEach(m => {
            m.classList.remove("show");
        });

        if (!isShowing) {
            menu.classList.add("show");
        }
    });
});

// close menus on outside click
document.addEventListener("click", function (e) {
    document.querySelectorAll(".action-menu").forEach(menu => {
        menu.classList.remove("show");
    });

    if (toggleColMenu) {
        toggleColMenu.classList.remove("show");
    }
});

// page limit elements
const limitSelect = document.getElementById("page-limit-select");

// page limit persistence
document.addEventListener('DOMContentLoaded', () => {
    const url = new URL(window.location.href);
    const urlLimit = url.searchParams.get('limit');
    const savedLimit = localStorage.getItem("preferred_limit");

    if (!urlLimit && savedLimit) {
        if (!sessionStorage.getItem("limit_applied")) {
            sessionStorage.setItem("limit_applied", "true");

            url.searchParams.set('limit', savedLimit);
            url.searchParams.delete("page");
            url.searchParams.set("page", 1);

            window.location.replace(url.toString());
        }
    } else {
        sessionStorage.removeItem("limit_applied");
    }
});

// Change page limit
if (limitSelect) {
    limitSelect.addEventListener("change", function () {
        const selectedValue = this.value;

        localStorage.setItem("preferred_limit", selectedValue);

        const url = new URL(window.location.href);
        url.searchParams.set("limit", selectedValue);
        url.searchParams.set("page", 1);

        window.location.href = url.toString();
    });
}

// REPLY TO REVIEW POP UP - OPEN
function openReplyModal(reviewId) {
    const modal = document.getElementById("replyModal");
    if(modal) {
        modal.style.display = "block";
        document.getElementById("replyReviewId").value = reviewId;
    }
}

// REPLY TO REVIEW POP UP - CLOSE
function closeReplyModal() {
    const modal = document.getElementById("replyModal");
    if(modal) {
        modal.style.display = "none";
    }
}

// SUBMIT review reply
const replyForm = document.getElementById("replyForm");
if (replyForm) {
    replyForm.addEventListener("submit", function(e) {
        e.preventDefault();

        const formData = new FormData(this);

        fetch("reply_review.php", {
            method: "POST",
            body: formData
        })
        .then(res => res.text())
        .then(data => {
            alert("Reply sent successfully!");
            closeReplyModal();
            location.reload(); 
        })
        .catch(err => {
            alert("Error sending reply");
            console.error(err);
        });
    });
}

// REVIEW TO REPLY - Listen for clicks anywhere on the window
window.addEventListener("click", function (e) {
    const modal = document.getElementById("replyModal");
    if (e.target === modal) {
        closeReplyModal();
    }
});

// UPDATE STATUS POP UP - OPEN
function openStatusModal(reviewId, currentStatus) {
    document.getElementById("statusModal").style.display = "block";

    document.getElementById("statusReviewId").value = reviewId;
    document.getElementById("modalStatus").value = currentStatus;
}

// UPDATE STATUS POP UP - CLOSE
function closeModal() {
    document.getElementById("statusModal").style.display = "none";
}

// UPDATE STATUS (submit via AJAX)
document.getElementById("statusForm").addEventListener("submit", function(e){
    e.preventDefault();

    const formData = new FormData(this);

    fetch("update_review.php", {
        method: "POST",
        body: formData
    })
    .then(res => res.text())
    .then(data => {
        if (data.trim() === "success") {
            showToast("success", "Status updated successfully!");
            closeModal();
            setTimeout(() => {
                location.reload();
            }, 800);
        } else {
            showToast("error", "Update failed");
        }
    })
    .catch(err => {
        showToast("Error updating status");
        console.error(err);
    });
});

// UPDATE STATUS - Listen for clicks anywhere on the window
window.addEventListener("click", function (e) {
    const modal = document.getElementById("statusModal");

    if (e.target === modal) {
        closeModal();
    }
});

// DELETE REPLY LIST
function deleteReply(replyId) {
    if (!replyId) {
        alert("No reply found");
        return;
    }

    if (!confirm("Are you sure you want to delete this reply?")) {
        return;
    }

    const formData = new FormData();
    formData.append("reply_id", replyId);

    fetch("delete_reply.php", {
        method: "POST",
        body: formData
    })
    .then(res => res.text())
    .then(data => {
        alert(data);
        location.reload();
    })
    .catch(err => {
        alert("Error deleting reply");
        console.error(err);
    });
}

// VIEW REPLY LIST POP UP - OPEN
function openReplyListModal(reviewId) {
    document.getElementById("replyListModal").style.display = "block";

    fetch("get_reply_list.php?review_id=" + reviewId)
        .then(res => res.text())
        .then(html => {
            document.getElementById("replyListContainer").innerHTML = html;
        });
}

// VIEW REPLY LIST POP UP - CLOSE
function closeReplyListModal() {
    document.getElementById("replyListModal").style.display = "none";
}

// VIEW REPLY LIST - Listen for clicks anywhere on the window
window.addEventListener("click", function (e) {
    const modal = document.getElementById("replyListModal");

    if (e.target === modal) {
        closeReplyListModal();
    }
});

// Show Toast
function showToast(type, message) {
    const toast = document.createElement("div");
    toast.className = "toast " + type;

    const text = document.createElement("span");
    text.innerText = message;

    const closeBtn = document.createElement("span");
    closeBtn.innerHTML = "×";
    closeBtn.className = "toast-close-btn";

    toast.appendChild(text);
    toast.appendChild(closeBtn);

    document.body.appendChild(toast);

    let removed = false;

    closeBtn.addEventListener("click", (e) => {
        e.stopPropagation(); 
        removeToast();
    });

    toast.addEventListener("click", removeToast);

    function removeToast() {
        if (removed) return;
        removed = true;

        toast.style.opacity = "0";
        toast.style.transform = "translateX(100%)";

        setTimeout(() => toast.remove(), 300);
    }

    const duration = type === "error" ? 8000 : 3000;

    setTimeout(removeToast, duration);
}
</script>

</body>
</html>