<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

$user = require_role(['admin']);
$db = get_db();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !organization_has_delivery_tracking()) {
    header('Location: contract_overview.php');
    exit;
}

$contractReference = trim($_POST['contract_reference'] ?? '');
$reference = trim($_POST['reference'] ?? '');
$deliveryDateRaw = trim($_POST['delivery_date'] ?? '');
$linkedDocumentId = (int) ($_POST['linked_document_id'] ?? 0);
$notes = trim($_POST['notes'] ?? '');

if (!in_array($contractReference, list_active_values('contract_reference'), true)) {
    header('Location: contract_overview.php');
    exit;
}

$redirect = 'contract_overview.php?contract=' . urlencode($contractReference);

if ($reference === '') {
    header('Location: ' . $redirect . '&delivery_error=reference_required');
    exit;
}

$deliveryDate = null;
if ($deliveryDateRaw !== '') {
    $parsed = DateTime::createFromFormat('Y-m-d', $deliveryDateRaw);
    if (!$parsed || $parsed->format('Y-m-d') !== $deliveryDateRaw) {
        header('Location: ' . $redirect . '&delivery_error=bad_date');
        exit;
    }
    $deliveryDate = $deliveryDateRaw;
}

// Leverancen peger bevidst på dokumentets GÆLDENDE godkendte version (current_version_id)
// på registreringstidspunktet, ikke på selve dokumentet — brugeren vælger kun dokumentet,
// den aktuelle version slås op og fastlåses her (se migrations/018_leverance_register.sql).
$linkedVersionId = null;
if ($linkedDocumentId) {
    $stmt = $db->prepare('
        SELECT current_version_id FROM documents
        WHERE id = ? AND organization_id = ? AND is_configuration_item = 1 AND baseline_reference = ?
    ');
    $stmt->execute([$linkedDocumentId, $user['organization_id'], $contractReference]);
    $doc = $stmt->fetch();
    if ($doc && $doc['current_version_id']) {
        $linkedVersionId = (int) $doc['current_version_id'];
    }
}

$stmt = $db->prepare('
    INSERT INTO deliveries (organization_id, contract_reference, reference, delivery_date, linked_version_id, notes, created_by)
    VALUES (?, ?, ?, ?, ?, ?, ?)
');
$stmt->execute([
    $user['organization_id'],
    $contractReference,
    $reference,
    $deliveryDate,
    $linkedVersionId,
    $notes !== '' ? $notes : null,
    $user['id'],
]);

header('Location: ' . $redirect);
exit;
