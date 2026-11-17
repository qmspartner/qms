<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

$user = require_login();
$db = get_db();
$isGqar = is_gqar($user);

// Samme adgangsvalidering som contract_overview.php: en GQAR-bruger må kun vælge en af sine
// egne kontrakter, andre kan vælge enhver aktiv kontraktreference i organisationen.
$availableContracts = $isGqar ? ($user['gqar_contract_references'] ?? []) : list_active_values('contract_reference');
$selectedContract = $_GET['contract'] ?? '';
if ($selectedContract === '' || !in_array($selectedContract, $availableContracts, true)) {
    http_response_code(400);
    exit(t('contractoverview.no_contracts'));
}

// Rækkerne herunder genskaber bevidst de samme forespørgsler som contract_overview.php,
// i stedet for at gå via document_list()/case_list()/audit_list() — contract_overview.php
// bruger selv ikke de generelle modul-repositories (den er en kontrakt-scopet tværsnitsvisning,
// ikke en af de almindelige modullister), så eksporten følger samme mønster for at være
// garanteret identisk med det, siden rent faktisk viser.
$rows = [];

// Samme synlighedsregel som contract_overview.php selv (se rettelsen der 2026-11-17):
// kun admin/editor/approver ser alle statusser, alle andre kun Godkendt.
$docStatusClause = can_view_all_document_statuses($user) ? '' : "AND status = 'Godkendt'";
$stmt = $db->prepare("
    SELECT * FROM documents
    WHERE organization_id = ? AND is_configuration_item = 1 AND baseline_reference = ? {$docStatusClause}
    ORDER BY doc_number
");
$stmt->execute([$user['organization_id'], $selectedContract]);
foreach ($stmt->fetchAll() as $d) {
    $rows[] = [t('contractoverview.section.documents'), $d['doc_number'], $d['title'], '', status_label($d['status'])];
}

$stmt = $db->prepare("
    SELECT * FROM audits
    WHERE organization_id = ? AND audit_type = 'Eksternt' AND contract_reference = ?
    ORDER BY planned_date DESC
");
$stmt->execute([$user['organization_id'], $selectedContract]);
foreach ($stmt->fetchAll() as $a) {
    $rows[] = [t('contractoverview.section.audits'), '', $a['title'], format_date($a['planned_date']), audit_status_label($a['status'])];
}

$stmt = $db->prepare("
    SELECT * FROM cases
    WHERE organization_id = ? AND requires_customer_notification = 1 AND contract_reference = ?
    ORDER BY status, due_date
");
$stmt->execute([$user['organization_id'], $selectedContract]);
foreach ($stmt->fetchAll() as $c) {
    $rows[] = [t('contractoverview.section.cases'), '', $c['title'], type_label($c['type']), case_status_label($c['status'])];
}

if (organization_delivers_software()) {
    $stmt = $db->prepare('
        SELECT sr.*, d.doc_number
        FROM software_releases sr
        LEFT JOIN documents d ON d.id = sr.linked_document_id
        WHERE sr.organization_id = ? AND sr.contract_reference = ?
        ORDER BY sr.delivery_date DESC, sr.id DESC
    ');
    $stmt->execute([$user['organization_id'], $selectedContract]);
    foreach ($stmt->fetchAll() as $sr) {
        $rows[] = [t('contractoverview.section.software_releases'), $sr['build_version'], $sr['doc_number'] ?: '', format_date($sr['delivery_date']), ''];
    }
}

if (organization_has_fai()) {
    $stmt = $db->prepare('
        SELECT * FROM fai_records
        WHERE organization_id = ? AND contract_reference = ?
        ORDER BY part_number
    ');
    $stmt->execute([$user['organization_id'], $selectedContract]);
    foreach ($stmt->fetchAll() as $fr) {
        $rows[] = [t('contractoverview.section.fai'), $fr['part_number'], $fr['drawing_revision'] ?: '', format_date($fr['inspection_date']), $fr['result']];
    }
}

if (organization_has_delivery_tracking()) {
    $stmt = $db->prepare('
        SELECT dl.*, dv.version_number, d.doc_number
        FROM deliveries dl
        LEFT JOIN document_versions dv ON dv.id = dl.linked_version_id
        LEFT JOIN documents d ON d.id = dv.document_id
        WHERE dl.organization_id = ? AND dl.contract_reference = ?
        ORDER BY dl.delivery_date DESC, dl.id DESC
    ');
    $stmt->execute([$user['organization_id'], $selectedContract]);
    foreach ($stmt->fetchAll() as $dl) {
        $docLabel = $dl['doc_number'] ? $dl['doc_number'] . ' v' . $dl['version_number'] : '';
        $rows[] = [t('contractoverview.section.deliveries'), $dl['reference'], $docLabel, format_date($dl['delivery_date']), ''];
    }
}

if (!$isGqar) {
    $stmt = $db->prepare("SELECT * FROM risks WHERE organization_id = ? AND contract_reference = ? AND type = 'Risiko' ORDER BY (probability * consequence) DESC");
    $stmt->execute([$user['organization_id'], $selectedContract]);
    foreach ($stmt->fetchAll() as $r) {
        $score = risk_score((int) $r['probability'], (int) $r['consequence']);
        $rows[] = [t('contractoverview.section.risks'), '', $r['title'], (string) $score, risk_status_label($r['status'])];
    }

    $stmt = $db->prepare('
        SELECT s.* FROM suppliers s
        JOIN supplier_contracts sc ON sc.supplier_id = s.id
        WHERE s.organization_id = ? AND sc.contract_reference = ?
        ORDER BY s.name
    ');
    $stmt->execute([$user['organization_id'], $selectedContract]);
    foreach ($stmt->fetchAll() as $s) {
        $rows[] = [t('contractoverview.section.suppliers'), '', $s['name'], '', supplier_status_label($s['status'])];
    }

    $stmt = $db->prepare('
        SELECT e.* FROM equipment e
        JOIN equipment_contracts ec ON ec.equipment_id = e.id
        WHERE e.organization_id = ? AND ec.contract_reference = ?
        ORDER BY e.name
    ');
    $stmt->execute([$user['organization_id'], $selectedContract]);
    foreach ($stmt->fetchAll() as $e) {
        $rows[] = [t('contractoverview.section.equipment'), '', $e['name'], '', equipment_status_label($e['status'])];
    }
}

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="kontraktoversigt-' . preg_replace('/[^A-Za-z0-9_-]/', '_', $selectedContract) . '.csv"');

$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF");

fputcsv($out, [
    t('export.csv.contractoverview.section'),
    t('export.csv.contractoverview.reference'),
    t('export.csv.contractoverview.title'),
    t('export.csv.contractoverview.details'),
    t('export.csv.contractoverview.status'),
], ';');

foreach ($rows as $row) {
    fputcsv($out, $row, ';');
}

fclose($out);
exit;
