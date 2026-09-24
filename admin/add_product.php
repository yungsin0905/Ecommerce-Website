<?php
require_once("config.php");
session_start();

if (!isset($_SESSION['admin_id'])) {
    header("Location: admin_login.php");
    exit;
}
$admin_id = $_SESSION['admin_id'];

// Fetch all categories (for the multi-select checkboxes)
// Fetch all categories and split into parent (PARENT_ID IS NULL) / children groups
$catRows = $conn->query("SELECT CATEGORY_ID, PARENT_ID, CATEGORY_NAME FROM category WHERE IS_DELETED = 0 ORDER BY CATEGORY_NAME")->fetch_all(MYSQLI_ASSOC);

$catParents  = [];
$catChildren = []; // PARENT_ID => [child rows]
foreach ($catRows as $c) {
    if ($c['PARENT_ID'] === null) {
        $catParents[] = $c;
    } else {
        $catChildren[$c['PARENT_ID']][] = $c;
    }
}

$errors = [];

/* =========================================================
   AJAX: Check duplicate product name (real-time validation)
   ========================================================= */
if (isset($_POST['ajax_check_name'])) {
    $name = trim($_POST['name'] ?? '');

    if ($name === "") {
        echo "ok";
        exit;
    }

    $stmt = $conn->prepare("SELECT PRODUCT_ID FROM product WHERE PRODUCT_NAME = ? AND IS_DELETED = 0");
    $stmt->bind_param("s", $name);
    $stmt->execute();

    echo ($stmt->get_result()->num_rows > 0) ? "exists" : "ok";
    exit;
}

/* =========================================================
   AJAX: Search products (for addon picker)
   ========================================================= */
if (isset($_POST['ajax_search_products'])) {
    $q = trim($_POST['q'] ?? '');
    $like = "%$q%";

    $stmt = $conn->prepare("
        SELECT PRODUCT_ID, PRODUCT_NAME, PRODUCT_CODE
        FROM product
        WHERE IS_DELETED = 0 AND (PRODUCT_NAME LIKE ? OR PRODUCT_CODE LIKE ?)
        ORDER BY PRODUCT_NAME
        LIMIT 20
    ");
    $stmt->bind_param("ss", $like, $like);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    header('Content-Type: application/json');
    echo json_encode($rows);
    exit;
}

/* =========================================================
   AJAX: Fetch variants of a chosen addon product
   ========================================================= */
if (isset($_POST['ajax_get_variants'])) {
    $pid = intval($_POST['product_id'] ?? 0);

    $stmt = $conn->prepare("
        SELECT v.VARIANT_ID, v.VARIANT_LABEL, v.SKU, v.VARIANT_PRICE,
               GROUP_CONCAT(pov.VALUE_NAME ORDER BY po.OPTION_ORDER, pov.VALUE_ORDER SEPARATOR ' / ') AS OPTION_TEXT
        FROM product_variant v
        LEFT JOIN variant_option_value vov ON vov.VARIANT_ID = v.VARIANT_ID
        LEFT JOIN product_option_value pov ON pov.OPTION_VALUE_ID = vov.OPTION_VALUE_ID
        LEFT JOIN product_option po ON po.OPTION_ID = pov.OPTION_ID
        WHERE v.PRODUCT_ID = ? AND v.IS_DELETED = 0
        GROUP BY v.VARIANT_ID
        ORDER BY v.VARIANT_ID
    ");
    $stmt->bind_param("i", $pid);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    header('Content-Type: application/json');
    echo json_encode($rows);
    exit;
}

/* =========================================================
   MAIN: Insert product
   ========================================================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['ajax_check_name'], $_POST['ajax_search_products'], $_POST['ajax_get_variants'])) {

    $name        = trim($_POST['name'] ?? '');
    $productCode = trim($_POST['product_code'] ?? '');
    $brand       = trim($_POST['brand'] ?? '');
    $status      = $_POST['status'] ?? '';
    $des         = $_POST['description'] ?? "";
    $warranty    = trim($_POST['warranty'] ?? '');

    // These columns require valid JSON (JSON_VALID CHECK constraint) since
    // the frontend (product details.php) json_decode()s them into a
    // [{group/category, items/points}] structure. The form only collects
    // free text, so wrap it into that minimal structure before inserting.
    $packingListRaw = trim($_POST['packing_list'] ?? '');
    $featuresRaw    = trim($_POST['features'] ?? '');

    $packingList = $packingListRaw === ''
        ? null
        : json_encode([[
            'group' => '',
            'items' => [['qty' => 1, 'item' => $packingListRaw]]
          ]]);

    $features = $featuresRaw === ''
        ? null
        : json_encode([[
            'category' => '',
            'points'   => [$featuresRaw]
          ]]);

    $categoryIds = $_POST['category_ids'] ?? [];

    // Options data (sent as JSON from JS - see form below)
    $optionsJson  = $_POST['options_json'] ?? '[]';
    $variantsJson = $_POST['variants_json'] ?? '[]';
    $addonsJson   = $_POST['addons_json'] ?? '[]';

    $optionGroups = json_decode($optionsJson, true) ?? [];
    $variants     = json_decode($variantsJson, true) ?? [];
    $addons       = json_decode($addonsJson, true) ?? [];

    // --- Basic validation ---
    if ($name === "") $errors[] = "Product name required";
    if ($productCode === "") $errors[] = "Product code required";
    if (!in_array($status, ['Active','Inactive'])) $errors[] = "Invalid status";
    if (empty($categoryIds)) $errors[] = "Select at least 1 category";
    if (empty($variants)) $errors[] = "At least 1 variant required";

    $warrantyVal = ($warranty !== '' && ctype_digit($warranty)) ? intval($warranty) : null;

    // Duplicate name check
    if (empty($errors)) {
        $check = $conn->prepare("SELECT PRODUCT_ID FROM product WHERE PRODUCT_NAME = ? AND IS_DELETED = 0");
        $check->bind_param("s", $name);
        $check->execute();
        if ($check->get_result()->num_rows > 0) {
            $errors[] = "Product already exists";
        }
    }

    // Duplicate product code check
    if (empty($errors)) {
        $check = $conn->prepare("SELECT PRODUCT_ID FROM product WHERE PRODUCT_CODE = ? AND IS_DELETED = 0");
        $check->bind_param("s", $productCode);
        $check->execute();
        if ($check->get_result()->num_rows > 0) {
            $errors[] = "Product code already exists";
        }
    }

    // --- Validate each variant row ---
    $seenSku = [];
    if (empty($errors)) {
        foreach ($variants as $v) {
            $sku   = trim($v['sku'] ?? '');
            $price = $v['price'] ?? '';
            $stock = $v['stock'] ?? '';
            $vstatus = $v['status'] ?? 'Active';

            if ($price === '' || !is_numeric($price) || $price < 0) {
                $errors[] = "Invalid variant price"; break;
            }
            if ($stock === '' || !ctype_digit(strval($stock))) {
                $errors[] = "Invalid variant stock"; break;
            }
            if (!in_array($vstatus, ['Active','Inactive'])) {
                $errors[] = "Invalid variant status"; break;
            }
            if ($sku !== '') {
                if (isset($seenSku[$sku])) { $errors[] = "Duplicate SKU: $sku"; break; }
                $seenSku[$sku] = true;
            }
        }
    }

    // --- Cover image ---
    $imagePath = null;
    if (empty($errors) && !empty($_FILES['cover']['name'])) {
        $allowedTypes = ['image/jpeg','image/png','image/webp'];
        $maxSize = 2 * 1024 * 1024;

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $type = finfo_file($finfo, $_FILES['cover']['tmp_name']);
        finfo_close($finfo);
        $size = $_FILES['cover']['size'];

        if (!in_array($type, $allowedTypes)) $errors[] = "Invalid cover image type (only jpeg, png, webp)";
        if ($size > $maxSize) $errors[] = "Cover image too large (max 2MB)";

        if (empty($errors)) {
            $dir = "uploads/product/";
            if (!is_dir($dir)) mkdir($dir, 0777, true);
            $extMap = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];
            $file = time() . "_" . uniqid() . "." . $extMap[$type];
            $path = $dir . $file;

            if (!move_uploaded_file($_FILES['cover']['tmp_name'], $path)) {
                $errors[] = "Upload failed";
            } else {
                $imagePath = $path;
            }
        }
    }

    if (!empty($errors)) {
        echo implode(" | ", $errors);
        exit;
    }

    // ================= INSERT (transaction) =================
    mysqli_begin_transaction($conn);
    try {
        // 1. product
        $stmt = $conn->prepare("
            INSERT INTO product
            (PRODUCT_NAME, PRODUCT_CODE, BRAND, PRODUCT_DES, WARRANTY,
             PACKING_LIST, FEATURES, PRODUCT_STATUS, COVER_IMAGE, IS_DELETED)
            VALUES (?,?,?,?,?,?,?,?,?,0)
        ");
        $stmt->bind_param("ssssissss", $name, $productCode, $brand, $des, $warrantyVal, $packingList, $features, $status, $imagePath);
        if (!$stmt->execute()) throw new Exception("Insert product failed: " . $conn->error);
        $productId = $stmt->insert_id;

        // 2. categories
        $stmtCat = $conn->prepare("INSERT INTO product_category (PRODUCT_ID, CATEGORY_ID) VALUES (?,?)");
        foreach ($categoryIds as $cid) {
            $cid = intval($cid);
            if ($cid <= 0) continue;
            $stmtCat->bind_param("ii", $productId, $cid);
            $stmtCat->execute();
        }

        // 3. option groups + values
        $valueIdMap = [];
        $stmtOpt  = $conn->prepare("INSERT INTO product_option (PRODUCT_ID, OPTION_NAME, OPTION_ORDER, IS_DELETED) VALUES (?,?,?,0)");
        $stmtOptV = $conn->prepare("INSERT INTO product_option_value (OPTION_ID, VALUE_NAME, VALUE_ORDER, IS_DELETED) VALUES (?,?,?,0)");

        foreach ($optionGroups as $gi => $group) {
            $optionName = trim($group['name'] ?? '');
            if ($optionName === '') continue;

            $stmtOpt->bind_param("isi", $productId, $optionName, $gi);
            $stmtOpt->execute();
            $optionId = $stmtOpt->insert_id;

            foreach (($group['values'] ?? []) as $vi => $valObj) {
                $valueName = trim($valObj['name'] ?? '');
                if ($valueName === '') continue;

                $stmtOptV->bind_param("isi", $optionId, $valueName, $vi);
                $stmtOptV->execute();
                $valueIdMap["{$gi}_{$vi}"] = $stmtOptV->insert_id;
            }
        }

        // 4. variants + variant_option_value links
        $stmtVar = $conn->prepare("
            INSERT INTO product_variant
            (PRODUCT_ID, SKU, VARIANT_LABEL, VARIANT_PRICE, SALE_PRICE, VARIANT_STOCK, VARIANT_STATUS, IS_DELETED)
            VALUES (?,?,?,?,?,?,?,0)
        ");
        $stmtVOV = $conn->prepare("INSERT INTO variant_option_value (VARIANT_ID, OPTION_VALUE_ID) VALUES (?,?)");

        $imageToVariants = []; // imageLocalId => [variantId, ...]

        foreach ($variants as $v) {
            $sku       = trim($v['sku'] ?? '');
            $sku       = ($sku === '') ? null : $sku;
            $label     = trim($v['label'] ?? '') ?: null;
            $price     = floatval($v['price']);
            $salePrice = (isset($v['sale_price']) && $v['sale_price'] !== '') ? floatval($v['sale_price']) : null;
            $stock     = intval($v['stock']);
            $vstatus   = $v['status'] ?? 'Active';

            $stmtVar->bind_param("issddis", $productId, $sku, $label, $price, $salePrice, $stock, $vstatus);
            $stmtVar->execute();
            $variantId = $stmtVar->insert_id;

            foreach (($v['combo'] ?? []) as $key) {
                if (!isset($valueIdMap[$key])) continue;
                $ovId = $valueIdMap[$key];
                $stmtVOV->bind_param("ii", $variantId, $ovId);
                $stmtVOV->execute();
            }

            // 记住这个 variant 选了哪张图
            $imgLocalId = $v['imageLocalId'] ?? null;
            if ($imgLocalId !== null && $imgLocalId !== '') {
                $imageToVariants[intval($imgLocalId)][] = $variantId;
            }
        }

        // 5. product images (每个 variant 一行；没关联任何 variant 的图 VARIANT_ID = NULL)
        if (!empty($_FILES['images']['name'])) {
            $allowedTypes = ['image/jpeg','image/png','image/webp'];
            $maxSize = 2 * 1024 * 1024;
            $dir = "uploads/product/";
            if (!is_dir($dir)) mkdir($dir, 0777, true);

            $stmtI = $conn->prepare("INSERT INTO product_images (PRODUCT_ID, VARIANT_ID, IMAGE_PATH, IS_DELETED) VALUES (?,?,?,0)");

            foreach ($_FILES['images']['name'] as $localId => $n) {
                if ($_FILES['images']['error'][$localId] !== UPLOAD_ERR_OK) continue;

                $tmp  = $_FILES['images']['tmp_name'][$localId];
                $size = $_FILES['images']['size'][$localId];

                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $type  = finfo_file($finfo, $tmp);
                finfo_close($finfo);

                if (!in_array($type, $allowedTypes) || $size > $maxSize) continue;

                $extMap = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];
                $path = $dir . time() . "_" . uniqid() . "." . $extMap[$type];

                if (move_uploaded_file($tmp, $path)) {
                    // 同一张图可以被多个 variant 共用：文件只存一份，每个 variant 一行记录
                    $linked = $imageToVariants[intval($localId)] ?? [null];
                    foreach ($linked as $vid) {
                        $stmtI->bind_param("iis", $productId, $vid, $path);
                        $stmtI->execute();
                    }
                }
            }
        }

        // 6. addons
        $stmtAddon = $conn->prepare("
            INSERT INTO product_addon (HOST_PRODUCT_ID, ADDON_PRODUCT_ID, ADDON_VARIANT_ID, ADDON_PRICE, SORT_ORDER, IS_DELETED)
            VALUES (?,?,?,?,?,0)
        ");
        foreach ($addons as $i => $a) {
            $addonProductId = intval($a['product_id'] ?? 0);
            if ($addonProductId <= 0) continue;
            $addonVariantId = !empty($a['variant_id']) ? intval($a['variant_id']) : null;
            $addonPrice     = (isset($a['price']) && $a['price'] !== '') ? floatval($a['price']) : null;

            $stmtAddon->bind_param("iiidi", $productId, $addonProductId, $addonVariantId, $addonPrice, $i);
            $stmtAddon->execute();
        }

        mysqli_commit($conn);
        echo "ok";
        exit;

    } catch (Exception $e) {
        mysqli_rollback($conn);
        error_log("add_product.php error: " . $e->getMessage());
        echo "Insert failed: " . $e->getMessage();
        exit;
    }
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
    max-width: 1200px;
}

.form-container2 {
    display: flex;
    gap: 30px;
    align-items: flex-start;
    margin-bottom: 20px;
}

.form-left {
    flex: 1;
    min-width: 0;        /* 防止 variant 行把左栏撑爆 */
}

.form-right {
    flex: 0 0 340px;     /* 右栏固定宽度 */
    background: #fafafa;
    padding: 15px;
    border-radius: 8px;
    border: 1px solid #eee;
    
}

.form-row {
    display: grid;
    gap: 16px;
}

.form-row.two   { grid-template-columns: 1fr 1fr; }
.form-row.three { grid-template-columns: 1fr 1fr 1fr; }
.form-row > .form-group { min-width: 0; }

.form-right #extraPreview img,
.form-right #coverPreview { width: 90px !important; }

@media (max-width: 900px) {
    .form-container2 { flex-direction: column; }
    .form-right { flex: 1 1 auto; width: 100%; position: static; }
    .form-row.two, .form-row.three { grid-template-columns: 1fr; }
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

/* ===== Category checkbox list ===== */
.form-group .addon-list label {
    font-weight: normal;
}

.category-tree {
    display: flex;
    flex-direction: column;
    gap: 4px;
    max-height: 280px;
    overflow-y: auto;
}

.category-parent-group {
    border: 1px solid #eee;
    border-radius: 6px;
    background: #fff;
}

.category-parent-header {
    display: flex;
    align-items: center;
    gap: 6px;
    padding: 8px 10px;
    cursor: pointer;
}

.category-parent-header:hover {
    background: #f6f6f6;
}

.category-parent-header label {
    display: flex;
    align-items: center;
    gap: 8px;
    font-weight: bold;
    margin: 0;
    cursor: pointer;
}

.category-toggle-icon {
    transition: transform 0.2s ease;
    font-size: 12px;
    color: #666;
}

.category-children {
    display: none;
    flex-direction: column;
    gap: 4px;
    padding: 4px 10px 8px 30px;
}

.category-children label {
    display: flex;
    align-items: center;
    gap: 8px;
    font-weight: normal;
    margin: 0;
    padding: 4px 0;
    cursor: pointer;
}

/* ===== Option groups ===== */
#optionGroups {
    display: flex;
    flex-direction: column;
    gap: 14px;
    margin: 12px 0;
}

.option-group-box {
    background: #fff;
    border: 1px solid #e5e5e5;
    border-radius: 8px;
    padding: 14px;
}

.option-group-box > input[type="text"] {
    width: 60%;
    padding: 8px;
    border: 1px solid #ddd;
    border-radius: 6px;
    font-size: 14px;
    font-weight: bold;
    margin-right: 8px;
}

.option-group-box > button {
    padding: 6px 10px;
    border: none;
    border-radius: 6px;
    background: #ff4d4d;
    color: white;
    font-size: 12px;
    cursor: pointer;
}

.option-group-box > button:hover {
    background: #e60000;
}

.option-values {
    display: flex;
    flex-direction: column;
    gap: 8px;
    margin: 12px 0;
}

.option-values > div {
    display: flex;
    align-items: center;
    gap: 8px;
}

.option-values input[type="text"] {
    flex: 1;
    padding: 8px;
    border: 1px solid #ddd;
    border-radius: 6px;
    font-size: 14px;
}

.option-values button {
    padding: 4px 9px;
    border: none;
    border-radius: 50px;
    background: #ff4d4d;
    color: white;
    font-size: 12px;
    cursor: pointer;
    flex-shrink: 0;
}

.option-values button:hover {
    background: #e60000;
}

/* "+ Add Option Group" / "+ Add Value" buttons */
#optionGroups + button,
.option-group-box .option-values + button {
    padding: 8px 14px;
    border: none;
    border-radius: 8px;
    background: var(--primary-light);
    color: var(--primary-dark);
    font-size: 13px;
    cursor: pointer;
    transition: 0.2s;
    margin-bottom:20px;
}

#optionGroups + button:hover,
.option-group-box .option-values + button:hover {
    background: var(--primary-dark);
    color: var(--primary-light);
}

/* ===== Variant table ===== */
#variantTable {
    display: flex;
    flex-direction: column;
    gap: 10px;
    margin: 12px 0 20px;
}

.variant-row-box {
    background: #fff;
    border: 1px solid #e5e5e5;
    border-radius: 10px;
    padding: 14px 16px;
    display: flex;
    flex-direction: column;
    gap: 12px;
}

.variant-row-box input,
.variant-row-box select {
    padding: 8px;
    border: 1px solid #ddd;
    border-radius: 6px;
    font-size: 13px;
    flex: 1;
    min-width: 100px;
}

.variant-head {
    display: flex;
    align-items: center;
    gap: 10px;
}

.variant-toggle {
    flex: 0 0 auto;
    width: 18px;
    height: 18px;
    cursor: pointer;
}

.variant-head .variant-toggle {
    width: 18px;
    height: 18px;
    flex: 0 0 18px;
    margin: 0;
}

.variant-disabled {
    opacity: 0.45;
}

.variant-disabled input,
.variant-disabled select {
    cursor: not-allowed;
}

.variant-combo-tag {
    flex: 1;
    min-width: 0;
    font-size: 14px;
    font-weight: 600;
    color: #333;
    word-break: break-word;      /* 组合名很长时自动换行 */
}

.variant-status {
    flex: 0 0 110px;
    width: 110px;
    padding: 6px 8px;
    border: 1px solid #ddd;
    border-radius: 6px;
    font-size: 13px;
}

.variant-fields {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 10px 12px;
}
.vf {
    display: flex;
    flex-direction: column;
    gap: 4px;
    margin: 0;
    min-width: 0;
    font-weight: normal;
}

.vf > span {
    font-size: 12px;
    color: #666;
}

.vf input {
    width: 100%;
    box-sizing: border-box;
    padding: 8px 10px;
    border: 1px solid #ddd;
    border-radius: 6px;
    font-size: 13px;
}

.vf-full { grid-column: 1 / -1; }

/* 底部：图片选择 */
.variant-image-row {
    display: flex;
    align-items: center;
    gap: 12px;
    padding-top: 10px;
    border-top: 1px dashed #eee;
}

.variant-image-row > .vf-title {
    flex: 0 0 auto;
    font-size: 12px;
    color: #666;
}

.variant-image-picker {
    flex: 1;
    min-width: 0;
    display: flex;
    align-items: center;
}

/* 取消勾选：字段区变灰，头部保持清晰，方便重新勾选 */
.variant-disabled .variant-fields,
.variant-disabled .variant-image-row { opacity: 0.45; }
.variant-disabled .variant-combo-tag { color: #999; text-decoration: line-through; }
.variant-disabled input:not(.variant-toggle),
.variant-disabled select { cursor: not-allowed; }
.variant-disabled .variant-image-picker { pointer-events: none; }

@media (max-width: 700px) {
    .variant-fields { grid-template-columns: 1fr 1fr; }
}

/* ===== Add-ons ===== */
#addonSearch {
    width: 100%;
    padding: 10px;
    border: 1px solid #ddd;
    border-radius: 8px;
    font-size: 14px;
    margin-bottom: 6px;
    box-sizing: border-box;
}

#addonResults {
    position: relative;
    background: #fff;
    border: 1px solid #eee;
    border-radius: 8px;
    max-height: 200px;
    overflow-y: auto;
    margin-bottom: 10px;
}

#addonResults:empty {
    border: none;
}

.addon-search-item {
    padding: 8px 12px;
    font-size: 14px;
    cursor: pointer;
    border-bottom: 1px solid #f0f0f0;
}

.addon-search-item:last-child {
    border-bottom: none;
}

.addon-search-item:hover {
    background: #f6f6f6;
}

#addonList {
    display: flex;
    flex-direction: column;
    gap: 8px;
    margin-bottom: 20px;
}

.addon-row-box {
    display: flex;
    align-items: center;
    gap: 10px;
    background: #fff;
    border: 1px solid #e5e5e5;
    border-radius: 8px;
    padding: 10px 12px;
    font-size: 14px;
    flex-wrap: wrap;
}

.addon-row-box input {
    padding: 6px 8px;
    border: 1px solid #ddd;
    border-radius: 6px;
    font-size: 13px;
    width: 160px;
}

.addon-row-box button {
    margin-left: auto;
    padding: 4px 9px;
    border: none;
    border-radius: 50px;
    background: #ff4d4d;
    color: white;
    font-size: 12px;
    cursor: pointer;
}

.addon-row-box button:hover {
    background: #e60000;
}

.addon-picker {
    background: #f8faff;
    border: 1px solid #cfdcff;
    border-radius: 8px;
    padding: 12px;
    margin-bottom: 10px;
    display: flex;
    flex-direction: column;
    gap: 6px;
    font-size: 14px;
}
.addon-picker-row {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 6px 8px;
    background: #fff;
    border: 1px solid #eee;
    border-radius: 6px;
    cursor: pointer;
    margin: 0;
    font-weight: normal;
}
.addon-picker-row small { margin-left: auto; color: #888; }
.addon-picker-actions { display: flex; gap: 8px; margin-top: 4px; }
.addon-picker-actions button {
    padding: 6px 12px; border: none; border-radius: 6px;
    background: var(--primary-light); color: var(--primary-dark); cursor: pointer;
}
/* ===== Option value -> product image picker ===== */
.ov-image-hint {
    font-size: 12px;
    color: #999;
}

.ov-image-thumbs {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
}

.ov-thumb {
    width: 34px;
    height: 34px;
    border-radius: 6px;
    border: 2px solid #eee;
    overflow: hidden;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    background: #fff;
    flex-shrink: 0;
}

.ov-thumb img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.ov-thumb:hover {
    border-color: #ccc;
}

.ov-thumb.selected {
    border-color: var(--primary-dark, #4a90d9);
}

.ov-thumb-none {
    font-size: 12px;
    color: #999;
}
</style>
</head>

<body>

<div class="form-wrapper">

    <a href="manage_product.php" class="form-back-link" title="Go Back To Product Page">← Back</a>

    <form method="POST" enctype="multipart/form-data" id="form">

    <div class="form-container">

        <div class="form-container2">

            <!-- ================= LEFT ================= -->
            <div class="form-left">

                <h2 class="form-pro-title">Add Product</h2>

                <!-- Name + Product Code -->
                <div class="form-row two">
                    <div class="form-group">
                        <label>Name<span class="asterisk">*</span></label>
                        <input type="text" id="nameInput" name="name" class="form-input" maxlength="50" required>
                        <span id="nameError" class="error"></span>
                    </div>

                    <div class="form-group">
                        <label>Product Code<span class="asterisk">*</span></label>
                        <input type="text" name="product_code" id="productCode" class="form-input" maxlength="50" required>
                        <span id="productCodeError" class="error"></span>
                    </div>
                </div>

                <!-- Status + Brand + Warranty -->
                <div class="form-row three">
                    <div class="form-group">
                        <label>Status<span class="asterisk">*</span></label>
                        <select name="status" class="form-input" required>
                            <option value="Active">Active</option>
                            <option value="Inactive">Inactive</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Brand<span class="asterisk">*</span></label>
                        <input type="text" name="brand" id="brandInput" class="form-input" maxlength="50" required>
                        <span id="brandError" class="error"></span>
                    </div>

                    <div class="form-group">
                        <label>Warranty (months)<span class="asterisk">*</span></label>
                        <input type="number" name="warranty" class="form-input" min="0" required>
                    </div>
                </div>

                <!-- ===== OPTIONS BUILDER ===== -->
                <h3>Options (optional)</h3>
                <div id="optionGroups"></div>
                <button type="button" onclick="addOptionGroup()">+ Add Option Group</button>

                <!-- ===== VARIANTS ===== -->
                <h3>Variants<span class="asterisk">*</span></h3>
                <span id="variantError" class="error"></span>
                <div id="variantTable"></div>

                <!-- ===== ADDONS ===== -->
                <h3>Add-ons</h3>
                <input type="text" id="addonSearch" placeholder="Search product to add as addon...">
                <div id="addonResults"></div>
                <div id="addonPicker"></div> 
                <div id="addonList"></div>

                <!-- hidden fields carrying JSON to PHP -->
                <input type="hidden" name="options_json" id="optionsJson">
                <input type="hidden" name="variants_json" id="variantsJson">
                <input type="hidden" name="addons_json" id="addonsJson">

                <div class="form-group">
                    <label>Packing List</label>
                    <textarea name="packing_list" class="form-input form-textarea" placeholder="e.g., 1x micro:bit V2, 1x USB cable, 1x soil sensor" required></textarea>
                </div>

                <div class="form-group">
                    <label>Features</label>
                    <textarea name="features" class="form-input form-textarea" placeholder="e.g., Bluetooth enabled, waterproof sensor" required></textarea>
                </div>

            </div>

            <!-- ================= RIGHT ================= -->
            <div class="form-right">

                <div class="form-group">
                    <label>Category<span class="asterisk">*</span></label>
                    <div class="category-tree">
                        <?php foreach ($catParents as $parent): ?>
                            <div class="category-parent-group">
                                <div class="category-parent-header" onclick="toggleCategoryGroup(this)">
                                    <i class="bi bi-chevron-right category-toggle-icon"></i>
                                    <label onclick="event.stopPropagation()">
                                        <input type="checkbox" name="category_ids[]" value="<?= $parent['CATEGORY_ID'] ?>">
                                        <?= htmlspecialchars($parent['CATEGORY_NAME']) ?>
                                    </label>
                                </div>
                                <?php if (!empty($catChildren[$parent['CATEGORY_ID']])): ?>
                                    <div class="category-children">
                                        <?php foreach ($catChildren[$parent['CATEGORY_ID']] as $child): ?>
                                            <label>
                                                <input type="checkbox" name="category_ids[]" value="<?= $child['CATEGORY_ID'] ?>">
                                                <?= htmlspecialchars($child['CATEGORY_NAME']) ?>
                                            </label>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <span id="categoryError" class="error"></span>
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

        </div>
        </div>

        <button type="submit" class="form-btn">Create</button>

    </div>
        
    </form>

</div>

<script>

    function esc(s) {
    return String(s ?? '').replace(/[&<>"']/g, c =>
        ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
}

    // Global state for options / variants / addons
        let optionGroups = [];       // [{ name, values: [{ name, imageLocalId }] }]
        let variantData  = {};
        let addons       = [];
        let nextImageLocalId = 1;    // assigns a stable id to each "Product Images" file so
                                      // option values can reference it even after re-renders

    function addOptionGroup() {
        optionGroups.push({ name: '', values: [{ name: '', imageLocalId: null }] });
        renderOptionGroups();
    }

    function toggleCategoryGroup(header) {
    const children = header.nextElementSibling;
    if (!children || !children.classList.contains('category-children')) return;

    const icon = header.querySelector('.category-toggle-icon');
    const isOpen = children.style.display === 'flex';

    children.style.display = isOpen ? 'none' : 'flex';
    icon.classList.toggle('bi-chevron-right', isOpen);
    icon.classList.toggle('bi-chevron-down', !isOpen);
}

function removeOptionGroup(gi) {
    optionGroups.splice(gi, 1);
    renderOptionGroups();
}

let lastCombos = [];

function generateVariantCombos() {
    const validGroups = optionGroups
        .map((g, gi) => ({
            gi,
            name: g.name,
            values: g.values.map((v, vi) => ({ vi, v: v.name })).filter(x => x.v.trim() !== '')
        }))
        .filter(g => g.name.trim() !== '' && g.values.length > 0);

    let combos = [[]];

    if (validGroups.length === 0) {
        combos = [[]];
    } else {
        validGroups.forEach(g => {
            const next = [];
            combos.forEach(existing => {
                g.values.forEach(val => {
                    next.push([...existing, { gi: g.gi, vi: val.vi, name: g.name, value: val.v }]);
                });
            });
            combos = next;
        });
    }

    lastCombos = combos;
    renderVariantTable(combos);
}

function renderVariantTable(combos) {
    const box = document.getElementById('variantTable');
    box.innerHTML = '';

    const newVariantData = {};

    combos.forEach(combo => {
        const key = combo.map(c => `${c.gi}_${c.vi}`).join('|');
        const autoName = combo.length ? combo.map(c => c.value).join(' / ') : 'Default';

        const existing = variantData[key] || { label:'', sku:'', price:'', sale_price:'', stock:'', status:'Active', imageLocalId: null, _enabled: true };
        newVariantData[key] = existing;

        const row = document.createElement('div');
        const en = existing._enabled;
        row.className = 'variant-row-box' + (en ? '' : ' variant-disabled');
        row.innerHTML = `
            <div class="variant-head">
                <input type="checkbox" class="variant-toggle" ${en ? 'checked' : ''}
                    onchange="toggleVariant('${key}', this.checked)">
                <span class="variant-combo-tag" title="Option combination">${esc(autoName)}</span>
                <select class="variant-status" ${en ? '' : 'disabled'}
                    onchange="variantData['${key}'].status = this.value">
                    <option ${existing.status==='Active'?'selected':''}>Active</option>
                    <option ${existing.status==='Inactive'?'selected':''}>Inactive</option>
                </select>
            </div>

            <div class="variant-fields">
                <label class="vf vf-full">
                    <span>Variant Label</span>
                    <input type="text" placeholder="e.g. Kit with micro:bit V2 + USB cable"
                        value="${esc(existing.label)}" ${en ? '' : 'disabled'}
                        onchange="variantData['${key}'].label = this.value">
                </label>
                <label class="vf">
                    <span>SKU</span>
                    <input type="text" value="${esc(existing.sku)}" ${en ? '' : 'disabled'}
                        onchange="variantData['${key}'].sku = this.value">
                </label>
                <label class="vf">
                    <span>Price (RM)<span class="asterisk">*</span></span>
                    <input type="number" step="0.01" min="0" placeholder="0.00"
                        value="${esc(existing.price)}" ${en ? '' : 'disabled'}
                        onchange="variantData['${key}'].price = this.value">
                </label>
                <label class="vf">
                    <span>Sale Price (RM)</span>
                    <input type="number" step="0.01" min="0" placeholder="Optional"
                        value="${esc(existing.sale_price)}" ${en ? '' : 'disabled'}
                        onchange="variantData['${key}'].sale_price = this.value">
                </label>
                <label class="vf">
                    <span>Stock<span class="asterisk">*</span></span>
                    <input type="number" min="0" placeholder="0"
                        value="${esc(existing.stock)}" ${en ? '' : 'disabled'}
                        onchange="variantData['${key}'].stock = this.value">
                </label>
            </div>

            <div class="variant-image-row">
                <span class="vf-title">Image</span>
                <div class="variant-image-picker">${renderVariantImagePicker(key, existing.imageLocalId ?? null)}</div>
            </div>
        `;
        box.appendChild(row);

        newVariantData[key]._combo = combo.map(c => `${c.gi}_${c.vi}`);
        newVariantData[key]._enabled = existing._enabled;

        
    });

    variantData = newVariantData;
}

function toggleVariant(key, enabled) {
    variantData[key]._enabled = enabled;
    renderVariantTable(lastCombos); // re-render to grey out / re-enable inputs

}

function esc(s) {
    return String(s ?? '').replace(/[&<>"']/g, c =>
        ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
}

let pendingAddon = null;

function pickAddonProduct(productId, productName) {
    fetch('add_product.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'ajax_get_variants=1&product_id=' + productId
    })
    .then(r => r.json())
    .then(variants => {
        document.getElementById('addonResults').innerHTML = '';
        document.getElementById('addonSearch').value = '';

        // 没有 option（只有 0 或 1 个 variant）：直接加入，不用选
        if (variants.length <= 1) {
            const v = variants[0];
            addAddons([{
                product_id: productId, product_name: productName,
                variant_id: v ? v.VARIANT_ID : null,
                variant_label: v ? (v.OPTION_TEXT || null) : null,
                base_price: v ? v.VARIANT_PRICE : null,
                price: ''
            }]);
            return;
        }

        // 有多个 option 组合：让管理员选
        pendingAddon = { productId, productName, variants };
        renderAddonPicker();
    });
}

function renderAddonPicker() {
    const box = document.getElementById('addonPicker');
    if (!pendingAddon) { box.innerHTML = ''; return; }
    const p = pendingAddon;

    box.innerHTML = `
        <div class="addon-picker">
            <strong>${esc(p.productName)}</strong>
            <span>Select some options for add-on (multiple choice)</span>
            <label class="addon-picker-row">
                <input type="checkbox" id="apAny" onchange="syncAddonPicker(this)">
                <span>Any option (choosed by customers) </span>
            </label>
            ${p.variants.map(v => `
                <label class="addon-picker-row">
                    <input type="checkbox" class="apVariant" value="${v.VARIANT_ID}" onchange="syncAddonPicker(this)">
                    <span>${esc(v.OPTION_TEXT || v.VARIANT_LABEL || v.SKU || 'Default')}</span>
                    <small>RM ${Number(v.VARIANT_PRICE).toFixed(2)}</small>
                </label>
            `).join('')}
            <div class="addon-picker-actions">
                <button type="button" onclick="confirmAddonPicker()">Add selected</button>
                <button type="button" onclick="cancelAddonPicker()">Cancel</button>
            </div>
        </div>`;
}

// "Any option" 和具体 option 互斥
function syncAddonPicker(el) {
    if (!el.checked) return;
    if (el.id === 'apAny') {
        document.querySelectorAll('#addonPicker .apVariant').forEach(cb => cb.checked = false);
    } else {
        document.getElementById('apAny').checked = false;
    }
}

function confirmAddonPicker() {
    const p = pendingAddon;
    if (!p) return;
    const rows = [];

    if (document.getElementById('apAny').checked) {
        rows.push({ product_id: p.productId, product_name: p.productName,
                    variant_id: null, variant_label: 'Any option', base_price: null, price: '' });
    } else {
        document.querySelectorAll('#addonPicker .apVariant:checked').forEach(cb => {
            const v = p.variants.find(x => String(x.VARIANT_ID) === cb.value);
            rows.push({
                product_id: p.productId, product_name: p.productName,
                variant_id: v.VARIANT_ID,
                variant_label: v.OPTION_TEXT || v.VARIANT_LABEL || v.SKU || 'Default',
                base_price: v.VARIANT_PRICE, price: ''
            });
        });
    }

    if (rows.length === 0) { showToast('error', 'Select at least one option'); return; }

    addAddons(rows);
    cancelAddonPicker();
}

function cancelAddonPicker() {
    pendingAddon = null;
    renderAddonPicker();
}

function addAddons(rows) {
    rows.forEach(r => {
        // 同一商品同一 option 不重复添加
        if (!addons.some(a => a.product_id == r.product_id && a.variant_id == r.variant_id)) {
            addons.push(r);
        }
    });
    renderAddonList();
}

function renderAddonList() {
    const box = document.getElementById('addonList');
    box.innerHTML = addons.map((a, i) => `
        <div class="addon-row-box">
            ${esc(a.product_name)}${a.variant_label ? ' — ' + esc(a.variant_label) : ''}
            <input type="number" step="0.01"
                placeholder="${a.base_price != null ? 'Override price (default RM ' + Number(a.base_price).toFixed(2) + ')' : 'Override price (optional)'}"
                value="${esc(a.price)}"
                onchange="addons[${i}].price = this.value">
            <button type="button" onclick="removeAddon(${i})">✕</button>
        </div>
    `).join('');
}


function removeAddon(i) {
    addons.splice(i, 1);
    renderAddonList();
}

let selectedFiles = [];

function addOptionValue(gi) {
    optionGroups[gi].values.push({ name: '', imageLocalId: null });
    renderOptionGroups();
}

function removeOptionValue(gi, vi) {
    optionGroups[gi].values.splice(vi, 1);
    renderOptionGroups();
}

function setVariantImage(key, localId) {
    variantData[key].imageLocalId = localId;
    renderVariantTable(lastCombos);
}

function renderVariantImagePicker(key, selectedLocalId) {
    if (selectedFiles.length === 0) {
        return `<span class="ov-image-hint">UUpload images from right side [Product Images] first,then come back to select this group of corresponding images.</span>`;
    }
    let html = `<div class="ov-image-thumbs">
        <div class="ov-thumb ov-thumb-none ${selectedLocalId == null ? 'selected' : ''}"
             title="不关联照片" onclick="setVariantImage('${key}', null)">✕</div>`;
    selectedFiles.forEach(entry => {
        html += `
            <div class="ov-thumb ${selectedLocalId === entry.localId ? 'selected' : ''}"
                 onclick="setVariantImage('${key}', ${entry.localId})">
                <img src="${entry.previewUrl}">
            </div>`;
    });
    return html + `</div>`;
}

function renderOptionGroups() {
    const box = document.getElementById('optionGroups');
    box.innerHTML = '';

    optionGroups.forEach((g, gi) => {
        const div = document.createElement('div');
        div.className = 'option-group-box';
        div.innerHTML = `
            <input type="text" placeholder="Option name (e.g. Kit Type)" value="${g.name}"
                onchange="optionGroups[${gi}].name = this.value; generateVariantCombos()">
            <button type="button" onclick="removeOptionGroup(${gi})">✕ Remove Group</button>
            <div class="option-values">
                ${g.values.map((v, vi) => `
                    <div>
                        <input type="text" placeholder="Value (e.g. With micro:bit)" value="${v.name}"
                            onchange="optionGroups[${gi}].values[${vi}].name = this.value; generateVariantCombos()">
                        <button type="button" onclick="removeOptionValue(${gi},${vi})">✕</button>
                    </div>
                `).join('')}
            </div>
            <button type="button" onclick="addOptionValue(${gi})">+ Add Value</button>
        `;
        box.appendChild(div);
    });

    generateVariantCombos();
}
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

    const MAX_SIZE = 2 * 1024 * 1024;
    const ALLOWED = ["image/jpeg", "image/png", "image/webp"];
    const productCodeError = document.getElementById("productCodeError");
    const brandError = document.getElementById("brandError");

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
    productCodeError.textContent = "";
    brandError.textContent = "";
    categoryError.textContent = "";

    let general = [];

    errors.forEach(err => {

        const lower = err.toLowerCase();

        if (lower.includes("code")) {
            productCodeError.textContent = err;
        }
        else if (lower.includes("brand")) {
            brandError.textContent = err;
        }
        else if (lower.includes("category")) {
            categoryError.textContent = err;
        }
        else if (lower.includes("variant") || lower.includes("sku")) {
            variantError.textContent = err;
        }
        else if (lower.includes("cover")) {
            coverError.textContent = err;
            coverInput.classList.add("input-error");
        }
        else if (lower.includes("image")) {
            extraError.textContent = err;
            imagesInput.classList.add("input-error");
        }
        else if (lower.includes("name")) {
            nameError.textContent = err;
            nameInput.classList.add("input-error");
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
                f.file.name === file.name && f.file.size === file.size
            );

            if (exists) continue;

            selectedFiles.push({
                file,
                localId: nextImageLocalId++,
                previewUrl: URL.createObjectURL(file)
            });
        }

        if (hasInvalid) {
            extraError.textContent = "Some images were skipped";
            this.classList.add("input-error");
        }

        renderPreview();
        renderOptionGroups(); // refresh option-value thumbnail pickers with the new images

        this.value = ""; 
    });

        // Addon search
        const addonSearchEl  = document.getElementById('addonSearch');
        const addonResultsEl = document.getElementById('addonResults');
        let addonSearchTimeout;

        function runAddonSearch(q) {
            fetch('add_product.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'ajax_search_products=1&q=' + encodeURIComponent(q)
            })
            .then(r => r.json())
            .then(list => {
                addonResultsEl.innerHTML = list.length
                    ? list.map(p => `
                        <div class="addon-search-item" data-id="${p.PRODUCT_ID}" data-name="${esc(p.PRODUCT_NAME)}">
                            ${esc(p.PRODUCT_NAME)} <small>(${esc(p.PRODUCT_CODE)})</small>
                        </div>`).join('')
                    : `<div class="addon-search-item">No match</div>`;
            });
        }

        addonSearchEl.addEventListener('input', function () {
            clearTimeout(addonSearchTimeout);
            addonSearchTimeout = setTimeout(() => runAddonSearch(this.value.trim()), 250);
        });
        addonSearchEl.addEventListener('focus', () => runAddonSearch(addonSearchEl.value.trim()));

        addonResultsEl.addEventListener('click', e => {
            const item = e.target.closest('.addon-search-item[data-id]');
            if (item) pickAddonProduct(Number(item.dataset.id), item.dataset.name);
        });

    // Render image previews with remove buttons
    function renderPreview() {

        const container = document.getElementById("extraPreview");
        container.innerHTML = "";

        selectedFiles.forEach(entry => {

            const wrapper = document.createElement("div");
            wrapper.style.display = "inline-block";
            wrapper.style.position = "relative";
            wrapper.style.marginRight = "8px";

            const img = document.createElement("img");
            img.src = entry.previewUrl;
            img.style.width = "120px";

            const btn = document.createElement("button");
            btn.innerText = "✕";
            btn.style.position = "absolute";
            btn.style.top = "0";
            btn.style.right = "0";

            btn.onclick = () => {
                selectedFiles = selectedFiles.filter(f => f !== entry);
                // any option value that was linked to this photo loses that link
                Object.values(variantData).forEach(v => {
                    if (v.imageLocalId === entry.localId) v.imageLocalId = null;
                });
                renderPreview();
                renderOptionGroups();
            };

            wrapper.appendChild(img);
            wrapper.appendChild(btn);
            container.appendChild(wrapper);
        });
    }

    // Form submit
    form.addEventListener("submit", function (e) {

        e.preventDefault();

        document.getElementById('optionsJson').value = JSON.stringify(
            optionGroups.map(g => ({ name: g.name, values: g.values.map(v => ({ name: v.name })) }))
        );

        const variantsArr = Object.keys(variantData)
        .filter(key => variantData[key]._enabled !== false)
        .map(key => ({
            sku: variantData[key].sku,
            label: variantData[key].label,
            price: variantData[key].price,
            sale_price: variantData[key].sale_price,
            stock: variantData[key].stock,
            status: variantData[key].status,
            imageLocalId: variantData[key].imageLocalId ?? null,
            combo: variantData[key]._combo
        }));
        document.getElementById('variantsJson').value = JSON.stringify(variantsArr);

        document.getElementById('addonsJson').value = JSON.stringify(addons.map(a => ({
            product_id: a.product_id,
            variant_id: a.variant_id,
            price: a.price
        })));

        let ok = true;

        // Name required
        if (nameInput.value.trim() === "") {
            nameInput.classList.add("input-error");
            nameError.textContent = "Name required";
            ok = false;
        }

        // At least one category required
        const categoryChecked = document.querySelectorAll('input[name="category_ids[]"]:checked').length;
        const categoryError = document.getElementById("categoryError");
        if (categoryChecked === 0) {
            categoryError.textContent = "Select at least 1 category";
            ok = false;
        } else {
            categoryError.textContent = "";
        }

        // Stop submit if validation fail
        if (!ok) {
            showToast("error", "Please fix the errors");
            return;
        }

        // If all validations pass, submit form data via AJAX
        const formData = new FormData(form);

        formData.delete("images[]");

        selectedFiles.forEach(entry => {
            // Keyed by localId (not a plain array) so the backend can report back
            // which PRODUCT_IMAGE_ID each upload became, matching option_json's imageLocalId.
            formData.append(`images[${entry.localId}]`, entry.file, entry.file.name);
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
        .catch((err) => {
            console.error(err);
            showToast("error", "Unexpected error: " + err.message);
        });
    });

    generateVariantCombos();
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
        Object.values(variantData).forEach(v => { v.imageLocalId = null; });
        renderOptionGroups();
    }
}


</script>
</body>
</html>