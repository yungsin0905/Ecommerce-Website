<?php
require_once("config.php");
session_start();

if (!isset($_SESSION['admin_id'])) {
    header("Location: admin_login.php");
    exit;
} 
$admin_id = $_SESSION['admin_id'];

// Fetch all categories & addons
$categories = $conn->query("SELECT * FROM category WHERE IS_DELETED = 0");
$all_addons = $conn->query("SELECT * FROM add_on WHERE IS_DELETED = 0");

$errors = [];

// AJAX: Check duplicate name - frontend validation - used for real-time validation on name input
// Cannot add product with same name
if (isset($_POST['ajax_check_name'])) {

    $name = trim($_POST['name'] ?? '');

    if ($name === "") {
        echo "ok";
        exit;
    }

    $stmt = $conn->prepare("
        SELECT PRODUCT_ID 
        FROM product 
        WHERE PRODUCT_NAME = ? 
        AND IS_DELETED = 0
    ");

    $stmt->bind_param("s", $name);
    $stmt->execute();

    echo ($stmt->get_result()->num_rows > 0) ? "exists" : "ok";
    exit;
}

// Insert product process or preparation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['ajax_check_name'])) {

    $name = trim($_POST['name'] ?? '');
    $categoryId = $_POST['category_id'] ?? '';
    $status = $_POST['status'] ?? '';
    $des = $_POST['description'] ?? "";
    $ingredients = $_POST['ingredients'] ?? "";
    $allergen = $_POST['allergen'] ?? "";
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
        ");

        $check->bind_param("s", $name);
        $check->execute();

        if ($check->get_result()->num_rows > 0) {
            $errors[] = "Product already exists";
        }
    }

    // Cover image validation and upload
    $imagePath = null;

    if (!empty($_FILES['cover']['name'])) {

        $allowedTypes = ['image/jpeg','image/png','image/webp'];
        $maxSize = 2 * 1024 * 1024;

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $type = finfo_file($finfo, $_FILES['cover']['tmp_name']);
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
                'image/png' => 'png',
                'image/webp' => 'webp'
            ];

            $ext = $extMap[$type];
            $file = time() . "_" . uniqid() . "." . $ext;
            $path = $dir . $file;

            if (!move_uploaded_file($_FILES['cover']['tmp_name'], $path)) {
                $errors[] = "Upload failed";
            } else {
                $imagePath = $path;
            }
        }
    }

    // Stop if errors
    if (!empty($errors)) {
        echo implode(" | ", $errors);
        exit;
    }

    // Final insert product
    $stmt = $conn->prepare("
        INSERT INTO product
        (PRODUCT_NAME, CATEGORY_ID, PRODUCT_STATUS, PRODUCT_DES,
         INGREDIENTS, ALLERGEN, ALLOW_WRITING, COVER_IMAGE, IS_DELETED)
        VALUES (?,?,?,?,?,?,?,?,0)
    ");

    $stmt->bind_param(
        "sissssis",
        $name,
        $categoryId,
        $status,
        $des,
        $ingredients,
        $allergen,
        $allowWriting,
        $imagePath
    );

    if (!$stmt->execute()) {
        echo "Insert failed";
        exit;
    }

    $productId = $stmt->insert_id;

    // Insert add-ons if any (optional)
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

    // Variant validation and insert (mandatory)
    if (empty($_POST['variant_size'])) {
        echo "At least 1 variant required";
        exit;
    }

    $stmtV = $conn->prepare("
        INSERT INTO product_variant
        (PRODUCT_ID, VARIANT_SIZE, VARIANT_PRICE, VARIANT_STOCK, VARIANT_STATUS)
        VALUES (?,?,?,?,?)
    ");

    $seenSizes = [];
    $valid = 0;

    foreach ($_POST['variant_size'] as $i => $size) {

        $size = trim($size);
        $price = $_POST['variant_price'][$i] ?? "";
        $stock = $_POST['variant_stock'][$i] ?? "";
        $statusV = $_POST['variant_status'][$i] ?? "Active";

        if (!ctype_digit($size) || (int)$size <= 0) {
            $errors[] = "Invalid variant size";
            continue;
        }

        if ($size === "" || $price === "" || $stock === "") {
            $errors[] = "Variant fields cannot be empty";
            continue;
        }

        if (isset($seenSizes[$size])) {
            $errors[] = "Duplicate variant size not allowed";
            continue;
        }
        $seenSizes[$size] = true;

        if (!is_numeric($price)) {
            $errors[] = "Invalid price";
            continue;
        }

        if (!ctype_digit(strval($stock))) {
            $errors[] = "Stock must be whole number";
            continue;
        }

        if ($price < 0) {
            $errors[] = "Price cannot be negative";
            continue;
        }

        if (!in_array($statusV, ['Active','Inactive'])) {
            $errors[] = "Invalid variant status";
            continue;
        }

        $stmtV->bind_param("iidis", $productId, $size, $price, $stock, $statusV);
        $stmtV->execute();
        $valid++;
    }

    if ($valid === 0) {
        echo "At least 1 valid variant required";
        exit;
    }

    // Extra images validation and insert (optional)
    if (!empty($_FILES['images']['name'][0])) {

        $allowedTypes = ['image/jpeg','image/png','image/webp'];
        $maxSize = 2 * 1024 * 1024;
        $dir = "uploads/product/";

        if (!is_dir($dir)) mkdir($dir, 0777, true);

        $stmtI = $conn->prepare("
            INSERT INTO product_images (PRODUCT_ID, IMAGE_PATH, IS_DELETED)
            VALUES (?, ?, 0)
        ");

        foreach ($_FILES['images']['name'] as $i => $n) {

            if ($_FILES['images']['error'][$i] !== UPLOAD_ERR_OK) continue;

            $tmp = $_FILES['images']['tmp_name'][$i];
            $size = $_FILES['images']['size'][$i];

            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $type = finfo_file($finfo, $tmp);
            finfo_close($finfo);

            if (!in_array($type, $allowedTypes)) continue;
            if ($size > $maxSize) continue;

            $extMap = [
                'image/jpeg' => 'jpg',
                'image/png' => 'png',
                'image/webp' => 'webp'
            ];

            $ext = $extMap[$type];
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
    <link rel="stylesheet" href="admin_global.css">
    <link rel="stylesheet" href="global_add_edit_form.css">
    <title>Add Product</title>
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

.form-left {
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

/* addon */
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
    display: flex;
    gap: 8px;
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

/* variant */
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

/* row layout */
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

/* size row */
.variant-size {
    display: flex;
    align-items: center;
    gap: 6px;
}

/* unit label */
.unit {
    font-size: 15px;
    font-weight: bold;
}

/* remove button */
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
    background: var(--primary-dark);
    color: var(--primary-light);
}

.add-variant-btn:active {
    transform: scale(0.98);
}

.form-line {
    margin-bottom: 10px;
}

.table-scroll{
    overflow-x:auto;
    overflow-y:auto;
    max-height:650px;
    border-radius:10px;
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

                <h2 class="form-pro-title">Add Product</h2>

                <div class="form-group">
                    <label>Name<span class="asterisk">*</span></label>
                    <input type="text" id="nameInput" name="name" class="form-input" maxlength="50">
                    <span id="nameError" class="error"></span>
                </div>

                <div class="form-group">
                    <label>Category<span class="asterisk">*</span></label>
                    <select name="category_id" class="form-input">
                        <?php while($c = $categories->fetch_assoc()): ?>
                        <option value="<?= $c['CATEGORY_ID'] ?>"><?= $c['CATEGORY_NAME'] ?></option>
                        <?php endwhile; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label>Status<span class="asterisk">*</span></label>
                    <select name="status" class="form-input">
                        <option value="Active">Active</option>
                        <option value="Inactive">Inactive</option>
                    </select> 
                </div>

                <div class="form-group">
                    <label>Allow Writing<span class="asterisk">*</span></label>
                    <select name="allow_writing" class="form-input">
                        <option value="1">Yes</option>
                        <option value="0">No</option>
                    </select>
                </div>

                <div class="form-group">
                    <label>Description</label>
                    <textarea name="description" maxlength="300" class="form-input form-textarea" placeholder="Enter product description (e.g., A delicious and ...)"></textarea>
                    <div class="text-cont">
                        <span class="text-hint">Maximum 300 characters</span>
                    </div>
                </div>

                <div class="form-group">
                    <label>Ingredients</label>
                    <textarea name="ingredients" maxlength="300" class="form-input form-textarea" placeholder="Enter ingredients (e.g., flour, sugar, etc.)"></textarea>
                    <div class="text-cont">
                        <span class="text-hint">Maximum 300 characters</span>
                    </div>
                </div>

                <div class="form-group">
                    <label>Allergen</label>
                    <textarea name="allergen" maxlength="300" class="form-input form-textarea" placeholder="Enter allergen information (e.g., nuts, dairy, etc.)"></textarea>
                    <div class="text-cont">
                        <span class="text-hint">Maximum 300 characters</span>
                    </div>
                </div>

                <div class="form-group">
                    <label>Cover Image</label>
                    <input type="file" name="cover" class="form-input">
                    <small class="text-hint">Only JPG, PNG, WEBP (Max 2MB)</small>
                    <span id="coverError" class="error"></span>
                    <img id="coverPreview" style="max-width:150px; display:none;">
                    <button type="button" class="btn-remove" title="Remove Uploaded Image" onclick="removeImage('cover')">✕ Remove Image</button>
                </div>

                <div class="form-group">
                    <label>Product Images</label>
                    <input type="file" name="images[]" class="form-input" multiple>
                    <span class="text-hint">You may select more than one. Only JPG, PNG, WEBP (Max 2MB)</span>
                    <span id="extraError" class="error"></span>
                    <div id="extraPreview"></div>
                    <button type="button" class="btn-remove" title="Remove All Uploaded Images" onclick="removeImage('extra')">✕ Remove Image</button>
                </div>

            </div>
        
            <div class="form-right">

                <h3>Add-ons</h3>

                <div class="addon-actions">
                    <button type="button" title="Select All Addon Choices" onclick="toggleAllAddons(true)">Select All</button>
                    <button type="button" title="Clear Selected Addon Choices" onclick="toggleAllAddons(false)">Clear</button>
                </div>

                <div class="addon-list">
                <br>
                    <?php while($a = $all_addons->fetch_assoc()): ?>
                    <label>
                    <input type="checkbox" class="addon-checkbox" name="addons[]" value="<?= $a['ADD_ON_ID'] ?>"><?= $a['ADD_ON_NAME'] ?> (RM <?= $a['ADD_ON_PRICE'] ?>)
                    </label>
                    <br>
                    <?php endwhile; ?>
                </div>

                <hr class="form-line">

                <h3>Variants<span class="asterisk">*</span></h3>
                <span id="variantError" class="error"></span>
                <div class="table-scroll">
                    <div id="variantBox"></div> <!--use js to control content-->
                </div>
                <button type="button" class="add-variant-btn" onclick="addVar()">+ Add Variant</button>

            </div>
        </div>

        <button type="submit" class="form-btn">Create</button>

    </div>
        
    </form>

</div>

<script>
document.addEventListener("DOMContentLoaded", function () {

    // Form
    const form = document.getElementById("form");

    // Inputs & Error message
    const nameInput = document.getElementById("nameInput");
    const nameError = document.getElementById("nameError");

    const coverInput = document.querySelector("input[name='cover']");
    const coverError = document.getElementById("coverError");

    const imagesInput = document.querySelector("input[name='images[]']");
    const extraError = document.getElementById("extraError");

    const variantError = document.getElementById("variantError");

    let selectedFiles = [];

    const MAX_SIZE = 2 * 1024 * 1024;
    const ALLOWED = ["image/jpeg", "image/png", "image/webp"];

    // Parse backend response for both AJAX and form submission
    function parseBackendResponse(text) {

        text = text.trim();

        if (text === "ok") return { 
            success: true, errors: [] 
        };

        if (text === "exists") {
            return { success: false, errors: ["Product already exists"] };
        }

        const errors = text.split("|").map(e => e.trim());

        return {
            success: false,
            errors
        };
    }

    // Show backend errors
    function showBackendErrors(errors) {

        nameError.textContent = "";
        coverError.textContent = "";
        extraError.textContent = "";
        variantError.textContent = "";

        let general = [];

        errors.forEach(err => {

            const lower = err.toLowerCase();

            if (lower.includes("name")) {
                nameError.textContent = err;
                nameInput.classList.add("input-error");
            }
            else if (lower.includes("cover")) {
                coverError.textContent = err;
                coverInput.classList.add("input-error");
            }
            else if (lower.includes("variant")) {
                variantError.textContent = err;
            }
            else if (lower.includes("image")) {
                extraError.textContent = err;
                imagesInput.classList.add("input-error");
            }
            else {
                general.push(err);
            }
        });

        if (general.length > 0) {
            showToast("error", general.join(" | "));
        }
    }

    // Real-time validation for name input
    nameInput.addEventListener("input", function () {

        const name = this.value.trim();

        if (name === "") {
            nameError.textContent = "Name required";
            this.classList.add("input-error");
            return;
        }

        fetch("add_product.php", {
            method: "POST",
            headers: { "Content-Type": "application/x-www-form-urlencoded" },
            body: "ajax_check_name=1&name=" + encodeURIComponent(name)
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

            coverError.textContent = "Invalid image (JPG/PNG/WEBP, max 2MB)";
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
        };
        reader.readAsDataURL(file);
    });

    // Real-time validation and preview for extra images
    imagesInput.addEventListener("change", function () {

        const files = Array.from(this.files);

        extraError.textContent = "";
        this.classList.remove("input-error");

        let hasInvalid = false;

        for (let file of files) {

            if (!ALLOWED.includes(file.type) || file.size > MAX_SIZE) {
                hasInvalid = true;
                continue;
            }

            const exists = selectedFiles.some(f =>
                f.name === file.name && f.size === file.size
            );

            if (exists) continue;

            selectedFiles.push(file); 
        }

        if (hasInvalid) {
            extraError.textContent = "Some images were skipped";
            this.classList.add("input-error");
        }

        renderPreview();

        this.value = ""; 
    });

    // Render image previews with remove buttons
    function renderPreview() {

        const container = document.getElementById("extraPreview");
        container.innerHTML = "";

        selectedFiles.forEach(file => {

            const reader = new FileReader();

            reader.onload = function (e) {

                const wrapper = document.createElement("div");
                wrapper.style.display = "inline-block";
                wrapper.style.position = "relative";
                wrapper.style.marginRight = "8px";

                const img = document.createElement("img");
                img.src = e.target.result;
                img.style.width = "120px";

                const btn = document.createElement("button");
                btn.innerText = "✕";
                btn.style.position = "absolute";
                btn.style.top = "0";
                btn.style.right = "0";

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

        // Name required
        if (nameInput.value.trim() === "") {
            nameInput.classList.add("input-error");
            nameError.textContent = "Name required";
            ok = false;
        }

        if (validateVariantsRealTime()) {
            ok = false;
        }

        // Stop submit if validation fail
        if (!ok) {
            showToast("error", "Please fix the errors");
            return;
        }

        // If all validations pass, submit form data via AJAX
        const formData = new FormData(form);

        formData.delete("images[]");

        selectedFiles.forEach(file => {
            formData.append("images[]", file);
        });

        fetch("add_product.php", {
            method: "POST",
            body: formData
        })
        .then(res => res.text())
        .then(data => {

            const res = parseBackendResponse(data);

            if (res.success) {

                showToast("success", "Product created");

                setTimeout(() => {
                    window.location.href = "manage_product.php";
                }, 800);

            } else {

                showBackendErrors(res.errors);
                showToast("error", "Please fix errors");
            }
        })
        .catch(() => {
            showToast("error", "Network error");
        });
    });

    addVar();
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

    // Close button event
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

// Add variant row
function addVar() {

    const box = document.getElementById("variantBox");
    const variantError = document.getElementById("variantError");

    const div = document.createElement("div");

    div.innerHTML = `
        <p>Size (inch)</p>
        <div class="variant-size">
            <input type="number" name="variant_size[]" min="1" placeholder="e.g., 6">
            <span class="error size-error"></span>
        </div>

        <p>Price (RM)</p>
        <input type="number" name="variant_price[]" step="0.01" min="0" placeholder="e.g., 29.99">
        <span class="error price-error"></span>

        <p>Stock</p>
        <input type="number" name="variant_stock[]" min="0" step="1"  placeholder="e.g., 20">
        <span class="error stock-error"></span>

        <p>Status</p>
        <select name="variant_status[]">
            <option>Active</option>
            <option>Inactive</option>
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
    document.querySelectorAll(".addon-checkbox").forEach(cb => {
        cb.checked = state;
    });
}

// Remove image for either cover or extra images
function removeImage(type) {

    if (type === "cover") {
        const input = document.querySelector("input[name='cover']");
        input.value = "";
        document.getElementById("coverPreview").style.display = "none";
        document.getElementById("coverError").textContent = "";
    }

    if (type === "extra") {
        selectedFiles = [];
        document.getElementById("extraPreview").innerHTML = "";
    }
}

// Real-time validation for variants - checks size, price, stock, and duplicates
function validateVariantsRealTime() {

    const rows = document.querySelectorAll("#variantBox > div");
    const set = new Set();
    const err = document.getElementById("variantError");

    let hasError = false;

    if (rows.length === 0) {
        err.textContent = "At least 1 variant required";
        return true;
    }

    err.textContent = "";

    rows.forEach(row => {

        const size = row.querySelector("input[name='variant_size[]']");
        const price = row.querySelector("input[name='variant_price[]']");
        const stock = row.querySelector("input[name='variant_stock[]']");

        const sizeError = row.querySelector(".size-error");
        const priceError = row.querySelector(".price-error");
        const stockError = row.querySelector(".stock-error");

        const sizeVal = size.value.trim();
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