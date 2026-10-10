<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

$user = require_role(['admin']);
$db = get_db();

$listTypes = ['document_category', 'record_type', 'risk_category', 'supplier_category', 'contract_reference'];

$stmt = $db->prepare('SELECT list_type, COUNT(*) AS cnt FROM list_items WHERE organization_id = ? AND active = 1 GROUP BY list_type');
$stmt->execute([$user['organization_id']]);
$counts = [];
foreach ($stmt->fetchAll() as $row) {
    $counts[$row['list_type']] = (int) $row['cnt'];
}

render_header(t('lists.title'));
?>
<div class="page-heading"><h1><?= h(t('lists.title')) ?></h1></div>
<p class="muted" style="margin:-8px 0 20px;"><?= h(t('lists.description')) ?></p>

<div class="module-grid">
    <?php foreach ($listTypes as $type): ?>
        <a href="list_detail.php?type=<?= h($type) ?>" class="module-card">
            <p class="module-title"><?= h(list_type_label($type)) ?></p>
            <p class="module-desc"><?= h(t('lists.active_count', ['count' => $counts[$type] ?? 0])) ?></p>
        </a>
    <?php endforeach; ?>
</div>

<?php render_footer(); ?>
