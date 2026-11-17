<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

$user = require_permission('documents', 'edit');
$db = get_db();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

$documentId = (int) ($_POST['document_id'] ?? 0);

$stmt = $db->prepare('SELECT id, checked_out_by FROM documents WHERE id = ? AND organization_id = ?');
$stmt->execute([$documentId, $user['organization_id']]);
$doc = $stmt->fetch();

if (!$doc) {
    http_response_code(404);
    exit(t('doc.not_found'));
}

// Allerede reserveret (af en selv eller en anden) - stille no-op, ikke en fejl, så et
// dobbeltklik eller et åbent faneblad fra før ikke virker forvirrende.
if (!$doc['checked_out_by']) {
    $stmt = $db->prepare('UPDATE documents SET checked_out_by = ?, checked_out_at = NOW() WHERE id = ? AND organization_id = ?');
    $stmt->execute([$user['id'], $documentId, $user['organization_id']]);
    log_action($documentId, null, t('doc.log.checked_out'));
}

header('Location: document.php?id=' . $documentId);
exit;
