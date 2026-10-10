<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

$user = require_role(['admin']);
$db = get_db();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !organization_delivers_software()) {
    header('Location: contract_overview.php');
    exit;
}

$contractReference = trim($_POST['contract_reference'] ?? '');
$buildVersion = trim($_POST['build_version'] ?? '');
$externalReference = trim($_POST['external_reference'] ?? '');
$deliveryDateRaw = trim($_POST['delivery_date'] ?? '');
$linkedDocumentId = (int) ($_POST['linked_document_id'] ?? 0);
$notes = trim($_POST['notes'] ?? '');

$redirect = 'contract_overview.php?contract=' . urlencode($contractReference);

if (!in_array($contractReference, list_active_values('contract_reference'), true)) {
    header('Location: contract_overview.php');
    exit;
}

if ($buildVersion === '') {
    header('Location: ' . $redirect . '&error=build_version_required');
    exit;
}

// delivery_date kommer fra et almindeligt <input type="date"> og er derfor allerede ÅÅÅÅ-MM-DD
// (native datovælgere følger ikke organisationens eget datoformat — se system_settings.php).
$deliveryDate = null;
if ($deliveryDateRaw !== '') {
    $d = DateTime::createFromFormat('Y-m-d', $deliveryDateRaw);
    if (!$d || $d->format('Y-m-d') !== $deliveryDateRaw) {
        header('Location: ' . $redirect . '&error=bad_date');
        exit;
    }
    $deliveryDate = $deliveryDateRaw;
}

if ($linkedDocumentId) {
    $stmt = $db->prepare('SELECT id FROM documents WHERE id = ? AND organization_id = ? AND is_configuration_item = 1 AND baseline_reference = ?');
    $stmt->execute([$linkedDocumentId, $user['organization_id'], $contractReference]);
    if (!$stmt->fetch()) {
        $linkedDocumentId = null;
    }
} else {
    $linkedDocumentId = null;
}

$stmt = $db->prepare('
    INSERT INTO software_releases (organization_id, contract_reference, build_version, external_reference, delivery_date, linked_document_id, notes, created_by)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
');
$stmt->execute([
    $user['organization_id'], $contractReference, $buildVersion,
    $externalReference ?: null, $deliveryDate, $linkedDocumentId, $notes ?: null, $user['id'],
]);

header('Location: ' . $redirect);
exit;
