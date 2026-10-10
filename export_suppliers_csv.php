<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

$user = require_permission('suppliers', 'view');
$db = get_db();

$stmt = $db->prepare('
    SELECT s.*, u.name AS owner_name
    FROM suppliers s
    JOIN users u ON u.id = s.owner_id
    WHERE s.organization_id = ?
    ORDER BY s.name
');
$stmt->execute([$user['organization_id']]);
$suppliers = $stmt->fetchAll();

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="leverandorer.csv"');

$out = fopen('php://output', 'w');
// UTF-8 BOM, så Excel læser de danske tegn korrekt
fwrite($out, "\xEF\xBB\xBF");

fputcsv($out, ['Navn', 'Kontaktperson', 'E-mail', 'Telefon', 'Kategori', 'Kritikalitet', 'Status', 'Ansvarlig', 'Kvalitetskrav videreført'], ';');

foreach ($suppliers as $s) {
    fputcsv($out, [
        $s['name'],
        $s['contact_person'],
        $s['email'],
        $s['phone'],
        $s['category'],
        $s['criticality'],
        $s['status'],
        $s['owner_name'],
        $s['quality_requirements_passed_on'] ? 'Ja' : 'Nej',
    ], ';');
}

fclose($out);
exit;
