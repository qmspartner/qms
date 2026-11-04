<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/audits.php';
require_once __DIR__ . '/includes/tasks.php';

$user = require_permission_allow_gqar('audits');
$myTasks = get_my_tasks($user);
$db = get_db();

$selectedStatuses = array_values(array_filter((array) ($_GET['status'] ?? [])));
$selectedTypes = array_values(array_filter((array) ($_GET['audit_type'] ?? [])));

$sortColumns = [
    'title' => 'a.title',
    'auditor' => 'u.name',
    'planned_date' => 'a.planned_date',
    'status' => 'a.status',
    'audit_type' => 'a.audit_type',
];
$sortCol = $_GET['sort'] ?? 'planned_date';
if (!isset($sortColumns[$sortCol])) {
    $sortCol = 'planned_date';
}
$sortDir = ($_GET['dir'] ?? 'desc') === 'asc' ? 'ASC' : 'DESC';

// Auditlisten og -statistikken hentes via det centrale audit-repository
// (includes/audits.php) i stedet for at bygge SQL direkte her — se den fil for den
// fulde forklaring og den centrale GQAR-synlighedsregel (audit_visibility_sql()).
$audits = audit_list($db, $user, [
    'statuses' => $selectedStatuses,
    'types' => $selectedTypes,
    'sortColumn' => $sortColumns[$sortCol],
    'sortDirection' => $sortDir,
]);

$auditStats = audit_stats($db, $user['organization_id']);

$statuses = ['Planlagt', 'Gennemført', 'Aflyst'];
$auditTypes = ['Internt', 'Eksternt'];

$activeChips = [];
foreach ($selectedStatuses as $val) {
    $activeChips[] = ['label' => t('audits.table.status') . ': ' . audit_status_label($val), 'href' => remove_array_filter_href('status', $selectedStatuses, $val)];
}
foreach ($selectedTypes as $val) {
    $activeChips[] = ['label' => t('audits.table.audit_type') . ': ' . audit_type_label($val), 'href' => remove_array_filter_href('audit_type', $selectedTypes, $val)];
}

render_header(t('audits.title'));
?>
<div class="page-heading-row">
<div class="page-heading">
    <h1><?= h(t('audits.title')) ?></h1>
    <?php if (can_manage_audits($user)): ?>
        <a class="button" href="new_audit.php"><?= h(t('audits.new')) ?></a>
    <?php endif; ?>
</div>
<div></div>
</div>

<div class="page-layout">
<div>
<div class="stat-grid">
    <div class="stat-card"><p class="stat-label"><?= h(t('audits.stat.planned')) ?></p><p class="stat-value"><?= (int)($auditStats['planned_this_year'] ?? 0) ?></p></div>
    <div class="stat-card"><p class="stat-label"><?= h(t('audits.stat.findings')) ?></p><p class="stat-value stat-warning"><?= (int)($auditStats['open_findings'] ?? 0) ?></p></div>
</div>

<?php render_active_filters($activeChips, clear_all_filters_href(['status', 'audit_type'])); ?>

<table class="doc-table">
    <thead>
    <tr>
        <th><?php render_th_controls(t('audits.table.title'), 'title', $sortCol, $sortDir === 'DESC' ? 'desc' : 'asc'); ?></th>
        <th><?php render_th_controls(t('audits.table.audit_type'), 'audit_type', $sortCol, $sortDir === 'DESC' ? 'desc' : 'asc', 'audit_type', $auditTypes, $selectedTypes, 'audit_type_label', t('audits.table.audit_type')); ?></th>
        <th><?php render_th_controls(t('audits.table.auditor'), 'auditor', $sortCol, $sortDir === 'DESC' ? 'desc' : 'asc'); ?></th>
        <th><?php render_th_controls(t('audits.table.planned_date'), 'planned_date', $sortCol, $sortDir === 'DESC' ? 'desc' : 'asc'); ?></th>
        <th><?php render_th_controls(t('audits.table.status'), 'status', $sortCol, $sortDir === 'DESC' ? 'desc' : 'asc', 'status', $statuses, $selectedStatuses, 'audit_status_label', t('audits.table.status')); ?></th>
    </tr>
    </thead>
    <tbody>
    <?php foreach ($audits as $a): ?>
        <tr onclick="window.location='audit.php?id=<?= (int)$a['id'] ?>'">
            <td><?= h($a['title']) ?></td>
            <td><?= h(audit_type_label($a['audit_type'])) ?></td>
            <td><?= h($a['auditor_name']) ?></td>
            <td><?= format_date($a['planned_date']) ?></td>
            <td><span class="<?= audit_status_class($a['status']) ?>"><?= h(audit_status_label($a['status'])) ?></span></td>
        </tr>
    <?php endforeach; ?>
    <?php if (!$audits): ?>
        <tr><td colspan="5" class="muted"><?= h(t('audits.empty')) ?></td></tr>
    <?php endif; ?>
    </tbody>
</table>
</div>
<?php render_task_panel($myTasks); ?>
</div>

<?php render_footer(); ?>
