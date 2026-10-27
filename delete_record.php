<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

$user = require_permission('records', 'delete');
$db = get_db();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: meetings.php');
    exit;
}

$recordId = (int) ($_POST['record_id'] ?? 0);

$stmt = $db->prepare('SELECT id, type FROM records WHERE id = ? AND organization_id = ?');
$stmt->execute([$recordId, $user['organization_id']]);
$record = $stmt->fetch();
if (!$record) {
    http_response_code(404);
    exit('Optegnelsen blev ikke fundet.');
}
$redirectTo = 'meetings.php';

$stmt = $db->prepare('SELECT stored_filename FROM record_versions WHERE record_id = ?');
$stmt->execute([$recordId]);
$filesToDelete = $stmt->fetchAll(PDO::FETCH_COLUMN);

$stmt = $db->prepare('DELETE FROM records WHERE id = ? AND organization_id = ?');
$stmt->execute([$recordId, $user['organization_id']]);

foreach ($filesToDelete as $filename) {
    $path = UPLOAD_DIR . $filename;
    if (is_file($path)) {
        @unlink($path);
    }
}

header('Location: ' . $redirectTo);
exit;
