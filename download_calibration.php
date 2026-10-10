<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

$user = require_permission('equipment', 'view');
$db = get_db();

$calibrationId = (int) ($_GET['id'] ?? 0);
$stmt = $db->prepare('
    SELECT c.* FROM equipment_calibrations c
    JOIN equipment e ON e.id = c.equipment_id
    WHERE c.id = ? AND e.organization_id = ?
');
$stmt->execute([$calibrationId, $user['organization_id']]);
$calibration = $stmt->fetch();

if (!$calibration || !$calibration['stored_filename']) {
    http_response_code(404);
    exit('Filen blev ikke fundet.');
}

$path = UPLOAD_DIR . $calibration['stored_filename'];
if (!is_file($path)) {
    http_response_code(404);
    exit('Filen mangler på serveren.');
}

header('Content-Description: File Transfer');
header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . basename($calibration['original_filename']) . '"');
header('Content-Length: ' . filesize($path));
readfile($path);
exit;
