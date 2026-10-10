<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/tasks.php';

$user = require_permission('equipment', 'view');
$myTasks = get_my_tasks($user);
$db = get_db();

$selectedCriticality = array_values(array_filter((array) ($_GET['criticality'] ?? [])));
$selectedStatuses = array_values(array_filter((array) ($_GET['status'] ?? [])));

$sortColumns = [
    'name' => 'e.name',
    'criticality' => 'e.criticality',
    'status' => 'e.status',
    'next_calibration_date' => 'e.next_calibration_date',
    'responsible' => 'u.name',
];
$sortCol = $_GET['sort'] ?? 'name';
if (!isset($sortColumns[$sortCol])) {
    $sortCol = 'name';
}
$sortDir = ($_GET['dir'] ?? 'asc') === 'desc' ? 'DESC' : 'ASC';

$sql = '
    SELECT e.*, u.name AS responsible_name, latest.outcome AS latest_outcome
    FROM equipment e
    JOIN users u ON u.id = e.responsible_id
    LEFT JOIN (
        SELECT c1.equipment_id, c1.outcome
        FROM equipment_calibrations c1
        INNER JOIN (
            SELECT equipment_id, MAX(calibration_date) AS max_date
            FROM equipment_calibrations
            GROUP BY equipment_id
        ) c2 ON c2.equipment_id = c1.equipment_id AND c2.max_date = c1.calibration_date
    ) latest ON latest.equipment_id = e.id
    WHERE e.organization_id = ?
';
$params = [$user['organization_id']];

if ($selectedCriticality) {
    $sql .= ' AND e.criticality IN (' . implode(',', array_fill(0, count($selectedCriticality), '?')) . ')';
    $params = array_merge($params, $selectedCriticality);
}
if ($selectedStatuses) {
    $sql .= ' AND e.status IN (' . implode(',', array_fill(0, count($selectedStatuses), '?')) . ')';
    $params = array_merge($params, $selectedStatuses);
}
$sql .= ' ORDER BY ' . $sortColumns[$sortCol] . ' ' . $sortDir;

$stmt = $db->prepare($sql);
$stmt->execute($params);
$equipmentList = $stmt->fetchAll();

$stmt = $db->prepare("
    SELECT
        COUNT(*) AS total,
        SUM(status = 'Aktiv' AND requires_calibration = 1 AND next_calibration_date IS NOT NULL AND next_calibration_date <= CURDATE() + INTERVAL 60 DAY) AS due_soon
    FROM equipment
    WHERE organization_id = ?
");
$stmt->execute([$user['organization_id']]);
$stats = $stmt->fetch();

$stmt = $db->prepare("
    SELECT COUNT(*) AS failed_count
    FROM equipment e
    WHERE e.organization_id = ? AND e.status = 'Aktiv' AND EXISTS (
        SELECT 1 FROM equipment_calibrations c
        WHERE c.equipment_id = e.id AND c.outcome = 'Ikke godkendt'
            AND c.calibration_date = (SELECT MAX(calibration_date) FROM equipment_calibrations c2 WHERE c2.equipment_id = e.id)
    )
");
$stmt->execute([$user['organization_id']]);
$failedStats = $stmt->fetch();

$criticalities = ['Kritisk', 'Ikke-kritisk'];
$statuses = ['Aktiv', 'Ikke aktiv'];

$activeChips = [];
foreach ($selectedCriticality as $val) {
    $activeChips[] = ['label' => t('equipment.table.criticality') . ': ' . equipment_criticality_label($val), 'href' => remove_array_filter_href('criticality', $selectedCriticality, $val)];
}
foreach ($selectedStatuses as $val) {
    $activeChips[] = ['label' => t('equipment.table.status') . ': ' . equipment_status_label($val), 'href' => remove_array_filter_href('status', $selectedStatuses, $val)];
}

render_header(t('equipment.title'));
?>
<div class="page-heading-row">
<div class="page-heading">
    <h1><?= h(t('equipment.title')) ?></h1>
    <?php if (can_manage_equipment($user)): ?>
        <a class="button" href="new_equipment.php"><?= h(t('equipment.new')) ?></a>
    <?php endif; ?>
</div>
<div></div>
</div>

<div class="page-layout">
<div>
<div class="stat-grid">
    <div class="stat-card"><p class="stat-label"><?= h(t('equipment.stat.total')) ?></p><p class="stat-value"><?= (int)($stats['total'] ?? 0) ?></p></div>
    <div class="stat-card"><p class="stat-label"><?= h(t('equipment.stat.due_soon')) ?></p><p class="stat-value stat-warning"><?= (int)($stats['due_soon'] ?? 0) ?></p></div>
    <div class="stat-card"><p class="stat-label"><?= h(t('equipment.stat.failed')) ?></p><p class="stat-value stat-danger"><?= (int)($failedStats['failed_count'] ?? 0) ?></p></div>
</div>

<?php render_active_filters($activeChips, clear_all_filters_href(['criticality', 'status'])); ?>

<table class="doc-table">
    <thead>
    <tr>
        <th><?php render_th_controls(t('equipment.table.name'), 'name', $sortCol, $sortDir === 'DESC' ? 'desc' : 'asc'); ?></th>
        <th><?php render_th_controls(t('equipment.table.criticality'), 'criticality', $sortCol, $sortDir === 'DESC' ? 'desc' : 'asc', 'criticality', $criticalities, $selectedCriticality, 'equipment_criticality_label', t('equipment.table.criticality')); ?></th>
        <th><?php render_th_controls(t('equipment.table.status'), 'status', $sortCol, $sortDir === 'DESC' ? 'desc' : 'asc', 'status', $statuses, $selectedStatuses, 'equipment_status_label', t('equipment.table.status')); ?></th>
        <th><?php render_th_controls(t('equipment.table.next_calibration'), 'next_calibration_date', $sortCol, $sortDir === 'DESC' ? 'desc' : 'asc'); ?></th>
        <th><?php render_th_controls(t('equipment.table.responsible'), 'responsible', $sortCol, $sortDir === 'DESC' ? 'desc' : 'asc'); ?></th>
    </tr>
    </thead>
    <tbody>
    <?php foreach ($equipmentList as $e): ?>
        <tr onclick="window.location='equipment_detail.php?id=<?= (int)$e['id'] ?>'">
            <td>
                <?= h($e['name']) ?>
                <?php if ($e['latest_outcome'] === 'Ikke godkendt'): ?>
                    <span class="badge badge-danger" style="margin-left:6px;"><?= h(equipment_outcome_label('Ikke godkendt')) ?></span>
                <?php endif; ?>
            </td>
            <td><span class="<?= equipment_criticality_class($e['criticality']) ?>"><?= h(equipment_criticality_label($e['criticality'])) ?></span></td>
            <td><span class="<?= equipment_status_class($e['status']) ?>"><?= h(equipment_status_label($e['status'])) ?></span></td>
            <td><?= $e['requires_calibration'] ? format_date($e['next_calibration_date']) : h(t('equipment.not_applicable')) ?></td>
            <td><?= h($e['responsible_name']) ?></td>
        </tr>
    <?php endforeach; ?>
    <?php if (!$equipmentList): ?>
        <tr><td colspan="5" class="muted"><?= h(t('equipment.empty')) ?></td></tr>
    <?php endif; ?>
    </tbody>
</table>
</div>
<?php render_task_panel($myTasks); ?>
</div>

<?php render_footer(); ?>
