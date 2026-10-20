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

// Opbygning af "Udestående" (tal, der endnu ikke er lukket, tværs over moduler)
// og "Alle moduler" (fuldt overblik). Samme underliggende tal som før, nu vist
// som en prioriteret liste + en kompakt oversigt i stedet for 8 ens store kort.
$modules = [
    [
        'href' => 'index.php', 'icon' => 'documents', 'title' => t('home.documents.title'),
        'attn_label' => t('home.documents.pending'), 'attn_value' => (int) ($docStats['pending'] ?? 0), 'attn_severity' => 'warning',
        'total_label' => t('home.documents.approved'), 'total_value' => (int) ($docStats['approved'] ?? 0),
    ],
    [
        'href' => 'cases.php', 'icon' => 'cases', 'title' => t('home.cases.title'),
        'attn_label' => t('home.cases.overdue'), 'attn_value' => (int) ($caseStats['overdue_count'] ?? 0), 'attn_severity' => 'danger',
        'total_label' => t('home.cases.open'), 'total_value' => (int) ($caseStats['open_count'] ?? 0),
    ],
];
if (!$gqar) {
    $modules[] = [
        'href' => 'records.php', 'icon' => 'records', 'title' => t('home.records.title'),
        'attn_label' => t('home.records.due_soon'), 'attn_value' => (int) ($recordStats['due_soon'] ?? 0), 'attn_severity' => 'warning',
        'total_label' => t('home.records.total'), 'total_value' => (int) ($recordStats['total'] ?? 0),
    ];
}
$modules[] = [
    'href' => 'audits.php', 'icon' => 'audits', 'title' => t('home.audits.title'),
    'attn_label' => t('home.audits.findings'), 'attn_value' => (int) ($auditFindingStats['open_findings'] ?? 0), 'attn_severity' => 'warning',
    'total_label' => t('home.audits.planned'), 'total_value' => (int) ($auditPlannedStats['planned_this_year'] ?? 0),
];
if (!$gqar) {
    $modules[] = [
        'href' => 'equipment.php', 'icon' => 'equipment', 'title' => t('home.equipment.title'),
        'attn_label' => t('home.equipment.due_soon'), 'attn_value' => (int) ($equipmentStats['due_soon'] ?? 0), 'attn_severity' => 'warning',
        'total_label' => t('home.equipment.total'), 'total_value' => (int) ($equipmentStats['total'] ?? 0),
    ];
    $modules[] = [
        'href' => 'risks.php', 'icon' => 'risks', 'title' => t('home.risks.title'),
        'attn_label' => t('home.risks.high_critical'), 'attn_value' => (int) ($riskStats['high_critical'] ?? 0), 'attn_severity' => 'danger',
        'total_label' => t('home.risks.total'), 'total_value' => (int) ($riskStats['total'] ?? 0),
    ];
    $modules[] = [
        'href' => 'suppliers.php', 'icon' => 'suppliers', 'title' => t('home.suppliers.title'),
        'attn_label' => t('home.suppliers.due_soon'), 'attn_value' => (int) ($supplierStats['due_soon'] ?? 0), 'attn_severity' => 'warning',
        'total_label' => t('home.suppliers.total'), 'total_value' => (int) ($supplierStats['total'] ?? 0),
    ];
    if ($user['role'] === 'admin') {
        $modules[] = [
            'href' => 'competence.php', 'icon' => 'competence', 'title' => t('home.competence.title'),
            'attn_label' => t('home.competence.attention'), 'attn_value' => (int) $competenceAttention, 'attn_severity' => 'danger',
            'total_label' => t('home.competence.total'), 'total_value' => (int) $competenceTotal,
        ];
    }
}
$outstanding = array_values(array_filter($modules, fn ($m) => $m['attn_value'] > 0));

render_header(t('nav.documents'));
?>
<div class="welcome-header">
    <div>
        <h1 class="welcome-title"><?= h(greeting_for_time()) ?>, <?= h(explode(' ', $user['name'])[0]) ?></h1>
        <p class="welcome-subtitle"><?= h(role_label($user['role'])) ?> · <?= h(format_long_date()) ?></p>
    </div>
    <div class="welcome-badges">
        <span class="welcome-badge">ISO 9001</span>
        <span class="welcome-badge">AQAP 2110</span>
    </div>
</div>

<div class="page-layout">
<div>

<h2 class="section-heading"><?= h(t('home.outstanding.title')) ?></h2>
<p class="section-subheading"><?= h(t('home.outstanding.subtitle')) ?></p>
<?php if (!$outstanding): ?>
    <p class="muted" style="margin:0 0 30px;"><?= h(t('home.outstanding.empty')) ?></p>
<?php else: ?>
    <div class="attn-list">
        <?php foreach ($outstanding as $m): ?>
            <a href="<?= h($m['href']) ?>" class="attn-row">
                <?= nav_icon($m['icon']) ?>
                <div style="flex:1; min-width:0;">
                    <p class="attn-title"><?= h($m['title']) ?></p>
                    <p class="attn-sub"><?= h($m['attn_label']) ?></p>
                </div>
                <span class="attn-badge attn-badge-<?= h($m['attn_severity']) ?>"><?= (int) $m['attn_value'] ?></span>
            </a>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<h2 class="section-heading"><?= h(t('home.all_modules.title')) ?></h2>
<p class="section-subheading"><?= h(t('home.all_modules.subtitle')) ?></p>
<div class="mini-grid">
    <?php foreach ($modules as $m): ?>
        <a href="<?= h($m['href']) ?>" class="mini-card">
            <p class="mini-label"><?= h($m['title']) ?> — <?= h($m['total_label']) ?></p>
            <p class="mini-val"><?= (int) $m['total_value'] ?></p>
        </a>
    <?php endforeach; ?>
</div>

</div>
<?php render_task_panel($myTasks); ?>
</div>

<?php render_footer(); ?>
