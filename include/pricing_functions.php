<?php
function get_addon_variant_row($conn, $addon_product_id, $addon_variant_id) {
    $addon_product_id = intval($addon_product_id);

    if (!empty($addon_variant_id)) {
        $variant_id = intval($addon_variant_id);
        $sql = "SELECT VARIANT_ID, VARIANT_LABEL, VARIANT_PRICE, SALE_PRICE, VARIANT_STOCK
                FROM product_variant
                WHERE VARIANT_ID = $variant_id AND IS_DELETED = 0 AND VARIANT_STATUS = 'Active' LIMIT 1";
    } else {
        $sql = "SELECT VARIANT_ID, VARIANT_LABEL, VARIANT_PRICE, SALE_PRICE, VARIANT_STOCK
                FROM product_variant
                WHERE PRODUCT_ID = $addon_product_id AND IS_DELETED = 0 AND VARIANT_STATUS = 'Active'
                ORDER BY VARIANT_ID ASC LIMIT 1";
    }
    $res = mysqli_query($conn, $sql);
    return $res ? mysqli_fetch_assoc($res) : null;
}

function resolve_addon_price($conn, $addon) {
    $variant = get_addon_variant_row($conn, $addon['ADDON_PRODUCT_ID'], $addon['ADDON_VARIANT_ID']);
    if (!$variant) return null;

    $price = (!empty($variant['SALE_PRICE'])) ? floatval($variant['SALE_PRICE']) : floatval($variant['VARIANT_PRICE']);
    if ($addon['OVERRIDE_PRICE'] !== null && floatval($addon['OVERRIDE_PRICE']) > 0) {
        $price = floatval($addon['OVERRIDE_PRICE']);
    }

    return ['price' => $price, 'variant_id' => intval($variant['VARIANT_ID']), 'label' => $variant['VARIANT_LABEL'], 'stock' => intval($variant['VARIANT_STOCK'])];
}
?>