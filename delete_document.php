<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

$user = require_permission('documents', 'delete');
$db = get_db();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

$documentId = (int) ($_POST['document_id'] ?? 0);

$stmt = $db->prepare('SELECT id FROM documents WHERE id = ? AND organization_id = ?');
$stmt->execute([$documentId, $user['organization_id']]);
if (!$stmt->fetch()) {
    http_response_code(404);
    exit('Dokumentet blev ikke fundet.');
}

// Hent filnavnene på alle versioner, så de fysiske filer kan slettes fra disken bagefter.
$stmt = $db->prepare('SELECT stored_filename FROM document_versions WHERE document_id = ?');
$stmt->execute([$documentId]);
$filesToDelete = $stmt->fetchAll(PDO::FETCH_COLUMN);

// Sletning af selve dokumentet sletter automatisk dets versioner og audit-log
// (ON DELETE CASCADE i databasen), så det er ikke muligt at fortryde bagefter.
$stmt = $db->prepare('DELETE FROM documents WHERE id = ? AND organization_id = ?');
$stmt->execute([$documentId, $user['organization_id']]);

foreach ($filesToDelete as $filename) {
    $path = UPLOAD_DIR . $filename;
    if (is_file($path)) {
        @unlink($path);
    }
}

header('Location: index.php');
exit;
