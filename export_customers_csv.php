<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

$user = require_permission('customers', 'view');
$db = get_db();

$stmt = $db->prepare('SELECT * FROM customers WHERE organization_id = ? ORDER BY name');
$stmt->execute([$user['organization_id']]);
$customers = $stmt->fetchAll();

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="kunder.csv"');

$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF");

fputcsv($out, ['Navn', 'Kontaktperson', 'E-mail', 'Telefon'], ';');

foreach ($customers as $c) {
    fputcsv($out, [$c['name'], $c['contact_person'], $c['email'], $c['phone']], ';');
}

fclose($out);
exit;
