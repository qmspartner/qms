<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

$user = require_role(['admin']);
$db = get_db();

$attachmentId = (int) ($_GET['id'] ?? 0);
$stmt = $db->prepare('SELECT * FROM employee_attachments WHERE id = ? AND organization_id = ?');
$stmt->execute([$attachmentId, $user['organization_id']]);
$attachment = $stmt->fetch();

if (!$attachment) {
    http_response_code(404);
    exit('Filen blev ikke fundet.');
}

$path = UPLOAD_DIR . $attachment['stored_filename'];
if (!is_file($path)) {
    http_response_code(404);
    exit('Filen mangler på serveren.');
}

header('Content-Description: File Transfer');
header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . basename($attachment['original_filename']) . '"');
header('Content-Length: ' . filesize($path));
readfile($path);
exit;
