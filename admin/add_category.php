<?php
require_once("config.php");
session_start();

if (!isset($_SESSION['admin_id'])) {
    header("Location: admin_login.php");
    exit;
} 
$admin_id = $_SESSION['admin_id'];

$errors = [];

// AJAX: Check duplicate name - frontend validation - used for real-time validation on name input
// Cannot add category with same name
if (isset($_POST['ajax_check_name'])) {

    $name = trim($_POST['name'] ?? '');

    $stmt = $conn->prepare("
        SELECT CATEGORY_ID 
        FROM category 
        WHERE CATEGORY_NAME = ? 
        AND IS_DELETED = 0
    ");

    $stmt->bind_param("s", $name);
    $stmt->execute();

    echo ($stmt->get_result()->num_rows > 0) ? "exists" : "ok";
    exit;
}

// Insert category process or preparation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['ajax_check_name'])) {

    // Get inputs
    $name   = trim($_POST['name'] ?? '');
    $status = $_POST['status'] ?? 'Active';
    $des    = trim($_POST['description'] ?? '');

    // Name required
    if ($name === "") {
        $errors[] = "Category name required";
    }

    // Check duplicate name - backend validation
    if (empty($errors)) {

        $check = $conn->prepare("
            SELECT CATEGORY_ID 
            FROM category 
            WHERE CATEGORY_NAME = ? 
            AND IS_DELETED = 0
        ");

        $check->bind_param("s", $name);
        $check->execute();

        if ($check->get_result()->num_rows > 0) {
            $errors[] = "Category already exists";
        }
    }

    // Image validation 
    $imagePath = null;

    if (!empty($_FILES['image']['name'])) {

        $allowedTypes = ['image/jpeg','image/png','image/webp'];
        $maxSize = 2 * 1024 * 1024;

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $type = finfo_file($finfo, $_FILES['image']['tmp_name']);
        finfo_close($finfo);

        $size = $_FILES['image']['size'];

        if (!in_array($type, $allowedTypes)) {
            $errors[] = "Invalid image type (only jpeg, png, webp)";
        }

        if ($size > $maxSize) {
            $errors[] = "Image too large (max 2MB)";
        }

        /* only upload if no error */
        if (empty($errors)) {

            $dir = "uploads/category/"; // Ensure this directory exists and is writable
            if (!is_dir($dir)) {
                mkdir($dir, 0777, true);
            }

            $extMap = [
                'image/jpeg' => 'jpg',
                'image/png' => 'png',
                'image/webp' => 'webp'
            ];

            $ext = $extMap[$type];
            $fileName = time() . "_" . uniqid() . "." . $ext;
            $path = $dir . $fileName;

            if (!move_uploaded_file($_FILES['image']['tmp_name'], $path)) {
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

    // Final insert category
    $stmt = $conn->prepare("
        INSERT INTO category
        (CATEGORY_NAME, CATEGORY_STATUS, CATEGORY_DES, CATEGORY_IMAGE, IS_DELETED)
        VALUES (?,?,?,?,0)
    ");

    $stmt->bind_param(
        "ssss",
        $name,
        $status,
        $des,
        $imagePath
    );

    if (!$stmt->execute()) {
        echo "Insert failed";
        exit;
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
    <title>Add Category</title>
    <link rel="stylesheet" href="admin_global.css">
    <link rel="stylesheet" href="global_add_edit_form.css">
<style>
</style>
</head>

<body>
<div class="form-wrapper">

    <a href="manage_category.php" class="form-back-link" title="Go Back To Category Page">← Back</a>

    <div class="form-container">

        <h2 class="form-title">Add Category</h2>

        <form method="POST" enctype="multipart/form-data" id="form">

            <div class="form-group">
                <label>Category Name<span class="asterisk">*</span></label>
                <input type="text" id="categoryName" name="name" class="form-input" maxlength="50">
                <span id="nameError" class="error"></span>
            </div>

            <div class="form-group">
                <label>Status<span class="asterisk">*</span></label>
                <select name="status" class="form-input">
                    <option value="Active">Active</option>
                    <option value="Inactive">Inactive</option>
                </select>
            </div>

            <div class="form-group">
                <label>Description</label>
                <textarea name="description" maxlength="300" class="form-input form-textarea"></textarea>
                <div class="text-cont">
                    <span class="text-hint">Maximum 300 characters</span>
                </div>
            </div>

            <div class="form-group">
                <label>Image</label>
                <input type="file" id="imageInput" name="image"  class="form-input">
                <small class="text-hint">Only JPG, PNG, WEBP (Max 2MB)</small>
                <span id="imageError" class="error"></span>
                <img id="preview" style="display:none;">
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
    const nameInput = document.getElementById("categoryName");
    const imageInput = document.getElementById("imageInput");

    // Error message elements
    const nameError = document.getElementById("nameError");
    const imageError = document.getElementById("imageError");

    const preview = document.getElementById("preview");

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
            setError(nameInput, nameError, "Category name required");
            return;
        }

        fetch("add_category.php", {
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

    // Real-time validation and preview for image
    const ALLOWED = ["image/jpeg", "image/png", "image/webp"];
    const MAX_SIZE = 2 * 1024 * 1024;

    imageInput.addEventListener("change", function () {

        const file = this.files[0];

        imageError.textContent = "";
        this.classList.remove("input-error");

        if (!file) {
            preview.src = "";
            preview.style.display = "none";
            return;
        }

        if (!ALLOWED.includes(file.type) || file.size > MAX_SIZE) {

            setError(this, imageError, "Cannot upload. Invalid image (JPG/PNG/WEBP, max 2MB)");

            this.value = "";
            preview.src = "";
            preview.style.display = "none";

            return;
        }

        // preview
        const reader = new FileReader();
        reader.onload = function (e) {
            preview.src = e.target.result;
            preview.style.display = "block";
        };
        reader.readAsDataURL(file);

        clearError(this, imageError);
    });

    // Form submit
    form.addEventListener("submit", function (e) {

        e.preventDefault();

        let ok = true;

        imageError.textContent = "";
        imageInput.classList.remove("input-error");

        // Required field checks
        if (isEmpty(nameInput.value)) {
            setError(nameInput, nameError, "Category name required");
            ok = false;
        }

        // Check if real-time validation has errors
        if (nameError.textContent || imageError.textContent) {
            ok = false;
        }

        // Stop submit if validation fail
        if (!ok) {
            showToast("error", "Please fix the errors");
            return;
        }

        // If all validations pass, submit form data via AJAX
        const formData = new FormData(form);

        fetch("add_category.php", {
            method: "POST",
            body: formData
        })
        .then(res => res.text())
        .then(data => {

            if (data.trim() === "ok") {
                showToast("success", "Category created");

                setTimeout(() => {
                    window.location.href = "manage_category.php";
                }, 800);

            } else {
                showToast("error", data);
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

    const input = document.getElementById("imageInput");
    const preview = document.getElementById("preview");
    const error = document.getElementById("imageError");

    input.value = "";

    preview.src = "";
    preview.style.display = "none";

    error.textContent = "";
    input.classList.remove("input-error");
}
</script>

</body>
</html>