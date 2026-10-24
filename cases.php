<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/tasks.php';

$user = require_permission_allow_gqar('cases');
$myTasks = get_my_tasks($user);
$db = get_db();

$selectedTypes = array_values(array_filter((array) ($_GET['type'] ?? [])));
$selectedSeverities = array_values(array_filter((array) ($_GET['severity'] ?? [])));
$selectedStatuses = array_values(array_filter((array) ($_GET['status'] ?? [])));

$sortColumns = [
    'case' => 'c.id',
    'type' => 'c.type',
    'severity' => 'c.severity',
    'status' => 'c.status',
    'assigned' => 'a.name',
    'deadline' => 'c.due_date',
];
$sortCol = $_GET['sort'] ?? 'case';
if (!isset($sortColumns[$sortCol])) {
    $sortCol = 'case';
}
$sortDir = ($_GET['dir'] ?? 'desc') === 'asc' ? 'ASC' : 'DESC';

$sql = "
    SELECT c.*, r.name AS reported_by_name, a.name AS assigned_to_name
    FROM cases c
    JOIN users r ON r.id = c.reported_by
    LEFT JOIN users a ON a.id = c.assigned_to
    WHERE c.organization_id = ? AND c.type != 'Opfølgningspunkt'
";
$params = [$user['organization_id']];

if ($selectedTypes) {
    $sql .= ' AND c.type IN (' . implode(',', array_fill(0, count($selectedTypes), '?')) . ')';
    $params = array_merge($params, $selectedTypes);
}
if ($selectedSeverities) {
    $sql .= ' AND c.severity IN (' . implode(',', array_fill(0, count($selectedSeverities), '?')) . ')';
    $params = array_merge($params, $selectedSeverities);
}
if ($selectedStatuses) {
    $sql .= ' AND c.status IN (' . implode(',', array_fill(0, count($selectedStatuses), '?')) . ')';
    $params = array_merge($params, $selectedStatuses);
}
$filterSupplierId = (int) ($_GET['supplier_id'] ?? 0);
if ($filterSupplierId) {
    $sql .= ' AND c.supplier_id = ?';
    $params[] = $filterSupplierId;
}
if (is_gqar($user)) {
    [$clause, $clauseParams] = contract_in_clause('c.contract_reference', $user['gqar_contract_references']);
    $sql .= " AND c.requires_customer_notification = 1 AND {$clause}";
    $params = array_merge($params, $clauseParams);
}
$sql .= ' ORDER BY ' . $sortColumns[$sortCol] . ' ' . $sortDir;

$stmt = $db->prepare($sql);
$stmt->execute($params);
$cases = $stmt->fetchAll();

$stmt = $db->prepare("
    SELECT
        SUM(status != 'Lukket') AS open_count,
        SUM(type = 'Afvigelse') AS deviation_count,
        SUM(type = 'Forslag') AS suggestion_count,
        SUM(due_date IS NOT NULL AND due_date < CURDATE() AND status != 'Lukket') AS overdue_count,
        SUM(requires_customer_notification = 1 AND status != 'Lukket') AS notification_count,
        SUM(type = 'Kundeklage' AND status != 'Lukket') AS complaint_count,
        SUM(type = 'Mistænkt forfalsket del' AND status != 'Lukket') AS counterfeit_count,
        SUM(type = 'Leverandør reklamation' AND status != 'Lukket') AS supplier_complaint_count,
        SUM(status = 'Lukket' AND corrective_action IS NOT NULL AND corrective_action != '' AND effectiveness_result IS NULL AND effectiveness_review_due IS NULL) AS effectiveness_pending_date_count,
        SUM(status = 'Lukket' AND corrective_action IS NOT NULL AND corrective_action != '' AND effectiveness_result IS NULL AND effectiveness_review_due IS NOT NULL AND effectiveness_review_due <= CURDATE() + INTERVAL " . EFFECTIVENESS_DUE_SOON_DAYS . " DAY) AS effectiveness_due_count
    FROM cases
    WHERE organization_id = ? AND type != 'Opfølgningspunkt'
");
$stmt->execute([$user['organization_id']]);
$stats = $stmt->fetch();

$types = ['Afvigelse', 'Forslag', 'Observation', 'Audit fund', 'Kundeklage', 'Mistænkt forfalsket del', 'Leverandør reklamation'];
$severities = ['Høj', 'Mellem', 'Lav'];
$caseStatuses = ['Registreret', 'Under undersøgelse', 'Handling igangsat', 'Lukket'];

$activeChips = [];
foreach ($selectedTypes as $val) {
    $activeChips[] = ['label' => t('cases.table.type') . ': ' . type_label($val), 'href' => remove_array_filter_href('type', $selectedTypes, $val)];
}
foreach ($selectedSeverities as $val) {
    $activeChips[] = ['label' => t('cases.table.severity') . ': ' . severity_label($val), 'href' => remove_array_filter_href('severity', $selectedSeverities, $val)];
}
foreach ($selectedStatuses as $val) {
    $activeChips[] = ['label' => t('cases.table.status') . ': ' . case_status_label($val), 'href' => remove_array_filter_href('status', $selectedStatuses, $val)];
}

render_header(t('cases.title'));
?>
<div class="page-heading-row">
<div class="page-heading">
    <h1><?= h(t('cases.title')) ?></h1>
    <?php if (can_create_cases($user)): ?>
        <a class="button" href="new_case.php"><?= h(t('cases.register')) ?></a>
    <?php endif; ?>
</div>
<div></div>
</div>

<div class="page-layout">
<div>
<div class="stat-grid">
    <div class="stat-card"><p class="stat-label"><?= h(t('cases.stat.open')) ?></p><p class="stat-value"><?= (int)($stats['open_count'] ?? 0) ?></p></div>
    <div class="stat-card"><p class="stat-label"><?= h(t('cases.stat.deviations')) ?></p><p class="stat-value stat-danger"><?= (int)($stats['deviation_count'] ?? 0) ?></p></div>
    <div class="stat-card"><p class="stat-label"><?= h(t('cases.stat.suggestions')) ?></p><p class="stat-value"><?= (int)($stats['suggestion_count'] ?? 0) ?></p></div>
    <div class="stat-card"><p class="stat-label"><?= h(t('cases.stat.overdue')) ?></p><p class="stat-value stat-warning"><?= (int)($stats['overdue_count'] ?? 0) ?></p></div>
    <div class="stat-card"><p class="stat-label"><?= h(t('cases.stat.customer_notification')) ?></p><p class="stat-value stat-danger"><?= (int)($stats['notification_count'] ?? 0) ?></p></div>
    <div class="stat-card"><p class="stat-label"><?= h(t('cases.stat.complaints')) ?></p><p class="stat-value stat-warning"><?= (int)($stats['complaint_count'] ?? 0) ?></p></div>
    <div class="stat-card"><p class="stat-label"><?= h(t('cases.stat.counterfeit')) ?></p><p class="stat-value stat-danger"><?= (int)($stats['counterfeit_count'] ?? 0) ?></p></div>
    <div class="stat-card"><p class="stat-label"><?= h(t('cases.stat.supplier_complaints')) ?></p><p class="stat-value stat-warning"><?= (int)($stats['supplier_complaint_count'] ?? 0) ?></p></div>
    <div class="stat-card"><p class="stat-label"><?= h(t('cases.stat.effectiveness_pending_date')) ?></p><p class="stat-value"><?= (int)($stats['effectiveness_pending_date_count'] ?? 0) ?></p></div>
    <div class="stat-card"><p class="stat-label"><?= h(t('cases.stat.effectiveness_due')) ?></p><p class="stat-value stat-warning"><?= (int)($stats['effectiveness_due_count'] ?? 0) ?></p></div>
</div>

<?php render_active_filters($activeChips, clear_all_filters_href(['type', 'severity', 'status'])); ?>

<table class="doc-table">
    <thead>
    <tr>
        <th><?php render_th_controls(t('cases.table.case'), 'case', $sortCol, $sortDir === 'DESC' ? 'desc' : 'asc'); ?></th>
        <th><?php render_th_controls(t('cases.table.type'), 'type', $sortCol, $sortDir === 'DESC' ? 'desc' : 'asc', 'type', $types, $selectedTypes, 'type_label', t('cases.table.type')); ?></th>
        <th><?php render_th_controls(t('cases.table.severity'), 'severity', $sortCol, $sortDir === 'DESC' ? 'desc' : 'asc', 'severity', $severities, $selectedSeverities, 'severity_label', t('cases.table.severity')); ?></th>
        <th><?php render_th_controls(t('cases.table.status'), 'status', $sortCol, $sortDir === 'DESC' ? 'desc' : 'asc', 'status', $caseStatuses, $selectedStatuses, 'case_status_label', t('cases.table.status')); ?></th>
        <th><?php render_th_controls(t('cases.table.responsible'), 'assigned', $sortCol, $sortDir === 'DESC' ? 'desc' : 'asc'); ?></th>
        <th><?php render_th_controls(t('cases.table.deadline'), 'deadline', $sortCol, $sortDir === 'DESC' ? 'desc' : 'asc'); ?></th>
        <th><?= h(t('cases.table.effectiveness')) ?></th>
    </tr>
    </thead>
    <tbody>
    <?php foreach ($cases as $c): ?>
        <?php $effStatus = effectiveness_status($c); ?>
        <tr onclick="window.location='case.php?id=<?= (int)$c['id'] ?>'">
            <td>SAG-<?= str_pad((string)$c['id'], 4, '0', STR_PAD_LEFT) ?>: <?= h($c['title']) ?></td>
            <td><?= h(type_label($c['type'])) ?></td>
            <td><?= $c['severity'] ? '<span class="' . severity_class($c['severity']) . '">' . h(severity_label($c['severity'])) . '</span>' : '—' ?></td>
            <td><span class="<?= case_status_class($c['status']) ?>"><?= h(case_status_label($c['status'])) ?></span></td>
            <td><?= h($c['assigned_to_name'] ?? t('cases.unassigned')) ?></td>
            <td><?= format_date($c['due_date']) ?></td>
            <td><?= $effStatus !== 'none' ? '<span class="' . effectiveness_status_class($effStatus) . '">' . h(effectiveness_status_label($effStatus)) . '</span>' : '<span class="muted">—</span>' ?></td>
        </tr>
    <?php endforeach; ?>
    <?php if (!$cases): ?>
        <tr><td colspan="7" class="muted"><?= h(t('cases.empty')) ?></td></tr>
    <?php endif; ?>
    </tbody>
</table>
</div>
<?php render_task_panel($myTasks); ?>
</div>

<?php render_footer(); ?>
