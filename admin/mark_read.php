<?php
require_once("config.php");

if (isset($_GET['id'])) {

    $id = intval($_GET['id']);

    $sql = "UPDATE notification 
            SET IS_READ = 1 
            WHERE NOTIF_ID = ?";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $id);
    $stmt->execute();

    echo "success";
}
?>