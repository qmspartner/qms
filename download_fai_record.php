<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

$user = require_login();
$db = get_db();

$faiId = (int) ($_GET['id'] ?? 0);
$stmt = $db->prepare('SELECT * FROM fai_records WHERE id = ? AND organization_id = ?');
$stmt->execute([$faiId, $user['organization_id']]);
$record = $stmt->fetch();

if (!$record || !$record['stored_filename']) {
    http_response_code(404);
    exit('Filen blev ikke fundet.');
}

if (is_gqar($user) && !in_array($record['contract_reference'], $user['gqar_contract_references'] ?? [], true)) {
    http_response_code(403);
    exit('Ingen adgang.');
}

$path = UPLOAD_DIR . $record['stored_filename'];
if (!is_file($path)) {
    http_response_code(404);
    exit('Filen mangler på serveren.');
}

header('Content-Description: File Transfer');
header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . basename($record['original_filename']) . '"');
header('Content-Length: ' . filesize($path));
readfile($path);
exit;
