<?php
require_once("config.php");
session_start();

if (!isset($_SESSION['admin_id'])) {
    header("Location: admin_login.php");
    exit;
} 
$admin_id = $_SESSION['admin_id'];

$productId = $_GET['product_id'] ?? 0;

if (!$productId || !is_numeric($productId)) {
    die("Invalid Product ID");
}

// Fetch product itself
$stmtP = $conn->prepare("
    SELECT * FROM product 
    WHERE PRODUCT_ID = ? AND IS_DELETED = 0
");
$stmtP->bind_param("i", $productId);
$stmtP->execute();
$product = $stmtP->get_result()->fetch_assoc();

if (!$product) {
    header("Location: manage_product.php");
    exit;
}

// Fetch product variants
$stmtVar = $conn->prepare("
    SELECT * FROM product_variant 
    WHERE PRODUCT_ID = ? AND IS_DELETED = 0
    ORDER BY VARIANT_SIZE ASC
");
$stmtVar->bind_param("i", $productId);
$stmtVar->execute();
$variants = $stmtVar->get_result()->fetch_all(MYSQLI_ASSOC);

// Fetch assigned addon
$stmtPA = $conn->prepare("
    SELECT ADD_ON_ID FROM product_addon 
    WHERE PRODUCT_ID = ?
");
$stmtPA->bind_param("i", $productId);
$stmtPA->execute();
$linkedAddons = [];
$paResult = $stmtPA->get_result();
while ($row = $paResult->fetch_assoc()) {
    $linkedAddons[] = $row['ADD_ON_ID'];
}

// Fetch extra images
$stmtImg = $conn->prepare("
    SELECT * FROM product_images 
    WHERE PRODUCT_ID = ? AND IS_DELETED = 0
");
$stmtImg->bind_param("i", $productId);
$stmtImg->execute();
$extraImages = $stmtImg->get_result()->fetch_all(MYSQLI_ASSOC);

// Fetch all categories & addons
$categories = $conn->query("SELECT * FROM category WHERE IS_DELETED = 0");
$all_addons = $conn->query("SELECT * FROM add_on WHERE IS_DELETED = 0");

$errors = [];

// AJAX: Check duplicate name - frontend validation - used for real-time validation on name input
// Cannot edit product with same name
if (isset($_POST['ajax_check_name'])) {

    $name = trim($_POST['name'] ?? '');
    $id   = intval($_POST['current_id'] ?? 0);

    if ($name === "") {
        echo "ok";
        exit;
    }

    $stmt = $conn->prepare("
        SELECT PRODUCT_ID 
        FROM product 
        WHERE PRODUCT_NAME = ? 
        AND IS_DELETED = 0
        AND PRODUCT_ID != ?
    ");
    $stmt->bind_param("si", $name, $id);
    $stmt->execute();

    echo ($stmt->get_result()->num_rows > 0) ? "exists" : "ok";
    exit;
}

// Update product process or preparation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['ajax_check_name'])) {

    // Get inputs
    $name         = trim($_POST['name'] ?? '');
    $categoryId   = $_POST['category_id'] ?? '';
    $status       = $_POST['status'] ?? '';
    $des          = $_POST['description'] ?? "";
    $ingredients  = $_POST['ingredients'] ?? "";
    $allergen     = $_POST['allergen'] ?? "";
    $allowWriting = $_POST['allow_writing'] ?? '';

    // Name required
    if ($name === "") {
        $errors[] = "Product name required";
    }

    // Category ID must be numeric (foreign key)
    if (!is_numeric($categoryId)) {
        $errors[] = "Invalid category";
    }

    // Status must be either Active or Inactive
    if (!in_array($status, ['Active','Inactive'])) {
        $errors[] = "Invalid status";
    }

    // Allow Writing must be either 0 or 1
    if (!in_array($allowWriting, ['0','1'])) {
        $errors[] = "Invalid writing option";
    }

    // Check duplicate name - backend validation
    if (empty($errors)) {

        $check = $conn->prepare("
            SELECT PRODUCT_ID 
            FROM product 
            WHERE PRODUCT_NAME = ? 
            AND IS_DELETED = 0
            AND PRODUCT_ID != ?
        ");
        $check->bind_param("si", $name, $productId);
        $check->execute();

        if ($check->get_result()->num_rows > 0) {
            $errors[] = "Product name already exists";
        }
    }

    // Get current COVER image
    $imagePath = $product['COVER_IMAGE'];

    // Remove original COVER image first
    if (isset($_POST['remove_image']) && $_POST['remove_image'] == '1') {

        if ($imagePath && file_exists($imagePath)) {
            unlink($imagePath);
        }

        $imagePath = null;
    }

    // Upload new COVER image
    if (!empty($_FILES['cover']['name'])) {

        $allowedTypes = ['image/jpeg','image/png','image/webp'];
        $maxSize      = 2 * 1024 * 1024;

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $type  = finfo_file($finfo, $_FILES['cover']['tmp_name']);
        finfo_close($finfo);

        $size = $_FILES['cover']['size'];

        if (!in_array($type, $allowedTypes)) {
            $errors[] = "Invalid cover image type (only jpeg, png, webp)";
        }

        if ($size > $maxSize) {
            $errors[] = "Cover image too large (max 2MB)";
        }

        if (empty($errors)) {

            $dir = "uploads/product/";
            if (!is_dir($dir)) mkdir($dir, 0777, true);

            $extMap = [
                'image/jpeg' => 'jpg',
                'image/png'  => 'png',
                'image/webp' => 'webp'
            ];

            $ext  = $extMap[$type];
            $file = time() . "_" . uniqid() . "." . $ext;
            $path = $dir . $file;

            if (!move_uploaded_file($_FILES['cover']['tmp_name'], $path)) {
                $errors[] = "Upload failed";
            } else {
                // delete old cover file
                if ($imagePath && file_exists($imagePath)) {
                    unlink($imagePath);
                }
                $imagePath = $path;
            }
        }
    }

    // Stop if errors
    if (!empty($errors)) {
        echo implode(" | ", $errors);
        exit;
    }

    // Final update product
    $stmt = $conn->prepare("
        UPDATE product SET
            PRODUCT_NAME   = ?,
            CATEGORY_ID    = ?,
            PRODUCT_STATUS = ?,
            PRODUCT_DES    = ?,
            INGREDIENTS    = ?,
            ALLERGEN       = ?,
            ALLOW_WRITING  = ?,
            COVER_IMAGE    = ?
        WHERE PRODUCT_ID = ?
    ");

    $stmt->bind_param(
        "sissssisi",
        $name,
        $categoryId,
        $status,
        $des,
        $ingredients,
        $allergen,
        $allowWriting,
        $imagePath,
        $productId
    );

    if (!$stmt->execute()) {
        echo "Update failed";
        exit;
    }

    // SYNC ADD-ONS, Delete all, re-insert selected
    $delA = $conn->prepare("DELETE FROM product_addon WHERE PRODUCT_ID = ?");
    $delA->bind_param("i", $productId);
    $delA->execute();

    if (!empty($_POST['addons'])) {

        $stmtA = $conn->prepare("
            INSERT INTO product_addon (PRODUCT_ID, ADD_ON_ID)
            VALUES (?,?)
        ");

        foreach ($_POST['addons'] as $aid) {
            if (!is_numeric($aid)) continue;
            $aid = intval($aid);
            $stmtA->bind_param("ii", $productId, $aid);
            $stmtA->execute();
        }
    }

    // VARIANTS (PRODUCTION SAFE SOFT SYNC)
    if (empty($_POST['variant_size'])) {
        echo "At least 1 variant required";
        exit;
    }

    $seenSizes = [];
    $valid = 0;

    // collect submitted sizes
    $submittedSizes = [];

    foreach ($_POST['variant_size'] as $i => $size) {
        $submittedSizes[] = trim($size);
    }

    // Soft delete ONLY missing variants
    if (!empty($submittedSizes)) {

        $placeholders = implode(',', array_fill(0, count($submittedSizes), '?'));
        $types = str_repeat('s', count($submittedSizes));

        $sql = "
            UPDATE product_variant 
            SET 
                IS_DELETED = 1,
                VARIANT_STATUS = 'Inactive'
            WHERE PRODUCT_ID = ? 
            AND VARIANT_SIZE NOT IN ($placeholders)
        ";

        $stmtDel = $conn->prepare($sql);

        $bindTypes = "i" . $types;
        $params = array_merge([$productId], $submittedSizes);

        $stmtDel->bind_param($bindTypes, ...$params);
        $stmtDel->execute();
    }

    // prepare check
    $stmtCheck = $conn->prepare("
        SELECT VARIANT_ID 
        FROM product_variant 
        WHERE PRODUCT_ID = ? 
        AND VARIANT_SIZE = ? 
        LIMIT 1
    ");

    // loop insert/update
    foreach ($_POST['variant_size'] as $i => $size) {

        $size    = trim($size);
        $price   = $_POST['variant_price'][$i]  ?? "";
        $stock   = $_POST['variant_stock'][$i]  ?? "";
        $statusV = $_POST['variant_status'][$i] ?? "Active";

        // Validation
        if ($size === "" || $price === "" || $stock === "") continue;

        if (!ctype_digit($size) || (int)$size <= 0) continue;

        if (!is_numeric($price) || $price < 0) continue;

        if (!ctype_digit(strval($stock))) continue;

        if (!in_array($statusV, ['Active', 'Inactive'])) {
            $statusV = 'Active';
        }

        if (isset($seenSizes[$size])) continue;
        $seenSizes[$size] = true;

        // Check existing
        $stmtCheck->bind_param("is", $productId, $size);
        $stmtCheck->execute();
        $existing = $stmtCheck->get_result()->fetch_assoc();

        if ($existing) {

            // Update & Restore
            $stmtU = $conn->prepare("
                UPDATE product_variant
                SET VARIANT_PRICE = ?,
                    VARIANT_STOCK = ?,
                    VARIANT_STATUS = ?,
                    IS_DELETED = 0
                WHERE VARIANT_ID = ?
            ");

            $stmtU->bind_param("disi", $price, $stock, $statusV, $existing['VARIANT_ID']);
            $stmtU->execute();

        } else {

            // Insert new
            $stmtV = $conn->prepare("
                INSERT INTO product_variant
                (PRODUCT_ID, VARIANT_SIZE, VARIANT_PRICE, VARIANT_STOCK, VARIANT_STATUS, IS_DELETED)
                VALUES (?,?,?,?,?,0)
            ");

            $stmtV->bind_param("iidis", $productId, $size, $price, $stock, $statusV);
            $stmtV->execute();
        }

        $valid++;
    }

    // Final check
    if ($valid === 0) {
        echo "At least 1 valid variant required";
        exit;
    }

    // Soft delete marked extra images
    if (!empty($_POST['remove_image_ids'])) {

        $delImg = $conn->prepare("
            UPDATE product_images 
            SET IS_DELETED = 1 
            WHERE IMAGE_ID = ? AND PRODUCT_ID = ?
        ");

        foreach ($_POST['remove_image_ids'] as $imgId) {

            if (!is_numeric($imgId) || $imgId === "") continue;

            $imgId = intval($imgId);
            $delImg->bind_param("ii", $imgId, $productId);
            $delImg->execute();
        }
    }

    // Upload new extra images
    if (!empty($_FILES['images']) && !empty($_FILES['images']['name'])) {

        $allowedTypes = ['image/jpeg','image/png','image/webp'];
        $maxSize      = 2 * 1024 * 1024;
        $dir          = "uploads/product/";

        if (!is_dir($dir)) mkdir($dir, 0777, true);

        $stmtI = $conn->prepare("
            INSERT INTO product_images (PRODUCT_ID, IMAGE_PATH, IS_DELETED)
            VALUES (?, ?, 0)
        ");

        foreach ($_FILES['images']['name'] as $i => $n) {

            if ($_FILES['images']['error'][$i] !== UPLOAD_ERR_OK) continue;

            $tmp  = $_FILES['images']['tmp_name'][$i];
            $size = $_FILES['images']['size'][$i];

            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $type  = finfo_file($finfo, $tmp);
            finfo_close($finfo);

            if (!in_array($type, $allowedTypes)) continue;
            if ($size > $maxSize) continue;

            $extMap = [
                'image/jpeg' => 'jpg',
                'image/png'  => 'png',
                'image/webp' => 'webp'
            ];

            $ext  = $extMap[$type];
            $file = time() . "_" . uniqid() . "." . $ext;
            $path = $dir . $file;

            if (move_uploaded_file($tmp, $path)) {
                $stmtI->bind_param("is", $productId, $path);
                $stmtI->execute();
            }
        }
    }

    echo "ok";
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Product</title>
    <link rel="stylesheet" href="admin_global.css">
    <link rel="stylesheet" href="global_add_edit_form.css">
<style>
.form-wrapper {
    max-width: 1000px;
}

.form-container2 {
    display: flex;
    gap: 30px;
    align-items: flex-start;
    margin-bottom: 20px;
}

.form-left  { 
    flex: 2; 
}

.form-right {
    flex: 1;
    background: #fafafa;
    padding: 15px;
    border-radius: 8px;
    border: 1px solid #eee;
}

.form-pro-title { 
    margin-bottom: 20px; 
}

.variant-size {
    display: flex;
    align-items: center;
    gap: 6px;
}

.unit {
    white-space: nowrap;
    font-size: 14px;
    color: #666;
}

/* Addon UI */
.addon-list {
    display: flex;
    flex-direction: column;
    max-height: 220px;
    overflow-y: auto;
    padding-right: 5px;
}

.addon-list label {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 5px 8px;
    border: 1px solid #eee;
    border-radius: 6px;
    background: #fff;
    cursor: pointer;
    font-size: 14px;
    margin: 0;
}

.addon-list label:hover { 
    background: #f6f6f6; 
}

.addon-list input[type="checkbox"] { 
    transform: scale(1.1); 
}

.addon-actions { 
    display: flex; gap: 8px; 
}

.addon-actions button {
    padding: 6px 10px;
    border: none;
    border-radius: 6px;
    cursor: pointer;
    font-size: 13px;
    background: #eee;
}

.addon-actions button:hover { 
    background: #ddd; 
}

/* Variant UI */
#variantBox {
    display: flex;
    flex-direction: column;
    gap: 12px;
    margin-top: 10px;
}

#variantBox > div {
    background: #fff;
    border: 1px solid #e5e5e5;
    padding: 12px;
    border-radius: 8px;
    position: relative;
    transition: 0.2s;
}

#variantBox > div:hover {
    box-shadow: 0 2px 8px rgba(0,0,0,0.05);
}

.variant-size,
#variantBox input,
#variantBox select { 
    width: 100%; 
}

#variantBox input,
#variantBox select {
    padding: 8px;
    margin-top: 3px;
    margin-bottom: 3px;
    border: 1px solid #ddd;
    border-radius: 6px;
    font-size: 14px;
}

.unit { 
    font-size: 15px; 
    font-weight: bold; 
}

#variantBox button {
    position: absolute;
    top: 0px;
    right: 0px;
    border: none;
    background: #ff4d4d;
    color: white;
    font-size: 12px;
    padding: 2px 5px;
    border-radius: 50px;
    cursor: pointer;
}

#variantBox button:hover { 
    background: #e60000; 
}

.add-variant-btn {
    margin-top: 12px;
    padding: 10px 14px;
    border: none;
    border-radius: 8px;
    background: var(--primary-light);
    color: var(--primary-dark);
    font-size: 14px;
    cursor: pointer;
    transition: 0.2s;
    width: 100%;
}

.add-variant-btn:hover { 
    background:var(--primary-dark); color:var(--primary-light); 
}

.add-variant-btn:active { 
    transform: scale(0.98); 
}

.smallAlert { 
    font-size: 12px; color: #8e8d8d; 
}

.form-line  { 
    margin-bottom: 10px; 
}

/* Existing images */
.existing-images {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    margin-top: 8px;
}

.existing-img-wrap {
    position: relative;
    display: inline-block;
}

.existing-img-wrap img {
    width: 100px;
    height: 100px;
    object-fit: cover;
    border-radius: 6px;
    border: 1px solid #ddd;
    display: block;
}

.remove-existing {
    position: absolute;
    top: 2px;
    right: 2px;
    background: #ff4d4d;
    color: white;
    border: none;
    border-radius: 50%;
    width: 20px;
    height: 20px;
    font-size: 11px;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 0;
    line-height: 1;
}

.existing-img-wrap.marked { 
    opacity: 0.35; 
}

.existing-img-wrap.marked img { 
    filter: grayscale(1); 
}

.cover-preview-wrap {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-top: 6px;
}

.cover-preview-wrap img {
    width: 100px;
    height: 100px;
    object-fit: cover;
    border-radius: 6px;
    border: 1px solid #ddd;
}
</style>
</head>

<body>

<div class="form-wrapper">

    <a href="manage_product.php" class="form-back-link" title="Go Back To Product Page">← Back</a>

    <form method="POST" enctype="multipart/form-data" id="form">

    <div class="form-container">

        <div class="form-container2">

            <div class="form-left">

                <h2 class="form-pro-title">Edit Product</h2>

                <div class="form-group">
                    <label>Name<span class="asterisk">*</span></label>
                    <input type="text" id="nameInput" name="name" class="form-input" maxlength="50"
                           value="<?= htmlspecialchars($product['PRODUCT_NAME']) ?>">
                    <span id="nameError" class="error"></span>
                </div>

                <div class="form-group">
                    <label>Category<span class="asterisk">*</span></label>
                    <select name="category_id" class="form-input">
                        <?php
                        $categories->data_seek(0);
                        while ($c = $categories->fetch_assoc()):
                        ?>
                        <option value="<?= $c['CATEGORY_ID'] ?>"
                            <?= $c['CATEGORY_ID'] == $product['CATEGORY_ID'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($c['CATEGORY_NAME']) ?>
                        </option>
                        <?php endwhile; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label>Status<span class="asterisk">*</span></label>
                    <select name="status" class="form-input">
                        <option value="Active"   <?= $product['PRODUCT_STATUS'] === 'Active'   ? 'selected' : '' ?>>Active</option>
                        <option value="Inactive" <?= $product['PRODUCT_STATUS'] === 'Inactive' ? 'selected' : '' ?>>Inactive</option>
                    </select>
                </div>

                <div class="form-group">
                    <label>Allow Writing<span class="asterisk">*</span></label>
                    <select name="allow_writing" class="form-input">
                        <option value="1" <?= $product['ALLOW_WRITING'] == 1 ? 'selected' : '' ?>>Yes</option>
                        <option value="0" <?= $product['ALLOW_WRITING'] == 0 ? 'selected' : '' ?>>No</option>
                    </select>
                </div>

                <div class="form-group">
                    <label>Description</label>
                    <textarea name="description" maxlength="300" class="form-input form-textarea" placeholder="Enter product description (e.g., A delicious and ...)"><?= htmlspecialchars($product['PRODUCT_DES']) ?></textarea>
                    <div class="text-cont">
                        <span class="text-hint">Maximum 300 characters</span>
                    </div>
                </div>

                <div class="form-group">
                    <label>Ingredients</label>
                    <textarea name="ingredients" maxlength="300" class="form-input form-textarea" placeholder="Enter ingredients (e.g., flour, sugar, etc.)"><?= htmlspecialchars($product['INGREDIENTS']) ?></textarea>
                    <div class="text-cont">
                        <span class="text-hint">Maximum 300 characters</span>
                    </div>
                </div>

                <div class="form-group">
                    <label>Allergen</label>
                    <textarea name="allergen" maxlength="300" class="form-input form-textarea" placeholder="Enter allergen information (e.g., nuts, dairy, etc.)"><?= htmlspecialchars($product['ALLERGEN']) ?></textarea>
                    <div class="text-cont">
                        <span class="text-hint">Maximum 300 characters</span>
                    </div>
                </div>

                <!-- COVER -->
                <div class="form-group">
                    <label>Cover Image</label>
                    <input type="file" name="cover" id="coverInput" class="form-input">
                    <small class="text-hint">Only JPG, PNG, WEBP (Max 2MB)</small>
                    <span id="coverError" class="error"></span>

                    <?php if (!empty($product['COVER_IMAGE'])): ?>
                        <img id="coverPreview" src="<?= $product['COVER_IMAGE'] ?>" style="width:120px; display:block; margin-top:10px;">
                    <?php else: ?>
                        <img id="coverPreview" style="display:none; width:120px; margin-top:10px;">
                    <?php endif; ?>

                    <input type="hidden" name="remove_image" id="removeImageInput" value="0">
                    <button type="button" class="btn-remove" onclick="removeImage('cover')">
                        ✕ Remove Uploaded Image
                    </button>
                </div>

                <!-- EXTRA IMAGES -->
                <div class="form-group">
                    <label>Product Images</label>

                    <input type="file" name="images[]" class="form-input" multiple style="margin-top:10px;">
                    <small class="text-hint">You may select more than one. Only JPG, PNG, WEBP (Max 2MB)</small>
                    <span id="extraError" class="error"></span>
                    <div id="extraPreview"></div>
                    <button type="button" class="btn-remove" title="Clear All Newly Selected Images" onclick="removeImage('extra')">✕ Remove Image</button>
                </div>

            </div>

            <div class="form-right">

                <!-- ADD-ONS -->
                <h3>Add-ons</h3>

                <div class="addon-actions">
                    <button type="button" onclick="toggleAllAddons(true)">Select All</button>
                    <button type="button" onclick="toggleAllAddons(false)">Clear</button>
                </div>

                <div class="addon-list">
                <br>
                    <?php
                    $all_addons->data_seek(0);
                    while ($a = $all_addons->fetch_assoc()):
                        $checked = in_array($a['ADD_ON_ID'], $linkedAddons) ? 'checked' : '';
                    ?>
                    <label>
                        <input type="checkbox" class="addon-checkbox" name="addons[]"
                               value="<?= $a['ADD_ON_ID'] ?>" <?= $checked ?>>
                        <?= htmlspecialchars($a['ADD_ON_NAME']) ?> (RM <?= $a['ADD_ON_PRICE'] ?>)
                    </label>
                    <br>
                    <?php endwhile; ?>
                </div>

                <hr class="form-line">

                <!-- VARIANTS -->
                <h3>Variants<span class="asterisk">*</span></h3>
                <span id="variantError" class="error"></span>
                <div id="variantBox"></div>
                <button type="button" class="add-variant-btn" onclick="addVar()">+ Add Variant</button>

            </div>
        </div>

        <button type="submit" class="form-btn">Save Changes</button>

    </div>

    </form>

</div>

<!-- Pass existing data to JS -->
<script>
const existingVariants  = <?= json_encode($variants) ?>;
const existingImages    = <?= json_encode($extraImages) ?>; 
const currentProductId  = <?= $productId ?>;
</script>

<script>
// Store all product images temporarily (new + existing)
let imageQueue = [];

// Render all image previews
function renderImages() {

    const container = document.getElementById("extraPreview");
    container.innerHTML = "";

    // Loop through all images in queue
    imageQueue.forEach(item => {

        const wrap = document.createElement("div");
        wrap.style.cssText = "display:inline-block;position:relative;margin-right:8px;margin-bottom:8px;";

        const img = document.createElement("img");
        img.style.cssText = "width:100px;height:100px;object-fit:cover;border-radius:6px;border:1px solid #ddd;display:block;";

        // If image is newly uploaded
        if (item.type === "new") {
            // Use FileReader preview
            img.src = item.preview;
        } else {
            // Existing database image
            img.src = item.path;
            // If marked for deletion
            if (item.status === "delete") {
                // Fade image
                wrap.style.opacity = "0.35";
                // Make grayscale
                img.style.filter   = "grayscale(1)";
            }
        }

        const btn = document.createElement("button");
        btn.innerText = "✕";
        btn.type = "button";
        btn.style.cssText = "position:absolute;top:2px;right:2px;background:#ff4d4d;color:white;border:none;border-radius:50%;width:20px;height:20px;font-size:11px;cursor:pointer;";

        btn.onclick = () => {
            if (item.type === "new") {
                imageQueue = imageQueue.filter(i => i.id !== item.id);
            } else {
                item.status = (item.status === "delete") ? "keep" : "delete";
            }
            renderImages();
        };

        // Add image into wrapper
        wrap.appendChild(img);
        // Add remove button into wrapper
        wrap.appendChild(btn);
        // Add wrapper into page
        container.appendChild(wrap);
    });
}

document.addEventListener("DOMContentLoaded", function () {

    // Form
    const form = document.getElementById("form");

    // Inputs & Error message
    const nameInput    = document.getElementById("nameInput");
    const nameError    = document.getElementById("nameError");
    const coverInput   = document.querySelector("input[name='cover']");
    const coverError   = document.getElementById("coverError");
    const imagesInput  = document.querySelector("input[name='images[]']");
    const extraError   = document.getElementById("extraError");
    const variantError = document.getElementById("variantError");

    // Load existing database images into queue
    existingImages.forEach(img => {
        imageQueue.push({
            id: "db_" + img.IMAGE_ID, // Unique frontend id
            type: "existing", // Existing image type
            dbId: img.IMAGE_ID, // Database image id
            path: img.IMAGE_PATH,  // Image path
            status: "keep"  // Default keep status
        });
    });

    // Show existing images immediately
    renderImages(); 

    const MAX_SIZE = 2 * 1024 * 1024;
    const ALLOWED  = ["image/jpeg", "image/png", "image/webp"];

    // Load existing variants
    existingVariants.forEach(v => {
        addVar(v.VARIANT_SIZE, v.VARIANT_PRICE, v.VARIANT_STOCK, v.VARIANT_STATUS);
    });

    // Parse backend response for both AJAX and form submission
    function parseBackendResponse(text) {

        text = text.trim();

        // Success response
        if (text === "ok")     return { success: true,  errors: [] };

        // Duplicate product response
        if (text === "exists") return { success: false, errors: ["Product already exists"] };

        // Split multiple errors
        return { success: false, errors: text.split("|").map(e => e.trim()) };
    }

    // Show backend errors
    function showBackendErrors(errors) {

        nameError.textContent    = "";
        coverError.textContent   = "";
        extraError.textContent   = "";
        variantError.textContent = "";

        let general = [];

        errors.forEach(err => {

            const lower = err.toLowerCase();

            if (lower.includes("name")) {
                nameError.textContent = err;
                nameInput.classList.add("input-error");
            } else if (lower.includes("cover")) {
                coverError.textContent = err;
                coverInput.classList.add("input-error");
            } else if (lower.includes("variant")) {
                variantError.textContent = err;
            } else if (lower.includes("image")) {
                extraError.textContent = err;
                imagesInput.classList.add("input-error");
            } else {
                general.push(err);
            }
        });

        if (general.length > 0) showToast("error", general.join(" | "));
    }

    // Real-time validation for name input
    nameInput.addEventListener("input", function () {

        const name = this.value.trim();

        if (name === "") {
            nameError.textContent = "Name required";
            this.classList.add("input-error");
            return;
        }

        fetch("edit_product.php?product_id=" + currentProductId, {
            method: "POST",
            headers: { "Content-Type": "application/x-www-form-urlencoded" },
            body: "ajax_check_name=1&current_id=" + currentProductId + "&name=" + encodeURIComponent(name)
        })
        .then(res => res.text())
        .then(data => {
            const res = parseBackendResponse(data);
            if (!res.success) {
                nameError.textContent = res.errors[0];
                nameInput.classList.add("input-error");
            } else {
                nameError.textContent = "";
                nameInput.classList.remove("input-error");
            }
        });
    });

    // Real-time validation and preview for cover image
    coverInput.addEventListener("change", function () {

        const file    = this.files[0];
        const preview = document.getElementById("coverPreview");

        coverError.textContent = "";
        this.classList.remove("input-error");

        if (!file) {
            preview.src = "";
            preview.style.display = "none";
            return;
        }

        if (!ALLOWED.includes(file.type) || file.size > MAX_SIZE) {
            coverError.textContent = "Invalid image (JPG/PNG/WEBP, max 2MB)";
            this.classList.add("input-error");
            this.value = "";
            preview.src = "";
            preview.style.display = "none";
            return;
        }

        const reader = new FileReader();
        reader.onload = e => {
            preview.src = e.target.result;
            preview.style.display = "block";
            preview.style.width   = "120px";
        };
        reader.readAsDataURL(file);
    });

    // Real-time validation and preview for extra images
    imagesInput.addEventListener("change", function () {

        const files = Array.from(this.files);

        files.forEach(file => {

            if (!ALLOWED.includes(file.type) || file.size > MAX_SIZE) return;

            const id = "new_" + Date.now() + Math.random();

            const reader = new FileReader();
            reader.onload = e => {

                imageQueue.push({
                    id,
                    type: "new",
                    file,
                    preview: e.target.result
                });

                renderImages();
            };

            reader.readAsDataURL(file);
        });

        this.value = "";
    });

    // Render image previews with remove buttons
    function renderPreview() {
 
        const container = document.getElementById("extraPreview");
        container.innerHTML = "";
 
        selectedFiles.forEach(file => {
 
            const reader = new FileReader();
 
            reader.onload = e => {
 
                const wrapper = document.createElement("div");
                wrapper.style.cssText = "display:inline-block;position:relative;margin-right:8px;";
 
                const img = document.createElement("img");
                img.src = e.target.result;
                img.style.width = "120px";
 
                const btn = document.createElement("button");
                btn.innerText = "✕";
                btn.style.cssText = "position:absolute;top:0;right:0;";
                btn.onclick = () => {
                    selectedFiles = selectedFiles.filter(f => f !== file);
                    renderPreview();
                };
 
                wrapper.appendChild(img);
                wrapper.appendChild(btn);
                container.appendChild(wrapper);
            };
 
            reader.readAsDataURL(file);
        });
    }

    // Form submit
    form.addEventListener("submit", function (e) {

        e.preventDefault();

        let ok = true;

        if (nameInput.value.trim() === "") {
            nameError.textContent = "Name required";
            ok = false;
        }

        if (validateVariantsRealTime()) ok = false;

        if (!ok) {
            showToast("error", "Please fix the errors");
            return;
        }

        // Enable remove flags so they submit
        document.querySelectorAll("input[id^='removeFlag']").forEach(inp => {
            if (inp.value !== "") inp.disabled = false;
        });

        const formData = new FormData(form);

        // new images
        imageQueue.forEach(item => {
            if (item.type === "new") {
                formData.append("images[]", item.file, item.file.name);
            }
        });

        // existing delete list
        imageQueue.forEach(item => {
            if (item.type === "existing" && item.status === "delete") {
                formData.append("remove_image_ids[]", item.dbId);
            }
        });

        fetch("edit_product.php?product_id=" + currentProductId, {
            method: "POST",
            body: formData
        })
        .then(res => res.text())
        .then(data => {
            const res = parseBackendResponse(data);

            if (res.success) {
                showToast("success", "Product updated");
                setTimeout(() => { window.location.href = "manage_product.php"; }, 800);
            } else {
                showBackendErrors(res.errors);
                showToast("error", "Please fix errors");
            }
        })
        .catch(() => showToast("error", "Network error"));
    });

    addVar.isInitialized = true;
});

// Show toast
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

// Add variant row
function addVar(size = "", price = "", stock = "", status = "Active") {

    const box          = document.getElementById("variantBox");
    const variantError = document.getElementById("variantError");

    const div = document.createElement("div");

    div.innerHTML = `
        <p>Size (inch)</p>
        <div class="variant-size">
            <input type="number" name="variant_size[]" min="1" placeholder="Size" value="${size}">
            <span class="error size-error"></span>
        </div>

        <p>Price (RM)</p>
        <input type="number" name="variant_price[]" step="0.01" min="0" placeholder="Price" value="${price}">
        <span class="error price-error"></span>

        <p>Stock</p>
        <input type="number" name="variant_stock[]" min="0" step="1" placeholder="Stock" value="${stock}">
        <span class="error stock-error"></span>

        <p>Status</p>
        <select name="variant_status[]">
            <option ${status === 'Active'   ? 'selected' : ''}>Active</option>
            <option ${status === 'Inactive' ? 'selected' : ''}>Inactive</option>
        </select>

        <button type="button" onclick="removeVar(this)">✕</button>
    `;

    box.appendChild(div);

    div.querySelectorAll("input").forEach(input => {
        input.addEventListener("input", validateVariantsRealTime);
    });

    variantError.textContent = "";
}

// Remove variant row
function removeVar(btn) {
    btn.parentElement.remove();
    validateVariantsRealTime();
}

// Toggle all add-on checkboxes
function toggleAllAddons(state) {
    document.querySelectorAll(".addon-checkbox").forEach(cb => cb.checked = state);
}

// Remove selected images
function removeImage(type) {

    if (type === "cover") {
        document.getElementById("coverInput").value = ""; // Clear file input
        document.getElementById("coverPreview").style.display = "none"; // Hide cover preview image
        document.getElementById("removeImageInput").value = "1";  // Tell backend to remove existing cover image
    }

    if (type === "extra") {

        imageQueue = imageQueue.map(i => {
            if (i.type === "new") { // If newly uploaded image
                return null; // Remove completely from queue
            }

            return { // Existing database image
                ...i, // Keep all original data
                status: "delete" // Mark image for deletion
            };
        }).filter(Boolean);

        renderImages();
    }
}

// MARK EXISTING IMAGE FOR REMOVAL, Click again to undo
function markRemoveImage(imgId) {

    const wrap = document.getElementById("imgWrap" + imgId); // Get image wrapper element
    const flag = document.getElementById("removeFlag" + imgId);  // Get hidden input flag for backend deletion

    if (wrap.classList.contains("marked")) { // If image is already marked for removal
        wrap.classList.remove("marked"); // Undo remove state (unmark)
        flag.value = ""; // Clear hidden input value
        flag.disabled = true;  // Disable input so it won't be submitted
    } else {
        wrap.classList.add("marked"); // Mark image visually as "to be removed"
        flag.value = imgId; // Set image ID for backend deletion
        flag.disabled = false;  // Enable hidden input so it will be submitted
    }
}

// Real-time validation for variants - checks size, price, stock, and duplicates
function validateVariantsRealTime() {

    const rows = document.querySelectorAll("#variantBox > div");
    const set  = new Set();
    const err  = document.getElementById("variantError");
    let hasError = false;

    if (rows.length === 0) {
        err.textContent = "At least 1 variant required";
        return true;
    }

    err.textContent = "";

    rows.forEach(row => {

        const size  = row.querySelector("input[name='variant_size[]']");
        const price = row.querySelector("input[name='variant_price[]']");
        const stock = row.querySelector("input[name='variant_stock[]']");

        const sizeError  = row.querySelector(".size-error");
        const priceError = row.querySelector(".price-error");
        const stockError = row.querySelector(".stock-error");

        const sizeVal  = size.value.trim();
        const priceVal = price.value.trim();
        const stockVal = stock.value.trim();

        // SIZE
        if (sizeVal === "") {
            sizeError.textContent = "Required";
            size.classList.add("input-error");
            hasError = true;
        } else if (!/^\d+$/.test(sizeVal)) {
            sizeError.textContent = "Must be whole number";
            size.classList.add("input-error");
            hasError = true;
        } else if (set.has(sizeVal)) {
            sizeError.textContent = "Duplicate";
            size.classList.add("input-error");
            hasError = true;
        } else {
            set.add(sizeVal);
            sizeError.textContent = "";
            size.classList.remove("input-error");
        }

        // PRICE
        if (priceVal === "") {
            priceError.textContent = "Required";
            price.classList.add("input-error");
            hasError = true;
        } else if (isNaN(priceVal) || Number(priceVal) < 0) {
            priceError.textContent = "Invalid price";
            price.classList.add("input-error");
            hasError = true;
        } else {
            priceError.textContent = "";
            price.classList.remove("input-error");
        }

        // STOCK
        if (stockVal === "") {
            stockError.textContent = "Required";
            stock.classList.add("input-error");
            hasError = true;
        } else if (!/^\d+$/.test(stockVal)) {
            stockError.textContent = "Must be integer";
            stock.classList.add("input-error");
            hasError = true;
        } else {
            stockError.textContent = "";
            stock.classList.remove("input-error");
        }
    });

    return hasError;
}
</script>
</body>
</html>