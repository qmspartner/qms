<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/quality_objectives.php';
require_once __DIR__ . '/includes/tasks.php';

$user = require_permission('quality_objectives', 'view');
$myTasks = get_my_tasks($user);
$db = get_db();

$selectedStatuses = array_values(array_filter((array) ($_GET['status'] ?? [])));

$sortColumns = [
    'title' => 'qo.title',
    'owner' => 'u.name',
    'status' => 'qo.status',
    'target_date' => 'qo.target_date',
];
$sortCol = $_GET['sort'] ?? 'title';
if (!isset($sortColumns[$sortCol])) {
    $sortCol = 'title';
}
$sortDir = ($_GET['dir'] ?? 'asc') === 'desc' ? 'DESC' : 'ASC';

$objectives = quality_objective_list($db, $user, [
    'statuses' => $selectedStatuses,
    'sortColumn' => $sortColumns[$sortCol],
    'sortDirection' => $sortDir,
]);

$stats = quality_objective_stats($db, $user['organization_id']);

$statuses = ['Ny', 'På kurs', 'Ikke på kurs', 'Opnået', 'Opgivet'];
$activeChips = [];
foreach ($selectedStatuses as $val) {
    $activeChips[] = ['label' => t('qualityobjectives.table.status') . ': ' . quality_objective_status_label($val), 'href' => remove_array_filter_href('status', $selectedStatuses, $val)];
}

$canManage = has_permission($user, 'quality_objectives', 'create');

render_header(t('qualityobjectives.title'));
?>
<div class="page-heading-row">
<div class="page-heading">
    <h1><?= h(t('qualityobjectives.title')) ?></h1>
    <?php if ($canManage): ?>
        <a class="button" href="new_quality_objective.php"><?= h(t('qualityobjectives.new')) ?></a>
    <?php endif; ?>
</div>
<div></div>
</div>
<p class="muted" style="margin:-8px 0 20px;"><?= h(t('qualityobjectives.description')) ?></p>

<div class="page-layout">
<div>
<div class="stat-grid">
    <div class="stat-card"><p class="stat-label"><?= h(t('qualityobjectives.stat.active')) ?></p><p class="stat-value"><?= (int) ($stats['active'] ?? 0) ?></p></div>
    <div class="stat-card"><p class="stat-label"><?= h(t('qualityobjectives.stat.achieved_ytd')) ?></p><p class="stat-value"><?= (int) ($stats['achieved_ytd'] ?? 0) ?></p></div>
    <div class="stat-card"><p class="stat-label"><?= h(t('qualityobjectives.stat.off_track')) ?></p><p class="stat-value <?= ($stats['off_track'] ?? 0) > 0 ? 'stat-warning' : '' ?>"><?= (int) ($stats['off_track'] ?? 0) ?></p></div>
</div>

<?php render_active_filters($activeChips, clear_all_filters_href(['status'])); ?>

<table class="doc-table">
    <thead>
    <tr>
        <th><?php render_th_controls(t('qualityobjectives.table.title'), 'title', $sortCol, $sortDir === 'DESC' ? 'desc' : 'asc'); ?></th>
        <th><?php render_th_controls(t('qualityobjectives.table.owner'), 'owner', $sortCol, $sortDir === 'DESC' ? 'desc' : 'asc'); ?></th>
        <th><?= h(t('qualityobjectives.table.target')) ?></th>
        <th><?php render_th_controls(t('qualityobjectives.table.target_date'), 'target_date', $sortCol, $sortDir === 'DESC' ? 'desc' : 'asc'); ?></th>
        <th><?php render_th_controls(t('qualityobjectives.table.status'), 'status', $sortCol, $sortDir === 'DESC' ? 'desc' : 'asc', 'status', $statuses, $selectedStatuses, 'quality_objective_status_label', t('qualityobjectives.table.status')); ?></th>
    </tr>
    </thead>
    <tbody>
    <?php foreach ($objectives as $o): ?>
        <tr onclick="window.location='quality_objective_detail.php?id=<?= (int) $o['id'] ?>'">
            <td><?= h($o['title']) ?></td>
            <td><?= h($o['owner_name']) ?></td>
            <td><?= h(quality_objective_target_label($o)) ?></td>
            <td><?= format_date($o['target_date']) ?></td>
            <td><span class="<?= quality_objective_status_class($o['status']) ?>"><?= h(quality_objective_status_label($o['status'])) ?></span></td>
        </tr>
    <?php endforeach; ?>
    <?php if (!$objectives): ?>
        <tr><td colspan="5" class="muted"><?= h(t('qualityobjectives.empty')) ?></td></tr>
    <?php endif; ?>
    </tbody>
</table>
</div>
<?php render_task_panel($myTasks); ?>
</div>

<?php render_footer(); ?>
