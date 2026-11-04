<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/quality_objectives.php';

$user = require_login();
$db = get_db();

$id = (int) ($_GET['id'] ?? 0);
$objective = quality_objective_find($db, $id, $user['organization_id']);

if (!$objective) {
    http_response_code(404);
    render_header(t('qualityobjective.not_found'));
    echo '<div class="card">' . h(t('qualityobjective.not_found')) . '</div>';
    render_footer();
    exit;
}

if (!can_manage_quality_objective($user, $objective)) {
    http_response_code(403);
    render_header(t('error.forbidden'));
    echo '<div class="card">' . h(t('error.forbidden')) . '</div>';
    render_footer();
    exit;
}

$canEditCore = has_permission($user, 'quality_objectives', 'edit');
$isOwner = (int) $user['id'] === (int) $objective['owner_user_id'];
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add_measurement' && ($canEditCore || $isOwner)) {
        $periodEnd = trim($_POST['period_end'] ?? '');
        $value = str_replace(',', '.', trim($_POST['value'] ?? ''));
        $note = trim($_POST['note'] ?? '');

        if ($periodEnd === '' || !is_numeric($value)) {
            $error = t('qualityobjective.error.measurement_required');
        } elseif (in_array($periodEnd, quality_objective_all_period_ends($db, $id), true)) {
            $error = t('qualityobjective.error.measurement_duplicate');
        } else {
            quality_objective_measurement_add($db, $id, $periodEnd, (float) $value, $note, (int) $user['id']);
            header('Location: quality_objective_detail.php?id=' . $id);
            exit;
        }
    } elseif ($action === 'update_reminder' && ($canEditCore || $isOwner)) {
        $leadDays = (int) ($_POST['reminder_lead_days'] ?? 7);
        if ($leadDays < 0 || $leadDays > 90) {
            $error = t('qualityobjective.error.reminder_range');
        } else {
            $stmt = $db->prepare('UPDATE quality_objectives SET reminder_lead_days = ? WHERE id = ? AND organization_id = ?');
            $stmt->execute([$leadDays, $id, $user['organization_id']]);
            header('Location: quality_objective_detail.php?id=' . $id);
            exit;
        }
    } elseif ($action === 'update_status' && $canEditCore) {
        $status = in_array($_POST['status'] ?? '', ['Ny', 'På kurs', 'Ikke på kurs', 'Opnået', 'Opgivet'], true) ? $_POST['status'] : $objective['status'];
        $stmt = $db->prepare('UPDATE quality_objectives SET status = ? WHERE id = ? AND organization_id = ?');
        $stmt->execute([$status, $id, $user['organization_id']]);
        header('Location: quality_objective_detail.php?id=' . $id);
        exit;
    }

    if ($error) {
        $objective = quality_objective_find($db, $id, $user['organization_id']);
    }
}

$measurements = quality_objective_measurement_list($db, $id);
$measuredPeriodEnds = array_column($measurements, 'period_end');
$nextDue = quality_objective_next_due_period($objective['measurement_frequency'], $objective['start_date'], $measuredPeriodEnds, date('Y-m-d'), (int) $objective['reminder_lead_days']);
$suggestedPeriodEnd = $nextDue ?? quality_objective_period_ends_until($objective['measurement_frequency'], $objective['start_date'], date('Y-m-d', strtotime('+1 year')))[0] ?? date('Y-m-d');

// Trendgraf (SVG) over de seneste målinger, ældste til venstre — rent visningslogik, ingen beregning gemmes.
// Titel og enheder på begge akser: titlen gentager målets navn (grafen skal kunne stå
// alene, fx hvis den klippes ud/printes), y-aksen viser min/maks-værdi inkl. enhed,
// x-aksen viser første/sidste periodes slutdato.
$chartPoints = array_reverse($measurements);
$chartSvg = null;
if (count($chartPoints) >= 2) {
    $unit = $objective['unit'] ?? '';
    $values = array_map(fn ($m) => (float) $m['value'], $chartPoints);
    $values[] = (float) $objective['target_value'];
    $min = min($values);
    $max = max($values);
    $range = ($max - $min) ?: 1;
    $width = 560;
    $height = 190;
    $padTop = 28;    // plads til titlen
    $padBottom = 26; // plads til x-aksens datoer
    $padLeft = 64;   // plads til y-aksens værdi+enhed
    $padRight = 16;
    $plotWidth = $width - $padLeft - $padRight;
    $plotHeight = $height - $padTop - $padBottom;
    $n = count($chartPoints);
    $stepX = $n > 1 ? $plotWidth / ($n - 1) : 0;
    $toX = fn ($i) => $padLeft + ($n > 1 ? $i * $stepX : $plotWidth / 2);
    $toY = fn ($v) => $padTop + $plotHeight - (($v - $min) / $range) * $plotHeight;
    $formatValue = fn ($v) => number_format($v, 2, ',', '.') . ($unit !== '' ? ' ' . $unit : '');
    $linePoints = [];
    foreach ($chartPoints as $i => $m) {
        $linePoints[] = round($toX($i), 1) . ',' . round($toY((float) $m['value']), 1);
    }
    $chartSvg = [
        'points' => implode(' ', $linePoints),
        'targetY' => round($toY((float) $objective['target_value']), 1),
        'width' => $width,
        'height' => $height,
        'padLeft' => $padLeft,
        'padRight' => $padRight,
        'padTop' => $padTop,
        'title' => t('qualityobjective.trend_chart_title', ['title' => $objective['title']]),
        'yMaxLabel' => $formatValue($max),
        'yMinLabel' => $formatValue($min),
        'xFirstLabel' => format_date($chartPoints[0]['period_end']),
        'xLastLabel' => format_date($chartPoints[$n - 1]['period_end']),
    ];
}

render_header($objective['title']);
?>
<p class="breadcrumb">
    <?php if ($canEditCore): ?><a href="quality_objectives.php"><?= h(t('qualityobjectives.title')) ?></a> &raquo; <?php endif; ?>
    <?= h($objective['title']) ?>
</p>

<?php if ($error): ?><p class="alert alert-danger"><?= h($error) ?></p><?php endif; ?>

<div class="card">
    <div class="doc-header">
        <div>
            <h1><?= h($objective['title']) ?></h1>
            <?php if ($objective['description']): ?><p class="muted" style="max-width:640px;"><?= nl2br(h($objective['description'])) ?></p><?php endif; ?>
        </div>
        <span class="<?= quality_objective_status_class($objective['status']) ?>"><?= h(quality_objective_status_label($objective['status'])) ?></span>
    </div>

    <div class="meta-grid">
        <div><p class="meta-label"><?= h(t('qualityobjective.owner')) ?></p><p><?= h($objective['owner_name']) ?></p></div>
        <div><p class="meta-label"><?= h(t('qualityobjective.target')) ?></p><p><?= h(quality_objective_target_label($objective)) ?></p></div>
        <div><p class="meta-label"><?= h(t('qualityobjective.frequency')) ?></p><p><?= h(t('qualityobjective.frequency.' . $objective['measurement_frequency'])) ?></p></div>
        <div><p class="meta-label"><?= h(t('qualityobjective.target_date')) ?></p><p><?= format_date($objective['target_date']) ?></p></div>
        <div><p class="meta-label"><?= h(t('qualityobjective.next_report')) ?></p><p><?= $nextDue ? format_date($nextDue) : h(t('qualityobjective.next_report_none')) ?></p></div>
    </div>

    <?php if ($canEditCore): ?>
        <form method="post" class="button-row" style="margin-top:14px;">
        <?= csrf_field() ?>
            <input type="hidden" name="action" value="update_status">
            <select name="status" onchange="this.form.submit()" style="width:auto;">
                <?php foreach (['Ny', 'På kurs', 'Ikke på kurs', 'Opnået', 'Opgivet'] as $s): ?>
                    <option value="<?= h($s) ?>" <?= $objective['status'] === $s ? 'selected' : '' ?>><?= h(quality_objective_status_label($s)) ?></option>
                <?php endforeach; ?>
            </select>
        </form>
    <?php endif; ?>
</div>

<div class="page-layout">
<div>

<h2><?= h(t('qualityobjective.trend_title')) ?></h2>
<div class="card">
    <?php if ($chartSvg): ?>
        <svg width="100%" height="<?= $chartSvg['height'] ?>" viewBox="0 0 <?= $chartSvg['width'] ?> <?= $chartSvg['height'] ?>" style="display:block;" role="img" aria-label="<?= h($chartSvg['title']) ?>">
            <text x="<?= $chartSvg['width'] / 2 ?>" y="16" text-anchor="middle" font-size="13" font-weight="600" fill="#111827"><?= h($chartSvg['title']) ?></text>
            <text x="<?= $chartSvg['padLeft'] - 8 ?>" y="<?= $chartSvg['padTop'] + 4 ?>" text-anchor="end" font-size="11" fill="#52525b"><?= h($chartSvg['yMaxLabel']) ?></text>
            <text x="<?= $chartSvg['padLeft'] - 8 ?>" y="<?= $chartSvg['height'] - 26 ?>" text-anchor="end" font-size="11" fill="#52525b"><?= h($chartSvg['yMinLabel']) ?></text>
            <text x="<?= $chartSvg['padLeft'] ?>" y="<?= $chartSvg['height'] - 6 ?>" text-anchor="start" font-size="11" fill="#52525b"><?= h($chartSvg['xFirstLabel']) ?></text>
            <text x="<?= $chartSvg['width'] - $chartSvg['padRight'] ?>" y="<?= $chartSvg['height'] - 6 ?>" text-anchor="end" font-size="11" fill="#52525b"><?= h($chartSvg['xLastLabel']) ?></text>
            <line x1="<?= $chartSvg['padLeft'] ?>" y1="<?= $chartSvg['targetY'] ?>" x2="<?= $chartSvg['width'] - $chartSvg['padRight'] ?>" y2="<?= $chartSvg['targetY'] ?>" stroke="#a1a1aa" stroke-width="1.5" stroke-dasharray="5,5"/>
            <polyline points="<?= h($chartSvg['points']) ?>" fill="none" stroke="#2563eb" stroke-width="2.5"/>
        </svg>
        <p class="muted" style="margin:8px 0 0; font-size:12px;"><?= h(t('qualityobjective.trend_note')) ?></p>
    <?php else: ?>
        <p class="muted"><?= h(t('qualityobjective.trend_empty')) ?></p>
    <?php endif; ?>
</div>

<h2><?= h(t('qualityobjective.measurements_title')) ?></h2>
<div class="card">
    <table class="doc-table">
        <thead>
        <tr>
            <th><?= h(t('qualityobjective.table.period')) ?></th>
            <th><?= h(t('qualityobjective.table.value')) ?></th>
            <th><?= h(t('qualityobjective.table.note')) ?></th>
            <th><?= h(t('qualityobjective.table.recorded_by')) ?></th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($measurements as $m): ?>
            <tr>
                <td><?= format_date($m['period_end']) ?></td>
                <td><?= h(number_format((float) $m['value'], 2, ',', '.')) ?></td>
                <td class="muted"><?= h($m['note'] ?: '—') ?></td>
                <td><?= h($m['recorded_by_name']) ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$measurements): ?>
            <tr><td colspan="4" class="muted"><?= h(t('qualityobjective.no_measurements')) ?></td></tr>
        <?php endif; ?>
        </tbody>
    </table>

    <?php if ($canEditCore || $isOwner): ?>
        <form method="post" style="margin-top:16px; border-top:1px solid var(--border); padding-top:16px;">
        <?= csrf_field() ?>
            <input type="hidden" name="action" value="add_measurement">
            <div style="display:flex; gap:12px; flex-wrap:wrap; align-items:flex-end;">
                <label style="flex:0 0 160px;"><?= h(t('qualityobjective.field.period_end')) ?>
                    <input type="date" name="period_end" value="<?= h($suggestedPeriodEnd) ?>" required>
                </label>
                <label style="flex:0 0 140px;"><?= h(t('qualityobjective.field.value')) ?>
                    <input type="text" name="value" inputmode="decimal" required>
                </label>
                <label style="flex:1 1 220px;"><?= h(t('qualityobjective.field.note')) ?>
                    <input type="text" name="note">
                </label>
                <button type="submit"><?= h(t('qualityobjective.add_measurement')) ?></button>
            </div>
        </form>
    <?php endif; ?>
</div>

</div>
<div>
    <?php if ($canEditCore || $isOwner): ?>
    <div class="card">
        <h2 style="margin-top:0; font-size:15px;"><?= h(t('qualityobjective.reminder_title')) ?></h2>
        <p class="muted" style="font-size:13px;"><?= h(t('qualityobjective.reminder_description')) ?></p>
        <form method="post">
        <?= csrf_field() ?>
            <input type="hidden" name="action" value="update_reminder">
            <label><?= h(t('qualityobjective.field.reminder_lead_days')) ?>
                <input type="number" name="reminder_lead_days" value="<?= (int) $objective['reminder_lead_days'] ?>" min="0" max="90">
            </label>
            <button type="submit" class="button-secondary"><?= h(t('qualityobjective.save_reminder')) ?></button>
        </form>
    </div>
    <?php endif; ?>
</div>
</div>

<?php render_footer(); ?>
