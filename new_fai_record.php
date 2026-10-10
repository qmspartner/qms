<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

$user = require_role(['admin']);
$db = get_db();

const FAI_RESULT_OPTIONS = ['Godkendt', 'Afvist', 'Under behandling'];

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !organization_has_fai()) {
    header('Location: contract_overview.php');
    exit;
}

$contractReference = trim($_POST['contract_reference'] ?? '');
$partNumber = trim($_POST['part_number'] ?? '');
$drawingRevision = trim($_POST['drawing_revision'] ?? '');
$result = $_POST['result'] ?? '';
$inspectionDateRaw = trim($_POST['inspection_date'] ?? '');
$notes = trim($_POST['notes'] ?? '');
$file = $_FILES['file'] ?? null;

$redirect = 'contract_overview.php?contract=' . urlencode($contractReference);

if (!in_array($contractReference, list_active_values('contract_reference'), true)) {
    header('Location: contract_overview.php');
    exit;
}

if ($partNumber === '') {
    header('Location: ' . $redirect . '&fai_error=part_number_required');
    exit;
}

if (!in_array($result, FAI_RESULT_OPTIONS, true)) {
    header('Location: ' . $redirect . '&fai_error=bad_result');
    exit;
}

// inspection_date kommer fra et almindeligt <input type="date"> og er derfor allerede ÅÅÅÅ-MM-DD
// (native datovælgere følger ikke organisationens eget datoformat — se system_settings.php).
$inspectionDate = null;
if ($inspectionDateRaw !== '') {
    $d = DateTime::createFromFormat('Y-m-d', $inspectionDateRaw);
    if (!$d || $d->format('Y-m-d') !== $inspectionDateRaw) {
        header('Location: ' . $redirect . '&fai_error=bad_date');
        exit;
    }
    $inspectionDate = $inspectionDateRaw;
}

if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
    header('Location: ' . $redirect . '&fai_error=choose_file');
    exit;
}
if ($file['size'] > MAX_FILE_SIZE) {
    header('Location: ' . $redirect . '&fai_error=too_large');
    exit;
}
$ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
if (!in_array($ext, ALLOWED_EXTENSIONS, true)) {
    header('Location: ' . $redirect . '&fai_error=bad_type');
    exit;
}

$storedName = bin2hex(random_bytes(16)) . '.' . $ext;
if (!move_uploaded_file($file['tmp_name'], UPLOAD_DIR . $storedName)) {
    header('Location: ' . $redirect . '&fai_error=save_failed');
    exit;
}

// Kun én aktiv FAI pr. delnummer pr. kontrakt - en ny upload for samme delnummer afløser den forrige.
$stmt = $db->prepare('SELECT id, stored_filename FROM fai_records WHERE organization_id = ? AND contract_reference = ? AND part_number = ?');
$stmt->execute([$user['organization_id'], $contractReference, $partNumber]);
$existing = $stmt->fetch();

if ($existing) {
    $stmt = $db->prepare('
        UPDATE fai_records
        SET drawing_revision = ?, result = ?, inspection_date = ?, stored_filename = ?, original_filename = ?, notes = ?, created_by = ?
        WHERE id = ?
    ');
    $stmt->execute([
        $drawingRevision ?: null, $result, $inspectionDate, $storedName, $file['name'], $notes ?: null, $user['id'], $existing['id'],
    ]);
    if ($existing['stored_filename'] && is_file(UPLOAD_DIR . $existing['stored_filename'])) {
        unlink(UPLOAD_DIR . $existing['stored_filename']);
    }
} else {
    $stmt = $db->prepare('
        INSERT INTO fai_records (organization_id, contract_reference, part_number, drawing_revision, result, inspection_date, stored_filename, original_filename, notes, created_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ');
    $stmt->execute([
        $user['organization_id'], $contractReference, $partNumber, $drawingRevision ?: null,
        $result, $inspectionDate, $storedName, $file['name'], $notes ?: null, $user['id'],
    ]);
}

header('Location: ' . $redirect);
exit;
