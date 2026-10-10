<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

$user = require_permission('audits', 'delete');
$db = get_db();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: audits.php');
    exit;
}

$auditId = (int) ($_POST['audit_id'] ?? 0);

$stmt = $db->prepare('SELECT id FROM audits WHERE id = ? AND organization_id = ?');
$stmt->execute([$auditId, $user['organization_id']]);
if (!$stmt->fetch()) {
    http_response_code(404);
    exit('Audittet blev ikke fundet.');
}

// Tilknyttede fund (sager) slettes ikke - audit_id sættes automatisk til NULL (ON DELETE SET NULL).
$stmt = $db->prepare('DELETE FROM audits WHERE id = ? AND organization_id = ?');
$stmt->execute([$auditId, $user['organization_id']]);

header('Location: audits.php');
exit;
