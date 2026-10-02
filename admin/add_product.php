<?php

require_once("config.php");
session_start();

$siteRoot = rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'])), '/\\') . '/';

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

if (isset($_POST['ajax_check_code'])) {
    $code = trim($_POST['code'] ?? '');
    if ($code === "") { echo "ok"; exit; }
    $stmt = $conn->prepare("
        SELECT 1 FROM product WHERE PRODUCT_CODE = ? AND IS_DELETED = 0
        UNION
        SELECT 1 FROM product_variant WHERE SKU = ? AND IS_DELETED = 0
        LIMIT 1
    ");
    $stmt->bind_param("ss", $code, $code);
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
    $packingGroups = json_decode($_POST['packing_json']  ?? '[]', true) ?: [];
    $featureGroups = json_decode($_POST['features_json'] ?? '[]', true) ?: [];
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
    // --- Category parent/child consistency check ---
    if (empty($errors)) {
        $submittedIds = array_map('intval', $categoryIds);

        // Rule 1: 选了子分类，母分类必须也选
        foreach ($catChildren as $parentId => $children) {
            foreach ($children as $child) {
                if (in_array((int)$child['CATEGORY_ID'], $submittedIds, true) && !in_array((int)$parentId, $submittedIds, true)) {
                    $errors[] = "Please also select the parent category for: " . $child['CATEGORY_NAME'];
                }
            }
        }

        // Rule 2: 选了母分类、且它有子分类，必须至少选一个子分类
        foreach ($catParents as $parent) {
            $pid = (int)$parent['CATEGORY_ID'];
            if (!in_array($pid, $submittedIds, true)) continue;              // 这个母分类根本没被选，跳过
            if (empty($catChildren[$parent['CATEGORY_ID']])) continue;       // 这个母分类本来就没有子分类，跳过

            $hasChildSelected = false;
            foreach ($catChildren[$parent['CATEGORY_ID']] as $child) {
                if (in_array((int)$child['CATEGORY_ID'], $submittedIds, true)) {
                    $hasChildSelected = true;
                    break;
                }
            }
            if (!$hasChildSelected) {
                $errors[] = "Please also select a sub-category under: " . $parent['CATEGORY_NAME'];
            }
        }
    }
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

    // Duplicate product code check (vs other product codes AND existing SKUs)
    if (empty($errors)) {
        $check = $conn->prepare("SELECT 1 FROM product WHERE PRODUCT_CODE = ? AND IS_DELETED = 0 LIMIT 1");
        $check->bind_param("s", $productCode);
        $check->execute();
        if ($check->get_result()->num_rows > 0) {
            $errors[] = "Product code already exists";
        } else {
            $check = $conn->prepare("SELECT 1 FROM product_variant WHERE SKU = ? AND IS_DELETED = 0 LIMIT 1");
            $check->bind_param("s", $productCode);
            $check->execute();
            if ($check->get_result()->num_rows > 0) {
                $errors[] = "Product code already used as an existing SKU";
            }
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
        if (strcasecmp($sku, $productCode) === 0) 
            { $errors[] = "SKU cannot be the same as the product code: $sku"; break; 
        }
        if ($sku !== '') {
            if (isset($seenSku[$sku])) { $errors[] = "Duplicate SKU: $sku"; break; }
            $seenSku[$sku] = true;
        }
    }
}

// --- SKU uniqueness against database ---
if (empty($errors) && !empty($seenSku)) {
    $skuList = array_keys($seenSku);
    $placeholders = implode(',', array_fill(0, count($skuList), '?'));
    $types = str_repeat('s', count($skuList));

    $stmt = $conn->prepare("SELECT SKU FROM product_variant WHERE SKU IN ($placeholders) AND IS_DELETED = 0");
    $stmt->bind_param($types, ...$skuList);
    $stmt->execute();
    $existing = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    if (!empty($existing)) {
        $dupSkus = array_column($existing, 'SKU');
        $errors[] = "SKU already used: " . implode(', ', $dupSkus);
    }
        if (empty($errors)) {
        $stmt = $conn->prepare("SELECT PRODUCT_CODE FROM product WHERE PRODUCT_CODE IN ($placeholders) AND IS_DELETED = 0");
        $stmt->bind_param($types, ...$skuList);
        $stmt->execute();
        $clash = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        if (!empty($clash)) {
            $errors[] = "SKU already used as a product code: " . implode(', ', array_column($clash, 'PRODUCT_CODE'));
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

    // --- Packing list photos (one optional photo per group) ---
    $packingImagePaths = [];
    if (empty($errors) && !empty($_FILES['packing_images']['name']) && is_array($_FILES['packing_images']['name'])) {
        $allowedTypes = ['image/jpeg','image/png','image/webp'];
        $extMap  = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];
        $maxSize = 2 * 1024 * 1024;
        $dir = "uploads/product/";
        if (!is_dir($dir)) mkdir($dir, 0777, true);

        foreach ($_FILES['packing_images']['name'] as $gi => $n) {
            if ($_FILES['packing_images']['error'][$gi] !== UPLOAD_ERR_OK) continue;

            $tmp = $_FILES['packing_images']['tmp_name'][$gi];
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $type  = finfo_file($finfo, $tmp);
            finfo_close($finfo);

            if (!in_array($type, $allowedTypes) || $_FILES['packing_images']['size'][$gi] > $maxSize) {
                $errors[] = "Packing list photo invalid (JPG/PNG/WEBP, max 2MB)";
                break;
            }

            $path = $dir . time() . "_" . uniqid() . "." . $extMap[$type];
            if (move_uploaded_file($tmp, $path)) $packingImagePaths[(int)$gi] = $path;
        }
    }

    // --- Build packing list JSON ---
    $packing = [];
    foreach ($packingGroups as $gi => $g) {
        $items = [];
        foreach (($g['items'] ?? []) as $it) {
            $itemName = trim($it['item'] ?? '');
            if ($itemName === '') continue;
            $items[] = ['qty' => max(1, intval($it['qty'] ?? 1)), 'item' => $itemName];
        }

        $title = trim($g['group'] ?? '');
        $note  = trim($g['note'] ?? '');
        $img   = $packingImagePaths[(int)$gi] ?? null;

        // 没有上传文件时，看有没有图片链接（只允许 http/https）
        if (!$img) {
            $url = trim($g['image_url'] ?? '');
            if ($url !== '') {
                if (filter_var($url, FILTER_VALIDATE_URL) && preg_match('#^https?://#i', $url)) {
                    $img = $url;
                } else {
                    $errors[] = "Invalid packing list picture URL: $url";
                    continue;
                }
            }
        }
    }
    $packingList = $packing ? json_encode($packing, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;

    // --- Build features JSON ---
    $featuresArr = [];
foreach ($featureGroups as $fg) {
    $points = [];
    foreach (($fg['points'] ?? []) as $p) {
        $text = trim($p['text'] ?? '');
        if ($text === '') continue;
        $subs = array_values(array_filter(array_map('trim', $p['sub_points'] ?? []), 'strlen'));
        $points[] = $subs ? ['text' => $text, 'sub_points' => $subs] : $text;
    }

    // 图片链接：只允许 http(s)，避免 javascript: 之类的注入
    $img = trim($fg['image'] ?? '');
    if ($img !== '' && !(filter_var($img, FILTER_VALIDATE_URL) && preg_match('#^https?://#i', $img))) {
        $errors[] = "Invalid feature picture URL: $img";
        continue;
    }

    if (!$points && $img === '') continue;   // 没有 point 也没有图片才跳过

    $entry = ['category' => trim($fg['category'] ?? ''), 'points' => $points];
    if ($img !== '') $entry['image'] = $img;
    $featuresArr[] = $entry;
}

  $features = $featuresArr ? json_encode($featuresArr, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;

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
    <title>Add Product | Admin</title>
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
    max-width: 1300px;
    margin: 0 auto;
    padding: 24px 30px 60px;
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
    margin-bottom: 16px;
}

.form-back-link:hover {
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

/* Form Layout Grid */
.form-container {
    background: transparent;
    padding: 0;
    border-radius: 0;
    box-shadow: none;
    border: none;
}

.form-container2 {
    display: grid;
    grid-template-columns: 1fr 360px;
    gap: 24px;
    align-items: flex-start;
    margin-bottom: 24px;
}

@media (max-width: 980px) {
    .form-container2 {
        grid-template-columns: 1fr;
    }
}

.form-left {
    min-width: 0;
    display: flex;
    flex-direction: column;
    gap: 20px;
}

.form-right {
    min-width: 0;
    display: flex;
    flex-direction: column;
    gap: 20px;
}

/* Card Boxes */
.form-card {
    background: var(--card-bg);
    border: 1px solid var(--border-card);
    border-radius: var(--radius-card);
    padding: 24px;
    box-shadow: var(--shadow-soft);
    position: relative;
}

.form-card-title {
    font-family: var(--font-heading);
    font-size: 16px;
    font-weight: 700;
    color: var(--text-dark);
    margin: 0 0 18px 0;
    display: flex;
    align-items: center;
    gap: 8px;
    padding-bottom: 12px;
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
.form-row {
    display: grid;
    gap: 16px;
    margin-bottom: 14px;
}

.form-row:last-child {
    margin-bottom: 0;
}

.form-row.two   { grid-template-columns: 1fr 1fr; }
.form-row.three { grid-template-columns: 1fr 1fr 1fr; }
.form-row > .form-group { min-width: 0; margin-bottom: 0; }

@media (max-width: 700px) {
    .form-row.two, .form-row.three { grid-template-columns: 1fr; }
}

.form-group {
    display: flex;
    flex-direction: column;
    gap: 6px;
    margin-bottom: 16px;
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
    padding: 10px 14px;
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

select.form-input {
    cursor: pointer;
}

.text-hint {
    font-size: 12px;
    color: var(--text-light);
    margin-top: 2px;
}

/* Buttons */
.btn-pill-dark {
    padding: 9px 20px;
    border: none;
    border-radius: var(--radius-pill);
    background: var(--dark-pill);
    color: #ffffff;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    transition: all 0.2s ease;
}

.btn-pill-dark:hover {
    background: #000000;
    color: var(--accent-lime);
    transform: translateY(-1px);
}

.btn-pill-del {
    padding: 4px 10px;
    border: none;
    border-radius: var(--radius-pill);
    background: #fee2e2;
    color: #dc2626;
    font-size: 11.5px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s;
}

.btn-pill-del:hover {
    background: #fecaca;
}

/* Option Groups */
#optionGroups {
    display: flex;
    flex-direction: column;
    gap: 14px;
    margin: 12px 0 16px;
}

.option-group-box {
    background: var(--bg-page);
    border: 1px solid var(--border-card);
    border-radius: 16px;
    padding: 16px;
}

.option-group-box > input[type="text"] {
    width: 60%;
    padding: 9px 14px;
    border: 1px solid var(--border-card);
    border-radius: 10px;
    font-size: 13.5px;
    font-weight: 600;
    background: #ffffff;
    margin-right: 8px;
    font-family: var(--font-body);
}

.option-group-box > input[type="text"]:focus {
    outline: none;
    border-color: var(--dark-pill);
    box-shadow: 0 0 0 3px rgba(212, 249, 56, 0.4);
}

.option-group-box > button {
    padding: 6px 12px;
    border: none;
    border-radius: var(--radius-pill);
    background: #fee2e2;
    color: #dc2626;
    font-size: 12px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s;
}

.option-group-box > button:hover {
    background: #fecaca;
}

.option-values {
    display: flex;
    flex-direction: column;
    gap: 8px;
    margin: 14px 0 10px;
}

.option-values > div {
    display: flex;
    align-items: center;
    gap: 8px;
}

.option-values input[type="text"] {
    flex: 1;
    padding: 8px 12px;
    border: 1px solid var(--border-card);
    border-radius: 10px;
    font-size: 13px;
    background: #ffffff;
    font-family: var(--font-body);
}

.option-values input[type="text"]:focus {
    outline: none;
    border-color: var(--dark-pill);
    box-shadow: 0 0 0 3px rgba(212, 249, 56, 0.4);
}

.option-values button {
    padding: 4px 10px;
    border: none;
    border-radius: var(--radius-pill);
    background: #fee2e2;
    color: #dc2626;
    font-size: 11px;
    font-weight: 600;
    cursor: pointer;
    flex-shrink: 0;
}

.option-values button:hover {
    background: #fecaca;
}

#optionGroups + button,
.option-group-box .option-values + button {
    padding: 8px 16px;
    border: none;
    border-radius: var(--radius-pill);
    background: var(--dark-pill);
    color: #ffffff;
    font-size: 12.5px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s;
}

#optionGroups + button:hover,
.option-group-box .option-values + button:hover {
    background: #000000;
    color: var(--accent-lime);
}

/* Variant Table / Rows */
#variantTable {
    display: flex;
    flex-direction: column;
    gap: 12px;
    margin: 12px 0 20px;
}

.variant-row-box {
    background: #ffffff;
    border: 1px solid var(--border-card);
    border-radius: 16px;
    padding: 16px 18px;
    display: flex;
    flex-direction: column;
    gap: 12px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.02);
    transition: all 0.2s ease;
}

.variant-row-box:hover {
    border-color: #cbd5e1;
    box-shadow: var(--shadow-soft);
}

.variant-head {
    display: flex;
    align-items: center;
    gap: 10px;
}

.variant-head .variant-toggle {
    width: 18px;
    height: 18px;
    accent-color: var(--dark-pill);
    cursor: pointer;
}

.variant-combo-tag {
    flex: 1;
    min-width: 0;
    font-family: var(--font-heading);
    font-size: 14.5px;
    font-weight: 700;
    color: var(--text-dark);
    word-break: break-word;
}

.variant-status {
    flex: 0 0 110px;
    width: 110px;
    padding: 6px 10px;
    border: 1px solid var(--border-card);
    border-radius: var(--radius-pill);
    font-size: 12.5px;
    font-weight: 600;
    background: var(--bg-page);
    cursor: pointer;
}

.variant-fields {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 10px 12px;
}

@media (max-width: 700px) {
    .variant-fields { grid-template-columns: 1fr 1fr; }
}

.vf {
    display: flex;
    flex-direction: column;
    gap: 4px;
    margin: 0;
    min-width: 0;
}

.vf > span {
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
    color: var(--text-muted);
}

.vf input {
    width: 100%;
    box-sizing: border-box;
    padding: 8px 12px;
    border: 1px solid var(--border-card);
    border-radius: 10px;
    font-size: 13px;
    background: var(--bg-page);
    font-family: var(--font-body);
}

.vf input:focus {
    outline: none;
    background: #ffffff;
    border-color: var(--dark-pill);
    box-shadow: 0 0 0 3px rgba(212, 249, 56, 0.4);
}

.variant-image-row {
    display: flex;
    align-items: center;
    gap: 12px;
    padding-top: 10px;
    border-top: 1px dashed var(--border-subtle);
}

.variant-image-row > .vf-title {
    flex: 0 0 auto;
    font-size: 11.5px;
    font-weight: 600;
    color: var(--text-muted);
}

.variant-disabled .variant-fields,
.variant-disabled .variant-image-row { opacity: 0.45; }
.variant-disabled .variant-combo-tag { color: var(--text-light); text-decoration: line-through; }
.variant-disabled input:not(.variant-toggle),
.variant-disabled select { cursor: not-allowed; }
/* Variant image picker thumbnails */
.ov-image-thumbs {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
}

.ov-thumb {
    width: 56px;
    height: 56px;
    border: 2px solid var(--border-card);
    border-radius: 10px;
    overflow: hidden;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    background: var(--bg-page);
    font-size: 16px;
    color: var(--text-muted);
    transition: all 0.15s ease;
}

.ov-thumb img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
}

.ov-thumb:hover {
    border-color: #cbd5e1;
}

.ov-thumb.selected {
    border-color: var(--dark-pill);
    box-shadow: 0 0 0 3px rgba(212, 249, 56, 0.6);
}

.ov-image-hint {
    font-size: 12px;
    color: var(--text-light);
}

/* Addon */
#addonSearch {
    width: 100%;
    padding: 10px 16px;
    border: 1px solid var(--border-card);
    border-radius: var(--radius-pill);
    font-size: 13.5px;
    background: var(--bg-page);
    box-sizing: border-box;
    margin-bottom: 8px;
    font-family: var(--font-body);
}

#addonSearch:focus {
    outline: none;
    background: #ffffff;
    border-color: var(--dark-pill);
    box-shadow: 0 0 0 3px rgba(212, 249, 56, 0.4);
}

#addonResults {
    position: relative;
    background: #ffffff;
    border: 1px solid var(--border-card);
    border-radius: 14px;
    max-height: 200px;
    overflow-y: auto;
    margin-bottom: 12px;
    box-shadow: var(--shadow-soft);
}

#addonResults:empty {
    border: none;
    box-shadow: none;
}

.addon-search-item {
    padding: 9px 14px;
    font-size: 13.5px;
    cursor: pointer;
    border-bottom: 1px solid var(--border-subtle);
    transition: background 0.15s;
}

.addon-search-item:hover {
    background: var(--bg-page);
}

.addon-row-box {
    display: flex;
    align-items: center;
    gap: 10px;
    background: var(--bg-page);
    border: 1px solid var(--border-card);
    border-radius: 12px;
    padding: 10px 14px;
    font-size: 13.5px;
    margin-bottom: 8px;
}

.addon-row-box input {
    padding: 6px 10px;
    border: 1px solid var(--border-card);
    border-radius: 8px;
    font-size: 13px;
    width: 140px;
    background: #ffffff;
}

.addon-row-box button {
    margin-left: auto;
    padding: 4px 10px;
    border: none;
    border-radius: var(--radius-pill);
    background: #fee2e2;
    color: #dc2626;
    font-size: 11.5px;
    font-weight: 600;
    cursor: pointer;
}

/* Addon variant picker */
.addon-picker {
    display: flex;
    flex-direction: column;
    gap: 8px;
    background: var(--bg-page);
    border: 1px solid var(--border-card);
    border-radius: 14px;
    padding: 16px;
    margin-bottom: 12px;
}

.addon-picker > strong {
    font-family: var(--font-heading);
    font-size: 14.5px;
    color: var(--text-dark);
}

.addon-picker > span {
    font-size: 12px;
    color: var(--text-muted);
}

.addon-picker-row {
    display: flex;
    align-items: center;
    gap: 10px;
    background: #ffffff;
    border: 1px solid var(--border-card);
    border-radius: 10px;
    padding: 9px 14px;
    font-size: 13.5px;
    cursor: pointer;
    transition: border-color 0.15s;
}

.addon-picker-row:hover {
    border-color: #cbd5e1;
}

.addon-picker-row input[type="checkbox"] {
    width: 16px;
    height: 16px;
    accent-color: var(--dark-pill);
    cursor: pointer;
    flex-shrink: 0;
}

.addon-picker-row small {
    margin-left: auto;
    color: var(--text-muted);
    font-weight: 600;
}

.addon-picker-actions {
    display: flex;
    gap: 8px;
    margin-top: 4px;
}

.addon-picker-actions button {
    padding: 8px 18px;
    border: none;
    border-radius: var(--radius-pill);
    font-size: 12.5px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s;
}

.addon-picker-actions button:first-child {
    background: var(--dark-pill);
    color: #ffffff;
}

.addon-picker-actions button:first-child:hover {
    background: #000000;
    color: var(--accent-lime);
}

.addon-picker-actions button:last-child {
    background: #e5e7eb;
    color: var(--text-dark);
}

.addon-picker-actions button:last-child:hover {
    background: #d1d5db;
}

/* Category Tree */
.category-tree {
    display: flex;
    flex-direction: column;
    gap: 6px;
    max-height: 320px;
    overflow-y: auto;
    border-radius: 14px;
}

.category-parent-group {
    border: 1px solid var(--border-card);
    border-radius: 12px;
    background: #ffffff;
    overflow: hidden;
}

.category-parent-header {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 10px 14px;
    cursor: pointer;
    background: var(--bg-page);
    transition: background 0.15s;
}

.category-parent-header:hover {
    background: #eceef2;
}

.category-parent-header label {
    display: flex;
    align-items: center;
    gap: 8px;
    font-weight: 700;
    font-size: 13px;
    color: var(--text-dark);
    margin: 0;
    cursor: pointer;
}

.category-children {
    display: none;
    flex-direction: column;
    gap: 6px;
    padding: 8px 14px 10px 36px;
    background: #ffffff;
}

.category-children label {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 13px;
    font-weight: normal;
    color: var(--text-dark);
    margin: 0;
    cursor: pointer;
}

/* Image Upload & Thumbnails */
.form-right #extraPreview {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    margin-top: 10px;
}

.form-right #extraPreview img,
.form-right #coverPreview {
    width: 80px !important;
    height: 80px;
    object-fit: cover;
    border-radius: 12px;
    border: 1px solid var(--border-card);
    box-shadow: 0 2px 6px rgba(0,0,0,0.04);
}

.btn-remove {
    margin-top: 8px;
    padding: 6px 14px;
    font-size: 12px;
    font-weight: 600;
    border: 1px solid var(--border-card);
    border-radius: var(--radius-pill);
    background: #fee2e2;
    color: #dc2626;
    cursor: pointer;
    transition: all 0.2s;
    display: inline-flex;
    align-items: center;
    gap: 4px;
    width: fit-content;
}

.btn-remove:hover {
    background: #fecaca;
    border-color: #fca5a5;
}

/* Packing & Features Builders */
.bl-group {
    background: var(--bg-page);
    border: 1px solid var(--border-card);
    border-radius: 16px;
    padding: 16px;
    margin-bottom: 14px;
    display: flex;
    flex-direction: column;
    gap: 10px;
}

.bl-head, .bl-row {
    display: flex;
    align-items: center;
    gap: 8px;
}

.bl-head input {
    flex: 1;
    font-weight: 700;
    font-size: 13.5px;
}

.bl-group input[type="text"], .bl-group input[type="number"], .bl-group textarea {
    padding: 8px 12px;
    border: 1px solid var(--border-card);
    border-radius: 10px;
    font-size: 13px;
    box-sizing: border-box;
    width: 100%;
    background: #ffffff;
    font-family: var(--font-body);
}

.bl-group input:focus, .bl-group textarea:focus {
    outline: none;
    border-color: var(--dark-pill);
    box-shadow: 0 0 0 3px rgba(212, 249, 56, 0.4);
}

.bl-row input[type="text"] { flex: 1; }
.bl-row .bl-qty { width: 70px; flex: 0 0 70px; }
.bl-group textarea { height: 60px; margin: 0; resize: vertical; }
.bl-point { display: flex; flex-direction: column; gap: 6px; }
.bl-point textarea { height: 40px; font-size: 12px; }

.bl-del {
    border: none;
    background: #fee2e2;
    color: #dc2626;
    border-radius: var(--radius-pill);
    padding: 4px 10px;
    font-size: 11.5px;
    font-weight: 600;
    cursor: pointer;
    flex-shrink: 0;
}
.bl-del:hover { background: #fecaca; }

.bl-add {
    padding: 8px 16px;
    border: none;
    border-radius: var(--radius-pill);
    cursor: pointer;
    background: var(--dark-pill);
    color: #ffffff;
    font-size: 12.5px;
    font-weight: 600;
    transition: all 0.2s;
    align-self: flex-start;
    margin-top: 6px;
}
.bl-add:hover {
    background: #000000;
    color: var(--accent-lime);
}

.bl-bulk summary {
    cursor: pointer;
    font-size: 12.5px;
    font-weight: 600;
    color: var(--text-muted);
    margin-bottom: 8px;
}
.bl-bulk[open] { display: flex; flex-direction: column; gap: 8px; }

/* Submit Bar */
.form-submit-bar {
    display: flex;
    justify-content: flex-end;
    align-items: center;
    margin-top: 10px;
}

.form-btn {
    background: var(--dark-pill);
    color: #ffffff;
    border: none;
    border-radius: var(--radius-pill);
    padding: 14px 36px;
    font-size: 15px;
    font-weight: 800;
    font-family: var(--font-heading);
    cursor: pointer;
    box-shadow: 0 4px 16px rgba(24, 24, 27, 0.2);
    display: inline-flex;
    align-items: center;
    gap: 8px;
    transition: all 0.25s ease;
    width: auto;
}

.form-btn:hover {
    background: #000000;
    color: var(--accent-lime);
    transform: translateY(-2px);
    box-shadow: 0 8px 24px rgba(24, 24, 27, 0.3);
}

/* Toast */
.toast {
    position: fixed;
    top: 24px;
    left: 50%;
    transform: translateX(-50%);
    padding: 12px 24px;
    border-radius: var(--radius-pill);
    font-size: 13.5px;
    font-weight: 600;
    z-index: 9999;
    box-shadow: 0 10px 25px rgba(0,0,0,0.15);
    display: flex;
    align-items: center;
    gap: 12px;
}
.toast.success { background: var(--dark-pill); color: var(--accent-lime) !important; }
.toast.error { background: #dc2626; color: #ffffff !important; }
.toast span { color: inherit !important; }
.toast-close-btn { cursor: pointer; font-size: 16px; margin-left: 8px; }
</style>
</head>

<body>

<div class="form-wrapper">

    <a href="manage_product.php" class="form-back-link" title="Go Back To Product Page">
        <i class="bi bi-arrow-left"></i> Back to Products
    </a>

    <!-- Page Header -->
    <div class="dash-header">
        <div class="dash-title-group">
            <div class="dash-title-badge">
                <i class="bi bi-box-seam-fill"></i>
                <div class="badge-arrow">+</div>
            </div>
            <div>
                <h1 class="dash-title">Add <strong>New Product</strong></h1>
            </div>
        </div>
    </div>

    <form method="POST" enctype="multipart/form-data" id="form">

    <div class="form-container">

        <div class="form-container2">

            <!-- ================= LEFT ================= -->
            <div class="form-left">

                <!-- 1. Basic Info Card -->
                <div class="form-card">
                    <h3 class="form-card-title">
                        <i class="bi bi-info-circle-fill"></i>
                        <span>Basic Information</span>
                    </h3>

                    <!-- Name + Product Code -->
                    <div class="form-row two">
                        <div class="form-group">
                            <label>Product Name<span class="asterisk">*</span></label>
                            <input type="text" id="nameInput" name="name" class="form-input" maxlength="50" placeholder="e.g. Wireless Noise-Cancelling Headphones" required>
                            <span id="nameError" class="error"></span>
                        </div>

                        <div class="form-group">
                            <label>Product Code<span class="asterisk">*</span></label>
                            <input type="text" name="product_code" id="productCode" class="form-input" maxlength="50" placeholder="e.g. PRD-2026-001" required>
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
                            <input type="text" name="brand" id="brandInput" class="form-input" maxlength="50" placeholder="e.g. Sony" required>
                            <span id="brandError" class="error"></span>
                        </div>

                        <div class="form-group">
                            <label>Warranty (Months)<span class="asterisk">*</span></label>
                            <input type="text" name="warranty" class="form-input" min="0" placeholder="12" required>
                        </div>
                    </div>
                </div>

                <!-- 2. Options Builder Card -->
                <div class="form-card">
                    <h3 class="form-card-title">
                        <i class="bi bi-sliders"></i>
                        <span>Product Options</span>
                        <span class="card-badge">Optional</span>
                    </h3>
                    <div id="optionGroups"></div>
                    <button type="button" class="btn-pill-dark" onclick="addOptionGroup()">
                        <i class="bi bi-plus-lg"></i>Add Option Group
                    </button>
                </div>

                <!-- 3. Variants Card -->
                <div class="form-card">
                    <h3 class="form-card-title">
                        <i class="bi bi-boxes"></i>
                        <span>Variants & Pricing</span>
                        <span class="card-badge" style="background: #fee2e2; color: #dc2626;">Required*</span>
                    </h3>
                    <span id="variantError" class="error"></span>
                    <div id="variantTable"></div>
                </div>

                <!-- 4. Addons Card -->
                <div class="form-card">
                    <h3 class="form-card-title">
                        <i class="bi bi-bag-plus-fill"></i>
                        <span>Add-on Products</span>
                        <span class="card-badge">Optional</span>
                    </h3>
                    <input type="text" id="addonSearch" placeholder="Search products to add as add-on...">
                    <div id="addonResults"></div>
                    <div id="addonPicker"></div> 
                    <div id="addonList"></div>

                    <!-- hidden fields carrying JSON to PHP -->
                    <input type="hidden" name="options_json" id="optionsJson">
                    <input type="hidden" name="variants_json" id="variantsJson">
                    <input type="hidden" name="addons_json" id="addonsJson">
                </div>

                <!-- 5. Description Card -->
                <div class="form-card">
                    <h3 class="form-card-title">
                        <i class="bi bi-card-text"></i>
                        <span>Product Description</span>
                    </h3>
                    <textarea name="description" id="descInput"></textarea>
                </div>

                <!-- 6. Features Card -->
                <div class="form-card">
                    <h3 class="form-card-title">
                        <i class="bi bi-star-fill"></i>
                        <span>Key Features & Specifications</span>
                    </h3>
                    <div id="featureBuilder"></div>
                    <button type="button" class="bl-add" onclick="addFeatureGroup()">
                        <i class="bi bi-plus-lg me-1"></i>Add Feature Category
                    </button>

                    <input type="hidden" name="packing_json" id="packingJson">
                    <input type="hidden" name="features_json" id="featuresJson">
                </div>

                <!-- 7. Packing List Card -->
                <div class="form-card">
                    <h3 class="form-card-title">
                        <i class="bi bi-box2-fill"></i>
                        <span>Packing List / What's In The Box</span>
                    </h3>
                    <div id="packingBuilder"></div>
                    <button type="button" class="bl-add" onclick="addPackingGroup()">
                        <i class="bi bi-plus-lg me-1"></i>Add Package Group
                    </button>
                </div>

            </div>

            <!-- ================= RIGHT ================= -->
            <div class="form-right">

                <!-- Categories Card -->
                <div class="form-card">
                    <h3 class="form-card-title">
                        <i class="bi bi-folder-fill"></i>
                        <span>Categories</span>
                        <span class="card-badge" style="background: #fee2e2; color: #dc2626;">Required*</span>
                    </h3>
                    <div class="category-tree">
                        <?php foreach ($catParents as $parent): ?>
                            <div class="category-parent-group">
                                <div class="category-parent-header" onclick="toggleCategoryGroup(this)">
                                    <i class="bi bi-chevron-right category-toggle-icon"></i>
                                    <label onclick="event.stopPropagation()">
                                        <input type="checkbox" class="cat-parent" name="category_ids[]"
                                            value="<?= $parent['CATEGORY_ID'] ?>"
                                            data-group="<?= $parent['CATEGORY_ID'] ?>"
                                            onchange="onParentToggle(this)">
                                        <?= htmlspecialchars($parent['CATEGORY_NAME']) ?>
                                    </label>
                                </div>
                                <?php if (!empty($catChildren[$parent['CATEGORY_ID']])): ?>
                                    <div class="category-children">
                                        <?php foreach ($catChildren[$parent['CATEGORY_ID']] as $child): ?>
                                            <label>
                                                <input type="checkbox" class="cat-child" name="category_ids[]"
                                                    value="<?= $child['CATEGORY_ID'] ?>"
                                                    data-group="<?= $parent['CATEGORY_ID'] ?>"
                                                    onchange="onChildToggle(this)">
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

                <!-- Media Upload Cards -->
                <div class="form-card">
                    <h3 class="form-card-title">
                        <i class="bi bi-image-fill"></i>
                        <span>Cover Image</span>
                    </h3>
                    <div class="form-group" style="margin-bottom:0;">
                        <input type="file" name="cover" class="form-input">
                        <small class="text-hint">JPG, PNG, WEBP (Max 2MB)</small>
                        <span id="coverError" class="error"></span>
                        <div style="margin-top: 10px;">
                            <img id="coverPreview" style="max-width:120px; display:none;">
                        </div>
                        <button type="button" class="btn-remove" title="Remove Uploaded Image" onclick="removeImage('cover')">✕ Remove</button>
                    </div>
                </div>

                <div class="form-card">
                    <h3 class="form-card-title">
                        <i class="bi bi-images"></i>
                        <span>Gallery Images</span>
                    </h3>
                    <div class="form-group" style="margin-bottom:0;">
                        <input type="file" name="images[]" class="form-input" multiple>
                        <span class="text-hint">Multiple images supported (Max 2MB each)</span>
                        <span id="extraError" class="error"></span>
                        <div id="extraPreview"></div>
                        <button type="button" class="btn-remove" title="Remove All Uploaded Images" onclick="removeImage('extra')">✕ Remove All</button>
                    </div>
                </div>

                <!-- Create Product Submit Card -->
                <div class="form-card" style="text-align: center;">
                    <button type="submit" class="form-btn" style="width: 100%;">
                        <i class="bi bi-check2-circle"></i> Create Product
                    </button>
                </div>

            </div>

        </div>
    </div>
        
    </form>

</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/tinymce/6.8.3/tinymce.min.js" referrerpolicy="origin"></script>
<script>
//===========================================================
//Description
//=============================================
const SITE_ROOT = <?= json_encode($siteRoot) ?>;

// "Layouts" 菜单里的现成版块，用的全是前台已有的 class
const descLayouts = {
    cards2: { label: '2 Cards',  html: `<div class="desc-grid-2">
<div class="desc-card"><h4>Title</h4><p>Text</p></div>
<div class="desc-card"><h4>Title</h4><p>Text</p></div>
</div><p></p>` },
    cards3: { label: '3 Cards',  html: `<div class="desc-grid-3">
<div class="desc-card"><h4>Title</h4><p>Text</p></div>
<div class="desc-card"><h4>Title</h4><p>Text</p></div>
<div class="desc-card"><h4>Title</h4><p>Text</p></div>
</div><p></p>` },
    note:   { label: 'Note box', html: `<div class="desc-note"><strong>Note:</strong> Your text here.</div><p></p>` }
};

tinymce.init({
    selector: '#descInput',
    height: 520,
    menubar: false,
   plugins: 'lists link image media table code',
toolbar: 'blocks styles | bold italic underline forecolor backcolor | alignleft aligncenter alignright | bullist numlist | link image media table | layouts | code',
media_live_embeds: true,
    media_dimensions: false,

    // 关键：body 带上 description-content，前台的 CSS 选择器在编辑器里就同样生效
    body_class: 'description-content',
    content_css: SITE_ROOT + 'css/description.css?v=1',
    content_style: 'body{padding:14px;font-family:Inter,sans-serif}',
    document_base_url: SITE_ROOT,
    convert_urls: false,
    valid_children: '+body[style]',          // 旧描述里的 <style> 块也保留

    // 选中内容后，在 "Styles" 下拉里套用前台的 class
    style_formats: [
        { title: 'Note box',          block: 'div', classes: 'desc-note',   wrapper: true },
        { title: 'Full-width banner', block: 'div', classes: 'desc-banner', wrapper: true }
    ],

    // 表格不要自带 border / 内联样式，让前台 CSS 决定外观
    table_default_attributes: {},
    table_default_styles: {},
    table_appearance_options: false,

    // 调色盘用网站的颜色
    color_map: [
        '80b8d2', 'Brand blue',
        '1B2A3C', 'Dark navy',
        '52708A', 'Grey blue',
        'e74c3c', 'Red',
        '27ae60', 'Green',
        '000000', 'Black'
    ],

    images_upload_handler: (blobInfo) => new Promise((resolve, reject) => {
        const fd = new FormData();
        fd.append('file', blobInfo.blob(), blobInfo.filename());
        fetch('upload_desc_image.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(d => d.location ? resolve(d.location) : reject(d.error || 'Upload failed'))
            .catch(() => reject('Upload failed'));
    }),

    setup: (editor) => {
        editor.ui.registry.addMenuButton('layouts', {
            text: 'Layouts',
            fetch: (cb) => cb(Object.values(descLayouts).map(l => ({
                type: 'menuitem',
                text: l.label,
                onAction: () => editor.insertContent(l.html)
            })))
        });
    }
});
// ================= Packing list builder =================
const PK_ALLOWED = ['image/jpeg', 'image/png', 'image/webp'];
const PK_MAX = 2 * 1024 * 1024;

const newPackingGroup = () => ({ group: '', note: '', items: [{ qty: 1, item: '' }], imageFile: null, imagePreview: null, imageUrl: '' });
let packingGroups = [newPackingGroup()];

function addPackingGroup()       { packingGroups.push(newPackingGroup()); renderPacking(); }
function removePackingGroup(gi)  { packingGroups.splice(gi, 1); renderPacking(); }
function addPackingItem(gi)      { packingGroups[gi].items.push({ qty: 1, item: '' }); renderPacking(); }
function removePackingItem(gi, ii) { packingGroups[gi].items.splice(ii, 1); renderPacking(); }

function setPackingImage(gi, input) {
    const f = input.files[0];
    if (!f) return;
    if (!PK_ALLOWED.includes(f.type) || f.size > PK_MAX) {
        showToast('error', 'Invalid image (JPG/PNG/WEBP, max 2MB)');
        input.value = '';
        return;
    }
    const g = packingGroups[gi];
    g.imageFile = f;
    g.imagePreview = URL.createObjectURL(f);
    g.imageUrl = '';                 // 选了文件就清掉链接
    g.existingImage = null;          // add 页没有这个字段，加了也没影响
    renderPacking();
}

function setPackingImageUrl(gi, url) {
    const g = packingGroups[gi];
    url = url.trim();
    g.imageUrl = url;
    if (url) {                       // 填了链接就清掉上传的文件和旧图
        g.imageFile = null;
        g.existingImage = null;
        g.imagePreview = url;
    } else if (!g.imageFile && !g.existingImage) {
        g.imagePreview = null;
    }
    renderPacking();
}

function clearPackingImage(gi) {
    const g = packingGroups[gi];
    g.imageFile = null;
    g.imagePreview = null;
    g.imageUrl = '';
    g.existingImage = null;
    renderPacking();
}

// 批量粘贴："1 x micro:bit V2 Board" / "1x USB cable" / 没写数量的行默认 1
function bulkAddItems(gi, btn) {
    const ta = btn.previousElementSibling;
    const g = packingGroups[gi];
    g.items = g.items.filter(i => i.item.trim() !== '');
    ta.value.split('\n').forEach(line => {
        line = line.trim();
        if (!line) return;
        const m = line.match(/^(\d+)\s*[x×X]\s+(.+)$/);
        g.items.push(m ? { qty: +m[1], item: m[2].trim() }
                       : { qty: 1, item: line.replace(/^[-•*]\s*/, '') });
    });
    renderPacking();
}

function renderPacking() {
    document.getElementById('packingBuilder').innerHTML = packingGroups.map((g, gi) => `
        <div class="bl-group">
            <div class="bl-head">
                <input type="text" placeholder="Group title (e.g. micro:bit Go Bundle)"
                    value="${esc(g.group)}" oninput="packingGroups[${gi}].group = this.value">
                <button type="button" class="bl-del" onclick="removePackingGroup(${gi})">✕ Remove</button>
            </div>

            <textarea placeholder="Note shown above the list (optional)"
                oninput="packingGroups[${gi}].note = this.value">${esc(g.note)}</textarea>

            ${g.items.map((it, ii) => `
                <div class="bl-row">
                    <input type="number" min="1" class="bl-qty" value="${esc(it.qty)}"
                        oninput="packingGroups[${gi}].items[${ii}].qty = this.value">
                    <span>×</span>
                    <input type="text" placeholder="Item name" value="${esc(it.item)}"
                        oninput="packingGroups[${gi}].items[${ii}].item = this.value">
                    <button type="button" class="bl-del" onclick="removePackingItem(${gi},${ii})">✕</button>
                </div>
            `).join('')}

            <button type="button" class="bl-add" style="margin:0" onclick="addPackingItem(${gi})">+ Add Item</button>

            <details class="bl-bulk">
                <summary>Paste multiple items at once</summary>
                <textarea placeholder="1 x micro:bit V2 Board&#10;4 x PKCell AA battery&#10;1 x USB micro B cable"></textarea>
                <button type="button" class="bl-add" style="margin:0" onclick="bulkAddItems(${gi}, this)">Add lines</button>
            </details>

            <div class="bl-image">
                <span>Photo below the list (optional): upload a file or paste an image link</span>
                <input type="file" accept="image/jpeg,image/png,image/webp" style="width:auto"
                    onchange="setPackingImage(${gi}, this)">
                <input type="text" placeholder="or Image URL, e.g. https://example.com/photo.jpg"
                    value="${esc(g.imageUrl)}"
                    onchange="setPackingImageUrl(${gi}, this.value)">
                ${g.imagePreview ? `
                    <div class="bl-preview">
                        <img src="${esc(g.imagePreview)}"
                             onerror="this.outerHTML='<small style=&quot;color:#dc2626&quot;>Image cannot be loaded</small>'">
                        <button type="button" class="bl-del" onclick="clearPackingImage(${gi})">✕</button>
                    </div>` : ''}
            </div>
        </div>
    `).join('');
}

// ================= Features builder =================
// 改成
const newFeatureGroup = () => ({ category: '', image: '', points: [{ text: '', subText: '' }] });
let featureGroups = [newFeatureGroup()];

function addFeatureGroup()        { featureGroups.push(newFeatureGroup()); renderFeatures(); }
function removeFeatureGroup(fi)   { featureGroups.splice(fi, 1); renderFeatures(); }
function addFeaturePoint(fi)      { featureGroups[fi].points.push({ text: '', subText: '' }); renderFeatures(); }
function removeFeaturePoint(fi, pi) { featureGroups[fi].points.splice(pi, 1); renderFeatures(); }
// 批量粘贴 Features
// 格式：
//   Display:            ← 以冒号结尾（或 "## 标题"）= 新 Category
//   - 5 inch screen     ← 普通行 / "-" "•" "*" 开头 = Feature point
//       - 1080p         ← 缩进的行 = 上一个 point 的 sub-point
function bulkAddFeatures(fi, btn) {
    const ta = btn.previousElementSibling;
    const bullet = /^(--|[-•*·▪■▫●◦○])\s*/;
    const isSub  = s => /^(\s{2,}|\t)/.test(s) || /^\s*(--|[▪■▫])/.test(s);

    let cur = featureGroups[fi];
    const firstGroup = cur;
    cur.points = cur.points.filter(p => p.text.trim() !== '');
    let insertAt = fi + 1;
    let lastPoint = null;

    const lines = ta.value.split('\n').filter(l => l.trim() !== '');
    lines.forEach((raw, idx) => {
        const indented = isSub(raw);
        let line = raw.trim();

        const next = lines[idx + 1];
        const nextIndented = !!next && isSub(next);

        const isHeader = !indented && !/^(\*|•|-)\s/.test(line) && !nextIndented &&
                         (/^#{1,6}\s+/.test(line) || /[:：]$/.test(line));

        if (isHeader) {
            const name = line.replace(/^#{1,6}\s+/, '').replace(/[:：]$/, '').trim();
            if (cur === firstGroup && !cur.category.trim() && cur.points.length === 0) {
                cur.category = name;
                
            } else {
                cur = { category: name, image: '', points: [] };
                featureGroups.splice(insertAt++, 0, cur);
            }
            lastPoint = null;
            return;
        }

        line = line.replace(bullet, '');

        if (indented && lastPoint) {
            lastPoint.subText = lastPoint.subText ? lastPoint.subText + '\n' + line : line;
        } else {
            lastPoint = { text: line, subText: '' };
            cur.points.push(lastPoint);
        }
    });

    featureGroups.forEach(g => { if (!g.points.length) g.points.push({ text: '', subText: '' }); });
    renderFeatures();
}
function renderFeatures() {
    document.getElementById('featureBuilder').innerHTML = featureGroups.map((g, fi) => `
        <div class="bl-group">
            <div class="bl-head">
                <input type="text" placeholder="Category (e.g. Display, Connectivity) - optional"
                    value="${esc(g.category)}" oninput="featureGroups[${fi}].category = this.value">
                <button type="button" class="bl-del" onclick="removeFeatureGroup(${fi})">✕ Remove</button>
            </div>
                        <div class="bl-row">
                <input type="text" placeholder="Image URL (optional) e.g. https://example.com/photo.jpg"
                    value="${esc(g.image)}"
                    oninput="featureGroups[${fi}].image = this.value.trim()"
                    onchange="renderFeatures()">
            </div>
            ${g.image ? `
                <div class="bl-preview">
                    <img src="${esc(g.image)}" style="max-width:160px;border-radius:10px;border:1px solid #edf0f3"
                         onerror="this.outerHTML='<small style=&quot;color:#dc2626&quot;>Image cannot be loaded</small>'">
                </div>` : ''}

            ${g.points.map((p, pi) => `
                <div class="bl-point">
                    <div class="bl-row">
                        <input type="text" placeholder="Feature point" value="${esc(p.text)}"
                            oninput="featureGroups[${fi}].points[${pi}].text = this.value">
                        <button type="button" class="bl-del" onclick="removeFeaturePoint(${fi},${pi})">✕</button>
                    </div>
                    <textarea placeholder="Sub-points, one per line (optional)"
                        oninput="featureGroups[${fi}].points[${pi}].subText = this.value">${esc(p.subText)}</textarea>
                </div>
            `).join('')}

            <button type="button" class="bl-add" style="margin:0" onclick="addFeaturePoint(${fi})">+ Add Point</button>

            <details class="bl-bulk">
                <summary>Paste multiple points at once</summary>
                <textarea style="height:140px" placeholder="Display:&#10;- 5 inch IPS screen&#10;    - 1080p resolution&#10;    - 400 nits brightness&#10;Connectivity:&#10;- Bluetooth 5.0&#10;- USB-C"></textarea>
                <button type="button" class="bl-add" style="margin:0" onclick="bulkAddFeatures(${fi}, this)">Add lines</button>
            </details>
        </div>
    `).join('');
}


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

    //==============category tree===================

    function toggleCategoryGroup(header) {
    const children = header.nextElementSibling;
    if (!children || !children.classList.contains('category-children')) return;

    const icon = header.querySelector('.category-toggle-icon');
    const isOpen = children.style.display === 'flex';

    children.style.display = isOpen ? 'none' : 'flex';
    icon.classList.toggle('bi-chevron-right', isOpen);
    icon.classList.toggle('bi-chevron-down', !isOpen);
}

function onChildToggle(childCheckbox) {
        // 勾选子分类 -> 自动勾选对应的母分类
        if (childCheckbox.checked) {
            const groupId = childCheckbox.dataset.group;
            const parent = document.querySelector(`.cat-parent[data-group="${groupId}"]`);
            if (parent) parent.checked = true;
        }
    }
function onParentToggle(parentCheckbox) {
        // 取消勾选母分类 -> 一并取消所有子分类，避免"选了子分类但母分类没选"的矛盾状态
        if (!parentCheckbox.checked) {
            const groupId = parentCheckbox.dataset.group;
            document.querySelectorAll(`.cat-child[data-group="${groupId}"]`).forEach(c => c.checked = false);
        }
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
        const hasOpt = combo.length > 0;
        row.className = 'variant-row-box' + (en ? '' : ' variant-disabled');
        row.innerHTML = `
            <div class="variant-head">
                ${hasOpt ? `<input type="checkbox" class="variant-toggle" ${en ? 'checked' : ''}
                    onchange="toggleVariant('${key}', this.checked)">` : ''}
                <span class="variant-combo-tag" title="Option combination">${esc(autoName)}</span>
                <select class="variant-status" ${en ? '' : 'disabled'}
                    onchange="variantData['${key}'].status = this.value">
                    <option ${existing.status==='Active'?'selected':''}>Active</option>
                    <option ${existing.status==='Inactive'?'selected':''}>Inactive</option>
                </select>
            </div>

             <div class="variant-fields">
             ${hasOpt ? `
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
                </label>` : ''}
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
        return `<span class="ov-image-hint">Upload images from right side [Product Images] first,then come back to select this group of corresponding images.</span>`;
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

       if (lower.startsWith("product code")) {
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

// Real-time validation for product code
    document.getElementById("productCode").addEventListener("input", function () {
    const code = this.value.trim();
    if (code === "") { productCodeError.textContent = "Product code required"; return; }
    fetch("add_product.php", {
        method: "POST",
        headers: { "Content-Type": "application/x-www-form-urlencoded" },
        body: "ajax_check_code=1&code=" + encodeURIComponent(code)
    })
    .then(r => r.text())
    .then(t => {
        productCodeError.textContent = t.trim() === "exists" ? "Product code already exists or is used as a SKU" : "";
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
        tinymce.triggerSave();

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

        document.getElementById('packingJson').value = JSON.stringify(
            packingGroups.map(g => ({ group: g.group, note: g.note, items: g.items, image_url: g.imageUrl }))
        );
        document.getElementById('featuresJson').value = JSON.stringify(
            featureGroups.map(g => ({
                category: g.category,
                image: g.image,
                points: g.points.map(p => ({ text: p.text, sub_points: p.subText.split('\n') }))
            }))
        );

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
            // 检查：勾了母分类、且它有子分类的话，是否至少勾了一个子分类
            let missingChild = false;
            document.querySelectorAll('.cat-parent:checked').forEach(parentCb => {
                const groupId = parentCb.dataset.group;
                const children = document.querySelectorAll(`.cat-child[data-group="${groupId}"]`);
                if (children.length === 0) return;                       // 这个母分类本来就没有子分类
                const anyChildChecked = Array.from(children).some(c => c.checked);
                if (!anyChildChecked) missingChild = true;
            });

            if (missingChild) {
                categoryError.textContent = "Please also select a sub-category for each selected category";
                ok = false;
            } else {
                categoryError.textContent = "";
            }
        }

        

        // Stop submit if validation fail
        if (!ok) {
            showToast("error", "Please fix the errors");
            return;
        }

        // If all validations pass, submit form data via AJAX
        const formData = new FormData(form);

        formData.delete("images[]");
        packingGroups.forEach((g, gi) => {
            if (g.imageFile) formData.append(`packing_images[${gi}]`, g.imageFile, g.imageFile.name);
        });

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
    renderPacking();
    renderFeatures();
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