<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

$user = require_role(['admin']);
$db = get_db();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: contract_overview.php');
    exit;
}

$faiId = (int) ($_POST['fai_id'] ?? 0);

$stmt = $db->prepare('SELECT contract_reference, stored_filename FROM fai_records WHERE id = ? AND organization_id = ?');
$stmt->execute([$faiId, $user['organization_id']]);
$record = $stmt->fetch();

if (!$record) {
    header('Location: contract_overview.php');
    exit;
}

$stmt = $db->prepare('DELETE FROM fai_records WHERE id = ? AND organization_id = ?');
$stmt->execute([$faiId, $user['organization_id']]);

if ($record['stored_filename'] && is_file(UPLOAD_DIR . $record['stored_filename'])) {
    unlink(UPLOAD_DIR . $record['stored_filename']);
}

header('Location: contract_overview.php?contract=' . urlencode($record['contract_reference']));
exit;
