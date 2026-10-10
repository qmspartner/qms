<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

$user = require_role(['admin']);
$db = get_db();

$stmt = $db->prepare('SELECT COUNT(*) AS cnt FROM employees WHERE organization_id = ? AND active = 1');
$stmt->execute([$user['organization_id']]);
$employeeCount = (int) $stmt->fetch()['cnt'];

$stmt = $db->prepare('SELECT COUNT(*) AS cnt FROM qualifications WHERE organization_id = ? AND active = 1');
$stmt->execute([$user['organization_id']]);
$qualificationCount = (int) $stmt->fetch()['cnt'];

$stmt = $db->prepare("
    SELECT eq.obtained_date, eq.expiry_date
    FROM employee_qualifications eq
    JOIN employees e ON e.id = eq.employee_id
    WHERE eq.organization_id = ? AND e.active = 1
");
$stmt->execute([$user['organization_id']]);
$attentionCount = 0;
foreach ($stmt->fetchAll() as $row) {
    $status = qualification_status($row);
    if ($status === 'expiring' || $status === 'expired') {
        $attentionCount++;
    }
}

render_header(t('competence.title'));
?>
<div class="page-heading"><h1><?= h(t('competence.title')) ?></h1></div>
<p class="muted" style="margin:-8px 0 20px;"><?= h(t('competence.description')) ?></p>

<div class="module-grid">
    <a href="employees.php" class="module-card">
        <p class="module-title"><?= h(t('competence.hub.employees')) ?></p>
        <p class="module-desc"><?= h(t('competence.hub.employees_desc')) ?></p>
        <p class="module-desc"><?= h(t('competence.count_active', ['count' => $employeeCount])) ?></p>
    </a>
    <a href="qualifications.php" class="module-card">
        <p class="module-title"><?= h(t('competence.hub.qualifications')) ?></p>
        <p class="module-desc"><?= h(t('competence.hub.qualifications_desc')) ?></p>
        <p class="module-desc"><?= h(t('competence.count_active', ['count' => $qualificationCount])) ?></p>
    </a>
    <a href="competence_matrix.php" class="module-card">
        <p class="module-title"><?= h(t('competence.hub.matrix')) ?></p>
        <p class="module-desc"><?= h(t('competence.hub.matrix_desc')) ?></p>
        <?php if ($attentionCount > 0): ?>
            <p class="module-desc stat-warning"><?= h(t('home.competence.attention')) ?>: <?= $attentionCount ?></p>
        <?php endif; ?>
    </a>
</div>

<?php render_footer(); ?>
