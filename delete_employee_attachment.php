<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

$user = require_role(['admin']);
$db = get_db();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: employees.php');
    exit;
}

$attachmentId = (int) ($_POST['id'] ?? 0);
$targetEmployeeId = (int) ($_POST['employee_id'] ?? 0);

$stmt = $db->prepare('SELECT * FROM employee_attachments WHERE id = ? AND organization_id = ? AND target_employee_id = ?');
$stmt->execute([$attachmentId, $user['organization_id'], $targetEmployeeId]);
$attachment = $stmt->fetch();

if ($attachment) {
    $stmt = $db->prepare('DELETE FROM employee_attachments WHERE id = ? AND organization_id = ?');
    $stmt->execute([$attachmentId, $user['organization_id']]);

    $path = UPLOAD_DIR . $attachment['stored_filename'];
    if (is_file($path)) {
        @unlink($path);
    }

    log_employee_action($targetEmployeeId, 'Fil slettet', $attachment['label'] . ' (' . $attachment['original_filename'] . ')');
}

header('Location: edit_employee.php?id=' . $targetEmployeeId);
exit;
