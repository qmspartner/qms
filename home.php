<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/tasks.php';

$user = require_login();
$myTasks = get_my_tasks($user);
$db = get_db();

$gqar = is_gqar($user);
$gqarContracts = $user['gqar_contract_references'] ?? [];

if ($gqar) {
    [$clause, $clauseParams] = contract_in_clause('baseline_reference', $gqarContracts);
    $stmt = $db->prepare("
        SELECT SUM(status = 'Godkendt') AS approved, 0 AS pending
        FROM documents
        WHERE organization_id = ? AND is_configuration_item = 1 AND status = 'Godkendt' AND {$clause}
    ");
    $stmt->execute(array_merge([$user['organization_id']], $clauseParams));
} else {
    $stmt = $db->prepare("
        SELECT
            SUM(status = 'Godkendt') AS approved,
            SUM(status = 'Til godkendelse') AS pending
        FROM documents
        WHERE organization_id = ?
    ");
    $stmt->execute([$user['organization_id']]);
}
$docStats = $stmt->fetch();

if ($gqar) {
    [$clause, $clauseParams] = contract_in_clause('contract_reference', $gqarContracts);
    $stmt = $db->prepare("
        SELECT
            SUM(status != 'Lukket') AS open_count,
            SUM(due_date IS NOT NULL AND due_date < CURDATE() AND status != 'Lukket') AS overdue_count
        FROM cases
        WHERE organization_id = ? AND requires_customer_notification = 1 AND {$clause}
    ");
    $stmt->execute(array_merge([$user['organization_id']], $clauseParams));
} else {
    $stmt = $db->prepare("
        SELECT
            SUM(status != 'Lukket') AS open_count,
            SUM(due_date IS NOT NULL AND due_date < CURDATE() AND status != 'Lukket') AS overdue_count
        FROM cases
        WHERE organization_id = ?
    ");
    $stmt->execute([$user['organization_id']]);
}
$caseStats = $stmt->fetch();

if ($gqar) {
    [$clauseA, $clauseParamsA] = contract_in_clause('contract_reference', $gqarContracts);
    $stmt = $db->prepare("SELECT SUM(YEAR(planned_date) = YEAR(CURDATE())) AS planned_this_year FROM audits WHERE organization_id = ? AND audit_type = 'Eksternt' AND {$clauseA}");
    $stmt->execute(array_merge([$user['organization_id']], $clauseParamsA));
    $auditPlannedStats = $stmt->fetch();

    [$clauseB, $clauseParamsB] = contract_in_clause('contract_reference', $gqarContracts);
    $stmt = $db->prepare("SELECT COUNT(*) AS open_findings FROM cases WHERE organization_id = ? AND audit_id IS NOT NULL AND status != 'Lukket' AND requires_customer_notification = 1 AND {$clauseB}");
    $stmt->execute(array_merge([$user['organization_id']], $clauseParamsB));
    $auditFindingStats = $stmt->fetch();
} else {
    $stmt = $db->prepare("SELECT SUM(YEAR(planned_date) = YEAR(CURDATE())) AS planned_this_year FROM audits WHERE organization_id = ?");
    $stmt->execute([$user['organization_id']]);
    $auditPlannedStats = $stmt->fetch();

    $stmt = $db->prepare("SELECT COUNT(*) AS open_findings FROM cases WHERE organization_id = ? AND audit_id IS NOT NULL AND status != 'Lukket'");
    $stmt->execute([$user['organization_id']]);
    $auditFindingStats = $stmt->fetch();
}

if (!$gqar) {
    $stmt = $db->prepare('
        SELECT
            COUNT(*) AS total,
            SUM(next_update_date IS NOT NULL AND next_update_date <= CURDATE() + INTERVAL 60 DAY) AS due_soon
        FROM records
        WHERE organization_id = ?
    ');
    $stmt->execute([$user['organization_id']]);
    $recordStats = $stmt->fetch();

    $stmt = $db->prepare("
        SELECT
            COUNT(*) AS total,
            SUM(status = 'Aktiv' AND requires_calibration = 1 AND next_calibration_date IS NOT NULL AND next_calibration_date <= CURDATE() + INTERVAL 60 DAY) AS due_soon
        FROM equipment WHERE organization_id = ?
    ");
    $stmt->execute([$user['organization_id']]);
    $equipmentStats = $stmt->fetch();

    $stmt = $db->prepare("
        SELECT
            COUNT(*) AS total,
            SUM((probability * consequence) >= 10 AND status != 'Lukket') AS high_critical
        FROM risks WHERE organization_id = ?
    ");
    $stmt->execute([$user['organization_id']]);
    $riskStats = $stmt->fetch();

    $stmt = $db->prepare("
        SELECT
            COUNT(*) AS total,
            SUM(status = 'Godkendt' AND next_evaluation_date IS NOT NULL AND next_evaluation_date <= CURDATE() + INTERVAL 60 DAY) AS due_soon
        FROM suppliers WHERE organization_id = ?
    ");
    $stmt->execute([$user['organization_id']]);
    $supplierStats = $stmt->fetch();

    if ($user['role'] === 'admin') {
        $stmt = $db->prepare("
            SELECT eq.obtained_date, eq.expiry_date
            FROM employee_qualifications eq
            JOIN employees e ON e.id = eq.employee_id
            WHERE eq.organization_id = ? AND e.active = 1
        ");
        $stmt->execute([$user['organization_id']]);
        $competenceTotal = 0;
        $competenceAttention = 0;
        foreach ($stmt->fetchAll() as $row) {
            $competenceTotal++;
            $status = qualification_status($row);
            if ($status === 'expiring' || $status === 'expired') {
                $competenceAttention++;
            }
        }
    }
}

render_header(t('nav.documents'));
?>
<div class="welcome-header">
    <h1 class="welcome-title"><?= h(greeting_for_time()) ?>, <?= h(explode(' ', $user['name'])[0]) ?></h1>
    <p class="welcome-subtitle"><?= h(role_label($user['role'])) ?> · <?= h(format_long_date()) ?></p>
</div>

<div class="page-layout">
<div>
<div class="module-grid">
    <a href="index.php" class="module-card">
        <div class="module-icon module-icon-accent">D</div>
        <p class="module-title"><?= h(t('home.documents.title')) ?></p>
        <p class="module-desc"><?= h(t('home.documents.desc')) ?></p>
        <div class="module-stats">
            <div>
                <p class="module-stat-label"><?= h(t('home.documents.approved')) ?></p>
                <p class="module-stat-value"><?= (int) ($docStats['approved'] ?? 0) ?></p>
            </div>
            <div>
                <p class="module-stat-label"><?= h(t('home.documents.pending')) ?></p>
                <p class="module-stat-value stat-warning"><?= (int) ($docStats['pending'] ?? 0) ?></p>
            </div>
        </div>
    </a>

    <a href="cases.php" class="module-card">
        <div class="module-icon module-icon-danger">S</div>
        <p class="module-title"><?= h(t('home.cases.title')) ?></p>
        <p class="module-desc"><?= h(t('home.cases.desc')) ?></p>
        <div class="module-stats">
            <div>
                <p class="module-stat-label"><?= h(t('home.cases.open')) ?></p>
                <p class="module-stat-value"><?= (int) ($caseStats['open_count'] ?? 0) ?></p>
            </div>
            <div>
                <p class="module-stat-label"><?= h(t('home.cases.overdue')) ?></p>
                <p class="module-stat-value stat-danger"><?= (int) ($caseStats['overdue_count'] ?? 0) ?></p>
            </div>
        </div>
    </a>

    <?php if (!$gqar): ?>
    <a href="records.php" class="module-card">
        <div class="module-icon module-icon-success">O</div>
        <p class="module-title"><?= h(t('home.records.title')) ?></p>
        <p class="module-desc"><?= h(t('home.records.desc')) ?></p>
        <div class="module-stats">
            <div>
                <p class="module-stat-label"><?= h(t('home.records.total')) ?></p>
                <p class="module-stat-value"><?= (int) ($recordStats['total'] ?? 0) ?></p>
            </div>
            <div>
                <p class="module-stat-label"><?= h(t('home.records.due_soon')) ?></p>
                <p class="module-stat-value stat-warning"><?= (int) ($recordStats['due_soon'] ?? 0) ?></p>
            </div>
        </div>
    </a>
    <?php endif; ?>

    <a href="audits.php" class="module-card">
        <div class="module-icon module-icon-accent">A</div>
        <p class="module-title"><?= h(t('home.audits.title')) ?></p>
        <p class="module-desc"><?= h(t('home.audits.desc')) ?></p>
        <div class="module-stats">
            <div>
                <p class="module-stat-label"><?= h(t('home.audits.planned')) ?></p>
                <p class="module-stat-value"><?= (int) ($auditPlannedStats['planned_this_year'] ?? 0) ?></p>
            </div>
            <div>
                <p class="module-stat-label"><?= h(t('home.audits.findings')) ?></p>
                <p class="module-stat-value stat-warning"><?= (int) ($auditFindingStats['open_findings'] ?? 0) ?></p>
            </div>
        </div>
    </a>

    <?php if (!$gqar): ?>
    <a href="equipment.php" class="module-card">
        <div class="module-icon module-icon-danger">U</div>
        <p class="module-title"><?= h(t('home.equipment.title')) ?></p>
        <p class="module-desc"><?= h(t('home.equipment.desc')) ?></p>
        <div class="module-stats">
            <div>
                <p class="module-stat-label"><?= h(t('home.equipment.total')) ?></p>
                <p class="module-stat-value"><?= (int) ($equipmentStats['total'] ?? 0) ?></p>
            </div>
            <div>
                <p class="module-stat-label"><?= h(t('home.equipment.due_soon')) ?></p>
                <p class="module-stat-value stat-warning"><?= (int) ($equipmentStats['due_soon'] ?? 0) ?></p>
            </div>
        </div>
    </a>

    <a href="risks.php" class="module-card">
        <div class="module-icon module-icon-accent">R</div>
        <p class="module-title"><?= h(t('home.risks.title')) ?></p>
        <p class="module-desc"><?= h(t('home.risks.desc')) ?></p>
        <div class="module-stats">
            <div>
                <p class="module-stat-label"><?= h(t('home.risks.total')) ?></p>
                <p class="module-stat-value"><?= (int) ($riskStats['total'] ?? 0) ?></p>
            </div>
            <div>
                <p class="module-stat-label"><?= h(t('home.risks.high_critical')) ?></p>
                <p class="module-stat-value stat-danger"><?= (int) ($riskStats['high_critical'] ?? 0) ?></p>
            </div>
        </div>
    </a>

    <a href="suppliers.php" class="module-card">
        <div class="module-icon module-icon-success">L</div>
        <p class="module-title"><?= h(t('home.suppliers.title')) ?></p>
        <p class="module-desc"><?= h(t('home.suppliers.desc')) ?></p>
        <div class="module-stats">
            <div>
                <p class="module-stat-label"><?= h(t('home.suppliers.total')) ?></p>
                <p class="module-stat-value"><?= (int) ($supplierStats['total'] ?? 0) ?></p>
            </div>
            <div>
                <p class="module-stat-label"><?= h(t('home.suppliers.due_soon')) ?></p>
                <p class="module-stat-value stat-warning"><?= (int) ($supplierStats['due_soon'] ?? 0) ?></p>
            </div>
        </div>
    </a>

    <?php if ($user['role'] === 'admin'): ?>
    <a href="competence.php" class="module-card">
        <div class="module-icon module-icon-accent">K</div>
        <p class="module-title"><?= h(t('home.competence.title')) ?></p>
        <p class="module-desc"><?= h(t('home.competence.desc')) ?></p>
        <div class="module-stats">
            <div>
                <p class="module-stat-label"><?= h(t('home.competence.total')) ?></p>
                <p class="module-stat-value"><?= (int) $competenceTotal ?></p>
            </div>
            <div>
                <p class="module-stat-label"><?= h(t('home.competence.attention')) ?></p>
                <p class="module-stat-value <?= $competenceAttention > 0 ? 'stat-danger' : '' ?>"><?= (int) $competenceAttention ?></p>
            </div>
        </div>
    </a>
    <?php endif; ?>
    <?php endif; ?>
</div>
</div>
<?php render_task_panel($myTasks); ?>
</div>

<?php render_footer(); ?>
