<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/customers.php';

$user = require_permission('customers', 'view');
$db = get_db();

// Ingen scope-parameter her: customer_list() har ingen filtre (kun sortering), så
// "hele listen" og "filtreret liste" ville altid være identiske — se customers.php.
$customers = customer_list($db, $user, []);

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="kunder.csv"');

$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF");

fputcsv($out, [
    t('customers.table.name'),
    t('customers.table.contact'),
    t('customers.table.email'),
    t('customers.table.phone'),
], ';');

foreach ($customers as $c) {
    fputcsv($out, [$c['name'], $c['contact_person'], $c['email'], $c['phone']], ';');
}

fclose($out);
exit;
