<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

$user = require_role(['admin', 'approver']);
$db = get_db();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

$versionId = (int) $_POST['version_id'];
$documentId = (int) $_POST['document_id'];
$decision = $_POST['decision'] ?? '';
$reason = trim($_POST['reason'] ?? '');

if (!in_array($decision, ['approve', 'reject'], true)) {
    header('Location: document.php?id=' . $documentId);
    exit;
}

$stmt = $db->prepare('SELECT id, title, doc_number, is_configuration_item FROM documents WHERE id = ? AND organization_id = ?');
$stmt->execute([$documentId, $user['organization_id']]);
$document = $stmt->fetch();
if (!$document) {
    http_response_code(404);
    exit('Dokumentet blev ikke fundet.');
}

// Konfigurationsstyrede dokumenter må kun behandles (godkendes/afvises) af en Administrator.
if ($document['is_configuration_item'] && $user['role'] !== 'admin') {
    header('Location: document.php?id=' . $documentId . '&error=config_approval_restricted');
    exit;
}

// Ved afvisning er en begrundelse obligatorisk.
if ($decision === 'reject' && $reason === '') {
    header('Location: document.php?id=' . $documentId . '&error=reason_required');
    exit;
}

$db->beginTransaction();
try {
    if ($decision === 'approve') {
        $stmt = $db->prepare("UPDATE document_versions SET status = 'Godkendt', approved_by = ?, approved_at = NOW(), review_comment = ? WHERE id = ?");
        $stmt->execute([$user['id'], $reason ?: null, $versionId]);

        // Arkivér tidligere godkendte versioner af samme dokument
        $stmt = $db->prepare("UPDATE document_versions SET status = 'Arkiveret' WHERE document_id = ? AND id != ? AND status = 'Godkendt'");
        $stmt->execute([$documentId, $versionId]);

        $stmt = $db->prepare("UPDATE documents SET status = 'Godkendt', current_version_id = ?, next_review_date = DATE_ADD(CURDATE(), INTERVAL 1 YEAR) WHERE id = ?");
        $stmt->execute([$versionId, $documentId]);

        $action = 'Version godkendt';
    } else {
        $stmt = $db->prepare("UPDATE document_versions SET status = 'Afvist', approved_by = ?, approved_at = NOW(), review_comment = ? WHERE id = ?");
        $stmt->execute([$user['id'], $reason, $versionId]);

        $stmt = $db->prepare("UPDATE documents SET status = 'Kladde' WHERE id = ?");
        $stmt->execute([$documentId]);

        $action = 'Version afvist';
    }

    $db->commit();
    log_action($documentId, $versionId, $action, 'Behandlet af ' . $user['name'] . ($reason ? ' — Begrundelse: ' . $reason : ''));

    if ($decision === 'approve' && $document['is_configuration_item']) {
        $stmt = $db->prepare("SELECT comment FROM document_versions WHERE id = ?");
        $stmt->execute([$versionId]);
        $versionRow = $stmt->fetch();

        $stmt = $db->prepare("SELECT version_number FROM document_versions WHERE id = ?");
        $stmt->execute([$versionId]);
        $vNum = $stmt->fetch();

        $stmt = $db->prepare("SELECT name, email FROM users WHERE organization_id = ? AND role IN ('admin', 'editor') AND active = 1");
        $stmt->execute([$user['organization_id']]);
        $recipients = $stmt->fetchAll();

        require_once __DIR__ . '/includes/notifications.php';
        foreach ($recipients as $recipient) {
            notify_configuration_change(
                $recipient,
                $documentId,
                $document['title'],
                $document['doc_number'],
                $vNum['version_number'] ?? '',
                $user['name'],
                $versionRow['comment'] ?? ''
            );
        }
    }
} catch (Exception $e) {
    $db->rollBack();
}

header('Location: document.php?id=' . $documentId);
exit;
