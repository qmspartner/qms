<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

$user = require_permission_allow_gqar('cases');
$db = get_db();

$evidenceId = (int) ($_GET['id'] ?? 0);
$stmt = $db->prepare('
    SELECT * FROM action_item_evidence WHERE id = ? AND organization_id = ?
');
$stmt->execute([$evidenceId, $user['organization_id']]);
$evidence = $stmt->fetch();

if (!$evidence) {
    http_response_code(404);
    exit('Filen blev ikke fundet.');
}

$path = UPLOAD_DIR . $evidence['stored_filename'];
if (!is_file($path)) {
    http_response_code(404);
    exit('Filen mangler på serveren.');
}

header('Content-Description: File Transfer');
header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . basename($evidence['original_filename']) . '"');
header('Content-Length: ' . filesize($path));
readfile($path);
exit;
