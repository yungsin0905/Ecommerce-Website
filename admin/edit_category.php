<?php
require_once("config.php");
session_start();

if (!isset($_SESSION['admin_id'])) {
    header("Location: admin_login.php");
    exit;
} 
$admin_id = $_SESSION['admin_id'];

$errors = [];

$categoryId = $_GET['category_id'] ?? 0;

if (!$categoryId || !is_numeric($categoryId)) {
    die("Invalid Category ID");
}

// Fetch category
$stmt = $conn->prepare("
    SELECT *
    FROM category
    WHERE CATEGORY_ID = ?
    AND IS_DELETED = 0
");

$stmt->bind_param("i", $categoryId);
$stmt->execute();

$result = $stmt->get_result();

if ($result->num_rows === 0) {
    die("Category not found");
}

$category = $result->fetch_assoc();

// AJAX: Check duplicate name - frontend validation - used for real-time validation on name input
// Cannot edit category with same name
if (isset($_POST['ajax_check_name'])) {

    $name = trim($_POST['name'] ?? '');

    $stmt = $conn->prepare("
        SELECT CATEGORY_ID
        FROM category
        WHERE CATEGORY_NAME = ?
        AND CATEGORY_ID != ?
        AND IS_DELETED = 0
    ");

    $stmt->bind_param("si", $name, $categoryId);
    $stmt->execute();

    echo ($stmt->get_result()->num_rows > 0) ? "exists" : "ok";
    exit;
}

// Update category process or preparation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['ajax_check_name'])) {

    // Get inputs
    $name   = trim($_POST['name'] ?? '');
    $status = $_POST['status'] ?? 'Active';
    $des    = trim($_POST['description'] ?? '');

    $removeImage = $_POST['remove_image'] ?? '0';

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
            AND CATEGORY_ID != ?
            AND IS_DELETED = 0
        ");

        $check->bind_param("si", $name, $categoryId);
        $check->execute();

        if ($check->get_result()->num_rows > 0) {
            $errors[] = "Category already exists";
        }
    }

    // Get current image 
    $imagePath = $category['CATEGORY_IMAGE'];

    // Remove original image first
    if ($removeImage == "1") {

        if (!empty($category['CATEGORY_IMAGE']) && file_exists($category['CATEGORY_IMAGE'])) {
            unlink($category['CATEGORY_IMAGE']);
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

            $dir = "uploads/category/";

            if (!is_dir($dir)) {
                mkdir($dir, 0777, true);
            }

            $extMap = [
                'image/jpeg' => 'jpg',
                'image/png'  => 'png',
                'image/webp' => 'webp'
            ];

            $ext = $extMap[$type];
            $file = time() . "_" . uniqid() . "." . $ext;
            $path = $dir . $file;

            if (move_uploaded_file($_FILES['cover']['tmp_name'], $path)) {

                if (!empty($category['CATEGORY_IMAGE']) && file_exists($category['CATEGORY_IMAGE'])) {
                    unlink($category['CATEGORY_IMAGE']);
                }

                $imagePath = $path;

            } else {
                $errors[] = "Upload failed";
            }
        }
    }

    // Stop if errors
    if (!empty($errors)) {
        echo implode(" | ", $errors);
        exit;
    }

    // Check have cake under category or not
    $checkCake = $conn->prepare("
        SELECT COUNT(*) AS total
        FROM product
        WHERE CATEGORY_ID = ?
        AND IS_DELETED = 0
    ");
    $checkCake->bind_param("i", $categoryId);
    $checkCake->execute();
    $cakeCount = $checkCake->get_result()->fetch_assoc()['total'] ?? 0;

    if ($status === "Inactive" && $cakeCount > 0 && empty($_POST['force_inactive'])) {
        echo "has_cake";
        exit;
    }

    // Final update category
    $stmt = $conn->prepare("
        UPDATE category
        SET
            CATEGORY_NAME = ?,
            CATEGORY_STATUS = ?,
            CATEGORY_DES = ?,
            CATEGORY_IMAGE = ?
        WHERE CATEGORY_ID = ?
    ");

    $stmt->bind_param(
        "ssssi",
        $name,
        $status,
        $des,
        $imagePath,
        $categoryId
    );

    if (!$stmt->execute()) {
        echo "Update failed";
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
    <title>Edit Category</title>
    <link rel="stylesheet" href="admin_global.css">
    <link rel="stylesheet" href="global_add_edit_form.css">
</head>

<body>

<div class="form-wrapper">

    <a href="manage_category.php" class="form-back-link" title="Go Back To Category Page">← Back</a>

    <div class="form-container">

        <h2 class="form-title">Edit Category</h2>

        <form method="POST" enctype="multipart/form-data" id="form">

        <!-- NAME -->
        <div class="form-group">
            <label>Name<span class="asterisk">*</span></label>
            <input type="text" name="name" id="nameInput" class="form-input" maxlength="50"
                value="<?= htmlspecialchars($category['CATEGORY_NAME']) ?>"
            >
            <span id="nameError" class="error"></span>
        </div>

        <!-- STATUS -->
        <div class="form-group">
            <label>Status<span class="asterisk">*</span></label>
            <select name="status" class="form-input">
                <option value="Active" <?= $category['CATEGORY_STATUS']=="Active"?"selected":"" ?>>Active</option>
                <option value="Inactive" <?= $category['CATEGORY_STATUS']=="Inactive"?"selected":"" ?>>Inactive</option>
            </select>
        </div>

        <!-- DESCRIPTION -->
        <div class="form-group">
            <label>Description</label>
            <textarea name="description" maxlength="300" class="form-input form-textarea"><?= htmlspecialchars($category['CATEGORY_DES']) ?></textarea>
            <div class="text-cont">
                <span class="text-hint">Maximum 300 characters</span>
            </div>
        </div>

        <!-- IMAGE -->
        <div class="form-group">
            <label>Image</label>
            <input type="file" id="coverInput" name="cover" class="form-input">
            <small class="text-hint">Only JPG, PNG, WEBP (Max 2MB)</small>
            <span id="coverError" class="error"></span>

            <?php if (!empty($category['CATEGORY_IMAGE'])): ?>
                <img id="coverPreview" src="<?= $category['CATEGORY_IMAGE'] ?>" style="width:120px; display:block; margin-top:10px;">
            <?php else: ?>
                <img id="coverPreview" style="display:none; width:120px; margin-top:10px;">
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
    const coverInput = document.getElementById("coverInput");

    // Error message elements
    const nameError = document.getElementById("nameError");
    const coverError = document.getElementById("coverError");

    // Image
    const preview = document.getElementById("coverPreview");
    const removeImageInput = document.getElementById("removeImageInput");

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
            setError(nameInput,nameError,"Category name required");
            return;
        }

        fetch("edit_category.php?category_id=<?= $categoryId ?>", {
            method: "POST",
            headers: { "Content-Type":"application/x-www-form-urlencoded"},
            body:"ajax_check_name=1&name=" + encodeURIComponent(name)
        })
        .then(res => res.text())
        .then(data => {

            if (data.trim() === "exists") {
                setError(nameInput,nameError,"Already exists");
            } else {
                clearError(nameInput,nameError);
            }
        });
    });

    // Real-time validation and preview for image
    const ALLOWED = ["image/jpeg","image/png","image/webp"];
    const MAX_SIZE = 2 * 1024 * 1024;

    coverInput.addEventListener("change", function () {

        const file = this.files[0];

        coverError.textContent = "";
        this.classList.remove("input-error");

        removeImageInput.value = "0";

        if (!file) {
            preview.src = "";
            preview.style.display = "none";
            return;
        }

        if (!ALLOWED.includes(file.type) || file.size > MAX_SIZE) {

            setError(coverInput,coverError,"Cannot upload. Invalid image (JPG/PNG/WEBP, max 2MB)");

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

        clearError(coverInput,coverError);
    });

    // Form submit
    form.addEventListener("submit", function (e) {

        e.preventDefault();

        let ok = true;

        coverError.textContent = "";
        coverInput.classList.remove("input-error");

        if (isEmpty(nameInput.value)) {
            setError(nameInput,nameError,"Category name required");
            ok = false;
        }

        if (nameError.textContent || coverError.textContent) {
            ok = false;
        }

        if (!ok) {
            showToast("error", "Please fix errors");
            return;
        }

        // If all validations pass, submit form data via AJAX
        const formData = new FormData(form);

        fetch("edit_category.php?category_id=<?= $categoryId ?>", {
            method: "POST",
            body: formData
        })
        .then(res => res.text())
        .then(data => {

            // Check if trying to inactive with cake still under category -> show confirm dialog
            if (data.trim() === "has_cake") {

            const ok = confirm(
                "There are still cake under this category.\n\n" +
                "Are you sure you want to inactive?\n" +
                "All cake under it will not be able to show in the website."
            );

            if (!ok) return;

            const formData = new FormData(form);
            formData.append("force_inactive", "1");

            fetch("edit_category.php?category_id=<?= $categoryId ?>", {
                method: "POST",
                body: formData
            })
            .then(res => res.text())
            .then(final => {

            if (final.trim() === "ok") {
                showToast("success","Category updated");

                setTimeout(() => {
                    window.location.href = "manage_category.php";
                }, 800);

            } else {
                showToast("error", final);
            }
        });

        return;
    }

            if (data.trim() === "ok") {
                showToast("success","Category updated");

                setTimeout(() => {
                    window.location.href = "manage_category.php";
                }, 800);

            } else {
                showToast("error", data);
            }
        })
        .catch(() => {
            showToast("error","Network error");
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
    const error = document.getElementById("coverError");
    const removeFlag = document.getElementById("removeImageInput");

    input.value = "";

    preview.src = "";
    preview.style.display = "none";

    error.textContent = "";
    input.classList.remove("input-error");

    removeFlag.value = "1";
}
</script>

</body>
</html>