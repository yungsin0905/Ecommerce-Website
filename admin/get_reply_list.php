<?php
require_once("config.php");

$review_id = $_GET['review_id'] ?? null;

if (!$review_id) {
    echo "Invalid review";
    exit;
}

// Fetch replies for the given review
$stmt = $conn->prepare("
    SELECT *
    FROM review_reply
    WHERE REVIEW_ID = ?
    AND IS_DELETED = 0
    ORDER BY CREATED_AT DESC
");

$stmt->bind_param("i", $review_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows == 0) {
    echo "<p style='color:gray'>No reply yet</p>";
    exit;
}

while ($row = $result->fetch_assoc()) {
    echo "<div style='padding:10px;border-bottom:1px solid #ddd'>";

    echo "<p>" . htmlspecialchars($row['REPLY_TEXT']) . "</p>";

    echo "<small>" . $row['CREATED_AT'] . "</small>";

    echo "<br>";

    echo "<button onclick='deleteReply(".$row['REPLY_ID'].")'>Delete</button>";

    echo "</div>";
}
?>