<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
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

$sql = '
    SELECT a.*, u.name AS auditor_name
    FROM audits a
    JOIN users u ON u.id = a.auditor_id
    WHERE a.organization_id = ?
';
$params = [$user['organization_id']];

if ($selectedStatuses) {
    $sql .= ' AND a.status IN (' . implode(',', array_fill(0, count($selectedStatuses), '?')) . ')';
    $params = array_merge($params, $selectedStatuses);
}
if ($selectedTypes) {
    $sql .= ' AND a.audit_type IN (' . implode(',', array_fill(0, count($selectedTypes), '?')) . ')';
    $params = array_merge($params, $selectedTypes);
}
if (is_gqar($user)) {
    [$clause, $clauseParams] = contract_in_clause('a.contract_reference', $user['gqar_contract_references']);
    $sql .= " AND a.audit_type = 'Eksternt' AND {$clause}";
    $params = array_merge($params, $clauseParams);
}
$sql .= ' ORDER BY ' . $sortColumns[$sortCol] . ' ' . $sortDir;

$stmt = $db->prepare($sql);
$stmt->execute($params);
$audits = $stmt->fetchAll();

$stmt = $db->prepare("
    SELECT SUM(YEAR(planned_date) = YEAR(CURDATE())) AS planned_this_year
    FROM audits WHERE organization_id = ?
");
$stmt->execute([$user['organization_id']]);
$auditStats = $stmt->fetch();

$stmt = $db->prepare("
    SELECT COUNT(*) AS open_findings
    FROM cases WHERE organization_id = ? AND audit_id IS NOT NULL AND status != 'Lukket'
");
$stmt->execute([$user['organization_id']]);
$findingStats = $stmt->fetch();

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
    <div class="stat-card"><p class="stat-label"><?= h(t('audits.stat.findings')) ?></p><p class="stat-value stat-warning"><?= (int)($findingStats['open_findings'] ?? 0) ?></p></div>
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
