<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

$user = require_role(['admin']);
$db = get_db();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: contract_overview.php');
    exit;
}

$deliveryId = (int) ($_POST['delivery_id'] ?? 0);

$stmt = $db->prepare('SELECT contract_reference FROM deliveries WHERE id = ? AND organization_id = ?');
$stmt->execute([$deliveryId, $user['organization_id']]);
$delivery = $stmt->fetch();

if (!$delivery) {
    header('Location: contract_overview.php');
    exit;
}

$stmt = $db->prepare('DELETE FROM deliveries WHERE id = ? AND organization_id = ?');
$stmt->execute([$deliveryId, $user['organization_id']]);

header('Location: contract_overview.php?contract=' . urlencode($delivery['contract_reference']));
exit;
