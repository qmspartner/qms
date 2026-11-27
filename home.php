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
if (!$gqar && !can_view_all_document_statuses($user)) {
    // Almindelige brugere skal ikke bekymre sig om dokumenter under udarbejdelse —
    // "afventer godkendelse" hører til redaktør/godkender/admins arbejdsgang.
    $docStats['pending'] = 0;
}

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
    $stmt = $db->prepare("
        SELECT
            (SELECT COUNT(*) FROM records WHERE organization_id = ? AND type IN ('Mødereferat', 'Ledelsens evaluering')) AS total,
            (SELECT COUNT(*) FROM cases WHERE organization_id = ? AND type = 'Opfølgningspunkt' AND status != 'Lukket') AS open_actions
    ");
    $stmt->execute([$user['organization_id'], $user['organization_id']]);
    $meetingStats = $stmt->fetch();

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
        FROM risks WHERE organization_id = ? AND type = 'Risiko'
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
        'href' => 'meetings.php', 'icon' => 'meetings', 'title' => t('home.meetings.title'),
        'attn_label' => t('home.meetings.open_actions'), 'attn_value' => (int) ($meetingStats['open_actions'] ?? 0), 'attn_severity' => 'warning',
        'total_label' => t('home.meetings.total'), 'total_value' => (int) ($meetingStats['total'] ?? 0),
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

// Herobanner på forsiden: et foto af virksomheden der samarbejder, med et
// gitter af afdelingsnavne ovenpå. Boksene er bevidst ikke links endnu (se
// Claudes hukommelsesfil/aftale med Lennart) — de fleste af disse afdelinger
// (Salg, Produktion, Økonomi m.fl.) har ingen naturlig 1:1-side i selve QMS-
// systemet, og skal først have rigtige mål, når det er besluttet hvor de skal
// føre hen. Navnene er oversatte (home.hero.dept.*), så listen er nem at
// udvide/ændre uden kodeændring andre steder.
$heroDepartments = [
    'home.hero.dept.management',
    'home.hero.dept.sales',
    'home.hero.dept.purchasing',
    'home.hero.dept.production',
    'home.hero.dept.quality',
    'home.hero.dept.delivery',
    'home.hero.dept.service',
    'home.hero.dept.finance',
    'home.hero.dept.hr',
];

render_header(t('nav.documents'));
?>
<div class="welcome-header">
    <div>
        <h1 class="welcome-title"><?= h(greeting_for_time()) ?>, <?= h(explode(' ', $user['name'])[0]) ?></h1>
        <p class="welcome-subtitle"><?= h(role_label($user['role'])) ?> · <?= h(format_long_date()) ?></p>
    </div>
</div>

<div class="page-layout">
<div>

<div class="home-hero">
    <img src="css/home-hero.jpg?v=1" alt="" class="home-hero-img">
    <div class="home-hero-fade"></div>
    <div class="home-hero-caption">
        <p><?= h(t('home.hero.caption')) ?></p>
    </div>
    <div class="home-hero-panel">
        <div class="home-hero-grid">
            <?php foreach ($heroDepartments as $deptKey): ?>
                <span class="home-hero-dept"><?= h(t($deptKey)) ?></span>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<div class="outstanding-section">
    <h2 class="section-heading"><?= h(t('home.outstanding.title')) ?></h2>
    <p class="section-subheading"><?= h(t('home.outstanding.subtitle')) ?></p>
    <?php if (!$outstanding): ?>
        <p class="muted" style="margin:0;"><?= h(t('home.outstanding.empty')) ?></p>
    <?php else: ?>
        <div class="outstanding-grid">
            <?php foreach ($outstanding as $m): ?>
                <a href="<?= h($m['href']) ?>" class="outstanding-card">
                    <span class="outstanding-icon attn-badge-<?= h($m['attn_severity']) ?>"><?= nav_icon($m['icon']) ?></span>
                    <span class="outstanding-text">
                        <span class="outstanding-title"><?= h($m['title']) ?></span>
                        <span class="outstanding-sub"><?= h($m['attn_label']) ?></span>
                    </span>
                    <span class="attn-badge attn-badge-<?= h($m['attn_severity']) ?>"><?= (int) $m['attn_value'] ?></span>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

</div>
<?php render_task_panel($myTasks); ?>
</div>

<?php render_footer(); ?>
