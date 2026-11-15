<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/audits.php';

$user = require_permission_allow_gqar('audits');
$db = get_db();

$sortColumns = [
    'title' => 'a.title',
    'auditor' => 'u.name',
    'planned_date' => 'a.planned_date',
    'status' => 'a.status',
    'audit_type' => 'a.audit_type',
];

if (($_GET['scope'] ?? 'all') === 'filtered') {
    $sortCol = $_GET['sort'] ?? 'planned_date';
    if (!isset($sortColumns[$sortCol])) {
        $sortCol = 'planned_date';
    }
    $filters = [
        'statuses' => array_values(array_filter((array) ($_GET['status'] ?? []))),
        'types' => array_values(array_filter((array) ($_GET['audit_type'] ?? []))),
        'sortColumn' => $sortColumns[$sortCol],
        'sortDirection' => ($_GET['dir'] ?? 'desc') === 'asc' ? 'ASC' : 'DESC',
    ];
} else {
    $filters = [];
}

$audits = audit_list($db, $user, $filters);

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="audits.csv"');

$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF");

fputcsv($out, [
    t('audits.table.title'),
    t('audits.table.audit_type'),
    t('audits.table.auditor'),
    t('audits.table.planned_date'),
    t('audits.table.status'),
], ';');

foreach ($audits as $a) {
    fputcsv($out, [
        $a['title'],
        audit_type_label($a['audit_type']),
        $a['auditor_name'],
        format_date($a['planned_date']),
        audit_status_label($a['status']),
    ], ';');
}

fclose($out);
exit;
