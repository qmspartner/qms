<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

$user = require_role(['admin']);
$db = get_db();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

$documentId = (int) ($_POST['document_id'] ?? 0);

$stmt = $db->prepare('
    SELECT d.id, u.name AS checked_out_by_name
    FROM documents d
    LEFT JOIN users u ON u.id = d.checked_out_by
    WHERE d.id = ? AND d.organization_id = ? AND d.checked_out_by IS NOT NULL
');
$stmt->execute([$documentId, $user['organization_id']]);
$doc = $stmt->fetch();

// Dokumentet er slet ikke reserveret (fx hvis siden var åben i et andet faneblad, og en
// anden admin allerede frigav den) - stille no-op, ikke en fejl.
if ($doc) {
    $stmt = $db->prepare('UPDATE documents SET checked_out_by = NULL, checked_out_at = NULL WHERE id = ? AND organization_id = ?');
    $stmt->execute([$documentId, $user['organization_id']]);
    log_action($documentId, null, t('doc.log.checkout_force_released'), t('doc.log.checkout_force_released_detail') . ' ' . $doc['checked_out_by_name']);
}

header('Location: document.php?id=' . $documentId);
exit;
