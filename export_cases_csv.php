<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/cases.php';

$user = require_permission_allow_gqar('cases');
$db = get_db();

$sortColumns = [
    'case' => 'c.id',
    'type' => 'c.type',
    'severity' => 'c.severity',
    'status' => 'c.status',
    'assigned' => 'a.name',
    'deadline' => 'c.due_date',
];

if (($_GET['scope'] ?? 'all') === 'filtered') {
    $sortCol = $_GET['sort'] ?? 'case';
    if (!isset($sortColumns[$sortCol])) {
        $sortCol = 'case';
    }
    $filters = [
        'types' => array_values(array_filter((array) ($_GET['type'] ?? []))),
        'severities' => array_values(array_filter((array) ($_GET['severity'] ?? []))),
        'statuses' => array_values(array_filter((array) ($_GET['status'] ?? []))),
        'supplierId' => (int) ($_GET['supplier_id'] ?? 0),
        'sortColumn' => $sortColumns[$sortCol],
        'sortDirection' => ($_GET['dir'] ?? 'desc') === 'asc' ? 'ASC' : 'DESC',
    ];
} else {
    $filters = [];
}

$cases = case_list($db, $user, $filters);

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="sager.csv"');

$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF");

fputcsv($out, [
    t('cases.table.case'),
    t('cases.table.type'),
    t('cases.table.severity'),
    t('cases.table.status'),
    t('cases.table.responsible'),
    t('cases.table.deadline'),
    t('cases.table.effectiveness'),
], ';');

foreach ($cases as $c) {
    $effStatus = effectiveness_status($c);
    fputcsv($out, [
        'SAG-' . str_pad((string) $c['id'], 4, '0', STR_PAD_LEFT) . ': ' . $c['title'],
        type_label($c['type']),
        $c['severity'] ? severity_label($c['severity']) : '',
        case_status_label($c['status']),
        $c['assigned_to_name'] ?? t('cases.unassigned'),
        format_date($c['due_date']),
        $effStatus !== 'none' ? effectiveness_status_label($effStatus) : '',
    ], ';');
}

fclose($out);
exit;
