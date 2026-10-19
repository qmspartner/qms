<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/tasks.php';

$user = require_permission_allow_gqar('documents');
$myTasks = get_my_tasks($user);
$db = get_db();

$search = trim($_GET['q'] ?? '');
$selectedCategories = array_values(array_filter((array) ($_GET['category'] ?? [])));
$selectedStatuses = array_values(array_filter((array) ($_GET['status'] ?? [])));

$sortColumns = [
    'title' => 'd.title',
    'doc_number' => 'd.doc_number',
    'category' => 'd.category',
    'owner_name' => 'u.name',
    'status' => 'd.status',
    'next_review_date' => 'd.next_review_date',
];
$sortCol = $_GET['sort'] ?? 'doc_number';
if (!isset($sortColumns[$sortCol])) {
    $sortCol = 'doc_number';
}
$sortDir = ($_GET['dir'] ?? 'asc') === 'desc' ? 'DESC' : 'ASC';

$sql = 'SELECT d.*, u.name AS owner_name FROM documents d JOIN users u ON u.id = d.owner_id WHERE d.organization_id = ?';
$params = [$user['organization_id']];

if ($search !== '') {
    $sql .= ' AND (d.title LIKE ? OR d.doc_number LIKE ?)';
    $params[] = "%$search%";
    $params[] = "%$search%";
}
if ($selectedCategories) {
    $sql .= ' AND d.category IN (' . implode(',', array_fill(0, count($selectedCategories), '?')) . ')';
    $params = array_merge($params, $selectedCategories);
}
if ($selectedStatuses) {
    $sql .= ' AND d.status IN (' . implode(',', array_fill(0, count($selectedStatuses), '?')) . ')';
    $params = array_merge($params, $selectedStatuses);
}
if (is_gqar($user)) {
    [$clause, $clauseParams] = contract_in_clause('d.baseline_reference', $user['gqar_contract_references']);
    $sql .= " AND d.is_configuration_item = 1 AND d.status = 'Godkendt' AND {$clause}";
    $params = array_merge($params, $clauseParams);
}
$sql .= ' ORDER BY ' . $sortColumns[$sortCol] . ' ' . $sortDir;

$stmt = $db->prepare($sql);
$stmt->execute($params);
$documents = $stmt->fetchAll();

$stmt = $db->prepare("
    SELECT
        SUM(status = 'Godkendt') AS active,
        SUM(status = 'Til godkendelse') AS pending,
        SUM(next_review_date IS NOT NULL AND next_review_date <= CURDATE() + INTERVAL 60 DAY) AS expiring
    FROM documents
    WHERE organization_id = ?
");
$stmt->execute([$user['organization_id']]);
$stats = $stmt->fetch();

$categories = document_category_selectable_values();
$statuses = ['Kladde', 'Til godkendelse', 'Godkendt', 'Udgået'];

$activeChips = [];
if ($search !== '') {
    $activeChips[] = ['label' => t('documents.search.placeholder') . ': ' . $search, 'href' => basename($_SERVER['PHP_SELF']) . '?' . query_merge(['q' => null])];
}
foreach ($selectedCategories as $val) {
    $activeChips[] = ['label' => t('documents.table.category') . ': ' . category_label($val), 'href' => remove_array_filter_href('category', $selectedCategories, $val)];
}
foreach ($selectedStatuses as $val) {
    $activeChips[] = ['label' => t('documents.table.status') . ': ' . status_label($val), 'href' => remove_array_filter_href('status', $selectedStatuses, $val)];
}

render_header(t('documents.title'));
?>
<div class="page-heading-row">
<div class="page-heading">
    <h1><?= h(t('documents.title')) ?></h1>
    <?php if (can_edit_documents($user)): ?>
        <a class="button button-secondary" href="import_documents_csv.php"><?= h(t('docimport.nav')) ?></a>
        <a class="button" href="upload_version.php"><?= h(t('documents.new')) ?></a>
    <?php endif; ?>
</div>
<div></div>
</div>

<div class="page-layout">
<div>
<div class="stat-grid">
    <div class="stat-card"><p class="stat-label"><?= h(t('documents.stat.approved')) ?></p><p class="stat-value"><?= (int)($stats['active'] ?? 0) ?></p></div>
    <div class="stat-card"><p class="stat-label"><?= h(t('documents.stat.pending')) ?></p><p class="stat-value stat-warning"><?= (int)($stats['pending'] ?? 0) ?></p></div>
    <div class="stat-card"><p class="stat-label"><?= h(t('documents.stat.expiring')) ?></p><p class="stat-value stat-danger"><?= (int)($stats['expiring'] ?? 0) ?></p></div>
</div>

<form method="get" class="filter-bar">
    <?php hidden_inputs_for_query(['q']); ?>
    <input type="text" name="q" placeholder="<?= h(t('documents.search.placeholder')) ?>" value="<?= h($search) ?>">
    <button type="submit"><?= h(t('documents.search.submit')) ?></button>
</form>

<?php render_active_filters($activeChips, clear_all_filters_href(['q', 'category', 'status'])); ?>

<table class="doc-table">
    <thead>
    <tr>
        <th><?php render_th_controls(t('documents.table.document'), 'title', $sortCol, $sortDir === 'DESC' ? 'desc' : 'asc'); ?></th>
        <th><?php render_th_controls(t('documents.table.id'), 'doc_number', $sortCol, $sortDir === 'DESC' ? 'desc' : 'asc'); ?></th>
        <th><?php render_th_controls(t('documents.table.category'), 'category', $sortCol, $sortDir === 'DESC' ? 'desc' : 'asc', 'category', $categories, $selectedCategories, 'category_label', t('documents.table.category')); ?></th>
        <th><?php render_th_controls(t('documents.table.owner'), 'owner_name', $sortCol, $sortDir === 'DESC' ? 'desc' : 'asc'); ?></th>
        <th><?php render_th_controls(t('documents.table.status'), 'status', $sortCol, $sortDir === 'DESC' ? 'desc' : 'asc', 'status', $statuses, $selectedStatuses, 'status_label', t('documents.table.status')); ?></th>
        <th><?php render_th_controls(t('documents.table.reviewed'), 'next_review_date', $sortCol, $sortDir === 'DESC' ? 'desc' : 'asc'); ?></th>
    </tr>
    </thead>
    <tbody>
    <?php foreach ($documents as $doc): ?>
        <tr onclick="window.location='document.php?id=<?= (int)$doc['id'] ?>'">
            <td><?= h($doc['title']) ?></td>
            <td><?= h($doc['doc_number']) ?></td>
            <td><?= h(category_label($doc['category'])) ?></td>
            <td><?= h($doc['owner_name']) ?></td>
            <td><span class="<?= status_badge_class($doc['status']) ?>"><?= h(status_label($doc['status'])) ?></span></td>
            <td><?= format_date($doc['next_review_date']) ?></td>
        </tr>
    <?php endforeach; ?>
    <?php if (!$documents): ?>
        <tr><td colspan="6" class="muted"><?= h(t('documents.empty')) ?></td></tr>
    <?php endif; ?>
    </tbody>
</table>
</div>
<?php render_task_panel($myTasks); ?>
</div>

<?php render_footer(); ?>
