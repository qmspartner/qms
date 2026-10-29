<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

$user = require_login();
$db = get_db();

$id = (int) ($_GET['id'] ?? 0);
$stmt = $db->prepare('SELECT * FROM employees WHERE id = ? AND organization_id = ?');
$stmt->execute([$id, $user['organization_id']]);
$employee = $stmt->fetch();

if (!$employee) {
    http_response_code(404);
    render_header(t('employees.not_found'));
    echo '<div class="card">' . h(t('employees.not_found')) . '</div>';
    render_footer();
    exit;
}

if (!can_manage_employee_offboarding($user, $employee)) {
    http_response_code(403);
    render_header(t('error.forbidden'));
    echo '<div class="card">' . h(t('error.forbidden')) . '</div>';
    render_footer();
    exit;
}

$allowedFilters = ['all', 'qualification', 'attachment', 'offboarding'];
$filter = $_GET['filter'] ?? 'all';
if (!in_array($filter, $allowedFilters, true)) {
    $filter = 'all';
}

$entries = get_employee_log_entries($db, $employee);
if ($filter !== 'all') {
    $entries = array_values(array_filter($entries, function ($entry) use ($filter) {
        return $entry['category'] === $filter || ($filter === 'offboarding' && $entry['category'] === 'reactivated');
    }));
}

$toneColors = [
    'neutral' => '#64748B',
    'qualification' => '#0D9488',
    'attachment' => '#7C3AED',
    'warn' => '#B45309',
    'danger' => '#DC2626',
    'success' => '#16A34A',
];

render_header(t('employeelog.title', ['name' => $employee['name']]));
?>
<p class="breadcrumb"><a href="competence.php"><?= h(t('competence.title')) ?></a> &raquo; <a href="employees.php"><?= h(t('employees.title')) ?></a> &raquo; <?= h($employee['name']) ?> &raquo; <?= h(t('log.action.view')) ?></p>

<div class="card">
    <div class="doc-header">
        <div>
            <h1><?= h($employee['name']) ?></h1>
            <p class="muted"><?= h($employee['title'] ?: '—') ?><?= $employee['department'] ? ' · ' . h($employee['department']) : '' ?></p>
        </div>
        <div style="display:flex; gap:8px; align-items:center;">
            <?php if ($employee['deactivation_requested_at']): ?>
                <span class="badge badge-warning"><?= h(t('offboard.pending_badge')) ?></span>
            <?php else: ?>
                <span class="<?= $employee['active'] ? 'badge badge-success' : 'badge badge-neutral' ?>"><?= $employee['active'] ? h(t('employees.active')) : h(t('employees.inactive')) ?></span>
            <?php endif; ?>
            <?php if ($user['role'] === 'admin'): ?>
                <a href="edit_employee.php?id=<?= (int) $employee['id'] ?>"><?= h(t('log.back_to_edit_employee')) ?></a>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="card">
    <div class="doc-header">
        <h2><?= h(t('log.heading')) ?></h2>
        <form method="get" style="display:flex; align-items:center; gap:8px;">
            <input type="hidden" name="id" value="<?= (int) $employee['id'] ?>">
            <label style="display:flex; align-items:center; gap:8px; font-size:13px;" class="muted">
                <?= h(t('log.filter.label')) ?>
                <select name="filter" onchange="this.form.submit()">
                    <option value="all" <?= $filter === 'all' ? 'selected' : '' ?>><?= h(t('log.filter.all')) ?></option>
                    <option value="qualification" <?= $filter === 'qualification' ? 'selected' : '' ?>><?= h(t('log.filter.qualification')) ?></option>
                    <option value="attachment" <?= $filter === 'attachment' ? 'selected' : '' ?>><?= h(t('log.filter.attachment')) ?></option>
                    <option value="offboarding" <?= $filter === 'offboarding' ? 'selected' : '' ?>><?= h(t('log.filter.offboarding')) ?></option>
                </select>
            </label>
        </form>
    </div>
    <p class="muted"><?= h(t('log.intro.employee')) ?></p>

    <div style="display:flex; gap:16px; flex-wrap:wrap; margin:12px 0 20px; padding-bottom:16px; border-bottom:1px solid #EEF0F3;">
        <?php foreach (['account' => 'neutral', 'qualification' => 'qualification', 'attachment' => 'attachment', 'offboarding' => 'warn', 'reactivated' => 'success'] as $cat => $tone): ?>
            <div style="display:flex; align-items:center; gap:6px; font-size:12px;" class="muted">
                <span style="width:8px; height:8px; border-radius:999px; background:<?= h($toneColors[$tone]) ?>; display:inline-block;"></span> <?= h(t('log.category.' . $cat)) ?>
            </div>
        <?php endforeach; ?>
    </div>

    <?php foreach ($entries as $entry): ?>
        <div style="display:flex; gap:14px; padding:12px 0; border-bottom:1px solid #F3F4F6;">
            <div style="flex:0 0 auto; padding-top:4px;">
                <span style="width:9px; height:9px; border-radius:999px; background:<?= h($toneColors[$entry['tone']]) ?>; display:inline-block;"></span>
            </div>
            <div style="flex:0 0 150px; font-size:12px;" class="muted"><?= h(format_date(substr($entry['created_at'], 0, 10))) ?> <?= h(substr($entry['created_at'], 11, 5)) ?></div>
            <div style="flex:1 1 auto; min-width:0;">
                <div><?= h($entry['title']) ?></div>
                <?php if ($entry['detail']): ?>
                    <div class="muted" style="font-size:13px;"><?= h($entry['detail']) ?></div>
                <?php endif; ?>
                <div class="muted" style="font-size:12px;"><?= h($entry['actor']) ?></div>
            </div>
        </div>
    <?php endforeach; ?>

    <?php if (!$entries): ?>
        <p class="muted"><?= h(t('log.empty')) ?></p>
    <?php endif; ?>
</div>

<?php render_footer(); ?>
