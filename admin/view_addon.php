<?php
require_once("config.php");
session_start();

// check admin 
if (!isset($_SESSION['admin_id'])) {
    header("Location: admin_login.php");
    exit;
} 
$admin_id = $_SESSION['admin_id'];

// Get addon id
$addonId = $_GET['addon_id'] ?? null;

if (!$addonId) {
    die("Invalid Add-on ID");
}

// Get addon info
$stmt = $conn->prepare("
    SELECT *
    FROM add_on
    WHERE ADD_ON_ID = ? AND IS_DELETED = 0
");

$stmt->bind_param("i", $addonId);
$stmt->execute();

$addon = $stmt->get_result()->fetch_assoc();

if (!$addon) {
    die("Add-on not found");
}

// Get assigned product
$productStmt = $conn->prepare("
    SELECT p.PRODUCT_ID, p.PRODUCT_NAME
    FROM product_addon pa
    JOIN product p ON pa.PRODUCT_ID = p.PRODUCT_ID
    WHERE pa.ADD_ON_ID = ?
");

$productStmt->bind_param("i", $addonId);
$productStmt->execute();

$products = $productStmt->get_result();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Add-on Details</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="admin_global.css">
    <link rel="stylesheet" href="global_view_form.css">
<style>
/* PRODUCT TAGS */
.product-list{
    display:flex;
    flex-wrap:wrap;
    gap:8px;
    padding-top:6px;
}

/* TAG */
.tag{
    background:#f3f4f6;
    border:1px solid #e5e7eb;
    padding:6px 12px;
    border-radius:999px;
    font-size:13px;
    color:#374151;
    transition:0.2s;
    cursor:default;
    text-decoration:none;
    display:inline-block;
}

/* +MORE TAG */
.tag.more{
    background:var(--sec-background-color);
    color:var(--text-primary);
    border:1px solid var(--primary-dark);
    cursor:pointer;
}

.tag.more:hover{
    background:var(--primary-dark);
    color:var(--text-secondary);
    cursor:pointer;
}

/* EMPTY STATE */
.product-empty{
    font-size:13px;
    color:#9ca3af;
    font-style:italic;
}

/* MODAL BACKDROP */
.modal{
    display:none;
    position:fixed;
    inset:0;
    background:rgba(17,24,39,0.6);
    justify-content:center;
    align-items:center;
    z-index:999;
}

/* MODAL BOX */
.modal-content{
    background:#fff;
    width:520px;
    max-height:75vh;
    border-radius:16px;
    overflow:hidden;
    box-shadow:0 20px 60px rgba(0,0,0,0.25);
}

/* MODAL HEADER */
.modal-header{
    display:flex;
    justify-content:space-between;
    align-items:center;
    padding:16px 18px;
    border-bottom:1px solid #f1f3f6;
}

.modal-header h3{
    margin:0;
    font-size:16px;
    font-weight:700;
}

/* CLOSE */
.close{
    font-size:22px;
    cursor:pointer;
    color:#6b7280;
}

.close:hover{
    color:#111827;
}

/* SEARCH BOX */
.modal-search{
    padding:12px 16px;
    border-bottom:1px solid #f1f3f6;
}

.modal-search input{
    width:100%;
    padding:10px 12px;
    border-radius:10px;
    border:1px solid #e5e7eb;
    outline:none;
    font-size:14px;
    transition:0.2s;
}

.modal-search input:focus{
    border-color:#4f46e5;
    box-shadow:0 0 0 3px rgba(79,70,229,0.1);
}

/* MODAL BODY */
.modal-body{
    padding:10px;
    max-height:55vh;
    overflow-y:auto;
}

/* ITEM */
.modal-item{
    padding:10px 12px;
    border-radius:10px;
    font-size:14px;
    transition:0.2s;
    border:1px solid transparent;
    display:block;
    text-decoration:none;
    color:#374151;
}

.modal-item:hover{
    background:#f9fafb;
    border-color:#e5e7eb;
}

.addon-link{
    text-decoration:none;
    display:inline-block;
}

.addon-link:hover{
    background:#e5e7eb;
    transform:translateY(-1px);
}
</style>
</head>

<body>
<div class="form-wrapper">

    <a href="manage_addon.php" class="form-back-link" title="Go Back To Addon Page">← Back</a>

    <div class="container">
        <div class="header-top">
            <div class="title">Add-on Details</div>

            <a href="edit_addon.php?addon_id=<?= $addonId ?>" class="edit-btn">
               <i class="bi bi-pencil-square"></i>Edit
            </a>
        </div>

        <div class="row">
            <span class="label">Name:</span>
            <?= htmlspecialchars($addon['ADD_ON_NAME']) ?>
        </div>

        <div class="row">
            <span class="label">Status:</span>

            <?php if ($addon['ADD_ON_STATUS'] == 'Active') { ?>
                <span class="badge status-active">Active</span>
            <?php } else { ?>
                <span class="badge status-inactive">Inactive</span>
            <?php } ?>
        </div>

        <div class="row">
            <span class="label">Price:</span>
            RM <?= number_format($addon['ADD_ON_PRICE'], 2) ?>
        </div>

        <div class="row">
            <span class="label">Stock:</span>
            <?= $addon['ADD_ON_STOCK'] ?>
        </div>

        <div class="row">
            <span class="label">Image:</span><br>

            <?php if (!empty($addon['ADD_ON_IMAGE'])) { ?>
                <img src="<?= $addon['ADD_ON_IMAGE'] ?>" alt="Add-on Image">
            <?php } else { ?>
                <span>N/A</span>
            <?php } ?>
        </div>

        <div class="row">
            <span class="label">Assigned Products:</span>

            <div class="product-box">

            <?php 
                $productsArray = [];
                while ($p = $products->fetch_assoc()) {
                    $productsArray[] = [
                        'id' => $p['PRODUCT_ID'],
                        'name' => $p['PRODUCT_NAME']
                    ];
                }

                $total = count($productsArray);
                $limit = 5;
            ?>

            <?php if ($total > 0) { ?>

                <div class="product-list">
                    <?php for ($i = 0; $i < min($limit, $total); $i++) { ?>
                        <a href="view_product.php?product_id=<?= $productsArray[$i]['id'] ?>" class="tag addon-link">
                            <?= htmlspecialchars($productsArray[$i]['name']) ?>
                        </a>
                    <?php } ?>

                    <?php if ($total > $limit) { ?>
                        <span class="tag more" onclick="openModal()">
                            +<?= $total - $limit ?> more
                        </span>
                    <?php } ?>
                </div>

            <?php } else { ?>

                <span class="product-empty">No products assigned</span>
            <?php } ?>
        </div>
    </div>

    <!-- ASSIGNED PRODUCT MODAL -->
    <div id="productModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>All Assigned Products</h3>
                <span class="close" onclick="closeModal()">&times;</span>
            </div>

        <!-- SEARCH BOX -->
            <div class="modal-search">
                <input type="text" id="productSearch" placeholder="Search product...">
            </div>

            <div class="modal-body">
                <div class="modal-list">
                    <?php foreach ($productsArray as $product) { ?>
                        <a href="view_product.php?product_id=<?= $product['id'] ?>" class="modal-item product-item">
                            <?= htmlspecialchars($product['name']) ?>
                        </a>
                    <?php } ?>
                </div>
            </div>
        </div>
    </div>

</div>

<script>
// Open assigned product modal
function openModal(){
    document.getElementById("productModal").style.display = "flex";
}

// Close assigned product modal
function closeModal(){
    document.getElementById("productModal").style.display = "none";
}

// click outside to close
window.onclick = function(event){
    let modal = document.getElementById("productModal");
    if(event.target === modal){
        modal.style.display = "none";
    }
}

// Product Search Filter
document.addEventListener("DOMContentLoaded", function () {

    const searchInput = document.getElementById("productSearch");
    const items = document.querySelectorAll(".product-item");

    searchInput.addEventListener("input", function () {

        const keyword = this.value.toLowerCase();

        items.forEach(item => {

            const text = item.textContent.toLowerCase();

            if (text.includes(keyword)) {
                item.style.display = "block";
            } else {
                item.style.display = "none";
            }

        });
    });
});
</script>

</body>
</html>