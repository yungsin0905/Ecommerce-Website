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
$categoryId = (int)$categoryId;

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

// Does this category have sub-categories?
// If yes, it must stay a main category (only 2 levels allowed)
$stmt = $conn->prepare("
    SELECT COUNT(*) AS total
    FROM category
    WHERE PARENT_ID = ?
    AND IS_DELETED = 0
");
$stmt->bind_param("i", $categoryId);
$stmt->execute();
$childCount  = (int)$stmt->get_result()->fetch_assoc()['total'];
$hasChildren = $childCount > 0;

// AJAX: Check duplicate name - frontend validation - used for real-time validation on name input
// Cannot edit category with same name under the same parent
if (isset($_POST['ajax_check_name'])) {

    $name = trim($_POST['name'] ?? '');
    $parentId = ($_POST['parent_id'] ?? '') !== '' ? (int)$_POST['parent_id'] : null;
    if ($hasChildren) $parentId = null;

    $stmt = $conn->prepare("
        SELECT CATEGORY_ID
        FROM category
        WHERE CATEGORY_NAME = ?
        AND PARENT_ID <=> ?
        AND CATEGORY_ID != ?
        AND IS_DELETED = 0
    ");

    $stmt->bind_param("sii", $name, $parentId, $categoryId);
    $stmt->execute();

    echo ($stmt->get_result()->num_rows > 0) ? "exists" : "ok";
    exit;
}

// Update category process or preparation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['ajax_check_name'])) {

    // Get inputs
    $name   = trim($_POST['name'] ?? '');
    $status = $_POST['status'] ?? 'Active';
    $parentId = ($_POST['parent_id'] ?? '') !== '' ? (int)$_POST['parent_id'] : null;

    // A category that has sub-categories cannot become a sub-category
    if ($hasChildren) {
        $parentId = null;
    }

    // Name required
    if ($name === "") {
        $errors[] = "Category name required";
    }

    // Validate parent (backend must not trust the dropdown)
    if ($parentId !== null) {

        if ($parentId === $categoryId) {
            $errors[] = "A category cannot be its own parent";
        } else {
            $p = $conn->prepare("
                SELECT CATEGORY_ID
                FROM category
                WHERE CATEGORY_ID = ?
                AND PARENT_ID IS NULL
                AND IS_DELETED = 0
            ");
            $p->bind_param("i", $parentId);
            $p->execute();

            if ($p->get_result()->num_rows === 0) {
                $errors[] = "Invalid parent category";
            }
        }
    }

    // Check duplicate name - backend validation
    if (empty($errors)) {

        $check = $conn->prepare("
            SELECT CATEGORY_ID
            FROM category
            WHERE CATEGORY_NAME = ?
            AND PARENT_ID <=> ?
            AND CATEGORY_ID != ?
            AND IS_DELETED = 0
        ");

        $check->bind_param("sii", $name, $parentId, $categoryId);
        $check->execute();

        if ($check->get_result()->num_rows > 0) {
            $errors[] = "Category already exists";
        }
    }

    // Stop if errors
    if (!empty($errors)) {
        echo implode(" | ", $errors);
        exit;
    }

    // Check have cake under category or not (same logic as manage_category.php product count)
    $checkCake = $conn->prepare("
        SELECT COUNT(*) AS total
        FROM product_category pc
        JOIN product pr ON pr.PRODUCT_ID = pc.PRODUCT_ID AND pr.IS_DELETED = 0
        WHERE pc.CATEGORY_ID = ?
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
            PARENT_ID = ?
        WHERE CATEGORY_ID = ?
    ");

    $stmt->bind_param(
        "ssii",
        $name,
        $status,
        $parentId,
        $categoryId
    );

    if (!$stmt->execute()) {
        echo "Update failed";
        exit;
    }

    echo "ok";
    exit;
}

// Main categories for the parent dropdown (exclude itself)
$stmtP = $conn->prepare("
    SELECT CATEGORY_ID, CATEGORY_NAME
    FROM category
    WHERE PARENT_ID IS NULL
    AND IS_DELETED = 0
    AND CATEGORY_ID != ?
    ORDER BY CATEGORY_NAME
");
$stmtP->bind_param("i", $categoryId);
$stmtP->execute();
$parents = $stmtP->get_result();

$currentParent = (int)($category['PARENT_ID'] ?? 0);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Category | Admin</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="admin_global.css">
    <link rel="stylesheet" href="global_add_edit_form.css">
<style>
:root {
    --bg-page: #f4f5f8;
    --card-bg: #ffffff;
    --text-dark: #111827;
    --text-muted: #71717a;
    --text-light: #9ca3af;
    --accent-lime: #d4f938;
    --accent-lime-hover: #c4ec29;
    --accent-lime-subtle: #f4fde6;
    --dark-pill: #18181b;
    --dark-pill-hover: #27272a;
    --border-card: #edf0f3;
    --border-subtle: #f1f3f6;
    --shadow-soft: 0 4px 20px rgba(17, 24, 39, 0.035);
    --shadow-hover: 0 10px 28px rgba(17, 24, 39, 0.07);
    --radius-card: 20px;
    --radius-pill: 9999px;
    --font-heading: 'Plus Jakarta Sans', sans-serif;
    --font-body: 'Inter', -apple-system, sans-serif;
}

body {
    background-color: var(--bg-page);
    font-family: var(--font-body);
    color: var(--text-dark);
    margin: 0;
    padding: 0;
    -webkit-font-smoothing: antialiased;
}

.form-wrapper {
    max-width: 680px;
    margin: 0 auto;
    padding: 28px 24px 60px;
}

/* Back Link */
.form-back-link {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    background: #ffffff;
    border: 1px solid var(--border-card);
    padding: 8px 18px;
    border-radius: var(--radius-pill);
    font-size: 13px;
    font-weight: 600;
    color: var(--text-dark);
    text-decoration: none;
    box-shadow: var(--shadow-soft);
    transition: all 0.2s ease;
    margin-bottom: 20px;
}

.form-back-link i:hover, .form-back-link:hover {
    background: var(--dark-pill);
    color: #ffffff !important;
    border-color: var(--dark-pill);
    transform: translateX(-2px);
    text-decoration: none;
}

/* Page Header */
.dash-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 24px;
    flex-wrap: wrap;
    gap: 16px;
}

.dash-title-group {
    display: flex;
    align-items: center;
    gap: 14px;
}

.dash-title-badge {
    width: 44px;
    height: 44px;
    border-radius: var(--radius-pill);
    background: var(--accent-lime);
    color: var(--text-dark);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 18px;
    font-weight: 800;
    box-shadow: 0 4px 12px rgba(212, 249, 56, 0.35);
    position: relative;
    cursor: default;
}

.dash-title-badge .badge-arrow {
    position: absolute;
    bottom: -2px;
    right: -2px;
    background: var(--text-dark);
    color: var(--accent-lime);
    width: 18px;
    height: 18px;
    border-radius: var(--radius-pill);
    font-size: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 700;
}

.badge-arrow i {
    color: var(--accent-lime);
}

.dash-title {
    font-family: var(--font-heading);
    font-size: 24px;
    font-weight: 500;
    color: var(--text-muted);
    line-height: 1.15;
    margin: 0;
    letter-spacing: -0.5px;
}

.dash-title strong {
    color: var(--text-dark);
    font-weight: 800;
    font-size: 26px;
    display: block;
}

/* Form Card */
.form-card {
    background: var(--card-bg);
    border: 1px solid var(--border-card);
    border-radius: var(--radius-card);
    padding: 28px 32px;
    box-shadow: var(--shadow-soft);
    position: relative;
}

.form-card-title {
    font-family: var(--font-heading);
    font-size: 16px;
    font-weight: 700;
    color: var(--text-dark);
    margin: 0 0 20px 0;
    display: flex;
    align-items: center;
    gap: 8px;
    padding-bottom: 14px;
    border-bottom: 1px solid var(--border-subtle);
}

.form-card-title i {
    color: var(--text-dark);
    font-size: 16px;
}

.form-card-title .card-badge {
    font-size: 11px;
    font-weight: 600;
    padding: 2px 8px;
    border-radius: var(--radius-pill);
    background: var(--bg-page);
    color: var(--text-muted);
    margin-left: auto;
}

/* Form Controls */
.form-group {
    display: flex;
    flex-direction: column;
    gap: 6px;
    margin-bottom: 20px;
}

.form-group label {
    font-size: 12px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: var(--text-muted);
    margin: 0;
}

.asterisk {
    color: #ef4444;
    margin-left: 2px;
}

.form-input {
    width: 100%;
    padding: 11px 16px;
    border: 1px solid var(--border-card);
    border-radius: 12px;
    font-size: 13.5px;
    background: var(--bg-page);
    color: var(--text-dark);
    font-family: var(--font-body);
    transition: all 0.2s ease;
    box-sizing: border-box;
}

.form-input:focus {
    outline: none;
    background: #ffffff;
    border-color: var(--dark-pill);
    box-shadow: 0 0 0 3px rgba(212, 249, 56, 0.4);
}

.form-input:disabled {
    background: #e9ecef;
    color: #6c757d;
    cursor: not-allowed;
    opacity: 0.8;
}

.form-input.input-error {
    border-color: #ef4444;
    background: #fff5f5;
}

.text-hint {
    font-size: 12px;
    color: var(--text-muted);
    margin-top: 4px;
}

.error {
    color: #ef4444;
    font-size: 12px;
    margin-top: 4px;
    font-weight: 500;
}

/* Actions */
.form-actions {
    margin-top: 28px;
    display: flex;
    justify-content: flex-end;
    gap: 12px;
}

.form-btn {
    background: var(--dark-pill);
    color: #ffffff;
    border: none;
    border-radius: var(--radius-pill);
    padding: 12px 28px;
    font-size: 14px;
    font-weight: 700;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    transition: all 0.2s ease;
    box-shadow: 0 4px 14px rgba(24, 24, 27, 0.15);
    font-family: var(--font-heading);
}

.form-btn:hover {
    background: #000000;
    color: var(--accent-lime);
    transform: translateY(-1px);
    box-shadow: 0 6px 20px rgba(24, 24, 27, 0.25);
}

/* Toast */
.toast {
    position: fixed;
    top: 24px;
    right: 24px;
    padding: 12px 20px;
    border-radius: var(--radius-pill);
    font-size: 13.5px;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 12px;
    box-shadow: 0 10px 30px rgba(0, 0, 0, 0.15);
    z-index: 9999;
    animation: toastIn 0.3s cubic-bezier(0.16, 1, 0.3, 1);
    transition: all 0.3s ease;
}

.toast.success {
    background: var(--dark-pill);
    color: #ffffff;
    border: 1px solid rgba(255, 255, 255, 0.1);
}

.toast.error {
    background: #ef4444;
    color: #ffffff;
}

.toast-close-btn {
    cursor: pointer;
    font-size: 16px;
    line-height: 1;
    opacity: 0.7;
    transition: opacity 0.2s;
}

.toast-close-btn:hover {
    opacity: 1;
}

@keyframes toastIn {
    from { opacity: 0; transform: translateY(-10px) scale(0.95); }
    to { opacity: 1; transform: translateY(0) scale(1); }
}
</style>
</head>

<body>

<div class="form-wrapper">

    <a href="manage_category.php" class="form-back-link" title="Go Back To Category Page">
        <i class="bi bi-arrow-left"></i> Back to Categories
    </a>

    <div class="dash-header">
        <div class="dash-title-group">
            <div class="dash-title-badge">
                <i class="bi bi-pencil-square"></i>
                <div class="badge-arrow"><i class="bi bi-arrow-up-right"></i></div>
            </div>
            <div>
                <h1 class="dash-title"><strong>Edit Category</strong> Update category details</h1>
            </div>
        </div>
    </div>

    <div class="form-card">
        <div class="form-card-title">
            <i class="bi bi-info-circle"></i> Edit Information
            <span class="card-badge">ID #<?= $categoryId ?></span>
        </div>

        <form method="POST" id="form">

            <!-- NAME -->
            <div class="form-group">
                <label>Category Name<span class="asterisk">*</span></label>
                <input type="text" name="name" id="nameInput" class="form-input" maxlength="50"
                    value="<?= htmlspecialchars($category['CATEGORY_NAME']) ?>"
                >
                <span id="nameError" class="error"></span>
            </div>

            <!-- PARENT CATEGORY -->
            <div class="form-group">
                <label>Parent Category</label>
                <select name="parent_id" id="parentSelect" class="form-input" <?= $hasChildren ? 'disabled' : '' ?>>
                    <option value="">— None (Main Category) —</option>
                    <?php while ($p = $parents->fetch_assoc()): ?>
                        <option value="<?= (int)$p['CATEGORY_ID'] ?>"
                            <?= $currentParent === (int)$p['CATEGORY_ID'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($p['CATEGORY_NAME']) ?>
                        </option>
                    <?php endwhile; ?>
                </select>
                <?php if ($hasChildren): ?>
                    <small class="text-hint">
                        This category has <?= $childCount ?> sub-categor<?= $childCount > 1 ? 'ies' : 'y' ?>,
                        so it must stay a main category.
                    </small>
                <?php else: ?>
                    <small class="text-hint">Leave empty to make this a main category</small>
                <?php endif; ?>
            </div>

            <!-- STATUS -->
            <div class="form-group">
                <label>Status<span class="asterisk">*</span></label>
                <select name="status" class="form-input">
                    <option value="Active" <?= $category['CATEGORY_STATUS']=="Active"?"selected":"" ?>>Active</option>
                    <option value="Inactive" <?= $category['CATEGORY_STATUS']=="Inactive"?"selected":"" ?>>Inactive</option>
                </select>
            </div>

            <div class="form-actions">
                <button type="submit" class="form-btn">
                    <i class="bi bi-check2"></i> Save Changes
                </button>
            </div>

        </form>
    </div>

</div>

<script>
document.addEventListener("DOMContentLoaded", function () {

    const SELF_URL = "edit_category.php?category_id=<?= $categoryId ?>";

    const form = document.getElementById("form");
    const nameInput = document.getElementById("nameInput");
    const parentSelect = document.getElementById("parentSelect");
    const nameError = document.getElementById("nameError");

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

    function checkName() {
        const name = nameInput.value.trim();

        if (isEmpty(name)) {
            setError(nameInput, nameError, "Category name required");
            return;
        }

        fetch(SELF_URL, {
            method: "POST",
            headers: { "Content-Type": "application/x-www-form-urlencoded" },
            body: "ajax_check_name=1&name=" + encodeURIComponent(name)
                + "&parent_id=" + encodeURIComponent(parentSelect.value)
        })
        .then(res => res.text())
        .then(data => {
            if (data.trim() === "exists") {
                setError(nameInput, nameError, "Already exists");
            } else {
                clearError(nameInput, nameError);
            }
        });
    }

    nameInput.addEventListener("input", checkName);
    parentSelect.addEventListener("change", function () {
        if (!isEmpty(nameInput.value)) checkName();
    });

    function sendForm(force) {
        const formData = new FormData(form);
        if (force) formData.append("force_inactive", "1");

        return fetch(SELF_URL, {
            method: "POST",
            body: formData
        }).then(res => res.text());
    }

    function handleResult(data) {
        data = data.trim();

        if (data === "ok") {
            showToast("success", "Category updated successfully");

            setTimeout(() => {
                window.location.href = "manage_category.php";
            }, 800);
            return;
        }

        if (data === "has_cake") {
            const sure = confirm(
                "There are still products under this category.\n\n" +
                "Are you sure you want to set it to Inactive?\n" +
                "All products under it will not be displayed on the website."
            );

            if (!sure) return;

            sendForm(true)
                .then(handleResult)
                .catch(() => showToast("error", "Network error"));
            return;
        }

        showToast("error", data);
    }

    form.addEventListener("submit", function (e) {
        e.preventDefault();

        let ok = true;

        if (isEmpty(nameInput.value)) {
            setError(nameInput, nameError, "Category name required");
            ok = false;
        }

        if (nameError.textContent) {
            ok = false;
        }

        if (!ok) {
            showToast("error", "Please fix errors");
            return;
        }

        sendForm(false)
            .then(handleResult)
            .catch(() => showToast("error", "Network error"));
    });

});

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
    function removeToast() {
        if (removed) return;
        removed = true;
        toast.style.opacity = "0";
        toast.style.transform = "translateX(100%)";
        setTimeout(() => toast.remove(), 300);
    }

    closeBtn.addEventListener("click", (e) => {
        e.stopPropagation(); 
        removeToast();
    });

    toast.addEventListener("click", removeToast);
    const duration = type === "error" ? 6000 : 3000;
    setTimeout(removeToast, duration);
}
</script>

</body>
</html>