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

// Central regel: admin eller denne medarbejders nærmeste leder — se
// can_manage_employee() i includes/functions.php. $assignment har kun
// manager_user_id med (fra joinet med employees), ikke et fuldt medarbejder-array,
// så vi bygger et minimalt array der opfylder det funktionen kigger på.
if (!can_manage_employee($user, ['manager_user_id' => $assignment['manager_user_id']])) {
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
