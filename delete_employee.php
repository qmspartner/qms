<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

$user = require_role(['admin']);
$db = get_db();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: employees.php');
    exit;
}

$employeeId = (int) ($_POST['employee_id'] ?? 0);

$stmt = $db->prepare('SELECT id FROM employees WHERE id = ? AND organization_id = ?');
$stmt->execute([$employeeId, $user['organization_id']]);
if (!$stmt->fetch()) {
    http_response_code(404);
    exit('Medarbejderen blev ikke fundet.');
}

$stmt = $db->prepare('SELECT stored_filename FROM employee_qualifications WHERE employee_id = ? AND stored_filename IS NOT NULL');
$stmt->execute([$employeeId]);
$filesToDelete = $stmt->fetchAll(PDO::FETCH_COLUMN);

$stmt = $db->prepare('DELETE FROM employees WHERE id = ? AND organization_id = ?');
$stmt->execute([$employeeId, $user['organization_id']]);

foreach ($filesToDelete as $filename) {
    $path = UPLOAD_DIR . $filename;
    if (is_file($path)) {
        @unlink($path);
    }
}

header('Location: employees.php');
exit;
