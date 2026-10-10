<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

$user = require_permission('equipment', 'delete');
$db = get_db();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: equipment.php');
    exit;
}

$equipmentId = (int) ($_POST['equipment_id'] ?? 0);

$stmt = $db->prepare('SELECT id FROM equipment WHERE id = ? AND organization_id = ?');
$stmt->execute([$equipmentId, $user['organization_id']]);
if (!$stmt->fetch()) {
    http_response_code(404);
    exit('Udstyret blev ikke fundet.');
}

$stmt = $db->prepare('SELECT stored_filename FROM equipment_calibrations WHERE equipment_id = ? AND stored_filename IS NOT NULL');
$stmt->execute([$equipmentId]);
$filesToDelete = $stmt->fetchAll(PDO::FETCH_COLUMN);

$stmt = $db->prepare('DELETE FROM equipment WHERE id = ? AND organization_id = ?');
$stmt->execute([$equipmentId, $user['organization_id']]);

foreach ($filesToDelete as $filename) {
    $path = UPLOAD_DIR . $filename;
    if (is_file($path)) {
        @unlink($path);
    }
}

header('Location: equipment.php');
exit;
