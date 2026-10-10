<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

$user = require_permission('risks', 'delete');
$db = get_db();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: risks.php');
    exit;
}

$riskId = (int) ($_POST['risk_id'] ?? 0);

$stmt = $db->prepare('SELECT id FROM risks WHERE id = ? AND organization_id = ?');
$stmt->execute([$riskId, $user['organization_id']]);
if (!$stmt->fetch()) {
    http_response_code(404);
    exit('Risikoen blev ikke fundet.');
}

$stmt = $db->prepare('DELETE FROM risks WHERE id = ? AND organization_id = ?');
$stmt->execute([$riskId, $user['organization_id']]);

header('Location: risks.php');
exit;
