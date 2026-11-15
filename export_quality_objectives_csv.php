<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/quality_objectives.php';

$user = require_permission('quality_objectives', 'view');
$db = get_db();

$sortColumns = [
    'title' => 'qo.title',
    'owner' => 'u.name',
    'status' => 'qo.status',
    'target_date' => 'qo.target_date',
];

if (($_GET['scope'] ?? 'all') === 'filtered') {
    $sortCol = $_GET['sort'] ?? 'title';
    if (!isset($sortColumns[$sortCol])) {
        $sortCol = 'title';
    }
    $filters = [
        'statuses' => array_values(array_filter((array) ($_GET['status'] ?? []))),
        'sortColumn' => $sortColumns[$sortCol],
        'sortDirection' => ($_GET['dir'] ?? 'asc') === 'desc' ? 'DESC' : 'ASC',
    ];
} else {
    $filters = [];
}

$objectives = quality_objective_list($db, $user, $filters);

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="kvalitetsmaal.csv"');

$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF");

fputcsv($out, [
    t('qualityobjectives.table.title'),
    t('qualityobjectives.table.owner'),
    t('qualityobjectives.table.target'),
    t('qualityobjectives.table.target_date'),
    t('qualityobjectives.table.status'),
], ';');

foreach ($objectives as $o) {
    fputcsv($out, [
        $o['title'],
        $o['owner_name'],
        quality_objective_target_label($o),
        format_date($o['target_date']),
        quality_objective_status_label($o['status']),
    ], ';');
}

fclose($out);
exit;
