<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

$user = require_role(['admin']);
$db = get_db();

$stmt = $db->prepare('SELECT id, name, title FROM employees WHERE organization_id = ? AND active = 1 ORDER BY name');
$stmt->execute([$user['organization_id']]);
$employees = $stmt->fetchAll();

$stmt = $db->prepare('SELECT id, name FROM qualifications WHERE organization_id = ? AND active = 1 ORDER BY sort_order, name');
$stmt->execute([$user['organization_id']]);
$qualifications = $stmt->fetchAll();

$stmt = $db->prepare('
    SELECT eq.employee_id, eq.qualification_id, eq.obtained_date, eq.expiry_date
    FROM employee_qualifications eq
    JOIN employees e ON e.id = eq.employee_id
    WHERE eq.organization_id = ? AND e.active = 1
');
$stmt->execute([$user['organization_id']]);
$cells = [];
$totalCount = 0;
$expiringCount = 0;
$expiredCount = 0;
foreach ($stmt->fetchAll() as $row) {
    $status = qualification_status($row);
    $cells[$row['employee_id']][$row['qualification_id']] = $status;
    $totalCount++;
    if ($status === 'expiring') {
        $expiringCount++;
    } elseif ($status === 'expired') {
        $expiredCount++;
    }
}

render_header(t('competence_matrix.title'));
?>
<p class="breadcrumb"><a href="competence.php"><?= h(t('competence.title')) ?></a> &raquo; <?= h(t('competence_matrix.title')) ?></p>

<div class="page-heading"><h1><?= h(t('competence_matrix.title')) ?></h1></div>

<div class="stat-grid">
    <div class="stat-card"><p class="stat-label"><?= h(t('competence_matrix.stat.total')) ?></p><p class="stat-value"><?= $totalCount ?></p></div>
    <div class="stat-card"><p class="stat-label"><?= h(t('competence_matrix.stat.expiring')) ?></p><p class="stat-value stat-warning"><?= $expiringCount ?></p></div>
    <div class="stat-card"><p class="stat-label"><?= h(t('competence_matrix.stat.expired')) ?></p><p class="stat-value stat-danger"><?= $expiredCount ?></p></div>
</div>

<?php if (!$employees): ?>
    <p class="muted"><?= h(t('competence_matrix.empty_employees')) ?> <a href="employees.php"><?= h(t('employees.title')) ?></a></p>
<?php elseif (!$qualifications): ?>
    <p class="muted"><?= h(t('competence_matrix.empty_qualifications')) ?> <a href="qualifications.php"><?= h(t('qualifications.title')) ?></a></p>
<?php else: ?>
<div class="matrix-legend">
    <?php foreach (['valid', 'expiring', 'expired', 'planned'] as $legendStatus): ?>
        <span class="matrix-legend-item">
            <span class="<?= qualification_status_dot_class($legendStatus) ?>"></span>
            <?= h(qualification_status_label($legendStatus)) ?>
        </span>
    <?php endforeach; ?>
    <span class="matrix-legend-item">
        <span class="muted">—</span>
        <?= h(t('competence_matrix.legend.none')) ?>
    </span>
</div>

<div class="matrix-scroll">
    <table class="doc-table matrix-table">
        <thead>
        <tr>
            <th class="matrix-corner"><?= h(t('competence_matrix.employee_col')) ?></th>
            <?php foreach ($qualifications as $q): ?>
                <th class="matrix-col-head"><span class="matrix-col-head-label"><?= h($q['name']) ?></span></th>
            <?php endforeach; ?>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($employees as $e): ?>
            <tr>
                <td class="matrix-row-head"><a href="edit_employee.php?id=<?= (int) $e['id'] ?>"><?= h($e['name']) ?></a></td>
                <?php foreach ($qualifications as $q): ?>
                    <?php $status = $cells[$e['id']][$q['id']] ?? null; ?>
                    <td class="matrix-cell">
                        <a href="employee_qualification.php?employee_id=<?= (int) $e['id'] ?>&qualification_id=<?= (int) $q['id'] ?>"
                           title="<?= h($e['name']) ?> — <?= h($q['name']) ?>: <?= $status ? h(qualification_status_label($status)) : h(t('competence_matrix.legend.none')) ?>">
                            <?php if ($status): ?>
                                <span class="<?= qualification_status_dot_class($status) ?>"></span>
                            <?php else: ?>
                                <span class="muted">—</span>
                            <?php endif; ?>
                        </a>
                    </td>
                <?php endforeach; ?>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<?php render_footer(); ?>
