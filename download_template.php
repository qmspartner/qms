<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/documents.php';

$user = require_permission('templates', 'view');
$db = get_db();

$versionId = (int) ($_GET['version_id'] ?? 0);
$version = document_version_find($db, $versionId, $user['organization_id'], ['templateOnly' => true]);

if (!$version) {
    http_response_code(404);
    exit('Filen blev ikke fundet.');
}

$path = UPLOAD_DIR . $version['stored_filename'];
if (!is_file($path)) {
    http_response_code(404);
    exit('Filen mangler på serveren.');
}

stream_file_download($path, $version['original_filename']);
