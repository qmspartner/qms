<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/tasks.php';

$user = require_permission('records', 'view');
$myTasks = get_my_tasks($user);
$db = get_db();

$selectedTypes = array_values(array_filter((array) ($_GET['type'] ?? [])));

$sortColumns = [
    'title' => 'r.title',
    'type' => 'r.type',
    'owner_name' => 'u.name',
    'last_updated_at' => 'v.uploaded_at',
    'next_update_date' => 'r.next_update_date',
];
$sortCol = $_GET['sort'] ?? 'title';
if (!isset($sortColumns[$sortCol])) {
    $sortCol = 'title';
}
$sortDir = ($_GET['dir'] ?? 'asc') === 'desc' ? 'DESC' : 'ASC';

$sql = '
    SELECT r.*, u.name AS owner_name, v.version_number, v.uploaded_at AS last_updated_at
    FROM records r
    JOIN users u ON u.id = r.owner_id
    LEFT JOIN record_versions v ON v.id = r.current_version_id
    WHERE r.organization_id = ?
';
$params = [$user['organization_id']];

if ($selectedTypes) {
    $sql .= ' AND r.type IN (' . implode(',', array_fill(0, count($selectedTypes), '?')) . ')';
    $params = array_merge($params, $selectedTypes);
}
$sql .= ' ORDER BY ' . $sortColumns[$sortCol] . ' ' . $sortDir;

$stmt = $db->prepare($sql);
$stmt->execute($params);
$records = $stmt->fetchAll();

$stmt = $db->prepare('
    SELECT
        COUNT(*) AS total,
        SUM(next_update_date IS NOT NULL AND next_update_date <= CURDATE() + INTERVAL 60 DAY) AS due_soon
    FROM records
    WHERE organization_id = ?
');
$stmt->execute([$user['organization_id']]);
$stats = $stmt->fetch();

$types = list_active_values('record_type');

$activeChips = [];
foreach ($selectedTypes as $val) {
    $activeChips[] = ['label' => t('records.table.type') . ': ' . record_type_label($val), 'href' => remove_array_filter_href('type', $selectedTypes, $val)];
}

render_header(t('records.title'));
?>
<div class="page-heading-row">
<div class="page-heading">
    <h1><?= h(t('records.title')) ?></h1>
    <?php if (can_manage_records($user)): ?>
        <a class="button" href="new_record.php"><?= h(t('records.new')) ?></a>
    <?php endif; ?>
</div>
<div></div>
</div>

<div class="page-layout">
<div>
<div class="stat-grid">
    <div class="stat-card"><p class="stat-label"><?= h(t('records.stat.total')) ?></p><p class="stat-value"><?= (int)($stats['total'] ?? 0) ?></p></div>
    <div class="stat-card"><p class="stat-label"><?= h(t('records.stat.due_soon')) ?></p><p class="stat-value stat-warning"><?= (int)($stats['due_soon'] ?? 0) ?></p></div>
</div>

<?php render_active_filters($activeChips, clear_all_filters_href(['type'])); ?>

<table class="doc-table">
    <thead>
    <tr>
        <th><?php render_th_controls(t('records.table.record'), 'title', $sortCol, $sortDir === 'DESC' ? 'desc' : 'asc'); ?></th>
        <th><?php render_th_controls(t('records.table.type'), 'type', $sortCol, $sortDir === 'DESC' ? 'desc' : 'asc', 'type', $types, $selectedTypes, 'record_type_label', t('records.table.type')); ?></th>
        <th><?php render_th_controls(t('records.table.owner'), 'owner_name', $sortCol, $sortDir === 'DESC' ? 'desc' : 'asc'); ?></th>
        <th><?php render_th_controls(t('records.table.last_updated'), 'last_updated_at', $sortCol, $sortDir === 'DESC' ? 'desc' : 'asc'); ?></th>
        <th><?php render_th_controls(t('records.table.next_update'), 'next_update_date', $sortCol, $sortDir === 'DESC' ? 'desc' : 'asc'); ?></th>
    </tr>
    </thead>
    <tbody>
    <?php foreach ($records as $r): ?>
        <tr onclick="window.location='record.php?id=<?= (int)$r['id'] ?>'">
            <td><?= h($r['title']) ?></td>
            <td><?= h(record_type_label($r['type'])) ?></td>
            <td><?= h($r['owner_name']) ?></td>
            <td><?= format_date($r['last_updated_at']) ?></td>
            <td><?= format_date($r['next_update_date']) ?></td>
        </tr>
    <?php endforeach; ?>
    <?php if (!$records): ?>
        <tr><td colspan="5" class="muted"><?= h(t('records.empty')) ?></td></tr>
    <?php endif; ?>
    </tbody>
</table>
</div>
<?php render_task_panel($myTasks); ?>
</div>

<?php render_footer(); ?>
