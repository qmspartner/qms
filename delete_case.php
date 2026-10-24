<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

$user = require_permission('cases', 'delete');
$db = get_db();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: cases.php');
    exit;
}

$caseId = (int) ($_POST['case_id'] ?? 0);

$stmt = $db->prepare('SELECT id FROM cases WHERE id = ? AND organization_id = ?');
$stmt->execute([$caseId, $user['organization_id']]);
if (!$stmt->fetch()) {
    http_response_code(404);
    exit('Kvalitetshændelsen blev ikke fundet.');
}

// Sletning af sagen sletter automatisk dens statushistorik (ON DELETE CASCADE i databasen).
$stmt = $db->prepare('DELETE FROM cases WHERE id = ? AND organization_id = ?');
$stmt->execute([$caseId, $user['organization_id']]);

header('Location: cases.php');
exit;
