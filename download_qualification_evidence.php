<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

$user = require_login();
$db = get_db();

$id = (int) ($_GET['id'] ?? 0);
$stmt = $db->prepare('
    SELECT eq.*, e.manager_user_id
    FROM employee_qualifications eq
    JOIN employees e ON e.id = eq.employee_id
    WHERE eq.id = ? AND eq.organization_id = ?
');
$stmt->execute([$id, $user['organization_id']]);
$assignment = $stmt->fetch();

if (!$assignment || !$assignment['stored_filename']) {
    http_response_code(404);
    exit('Filen blev ikke fundet.');
}

$isManager = (int) ($assignment['manager_user_id'] ?? 0) === (int) $user['id'];
if ($user['role'] !== 'admin' && !$isManager) {
    http_response_code(403);
    exit(t('error.forbidden'));
}

$path = UPLOAD_DIR . $assignment['stored_filename'];
if (!is_file($path)) {
    http_response_code(404);
    exit('Filen mangler på serveren.');
}

header('Content-Description: File Transfer');
header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . basename($assignment['original_filename']) . '"');
header('Content-Length: ' . filesize($path));
readfile($path);
exit;
