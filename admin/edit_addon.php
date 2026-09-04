<?php
require_once("config.php");
session_start();

if (!isset($_SESSION['admin_id'])) {
    header("Location: admin_login.php");
    exit;
} 
$admin_id = $_SESSION['admin_id'];

$errors = [];

$addonId = $_GET['addon_id'] ?? 0;

if (!$addonId || !is_numeric($addonId)) {
    die("Invalid Add-on ID");
}

// Fetch addon
$stmt = $conn->prepare("
    SELECT *
    FROM add_on
    WHERE ADD_ON_ID = ?
    AND IS_DELETED = 0
");

$stmt->bind_param("i", $addonId);
$stmt->execute();

$result = $stmt->get_result();

if ($result->num_rows === 0) {
    die("Add-on not found");
}

$addon = $result->fetch_assoc();

// Get current assignment products
$currentAssigned = [];

$getAssign = $conn->prepare("
    SELECT PRODUCT_ID
    FROM product_addon
    WHERE ADD_ON_ID = ?
");

$getAssign->bind_param("i", $addonId);
$getAssign->execute();

$resAssign = $getAssign->get_result();

while ($r = $resAssign->fetch_assoc()) {
    $currentAssigned[] = $r['PRODUCT_ID'];
}

// AJAX: Check duplicate name - frontend validation - used for real-time validation on name input
// Cannot edit add-on with same name
if (isset($_POST['ajax_check_name'])) {

    $name = trim($_POST['name'] ?? '');

    $stmt = $conn->prepare("
        SELECT ADD_ON_ID
        FROM add_on
        WHERE ADD_ON_NAME = ?
        AND ADD_ON_ID != ?
        AND IS_DELETED = 0
    ");

    $stmt->bind_param("si", $name, $addonId);
    $stmt->execute();

    echo ($stmt->get_result()->num_rows > 0) ? "exists" : "ok";
    exit;
}

// Update addon process or preparation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['ajax_check_name'])) {

    // Get inputs
    $name   = trim($_POST['name'] ?? '');
    $price  = $_POST['price'] ?? '';
    $stock  = $_POST['stock'] ?? '';
    $status = $_POST['status'] ?? 'Active';

    $assign_type = $_POST['assign_type'] ?? 'all';

    // Name required
    if ($name === "") {
        $errors[] = "Name required";
    }

    // Price Required + Numeric + Non-negative
    if ($price === "" || !is_numeric($price) || $price < 0) {
        $errors[] = "Valid price required";
    }

    // Stock Required + Integer + Non-negative
    if ($stock === "" || !ctype_digit($stock) || (int)$stock < 0) {
        $errors[] = "Stock must be whole number";
    }

    // Check duplicate name - backend validation
    if (empty($errors)) {

        $check = $conn->prepare("
            SELECT ADD_ON_ID
            FROM add_on
            WHERE ADD_ON_NAME = ?
            AND ADD_ON_ID != ?
            AND IS_DELETED = 0
        ");

        $check->bind_param("si", $name, $addonId);
        $check->execute();

        if ($check->get_result()->num_rows > 0) {
            $errors[] = "Add-on already exists";
        }
    }

    // Assign validation 
    if ($assign_type == "category") {

        $ids = $_POST['category_ids'] ?? [];

        if (empty($ids)) {
            $errors[] = "Please select at least 1 category";
        }
    }

    if ($assign_type == "specific") {

        $ids = $_POST['product_ids'] ?? [];

        if (empty($ids)) {
            $errors[] = "Please select at least 1 product";
        }
    }

    // Get image input & upload preparation & process
    $imagePath = $addon['ADD_ON_IMAGE'];
    $removeImage = $_POST['remove_image'] ?? '0';

    // Remove original image first
    if ($removeImage == "1") {

        if (!empty($addon['ADD_ON_IMAGE']) && file_exists($addon['ADD_ON_IMAGE'])) {
            unlink($addon['ADD_ON_IMAGE']);
        }

        $imagePath = null;
    }

    // Upload new image
    if (!empty($_FILES['cover']['name'])) {

        $allowedTypes = ['image/jpeg','image/png','image/webp'];
        $maxSize = 2 * 1024 * 1024;

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $type = finfo_file($finfo, $_FILES['cover']['tmp_name']);
        finfo_close($finfo);

        $size = $_FILES['cover']['size'];

        if (!in_array($type, $allowedTypes)) {
            $errors[] = "Invalid image type (only jpeg, png, webp)";
        }

        if ($size > $maxSize) {
            $errors[] = "Image too large (max 2MB)";
        }

        if (empty($errors)) {

            $dir = "uploads/addon/";

            if (!is_dir($dir)) mkdir($dir, 0777, true);

            $extMap = [
                'image/jpeg' => 'jpg',
                'image/png'  => 'png',
                'image/webp' => 'webp'
            ];

            $ext = $extMap[$type];
            $file = time() . "_" . uniqid() . "." . $ext;
            $path = $dir . $file;

            if (move_uploaded_file($_FILES['cover']['tmp_name'], $path)) {
               $imagePath = $path;

            } else {
               $errors[] = "Upload failed";
            }
        }
    }

    // Stop if errors
    if (!empty($errors)) {
        echo json_encode([
            "status" => "error",
            "message" => implode(" | ", $errors)
        ]);
        exit;
    }

    // Final update addon
    $stmt = $conn->prepare("
        UPDATE add_on
        SET
            ADD_ON_NAME = ?,
            ADD_ON_STOCK = ?,
            ADD_ON_PRICE = ?,
            ADD_ON_IMAGE = ?,
            ADD_ON_STATUS = ?
        WHERE ADD_ON_ID = ?
    ");

    $stmt->bind_param(
        "sidssi",
        $name,
        $stock,
        $price,
        $imagePath,
        $status,
        $addonId
    );

    if (!$stmt->execute()) {
        echo json_encode([
            "status" => "error",
            "message" => "Update failed"
        ]);
        exit;
    }

    // Remove old assign 
    $delete = $conn->prepare("
        DELETE FROM product_addon
        WHERE ADD_ON_ID = ?
    ");

    $delete->bind_param("i", $addonId);
    $delete->execute();

    // Update assign to all product
    if ($assign_type == "all") {

        $result = $conn->query("
            SELECT PRODUCT_ID
            FROM product
            WHERE IS_DELETED = 0
        ");

        $insert = $conn->prepare("
            INSERT INTO product_addon
            (PRODUCT_ID, ADD_ON_ID)
            VALUES (?, ?)
        ");

        while ($row = $result->fetch_assoc()) {

            $insert->bind_param(
                "ii",
                $row['PRODUCT_ID'],
                $addonId
            );

            $insert->execute();
        }
    }

    // Update assign by category
    elseif ($assign_type == "category") {

        $ids = $_POST['category_ids'] ?? [];

        $in = implode(",", array_map('intval', $ids));

        $result = $conn->query("
            SELECT PRODUCT_ID
            FROM product
            WHERE CATEGORY_ID IN ($in)
            AND IS_DELETED = 0
        ");

        $insert = $conn->prepare("
            INSERT INTO product_addon
            (PRODUCT_ID, ADD_ON_ID)
            VALUES (?, ?)
        ");

        while ($row = $result->fetch_assoc()) {

            $insert->bind_param(
                "ii",
                $row['PRODUCT_ID'],
                $addonId
            );

            $insert->execute();
        }
    }

    // Update assign by specific product
    elseif ($assign_type == "specific") {

        $ids = $_POST['product_ids'] ?? [];

        $insert = $conn->prepare("
            INSERT INTO product_addon
            (PRODUCT_ID, ADD_ON_ID)
            VALUES (?, ?)
        ");

        foreach ($ids as $pid) {

            $insert->bind_param(
                "ii",
                $pid,
                $addonId
            );

            $insert->execute();
        }
    }

    echo json_encode([
        "status" => "success",
        "message" => "Add-on updated successfully"
    ]);
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Add-on</title>
    <link rel="stylesheet" href="admin_global.css">
    <link rel="stylesheet" href="global_add_edit_form.css">
</head>

<body>

<div class="form-wrapper">

    <a href="manage_addon.php" class="form-back-link" title="Go Back To Addon Page">← Back</a>

    <div class="form-container">

        <h2 class="form-title">Edit Add-on</h2>

        <form method="POST" enctype="multipart/form-data" id="form">

            <!-- NAME -->
            <div class="form-group">
                <label>Name<span class="asterisk">*</span></label>
                <input type="text" name="name" id="nameInput" class="form-input" maxlength="50"
                    value="<?= htmlspecialchars($addon['ADD_ON_NAME']) ?>"
                >
                <span id="nameError" class="error"></span>
            </div>

            <!-- PRICE -->
            <div class="form-group">
                <label>Price (RM)<span class="asterisk">*</span></label>
                <input type="number" name="price" id="priceInput" class="form-input" step="0.01" min="0"
                    value="<?= $addon['ADD_ON_PRICE'] ?>"
                >
                <span id="priceError" class="error"></span>
            </div>

            <!-- STOCK -->
            <div class="form-group">
                <label>Stock<span class="asterisk">*</span></label>

                <input
                    type="number"
                    name="stock"
                    id="stockInput"
                    class="form-input"
                    step="1"
                    min="0"
                    value="<?= $addon['ADD_ON_STOCK'] ?>"
                >

                <span id="stockError" class="error"></span>
            </div>

            <!-- STATUS -->
            <div class="form-group">
                <label>Status<span class="asterisk">*</span></label>

                <select name="status" class="form-input">

                    <option value="Active"
                        <?= ($addon['ADD_ON_STATUS'] == 'Active') ? 'selected' : '' ?>>
                        Active
                    </option>

                    <option value="Inactive"
                        <?= ($addon['ADD_ON_STATUS'] == 'Inactive') ? 'selected' : '' ?>>
                        Inactive
                    </option>

                </select>
            </div>

            <!-- ASSIGN TYPE -->
            <div class="form-group">
                <label>Assign To<span class="asterisk">*</span></label>

                <select name="assign_type" id="assignType" class="form-input">
                    <option value="all">All Products</option>
                    <option value="category">By Category</option>
                    <option value="specific" selected>Specific Products</option>
                </select>
            </div>

            <!-- CATEGORY -->
            <div class="form-group" id="categoryBox" style="display:none;">

                <label>Category</label>

                <span id="categoryError" class="error"></span>

                <div style="max-height:200px; overflow:auto; border:1px solid #ccc; padding:10px;">

                <?php

                $cat = $conn->query("
                    SELECT CATEGORY_ID, CATEGORY_NAME
                    FROM category
                    WHERE IS_DELETED = 0
                ");

                while ($c = $cat->fetch_assoc()) {

                    echo "
                    <label style='display:block; margin-bottom:5px;'>

                        <input
                            type='checkbox'
                            name='category_ids[]'
                            value='{$c['CATEGORY_ID']}'
                        >

                        {$c['CATEGORY_NAME']}

                    </label>
                    ";
                }

                ?>

                </div>

            </div>

            <!-- PRODUCT -->
            <div class="form-group" id="productBox">

                <label>Products</label>

                <span id="productError" class="error"></span>

                <div style="max-height:200px; overflow:auto; border:1px solid #ccc; padding:10px;">

                <?php

                $prod = $conn->query("
                    SELECT PRODUCT_ID, PRODUCT_NAME
                    FROM product
                    WHERE IS_DELETED = 0
                ");

                while ($p = $prod->fetch_assoc()) {

                    $checked = in_array(
                        $p['PRODUCT_ID'],
                        $currentAssigned
                    ) ? "checked" : "";

                    echo "
                    <label style='display:block; margin-bottom:5px;'>

                        <input
                            type='checkbox'
                            name='product_ids[]'
                            value='{$p['PRODUCT_ID']}'
                            $checked
                        >

                        {$p['PRODUCT_NAME']}

                    </label>
                    ";
                }

                ?>

                </div>

            </div>

            <!-- IMAGE -->
            <div class="form-group">
                <label>Image</label>
                <input type="file" id="coverInput" name="cover" class="form-input">
                <small class="text-hint">Only JPG, PNG, WEBP (Max 2MB)</small>
                <span id="coverError" class="error"></span>

                <?php if (!empty($addon['ADD_ON_IMAGE'])): ?>
                    <img id="coverPreview" src="<?= $addon['ADD_ON_IMAGE'] ?>" style="width:120px; display:block; margin-top:10px;">
                <?php else: ?>
                    <img id="coverPreview" style="display:none;">
                <?php endif; ?>

                <input type="hidden" name="remove_image" id="removeImageInput" value="0">

                <button type="button" class="btn-remove" onclick="removeImage()">
                    ✕ Remove Image
                </button>

            </div>

            <button type="submit" class="form-btn">
                Save Changes
            </button>

        </form>

    </div>

</div>

<script>
document.addEventListener("DOMContentLoaded", function () {

    // Form
    const form = document.getElementById("form");

    // Inputs
    const nameInput = document.getElementById("nameInput");
    const priceInput = document.getElementById("priceInput");
    const stockInput = document.getElementById("stockInput");
    const coverInput = document.getElementById("coverInput");

    const assignType = document.getElementById("assignType");
    const categoryBox = document.getElementById("categoryBox");
    const productBox = document.getElementById("productBox");

    // Error message elements
    const nameError = document.getElementById("nameError");
    const priceError = document.getElementById("priceError");
    const stockError = document.getElementById("stockError");
    const coverError = document.getElementById("coverError");
    const categoryError = document.getElementById("categoryError");
    const productError = document.getElementById("productError");

    // Error handling functions
    function setError(input, errorEl, msg) {
        errorEl.textContent = msg;
        input.classList.add("input-error");
    }
    function clearError(input, errorEl) {
        errorEl.textContent = "";
        input.classList.remove("input-error");
    }
    function isEmpty(val) {
        return val.trim() === "";
    }

    // Control assign type visibility
    assignType.addEventListener("change", function () {

        categoryBox.style.display = "none";
        productBox.style.display = "none";

        if (this.value === "category") {
            categoryBox.style.display = "block";
        }

        if (this.value === "specific") {
            productBox.style.display = "block";
        }
    });

    // Real-time validation for name with AJAX duplicate check / Name required
    nameInput.addEventListener("input", function () {

        const name = this.value.trim();

        if (isEmpty(name)) {
            setError(nameInput, nameError, "Name required");
            return;
        }

        fetch("edit_addon.php?addon_id=<?= $addonId ?>", {
            method: "POST",
            headers: {
                "Content-Type": "application/x-www-form-urlencoded"
            },
            body: "ajax_check_name=1&name=" + encodeURIComponent(name)
        })
        .then(res => res.text())
        .then(data => {

            if (data.trim() === "exists") {
                setError(nameInput, nameError, "Already exists");
            } else {
                clearError(nameInput, nameError);
            }
        });
    });

    // Real-time validation for price
    priceInput.addEventListener("input", function () {

        const val = this.value;

        if (isEmpty(val)) {
            setError(this, priceError, "Price required");
        }
        else if (parseFloat(val) < 0) {
            setError(this, priceError, "Cannot be negative");
        }
        else {
            clearError(this, priceError);
        }
    });

    // Real-time validation for stock
    stockInput.addEventListener("input", function () {

        const val = this.value;

        if (isEmpty(val)) {
            setError(this, stockError, "Stock required");
        }
        else if (!/^\d+$/.test(val)) {
            setError(this, stockError, "Whole number only");
        }
        else {
            clearError(this, stockError);
        }
    });

    // Real-time validation and preview for image
    const ALLOWED = ["image/jpeg", "image/png", "image/webp"];
    const MAX_SIZE = 2 * 1024 * 1024;

    coverInput.addEventListener("change", function () {

        const file = this.files[0];
        const preview = document.getElementById("coverPreview");

        coverError.textContent = "";
        this.classList.remove("input-error");

        if (!file) return;

        if (!ALLOWED.includes(file.type) || file.size > MAX_SIZE) {

            coverError.textContent = "Cannot upload. Invalid image (JPG/PNG/WEBP, max 2MB)";
            this.classList.add("input-error");

            this.value = "";

            return;
        }

        const reader = new FileReader();

        reader.onload = function (e) {

            preview.src = e.target.result;
            preview.style.display = "block";
            preview.style.width = "120px";
        };

        reader.readAsDataURL(file);
    });

    // Form submit
    form.addEventListener("submit", function (e) {

        e.preventDefault();

        let ok = true;

        coverError.textContent = "";
        coverInput.classList.remove("input-error");


        if (isEmpty(nameInput.value)) {
            setError(nameInput, nameError, "Name required");
            ok = false;
        }

        if (isEmpty(priceInput.value)) {
            setError(priceInput, priceError, "Price required");
            ok = false;
        }

        if (isEmpty(stockInput.value)) {
            setError(stockInput, stockError, "Stock required");
            ok = false;
        }

        if (assignType.value === "category") {

            const checked = document.querySelectorAll(
                "input[name='category_ids[]']:checked"
            );

            if (checked.length === 0) {
                categoryError.textContent = "Select at least 1 category";
                ok = false;
            }
        }

        if (assignType.value === "specific") {

            const checked = document.querySelectorAll(
                "input[name='product_ids[]']:checked"
            );

            if (checked.length === 0) {
                productError.textContent = "Select at least 1 product";
                ok = false;
            }
        }

        if (
            nameError.textContent ||
            priceError.textContent ||
            stockError.textContent ||
            coverError.textContent
        ) {
            ok = false;
        }

        if (!ok) {
            showToast("error", "Please fix the errors");
            return;
        }

        // If all validations pass, submit form data via AJAX
        const formData = new FormData(form);

        fetch("edit_addon.php?addon_id=<?= $addonId ?>", {
            method: "POST",
            body: formData
        })
        .then(res => res.json())
        .then(data => {

            if (data.status === "success") {
                showToast("success", data.message || "Updated");

                setTimeout(() => {
                    window.location.href = "manage_addon.php";
                }, 800);
                
            } else {
                showToast("error", data.message || "Update failed");
            }
        })
        .catch(() => {
            showToast("error", "Network error");
        });
    });
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

// Remove image function for "Remove Image" button
function removeImage() {

    const input = document.getElementById("coverInput");
    const preview = document.getElementById("coverPreview");
    const hidden = document.getElementById("removeImageInput");
    const error = document.getElementById("coverError");

    input.value = "";

    preview.src = "";
    preview.style.display = "none";

    hidden.value = "1";

    error.textContent = "";

    input.classList.remove("input-error");
}
</script>

</body>
</html>