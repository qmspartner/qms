<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/tasks.php';

$user = require_permission('suppliers', 'view');
$myTasks = get_my_tasks($user);
$db = get_db();

$selectedCategories = array_values(array_filter((array) ($_GET['category'] ?? [])));
$selectedCriticality = array_values(array_filter((array) ($_GET['criticality'] ?? [])));
$selectedStatuses = array_values(array_filter((array) ($_GET['status'] ?? [])));

$sortColumns = [
    'name' => 's.name',
    'category' => 's.category',
    'criticality' => 's.criticality',
    'status' => 's.status',
    'next_evaluation_date' => 's.next_evaluation_date',
];
$sortCol = $_GET['sort'] ?? 'name';
if (!isset($sortColumns[$sortCol])) {
    $sortCol = 'name';
}
$sortDir = ($_GET['dir'] ?? 'asc') === 'desc' ? 'DESC' : 'ASC';

$sql = 'SELECT s.* FROM suppliers s WHERE s.organization_id = ?';
$params = [$user['organization_id']];

if ($selectedCategories) {
    $sql .= ' AND s.category IN (' . implode(',', array_fill(0, count($selectedCategories), '?')) . ')';
    $params = array_merge($params, $selectedCategories);
}
if ($selectedCriticality) {
    $sql .= ' AND s.criticality IN (' . implode(',', array_fill(0, count($selectedCriticality), '?')) . ')';
    $params = array_merge($params, $selectedCriticality);
}
if ($selectedStatuses) {
    $sql .= ' AND s.status IN (' . implode(',', array_fill(0, count($selectedStatuses), '?')) . ')';
    $params = array_merge($params, $selectedStatuses);
}
$sql .= ' ORDER BY ' . $sortColumns[$sortCol] . ' ' . $sortDir;

$stmt = $db->prepare($sql);
$stmt->execute($params);
$suppliers = $stmt->fetchAll();

$stmt = $db->prepare("
    SELECT
        COUNT(*) AS total,
        SUM(status = 'Godkendt' AND next_evaluation_date IS NOT NULL AND next_evaluation_date <= CURDATE() + INTERVAL 60 DAY) AS due_soon
    FROM suppliers WHERE organization_id = ?
");
$stmt->execute([$user['organization_id']]);
$stats = $stmt->fetch();

$categories = supplier_category_options();
$criticalities = ['Kritisk', 'Ikke-kritisk'];
$statuses = ['Godkendt', 'Under vurdering', 'Afvist', 'Udfaset'];

$activeChips = [];
foreach ($selectedCategories as $val) {
    $activeChips[] = ['label' => t('suppliers.table.category') . ': ' . supplier_category_label($val), 'href' => remove_array_filter_href('category', $selectedCategories, $val)];
}
foreach ($selectedCriticality as $val) {
    $activeChips[] = ['label' => t('suppliers.table.criticality') . ': ' . equipment_criticality_label($val), 'href' => remove_array_filter_href('criticality', $selectedCriticality, $val)];
}
foreach ($selectedStatuses as $val) {
    $activeChips[] = ['label' => t('suppliers.table.status') . ': ' . supplier_status_label($val), 'href' => remove_array_filter_href('status', $selectedStatuses, $val)];
}

render_header(t('suppliers.title'));
?>
<div class="page-heading-row">
<div class="page-heading">
    <h1><?= h(t('suppliers.title')) ?></h1>
    <div class="button-row" style="margin:0;">
        <a class="button button-secondary" href="export_suppliers_csv.php"><?= h(t('suppliers.export')) ?></a>
        <?php if (can_manage_suppliers($user)): ?>
            <a class="button button-secondary" href="import_suppliers_csv.php"><?= h(t('suppliers.import')) ?></a>
            <a class="button" href="new_supplier.php"><?= h(t('suppliers.new')) ?></a>
        <?php endif; ?>
    </div>
</div>
<div></div>
</div>

<div class="page-layout">
<div>
<div class="stat-grid">
    <div class="stat-card"><p class="stat-label"><?= h(t('suppliers.stat.total')) ?></p><p class="stat-value"><?= (int)($stats['total'] ?? 0) ?></p></div>
    <div class="stat-card"><p class="stat-label"><?= h(t('suppliers.stat.due_soon')) ?></p><p class="stat-value stat-warning"><?= (int)($stats['due_soon'] ?? 0) ?></p></div>
</div>

<?php render_active_filters($activeChips, clear_all_filters_href(['category', 'criticality', 'status'])); ?>

<table class="doc-table">
    <thead>
    <tr>
        <th><?php render_th_controls(t('suppliers.table.name'), 'name', $sortCol, $sortDir === 'DESC' ? 'desc' : 'asc'); ?></th>
        <th><?php render_th_controls(t('suppliers.table.category'), 'category', $sortCol, $sortDir === 'DESC' ? 'desc' : 'asc', 'category', $categories, $selectedCategories, 'supplier_category_label', t('suppliers.table.category')); ?></th>
        <th><?php render_th_controls(t('suppliers.table.criticality'), 'criticality', $sortCol, $sortDir === 'DESC' ? 'desc' : 'asc', 'criticality', $criticalities, $selectedCriticality, 'equipment_criticality_label', t('suppliers.table.criticality')); ?></th>
        <th><?php render_th_controls(t('suppliers.table.status'), 'status', $sortCol, $sortDir === 'DESC' ? 'desc' : 'asc', 'status', $statuses, $selectedStatuses, 'supplier_status_label', t('suppliers.table.status')); ?></th>
        <th><?php render_th_controls(t('suppliers.table.next_evaluation'), 'next_evaluation_date', $sortCol, $sortDir === 'DESC' ? 'desc' : 'asc'); ?></th>
    </tr>
    </thead>
    <tbody>
    <?php foreach ($suppliers as $s): ?>
        <tr onclick="window.location='supplier_detail.php?id=<?= (int)$s['id'] ?>'">
            <td>
                <?= h($s['name']) ?>
                <?php $counterfeitCount = count_open_counterfeit_cases((int) $s['id']); ?>
                <?php if ($counterfeitCount > 0): ?>
                    <a href="cases.php?<?= http_build_query(['type' => ['Mistænkt forfalsket del']]) ?>&supplier_id=<?= (int) $s['id'] ?>"
                       title="<?= h(t('supplier.counterfeit_badge', ['count' => $counterfeitCount])) ?>"
                       style="display:inline-flex; align-items:center; gap:2px; margin-left:6px; color:var(--danger);"
                       onclick="event.stopPropagation();">
                        <i class="ti ti-alert-triangle" style="font-size:15px;"></i><span style="font-size:13px; font-weight:600;"><?= $counterfeitCount ?></span>
                    </a>
                <?php endif; ?>
                <?php $complaintCount = count_open_supplier_complaint_cases((int) $s['id']); ?>
                <?php if ($complaintCount > 0): ?>
                    <a href="cases.php?<?= http_build_query(['type' => ['Leverandør reklamation']]) ?>&supplier_id=<?= (int) $s['id'] ?>"
                       title="<?= h(t('supplier.complaint_badge', ['count' => $complaintCount])) ?>"
                       style="display:inline-flex; align-items:center; gap:2px; margin-left:6px; color:var(--warning);"
                       onclick="event.stopPropagation();">
                        <i class="ti ti-alert-triangle" style="font-size:15px;"></i><span style="font-size:13px; font-weight:600;"><?= $complaintCount ?></span>
                    </a>
                <?php endif; ?>
            </td>
            <td><?= h(supplier_category_label($s['category'])) ?></td>
            <td><span class="<?= equipment_criticality_class($s['criticality']) ?>"><?= h(equipment_criticality_label($s['criticality'])) ?></span></td>
            <td><span class="<?= supplier_status_class($s['status']) ?>"><?= h(supplier_status_label($s['status'])) ?></span></td>
            <td><?= format_date($s['next_evaluation_date']) ?></td>
        </tr>
    <?php endforeach; ?>
    <?php if (!$suppliers): ?>
        <tr><td colspan="5" class="muted"><?= h(t('suppliers.empty')) ?></td></tr>
    <?php endif; ?>
    </tbody>
</table>
</div>
<?php render_task_panel($myTasks); ?>
</div>

<?php render_footer(); ?>
