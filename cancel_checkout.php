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

// Kun den der selv reserverede kan annullere her - admin bruger i stedet
// force_release_checkout.php (tvangsfrigivelse af en ANDENS reservation, logget separat).
if ((int) $doc['checked_out_by'] === (int) $user['id']) {
    $stmt = $db->prepare('UPDATE documents SET checked_out_by = NULL, checked_out_at = NULL WHERE id = ? AND organization_id = ?');
    $stmt->execute([$documentId, $user['organization_id']]);
    log_action($documentId, null, t('doc.log.checkout_cancelled'));
}

header('Location: document.php?id=' . $documentId);
exit;
