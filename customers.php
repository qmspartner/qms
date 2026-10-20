<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

$user = require_permission('customers', 'view');
$db = get_db();

$sortColumns = ['name' => 'name', 'contact' => 'contact_person'];
$sortCol = $_GET['sort'] ?? 'name';
if (!isset($sortColumns[$sortCol])) {
    $sortCol = 'name';
}
$sortDir = ($_GET['dir'] ?? 'asc') === 'desc' ? 'DESC' : 'ASC';

$stmt = $db->prepare('SELECT * FROM customers WHERE organization_id = ? ORDER BY ' . $sortColumns[$sortCol] . ' ' . $sortDir);
$stmt->execute([$user['organization_id']]);
$customers = $stmt->fetchAll();

$stmt = $db->prepare('SELECT COUNT(*) AS total FROM customers WHERE organization_id = ?');
$stmt->execute([$user['organization_id']]);
$stats = $stmt->fetch();

render_header(t('customers.title'));
?>
<div class="page-heading">
    <h1><?= h(t('customers.title')) ?></h1>
    <div class="button-row" style="margin:0;">
        <a class="button button-secondary" href="export_customers_csv.php"><?= h(t('customers.export')) ?></a>
        <?php if (can_manage_customers($user)): ?>
            <a class="button button-secondary" href="import_customers_csv.php"><?= h(t('customers.import')) ?></a>
            <a class="button" href="new_customer.php"><?= h(t('customers.new')) ?></a>
        <?php endif; ?>
    </div>
</div>

<div class="stat-grid">
    <div class="stat-card"><p class="stat-label"><?= h(t('customers.stat.total')) ?></p><p class="stat-value"><?= (int)($stats['total'] ?? 0) ?></p></div>
</div>

<table class="doc-table">
    <thead>
    <tr>
        <th><?php render_th_controls(t('customers.table.name'), 'name', $sortCol, $sortDir === 'DESC' ? 'desc' : 'asc'); ?></th>
        <th><?php render_th_controls(t('customers.table.contact'), 'contact', $sortCol, $sortDir === 'DESC' ? 'desc' : 'asc'); ?></th>
        <th><?= h(t('customers.table.email')) ?></th>
        <th><?= h(t('customers.table.phone')) ?></th>
        <?php if (can_manage_customers($user)): ?><th></th><?php endif; ?>
    </tr>
    </thead>
    <tbody>
    <?php foreach ($customers as $c): ?>
        <tr>
            <td><?= h($c['name']) ?></td>
            <td><?= h($c['contact_person'] ?: '—') ?></td>
            <td><?= h($c['email'] ?: '—') ?></td>
            <td class="nowrap"><?= h($c['phone'] ?: '—') ?></td>
            <?php if (can_manage_customers($user)): ?>
                <td><a href="edit_customer.php?id=<?= (int) $c['id'] ?>"><?= h(t('customers.edit')) ?></a></td>
            <?php endif; ?>
        </tr>
    <?php endforeach; ?>
    <?php if (!$customers): ?>
        <tr><td colspan="5" class="muted"><?= h(t('customers.empty')) ?></td></tr>
    <?php endif; ?>
    </tbody>
</table>

<?php render_footer(); ?>
