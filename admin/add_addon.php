<?php
require_once("config.php");
session_start();

date_default_timezone_set('Asia/Kuala_Lumpur');

if (!isset($_SESSION['admin_id'])) {
    header("Location: admin_login.php");
    exit;
} 
$admin_id = $_SESSION['admin_id'];

$errors = [];

// AJAX: Check duplicate name - frontend validation - used for real-time validation on name input
// Cannot add add-on with same name
if (isset($_POST['ajax_check_name'])) {

    $name = trim($_POST['name'] ?? '');

    $stmt = $conn->prepare("
        SELECT ADD_ON_ID 
        FROM add_on 
        WHERE ADD_ON_NAME = ? 
        AND IS_DELETED = 0
    ");

    $stmt->bind_param("s", $name);
    $stmt->execute();

    echo ($stmt->get_result()->num_rows > 0) ? "exists" : "ok";
    exit;
}

// Insert addon process or preparation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['ajax_check_name'])) {

    // Get inputs
    $name   = trim($_POST['name'] ?? '');
    $price  = $_POST['price'] ?? '';
    $stock  = $_POST['stock'] ?? '';
    $status = $_POST['status'] ?? 'Active';
    $assign_type = $_POST['assign_type'] ?? 'all';

    // Name Required
    if ($name === "") {
        $errors[] = "Name required";
    }

    // Price Required + Numeric + Non-negative
    if ($price === "" || !is_numeric($price) || $price < 0) {
        $errors[] = "Valid price required (must be >= 0)";
    }

    // Stock Required + Integer + Non-negative
    if ($stock === "" || !ctype_digit($stock) || (int)$stock < 0) {
        $errors[] = "Stock must be a non-negative whole number";
    }

    // Check duplicate name - backend validation
    if (empty($errors)) {

        $check = $conn->prepare("
            SELECT ADD_ON_ID 
            FROM add_on 
            WHERE ADD_ON_NAME = ? 
            AND IS_DELETED = 0
        ");

        $check->bind_param("s", $name);
        $check->execute();

        if ($check->get_result()->num_rows > 0) {
            $errors[] = "Add-on already exists";
        }
    }

    // Image validation
    $imagePath = null;

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

        $dir = "uploads/addon/";  // later need to change
        if (!is_dir($dir)) mkdir($dir, 0777, true);

        $extMap = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp'
        ];

        $ext = $extMap[$type];
        $file = time() . "_" . uniqid() . "." . $ext;
        $path = $dir . $file;

        if (!move_uploaded_file($_FILES['cover']['tmp_name'], $path)) {
            $errors[] = "Upload failed";
        }

        $imagePath = $path;
    }

    // Stop if errors
    if (!empty($errors)) {
        echo implode(" | ", $errors);
        exit;
    }

    // Final insert addon
    $stmt = $conn->prepare("
        INSERT INTO add_on
        (ADD_ON_NAME, ADD_ON_STOCK, ADD_ON_PRICE, ADD_ON_IMAGE, ADD_ON_STATUS, IS_DELETED)
        VALUES (?,?,?,?,?,0)
    ");

    $stmt->bind_param(
        "sidss",
        $name,
        $stock,
        $price,
        $imagePath,
        $status
    );

    if (!$stmt->execute()) {
        echo "Insert failed";
        exit;
    }

    $addon_id = $stmt->insert_id;

    // Assign addon to products logic
    // Assign all products
    if ($assign_type == "all") {

        $result = $conn->query("SELECT PRODUCT_ID FROM product");

        $insert = $conn->prepare("
            INSERT INTO product_addon (PRODUCT_ID, ADD_ON_ID)
            VALUES (?, ?)
        ");

        while ($row = $result->fetch_assoc()) {
            $insert->bind_param("ii", $row['PRODUCT_ID'], $addon_id);
            $insert->execute();
        }
    }

    // Assign by category
    elseif ($assign_type == "category") {

        $ids = $_POST['category_ids'] ?? [];

        // Validation - must select at least 1 category
        if (empty($ids)) {
            echo "Please select at least 1 category";
            exit;
        }

        $in = implode(",", array_map('intval', $ids));

        $result = $conn->query("
            SELECT PRODUCT_ID FROM product 
            WHERE CATEGORY_ID IN ($in)
        ");

        $insert = $conn->prepare("
            INSERT INTO product_addon (PRODUCT_ID, ADD_ON_ID)
            VALUES (?, ?)
        ");

        while ($row = $result->fetch_assoc()) {
            $insert->bind_param("ii", $row['PRODUCT_ID'], $addon_id);
            $insert->execute();
        }
    }

    // Assign specific products
    elseif ($assign_type == "specific") {

        $ids = $_POST['product_ids'] ?? [];

        // Validation - must select at least 1 product
        if (empty($ids)) {
            echo "Please select at least 1 product";
            exit;
        }

        $insert = $conn->prepare("
            INSERT INTO product_addon (PRODUCT_ID, ADD_ON_ID)
            VALUES (?, ?)
        ");

        foreach ($ids as $pid) {
            $insert->bind_param("ii", $pid, $addon_id);
            $insert->execute();
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
    <title>Add Addons</title>
    <link rel="stylesheet" href="admin_global.css">
    <link rel="stylesheet" href="global_add_edit_form.css">
<style>
</style>
</head>

<body>
<div class="form-wrapper">

    <a href="manage_addon.php" class="form-back-link" title="Go Back To Addon Page">← Back</a>

    <div class="form-container">

        <h2 class="form-title">Add Add-on</h2>

        <form method="POST" enctype="multipart/form-data" id="form">

        <div class="form-group">
            <label>Name<span class="asterisk">*</span></label>
            <input type="text" id="nameInput" name="name" class="form-input" maxlength="50">
            <span id="nameError" class="error"></span>
        </div>

        <div class="form-group">
            <label>Price (RM)<span class="asterisk">*</span></label>
            <input type="number" id="priceInput" name="price" class="form-input" step="0.01" min="0">
            <span id="priceError" class="error"></span>
        </div>

        <div class="form-group">
            <label>Stock<span class="asterisk">*</span></label>
            <input type="number" id="stockInput" name="stock" class="form-input" step="1" min="0">
            <span id="stockError" class="error"></span>
        </div>

        <div class="form-group">
            <label>Status<span class="asterisk">*</span></label>
            <select name="status" class="form-input">
                <option value="Active">Active</option>
                <option value="Inactive">Inactive</option>
            </select>
        </div>

        <div class="form-group">
            <label>Assign To<span class="asterisk">*</span></label>
            <select name="assign_type" id="assignType" class="form-input">
                <option value="all">All Products</option>
                <option value="category">By Category</option>
                <option value="specific">Specific Products</option>
            </select>
        </div>

        <div class="form-group" id="categoryBox" style="display:none;">
            <label>Category</label>
            <span id="categoryError" class="error"></span>

            <div style="max-height:200px; overflow:auto; border:1px solid #ccc; padding:10px;">
            <?php
                $cat = $conn->query("SELECT CATEGORY_ID, CATEGORY_NAME FROM category WHERE IS_DELETED = 0");
                while ($c = $cat->fetch_assoc()) {
                    echo "
                    <label style='display:block; margin-bottom:5px;'>
                    <input type='checkbox' name='category_ids[]' value='{$c['CATEGORY_ID']}'>
                    {$c['CATEGORY_NAME']}
                    </label>
                    ";
                }
            ?>
            </div>
        </div>

        <div class="form-group" id="productBox" style="display:none;">
            <label>Products</label>
            <span id="productError" class="error"></span>

            <div style="max-height:200px; overflow:auto; border:1px solid #ccc; padding:10px;">
            <?php
                $prod = $conn->query("SELECT PRODUCT_ID, PRODUCT_NAME FROM product WHERE IS_DELETED = 0");
                while ($p = $prod->fetch_assoc()) {
                    echo "
                    <label style='display:block; margin-bottom:5px;'>
                    <input type='checkbox' name='product_ids[]' value='{$p['PRODUCT_ID']}'>
                    {$p['PRODUCT_NAME']}
                    </label>
                    ";
                }
            ?>
            </div>
        </div>

        <div class="form-group">
            <label>Image</label>
            <input type="file" id="coverInput" name="cover" class="form-input">
            <small class="text-hint">Only JPG, PNG, WEBP (Max 2MB)</small>
            <span id="coverError" class="error"></span>
            <img id="coverPreview" style="display:none;">
            <button type="button" class="btn-remove" title="Remove Uploaded Image" onclick="removeImage()">
                ✕ Remove Image
            </button>
        </div>

        <button type="submit" class="form-btn">Create</button>

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

    // Real-time validation for name with AJAX duplicate check / Name required
    nameInput.addEventListener("input", function () {

        const name = this.value.trim();

        if (isEmpty(name)) {
            setError(nameInput, nameError, "Name required");
            return;
        }

        fetch("add_addon.php", {
            method: "POST",
            headers: { "Content-Type": "application/x-www-form-urlencoded" },
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
            setError(this, priceError, "Price cannot be negative");
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
            setError(this, stockError, "Stock must be whole number");
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

        if (!file) {
            preview.src = "";
            preview.style.display = "none";
            return;
        }

        if (!ALLOWED.includes(file.type) || file.size > MAX_SIZE) {

            coverError.textContent = "Cannot upload. Invalid image (JPG/PNG/WEBP, max 2MB)";
            this.classList.add("input-error");

            this.value = "";
            preview.src = "";
            preview.style.display = "none";

            return;
        }

        const reader = new FileReader();
        reader.onload = function (e) {
            preview.src = e.target.result;
            preview.style.display = "block";
            preview.style.width = "120px";

            coverError.textContent = "";
        };
        reader.readAsDataURL(file);
    });

    // Real-time validation for assign type toggle  
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

    // Form submit
    form.addEventListener("submit", function (e) {

        e.preventDefault();

        let ok = true;

        coverError.textContent = "";
        coverInput.classList.remove("input-error");

        // Required field checks
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

        // Assignment checks
        const type = assignType.value;

        categoryError.textContent = "";
        productError.textContent = "";
        categoryBox.classList.remove("input-error-box");
        productBox.classList.remove("input-error-box");

        if (type === "category") {
           const checked = document.querySelectorAll("input[name='category_ids[]']:checked");

            if (checked.length === 0) {
                categoryError.textContent = "Please select at least 1 category";
                categoryBox.classList.add("input-error"); 
                ok = false;
            }
        }

        if (type === "specific") {
            const checked = document.querySelectorAll("input[name='product_ids[]']:checked");

            if (checked.length === 0) {
                productError.textContent = "Please select at least 1 product";
                productBox.classList.add("input-error"); 
                ok = false;
            }
        }

        // Add change listeners to clear error when user selects category/product after error
        document.querySelectorAll("input[name='category_ids[]']").forEach(cb => {
            cb.addEventListener("change", () => {
                if (document.querySelectorAll("input[name='category_ids[]']:checked").length > 0) {
                    categoryError.textContent = "";
                    categoryBox.classList.remove("input-error"); 
                }
            });
        });
        document.querySelectorAll("input[name='product_ids[]']").forEach(cb => {
            cb.addEventListener("change", () => {
                if (document.querySelectorAll("input[name='product_ids[]']:checked").length > 0) {
                    productError.textContent = "";
                    productBox.classList.remove("input-error"); 
                }
            });
        });

        // Final check - if any error messages still exist, prevent submission
        if (nameError.textContent || priceError.textContent || stockError.textContent || coverError.textContent) {
            ok = false;
        }

        // Stop submit if validation fail
        if (!ok) {
            showToast("error", "Please fix the errors");
            return;
        }

        // If all validations pass, submit form data via AJAX
        const formData = new FormData(form);

        fetch("add_addon.php", {
            method: "POST",
            body: formData
        })
        .then(res => res.text())
        .then(data => {

            if (data.trim() === "ok") {
                showToast("success", "Add-on created");

                setTimeout(() => {
                    window.location.href = "manage_addon.php";
                }, 800);

            } else {
                showToast("error", data);
            }
        });
    });

});

// Show toast 
function showToast(type, message) {
    const toast = document.createElement("div");
    toast.className = "toast " + type;

    // Message text
    const text = document.createElement("span");
    text.innerText = message;

    // Close button
    const closeBtn = document.createElement("span");
    closeBtn.innerHTML = "×";
    closeBtn.className = "toast-close-btn";

    toast.appendChild(text);
    toast.appendChild(closeBtn);

    document.body.appendChild(toast);

    let removed = false;

    // Close button event listener
    closeBtn.addEventListener("click", (e) => {
        e.stopPropagation(); 
        removeToast();
    });

    // Click toast to remove
    toast.addEventListener("click", removeToast);

    function removeToast() {
        if (removed) return;
        removed = true;

        toast.style.opacity = "0";
        toast.style.transform = "translateX(100%)";

        setTimeout(() => toast.remove(), 300);
    }

    // Auto-remove after duration (8s for error, 3s for success)
    const duration = type === "error" ? 8000 : 3000;

    setTimeout(removeToast, duration);
}

// Remove image function for "Remove Image" button
function removeImage() {

    const input = document.getElementById("coverInput");
    const preview = document.getElementById("coverPreview");
    const error = document.getElementById("coverError");

    input.value = "";

    preview.src = "";
    preview.style.display = "none";

    error.textContent = "";
    input.classList.remove("input-error");
}
</script>

</body>
</html>