<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

$user = require_permission('suppliers', 'view');
$db = get_db();

$evalId = (int) ($_GET['id'] ?? 0);
$stmt = $db->prepare('
    SELECT e.* FROM supplier_evaluations e
    JOIN suppliers s ON s.id = e.supplier_id
    WHERE e.id = ? AND s.organization_id = ?
');
$stmt->execute([$evalId, $user['organization_id']]);
$eval = $stmt->fetch();

if (!$eval || !$eval['stored_filename']) {
    http_response_code(404);
    exit('Filen blev ikke fundet.');
}

$path = UPLOAD_DIR . $eval['stored_filename'];
if (!is_file($path)) {
    http_response_code(404);
    exit('Filen mangler på serveren.');
}

header('Content-Description: File Transfer');
header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . basename($eval['original_filename']) . '"');
header('Content-Length: ' . filesize($path));
readfile($path);
exit;
