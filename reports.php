<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

$user = require_login();
deny_gqar($user);
$db = get_db();

$period = $_GET['period'] ?? '12m';
$allowedPeriods = ['3m', '6m', '12m', 'ytd', 'custom'];
if (!in_array($period, $allowedPeriods, true)) {
    $period = '12m';
}

$today = new DateTime();
$end = clone $today;

switch ($period) {
    case '3m':
        $start = (clone $today)->modify('-3 months');
        break;
    case '6m':
        $start = (clone $today)->modify('-6 months');
        break;
    case 'ytd':
        $start = new DateTime($today->format('Y') . '-01-01');
        break;
    case 'custom':
        $fromInput = $_GET['from'] ?? '';
        $toInput = $_GET['to'] ?? '';
        $start = $fromInput ? new DateTime($fromInput) : (clone $today)->modify('-12 months');
        $end = $toInput ? new DateTime($toInput) : $today;
        break;
    default:
        $start = (clone $today)->modify('-12 months');
}

$periodStart = $start->format('Y-m-d 00:00:00');
$periodEnd = $end->format('Y-m-d 23:59:59');
$orgId = $user['organization_id'];

// KPI 1: Gennemsnitlig godkendelsestid for dokumenter
$stmt = $db->prepare("
    SELECT AVG(TIMESTAMPDIFF(HOUR, v.uploaded_at, v.approved_at)) AS avg_hours
    FROM document_versions v
    JOIN documents d ON d.id = v.document_id
    WHERE d.organization_id = ? AND v.status = 'Godkendt' AND v.approved_at BETWEEN ? AND ?
");
$stmt->execute([$orgId, $periodStart, $periodEnd]);
$avgApprovalHours = $stmt->fetch()['avg_hours'];
$avgApprovalDays = $avgApprovalHours !== null ? round($avgApprovalHours / 24, 1) : null;

// KPI 2: Sager pr. måned (trend)
$stmt = $db->prepare("
    SELECT DATE_FORMAT(created_at, '%Y-%m') AS ym, COUNT(*) AS cnt
    FROM cases
    WHERE organization_id = ? AND created_at BETWEEN ? AND ?
    GROUP BY ym ORDER BY ym
");
$stmt->execute([$orgId, $periodStart, $periodEnd]);
$monthlyCases = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
$maxMonthlyCount = $monthlyCases ? max($monthlyCases) : 0;

// Byg en komplet månedsliste for perioden, så måneder uden sager også vises
$monthCursor = clone $start;
$monthCursor->modify('first day of this month');
$endMonthMarker = clone $end;
$endMonthMarker->modify('first day of this month');
$monthLabels = [];
while ($monthCursor <= $endMonthMarker) {
    $ym = $monthCursor->format('Y-m');
    $monthLabels[$ym] = (int) ($monthlyCases[$ym] ?? 0);
    $monthCursor->modify('+1 month');
}
$maxMonthlyCount = $monthLabels ? max($monthLabels) : 0;
$avgMonthlyCount = $monthLabels ? array_sum($monthLabels) / count($monthLabels) : 0;

// KPI 3: Gennemsnitlig tid til sagslukning + andel lukket til tiden
$stmt = $db->prepare("
    SELECT
        AVG(TIMESTAMPDIFF(DAY, created_at, closed_at)) AS avg_days,
        SUM(due_date IS NOT NULL AND closed_at <= CONCAT(due_date, ' 23:59:59')) AS on_time,
        SUM(due_date IS NOT NULL) AS with_deadline
    FROM cases
    WHERE organization_id = ? AND status = 'Lukket' AND closed_at BETWEEN ? AND ?
");
$stmt->execute([$orgId, $periodStart, $periodEnd]);
$caseClosure = $stmt->fetch();
$avgClosureDays = $caseClosure['avg_days'] !== null ? round($caseClosure['avg_days'], 1) : null;
$onTimeSharePct = $caseClosure['with_deadline'] > 0 ? round($caseClosure['on_time'] / $caseClosure['with_deadline'] * 100) : null;

// KPI 4: Audits — planlagt vs. gennemført, andel til tiden
$stmt = $db->prepare("
    SELECT
        COUNT(*) AS planned,
        SUM(status = 'Gennemført') AS completed,
        SUM(status = 'Gennemført' AND completed_date IS NOT NULL AND completed_date <= planned_date) AS on_time
    FROM audits
    WHERE organization_id = ? AND planned_date BETWEEN ? AND ?
");
$stmt->execute([$orgId, $start->format('Y-m-d'), $end->format('Y-m-d')]);
$auditStats = $stmt->fetch();
$auditOnTimePct = $auditStats['completed'] > 0 ? round($auditStats['on_time'] / $auditStats['completed'] * 100) : null;

render_header(t('reports.title'));
?>
<div class="page-heading">
    <h1><?= h(t('reports.title')) ?> <?php help_tip('help.tip.reports'); ?></h1>
</div>

<form method="get" class="inline-grid-form" style="margin-bottom:1.75rem; align-items:center;">
    <select name="period" id="period-select" onchange="document.getElementById('custom-dates').style.display = (this.value === 'custom') ? 'flex' : 'none';">
        <option value="3m" <?= $period === '3m' ? 'selected' : '' ?>><?= h(t('reports.period.3m')) ?></option>
        <option value="6m" <?= $period === '6m' ? 'selected' : '' ?>><?= h(t('reports.period.6m')) ?></option>
        <option value="12m" <?= $period === '12m' ? 'selected' : '' ?>><?= h(t('reports.period.12m')) ?></option>
        <option value="ytd" <?= $period === 'ytd' ? 'selected' : '' ?>><?= h(t('reports.period.ytd')) ?></option>
        <option value="custom" <?= $period === 'custom' ? 'selected' : '' ?>><?= h(t('reports.period.custom')) ?></option>
    </select>
    <div id="custom-dates" style="display:<?= $period === 'custom' ? 'flex' : 'none' ?>; gap:8px;">
        <input type="date" name="from" value="<?= h($_GET['from'] ?? $start->format('Y-m-d')) ?>">
        <input type="date" name="to" value="<?= h($_GET['to'] ?? $end->format('Y-m-d')) ?>">
    </div>
    <button type="submit"><?= h(t('reports.show')) ?></button>
</form>

<div class="stat-grid">
    <div class="stat-card">
        <p class="stat-label"><?= h(t('reports.kpi.approval_time')) ?></p>
        <p class="stat-value"><?= $avgApprovalDays !== null ? h(t('reports.days', ['count' => $avgApprovalDays])) : '—' ?></p>
    </div>
    <div class="stat-card">
        <p class="stat-label"><?= h(t('reports.kpi.closure_time')) ?></p>
        <p class="stat-value"><?= $avgClosureDays !== null ? h(t('reports.days', ['count' => $avgClosureDays])) : '—' ?></p>
    </div>
    <div class="stat-card">
        <p class="stat-label"><?= h(t('reports.kpi.on_time_share')) ?></p>
        <p class="stat-value <?= $onTimeSharePct !== null && $onTimeSharePct < 80 ? 'stat-warning' : '' ?>"><?= $onTimeSharePct !== null ? $onTimeSharePct . '%' : '—' ?></p>
    </div>
    <div class="stat-card">
        <p class="stat-label"><?= h(t('reports.kpi.audits_on_time')) ?></p>
        <p class="stat-value <?= $auditOnTimePct !== null && $auditOnTimePct < 80 ? 'stat-warning' : '' ?>"><?= $auditOnTimePct !== null ? $auditOnTimePct . '%' : '—' ?></p>
    </div>
</div>

<h2><?= h(t('reports.section.cases_trend')) ?></h2>
<div class="card">
    <?php if ($monthLabels): ?>
        <div style="display:flex; align-items:flex-end; gap:8px; height:160px;">
            <?php foreach ($monthLabels as $ym => $count): ?>
                <?php
                $heightPx = $maxMonthlyCount > 0 ? max(4, round($count / $maxMonthlyCount * 130)) : 4;
                $isAboveAvg = $count > $avgMonthlyCount * 1.3;
                $barColor = $isAboveAvg ? 'var(--danger-bg)' : 'var(--accent-bg)';
                $monthLabel = (new DateTime($ym . '-01'))->format('M');
                ?>
                <div style="flex:1; display:flex; flex-direction:column; align-items:center; gap:4px;">
                    <span style="font-size:11px; color:var(--text-muted);"><?= $count ?></span>
                    <div style="width:100%; height:<?= $heightPx ?>px; background:<?= $barColor ?>; border-radius:3px 3px 0 0;"></div>
                    <span style="font-size:11px; color:var(--text-muted);"><?= h($monthLabel) ?></span>
                </div>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <p class="muted"><?= h(t('reports.empty')) ?></p>
    <?php endif; ?>
</div>

<h2><?= h(t('reports.section.audits')) ?></h2>
<div class="card">
    <table class="doc-table">
        <thead>
        <tr>
            <th><?= h(t('reports.audits.planned')) ?></th>
            <th><?= h(t('reports.audits.completed')) ?></th>
            <th><?= h(t('reports.kpi.audits_on_time')) ?></th>
        </tr>
        </thead>
        <tbody>
        <tr>
            <td><?= (int) ($auditStats['planned'] ?? 0) ?></td>
            <td><?= (int) ($auditStats['completed'] ?? 0) ?></td>
            <td><?= $auditOnTimePct !== null ? $auditOnTimePct . '%' : '—' ?></td>
        </tr>
        </tbody>
    </table>
</div>

<?php render_footer(); ?>
