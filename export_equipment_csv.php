<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/equipment.php';

$user = require_permission('equipment', 'view');
$db = get_db();

$sortColumns = [
    'name' => 'e.name',
    'criticality' => 'e.criticality',
    'status' => 'e.status',
    'next_calibration_date' => 'e.next_calibration_date',
    'responsible' => 'u.name',
];

if (($_GET['scope'] ?? 'all') === 'filtered') {
    $sortCol = $_GET['sort'] ?? 'name';
    if (!isset($sortColumns[$sortCol])) {
        $sortCol = 'name';
    }
    $filters = [
        'criticality' => array_values(array_filter((array) ($_GET['criticality'] ?? []))),
        'statuses' => array_values(array_filter((array) ($_GET['status'] ?? []))),
        'sortColumn' => $sortColumns[$sortCol],
        'sortDirection' => ($_GET['dir'] ?? 'asc') === 'desc' ? 'DESC' : 'ASC',
    ];
} else {
    $filters = [];
}

$equipmentList = equipment_list($db, $user, $filters);

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="udstyr.csv"');

$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF");

fputcsv($out, [
    t('equipment.table.name'),
    t('equipment.table.criticality'),
    t('equipment.table.status'),
    t('equipment.table.next_calibration'),
    t('equipment.table.responsible'),
], ';');

foreach ($equipmentList as $e) {
    fputcsv($out, [
        $e['name'],
        equipment_criticality_label($e['criticality']),
        equipment_status_label($e['status']),
        $e['requires_calibration'] ? format_date($e['next_calibration_date']) : t('equipment.not_applicable'),
        $e['responsible_name'],
    ], ';');
}

fclose($out);
exit;
