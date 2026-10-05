<?php
require_once("config.php");

$review_id = $_GET['review_id'] ?? null;

if (!$review_id) {
    echo "<p style='color:var(--text-muted); font-size:13px;'>Invalid review ID.</p>";
    exit;
}

// Fetch replies for the given review
$stmt = $conn->prepare("
    SELECT rr.*, a.ADMIN_NAME
    FROM review_reply rr
    LEFT JOIN admin a ON rr.ADMIN_ID = a.ADMIN_ID
    WHERE rr.REVIEW_ID = ?
    AND rr.IS_DELETED = 0
    ORDER BY rr.CREATED_AT DESC
");

$stmt->bind_param("i", $review_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows == 0) {
    echo "<div style='text-align:center; padding: 24px 10px; color: var(--text-muted); font-size: 13.5px;'>";
    echo "<i class='bi bi-chat-square' style='font-size: 24px; display:block; margin-bottom: 6px; color: var(--text-light);'></i>";
    echo "No replies found for this review.";
    echo "</div>";
    exit;
}

while ($row = $result->fetch_assoc()) {
    $adminName = !empty($row['ADMIN_NAME']) ? htmlspecialchars($row['ADMIN_NAME']) : "Admin";
    $formattedDate = date("d M Y, h:i A", strtotime($row['CREATED_AT']));
    ?>
    <div class="reply-item-card">
        <div class="reply-item-header">
            <span class="reply-item-admin">
                <i class="bi bi-shield-check" style="color:#15803d;"></i>
                <?= $adminName ?>
            </span>
            <button type="button" class="reply-delete-btn" onclick="deleteReply(<?= (int)$row['REPLY_ID'] ?>)">
                <i class="bi bi-trash"></i> Delete
            </button>
        </div>
        <p class="reply-item-text"><?= nl2br(htmlspecialchars($row['REPLY_TEXT'])) ?></p>
        <span class="reply-item-date"><i class="bi bi-clock"></i> <?= $formattedDate ?></span>
    </div>
    <?php
}
?>