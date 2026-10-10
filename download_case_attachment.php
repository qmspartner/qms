<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

$user = require_permission_allow_gqar('cases');
$db = get_db();

$attachmentId = (int) ($_GET['id'] ?? 0);
$stmt = $db->prepare('
    SELECT a.* FROM case_attachments a
    JOIN cases c ON c.id = a.case_id
    WHERE a.id = ? AND c.organization_id = ?
');
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
