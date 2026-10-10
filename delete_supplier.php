<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

$user = require_permission('suppliers', 'delete');
$db = get_db();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: suppliers.php');
    exit;
}

$supplierId = (int) ($_POST['supplier_id'] ?? 0);

$stmt = $db->prepare('SELECT id FROM suppliers WHERE id = ? AND organization_id = ?');
$stmt->execute([$supplierId, $user['organization_id']]);
if (!$stmt->fetch()) {
    http_response_code(404);
    exit('Leverandøren blev ikke fundet.');
}

$stmt = $db->prepare('SELECT stored_filename FROM supplier_evaluations WHERE supplier_id = ? AND stored_filename IS NOT NULL');
$stmt->execute([$supplierId]);
$filesToDelete = $stmt->fetchAll(PDO::FETCH_COLUMN);

$stmt = $db->prepare('DELETE FROM suppliers WHERE id = ? AND organization_id = ?');
$stmt->execute([$supplierId, $user['organization_id']]);

foreach ($filesToDelete as $filename) {
    $path = UPLOAD_DIR . $filename;
    if (is_file($path)) {
        @unlink($path);
    }
}

header('Location: suppliers.php');
exit;
