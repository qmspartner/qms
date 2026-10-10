<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/tasks.php';

$user = require_permission('risks', 'view');
$myTasks = get_my_tasks($user);
$db = get_db();

$selectedStatuses = array_values(array_filter((array) ($_GET['status'] ?? [])));
$selectedCategories = array_values(array_filter((array) ($_GET['category'] ?? [])));

$sortColumns = [
    'title' => 'r.title',
    'category' => 'r.category',
    'owner' => 'u.name',
    'score' => '(r.probability * r.consequence)',
    'status' => 'r.status',
];
$sortCol = $_GET['sort'] ?? 'score';
if (!isset($sortColumns[$sortCol])) {
    $sortCol = 'score';
}
$sortDir = ($_GET['dir'] ?? 'desc') === 'asc' ? 'ASC' : 'DESC';

$sql = '
    SELECT r.*, u.name AS owner_name
    FROM risks r
    JOIN users u ON u.id = r.owner_id
    WHERE r.organization_id = ?
';
$params = [$user['organization_id']];

if ($selectedStatuses) {
    $sql .= ' AND r.status IN (' . implode(',', array_fill(0, count($selectedStatuses), '?')) . ')';
    $params = array_merge($params, $selectedStatuses);
}
if ($selectedCategories) {
    $sql .= ' AND r.category IN (' . implode(',', array_fill(0, count($selectedCategories), '?')) . ')';
    $params = array_merge($params, $selectedCategories);
}
$sql .= ' ORDER BY ' . $sortColumns[$sortCol] . ' ' . $sortDir;

$stmt = $db->prepare($sql);
$stmt->execute($params);
$risks = $stmt->fetchAll();

$stmt = $db->prepare("
    SELECT
        COUNT(*) AS total,
        SUM((probability * consequence) >= 10 AND status != 'Lukket') AS high_critical,
        SUM(next_review_date IS NOT NULL AND next_review_date <= CURDATE() + INTERVAL 60 DAY AND status != 'Lukket') AS due_soon
    FROM risks WHERE organization_id = ?
");
$stmt->execute([$user['organization_id']]);
$stats = $stmt->fetch();

$view = ($_GET['view'] ?? 'gross') === 'residual' ? 'residual' : 'gross';

// Matrix: tæl åbne risici pr. sandsynlighed/konsekvens-kombination (brutto eller efter handling)
$matrix = [];
$notAssessedCount = 0;
foreach ($risks as $r) {
    if ($r['status'] === 'Lukket') {
        continue;
    }
    if ($view === 'residual') {
        if ($r['residual_probability'] === null || $r['residual_consequence'] === null) {
            $notAssessedCount++;
            continue;
        }
        $key = $r['residual_probability'] . '-' . $r['residual_consequence'];
    } else {
        $key = $r['probability'] . '-' . $r['consequence'];
    }
    $matrix[$key] = ($matrix[$key] ?? 0) + 1;
}

$categories = list_active_values('risk_category');
$statuses = ['Identificeret', 'Under behandling', 'Lukket'];

$activeChips = [];
foreach ($selectedStatuses as $val) {
    $activeChips[] = ['label' => t('risks.table.status') . ': ' . risk_status_label($val), 'href' => remove_array_filter_href('status', $selectedStatuses, $val)];
}
foreach ($selectedCategories as $val) {
    $activeChips[] = ['label' => t('risks.table.category') . ': ' . risk_category_label($val), 'href' => remove_array_filter_href('category', $selectedCategories, $val)];
}

render_header(t('risks.title'));
?>
<div class="page-heading-row">
<div class="page-heading">
    <h1><?= h(t('risks.title')) ?></h1>
    <?php if (can_manage_risks($user)): ?>
        <a class="button" href="new_risk.php"><?= h(t('risks.new')) ?></a>
    <?php endif; ?>
</div>
<div></div>
</div>

<div class="page-layout">
<div>
<div class="stat-grid">
    <div class="stat-card"><p class="stat-label"><?= h(t('risks.stat.total')) ?></p><p class="stat-value"><?= (int)($stats['total'] ?? 0) ?></p></div>
    <div class="stat-card"><p class="stat-label"><?= h(t('risks.stat.high_critical')) ?></p><p class="stat-value stat-danger"><?= (int)($stats['high_critical'] ?? 0) ?></p></div>
    <div class="stat-card"><p class="stat-label"><?= h(t('risks.stat.due_soon')) ?></p><p class="stat-value stat-warning"><?= (int)($stats['due_soon'] ?? 0) ?></p></div>
</div>

<div class="tab-bar">
    <a href="risks.php?<?= h(query_merge(['view' => null])) ?>" class="tab <?= $view === 'gross' ? 'tab-active' : '' ?>"><?= h(t('risks.view.gross')) ?></a>
    <a href="risks.php?<?= h(query_merge(['view' => 'residual'])) ?>" class="tab <?= $view === 'residual' ? 'tab-active' : '' ?>"><?= h(t('risks.view.residual')) ?></a>
</div>

<div style="display:flex; gap:6px; margin-bottom:0.25rem;">
    <span style="writing-mode:vertical-rl; transform:rotate(180deg); font-size:11px; color:var(--text-muted); flex-shrink:0; display:flex; align-items:center; justify-content:center;"><?= h(t('risks.matrix.consequence')) ?></span>
    <div style="display:flex; flex-direction:column;">
        <?php for ($c = 5; $c >= 1; $c--): ?>
            <div class="risk-axis-label" style="height:50px; width:20px; display:flex; align-items:center; justify-content:center;"><?= $c ?></div>
        <?php endfor; ?>
        <div style="height:16px;"></div>
    </div>
    <div class="risk-matrix-wrap" style="margin-bottom:0;">
        <div>
            <table class="risk-matrix">
                <?php for ($c = 5; $c >= 1; $c--): ?>
                    <tr>
                        <?php for ($p = 1; $p <= 5; $p++): ?>
                            <?php
                            $score = $p * $c;
                            $band = risk_band($score);
                            $cellClass = match ($band) {
                                'Kritisk' => 'risk-cell-critical',
                                'Høj' => 'risk-cell-high',
                                'Mellem' => 'risk-cell-medium',
                                default => 'risk-cell-low',
                            };
                            $count = $matrix[$p . '-' . $c] ?? 0;
                            ?>
                            <td class="<?= $cellClass ?>">
                                <?php if ($count > 0): ?>
                                    <span class="risk-cell-count"><?= (int) $count ?></span>
                                <?php endif; ?>
                            </td>
                        <?php endfor; ?>
                    </tr>
                <?php endfor; ?>
            </table>
            <div style="display:flex;">
                <?php for ($p = 1; $p <= 5; $p++): ?>
                    <div class="risk-axis-label" style="width:60px; text-align:center; padding-top:4px;"><?= $p ?></div>
                <?php endfor; ?>
            </div>
            <p class="muted" style="margin:4px 0 0; text-align:center;"><?= h(t('risks.matrix.probability')) ?></p>
        </div>
    </div>
</div>
<?php if ($view === 'residual' && $notAssessedCount > 0): ?>
    <p class="muted" style="margin:0.75rem 0 0;"><?= h($notAssessedCount === 1 ? t('risks.view.not_assessed_one') : t('risks.view.not_assessed_many', ['count' => $notAssessedCount])) ?></p>
<?php endif; ?>
<div style="margin-bottom:1.5rem;"></div>

<?php render_active_filters($activeChips, clear_all_filters_href(['status', 'category'])); ?>

<table class="doc-table">
    <thead>
    <tr>
        <th><?php render_th_controls(t('risks.table.title'), 'title', $sortCol, $sortDir === 'DESC' ? 'desc' : 'asc'); ?></th>
        <th><?php render_th_controls(t('risks.table.category'), 'category', $sortCol, $sortDir === 'DESC' ? 'desc' : 'asc', 'category', $categories, $selectedCategories, 'risk_category_label', t('risks.table.category')); ?></th>
        <th><?php render_th_controls(t('risks.table.owner'), 'owner', $sortCol, $sortDir === 'DESC' ? 'desc' : 'asc'); ?></th>
        <th><?php render_th_controls(t('risks.table.score'), 'score', $sortCol, $sortDir === 'DESC' ? 'desc' : 'asc'); ?></th>
        <th><?php render_th_controls(t('risks.table.status'), 'status', $sortCol, $sortDir === 'DESC' ? 'desc' : 'asc', 'status', $statuses, $selectedStatuses, 'risk_status_label', t('risks.table.status')); ?></th>
    </tr>
    </thead>
    <tbody>
    <?php foreach ($risks as $r): ?>
        <?php $score = risk_score((int)$r['probability'], (int)$r['consequence']); $band = risk_band($score); ?>
        <tr onclick="window.location='risk.php?id=<?= (int)$r['id'] ?>'">
            <td><?= h($r['title']) ?></td>
            <td><?= h(risk_category_label($r['category'])) ?></td>
            <td><?= h($r['owner_name']) ?></td>
            <td><span class="<?= risk_band_class($band) ?>"><?= (int) $score ?> — <?= h(risk_band_label($band)) ?></span></td>
            <td><span class="<?= risk_status_class($r['status']) ?>"><?= h(risk_status_label($r['status'])) ?></span></td>
        </tr>
    <?php endforeach; ?>
    <?php if (!$risks): ?>
        <tr><td colspan="5" class="muted"><?= h(t('risks.empty')) ?></td></tr>
    <?php endif; ?>
    </tbody>
</table>
</div>
<?php render_task_panel($myTasks); ?>
</div>

<?php render_footer(); ?>
