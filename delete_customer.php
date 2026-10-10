<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

$user = require_permission('customers', 'delete');
$db = get_db();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: customers.php');
    exit;
}

$customerId = (int) ($_POST['customer_id'] ?? 0);

$stmt = $db->prepare('SELECT id FROM customers WHERE id = ? AND organization_id = ?');
$stmt->execute([$customerId, $user['organization_id']]);
if (!$stmt->fetch()) {
    http_response_code(404);
    exit(t('customers.not_found'));
}

$stmt = $db->prepare('DELETE FROM customers WHERE id = ? AND organization_id = ?');
$stmt->execute([$customerId, $user['organization_id']]);

header('Location: customers.php');
exit;
