<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

$user = require_role(['admin']);
$db = get_db();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: contract_overview.php');
    exit;
}

$releaseId = (int) ($_POST['release_id'] ?? 0);

$stmt = $db->prepare('SELECT contract_reference FROM software_releases WHERE id = ? AND organization_id = ?');
$stmt->execute([$releaseId, $user['organization_id']]);
$release = $stmt->fetch();

if (!$release) {
    header('Location: contract_overview.php');
    exit;
}

$stmt = $db->prepare('DELETE FROM software_releases WHERE id = ? AND organization_id = ?');
$stmt->execute([$releaseId, $user['organization_id']]);

header('Location: contract_overview.php?contract=' . urlencode($release['contract_reference']));
exit;
