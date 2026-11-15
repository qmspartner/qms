<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/suppliers.php';

$user = require_permission('suppliers', 'view');
$db = get_db();

$sortColumns = [
    'name' => 's.name',
    'category' => 's.category',
    'criticality' => 's.criticality',
    'status' => 's.status',
    'next_evaluation_date' => 's.next_evaluation_date',
];

if (($_GET['scope'] ?? 'all') === 'filtered') {
    $sortCol = $_GET['sort'] ?? 'name';
    if (!isset($sortColumns[$sortCol])) {
        $sortCol = 'name';
    }
    $filters = [
        'categories' => array_values(array_filter((array) ($_GET['category'] ?? []))),
        'criticality' => array_values(array_filter((array) ($_GET['criticality'] ?? []))),
        'statuses' => array_values(array_filter((array) ($_GET['status'] ?? []))),
        'sortColumn' => $sortColumns[$sortCol],
        'sortDirection' => ($_GET['dir'] ?? 'asc') === 'desc' ? 'DESC' : 'ASC',
    ];
} else {
    $filters = [];
}

$suppliers = supplier_list($db, $user, $filters);

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="leverandorer.csv"');

$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF");

fputcsv($out, [
    t('suppliers.table.name'),
    t('suppliers.table.category'),
    t('suppliers.table.criticality'),
    t('suppliers.table.status'),
    t('suppliers.table.next_evaluation'),
], ';');

foreach ($suppliers as $s) {
    fputcsv($out, [
        $s['name'],
        supplier_category_label($s['category']),
        equipment_criticality_label($s['criticality']),
        supplier_status_label($s['status']),
        format_date($s['next_evaluation_date']),
    ], ';');
}

fclose($out);
exit;
