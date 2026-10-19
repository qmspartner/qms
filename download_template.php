<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

$user = require_permission('templates', 'view');
$db = get_db();

$versionId = (int) ($_GET['version_id'] ?? 0);
$stmt = $db->prepare('
    SELECT v.*
    FROM document_versions v
    JOIN documents d ON d.id = v.document_id
    WHERE v.id = ? AND d.organization_id = ? AND d.is_template = 1
');
$stmt->execute([$versionId, $user['organization_id']]);
$version = $stmt->fetch();

if (!$version) {
    http_response_code(404);
    exit('Filen blev ikke fundet.');
}

$path = UPLOAD_DIR . $version['stored_filename'];
if (!is_file($path)) {
    http_response_code(404);
    exit('Filen mangler på serveren.');
}

header('Content-Description: File Transfer');
header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . basename($version['original_filename']) . '"');
header('Content-Length: ' . filesize($path));
readfile($path);
exit;
