<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

$user = require_role(['admin']);
$db = get_db();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: users.php');
    exit;
}

$attachmentId = (int) ($_POST['id'] ?? 0);
$targetUserId = (int) ($_POST['user_id'] ?? 0);

$stmt = $db->prepare('SELECT * FROM user_attachments WHERE id = ? AND organization_id = ? AND target_user_id = ?');
$stmt->execute([$attachmentId, $user['organization_id'], $targetUserId]);
$attachment = $stmt->fetch();

if ($attachment) {
    $stmt = $db->prepare('DELETE FROM user_attachments WHERE id = ? AND organization_id = ?');
    $stmt->execute([$attachmentId, $user['organization_id']]);

    $path = UPLOAD_DIR . $attachment['stored_filename'];
    if (is_file($path)) {
        @unlink($path);
    }

    log_user_action($targetUserId, 'Fil slettet', $attachment['label'] . ' (' . $attachment['original_filename'] . ')');
}

header('Location: edit_user.php?id=' . $targetUserId);
exit;
