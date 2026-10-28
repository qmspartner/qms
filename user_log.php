<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

$user = require_role(['admin']);
$db = get_db();

$id = (int) ($_GET['id'] ?? 0);
$stmt = $db->prepare('SELECT * FROM users WHERE id = ? AND organization_id = ?');
$stmt->execute([$id, $user['organization_id']]);
$targetUser = $stmt->fetch();

if (!$targetUser) {
    http_response_code(404);
    render_header(t('edituser.not_found'));
    echo '<div class="card">' . h(t('edituser.not_found')) . '</div>';
    render_footer();
    exit;
}

$allowedFilters = ['all', 'access', 'offboarding'];
$filter = $_GET['filter'] ?? 'all';
if (!in_array($filter, $allowedFilters, true)) {
    $filter = 'all';
}

$entries = get_user_log_entries($db, $targetUser);
if ($filter !== 'all') {
    $entries = array_values(array_filter($entries, function ($entry) use ($filter) {
        return $entry['category'] === $filter || ($filter === 'offboarding' && $entry['category'] === 'reactivated');
    }));
}

$toneColors = [
    'neutral' => '#64748B',
    'info' => '#4F46E5',
    'qualification' => '#0D9488',
    'warn' => '#B45309',
    'danger' => '#DC2626',
    'success' => '#16A34A',
];

render_header(t('userlog.title', ['name' => $targetUser['name']]));
?>
<p class="breadcrumb"><a href="users.php"><?= h(t('users.title')) ?></a> &raquo; <?= h($targetUser['name']) ?> &raquo; <?= h(t('log.action.view')) ?></p>

<div class="card">
    <div class="doc-header">
        <div>
            <h1><?= h($targetUser['name']) ?></h1>
            <p class="muted"><?= h($targetUser['email']) ?></p>
        </div>
        <div style="display:flex; gap:8px; align-items:center;">
            <span class="badge badge-neutral"><?= h(role_label($targetUser['role'])) ?></span>
            <?php if ($targetUser['deactivation_requested_at']): ?>
                <span class="badge badge-warning"><?= h(t('offboard.pending_badge')) ?></span>
            <?php else: ?>
                <span class="<?= $targetUser['active'] ? 'badge badge-success' : 'badge badge-neutral' ?>"><?= $targetUser['active'] ? h(t('users.active')) : h(t('users.inactive')) ?></span>
            <?php endif; ?>
            <a href="edit_user.php?id=<?= (int) $targetUser['id'] ?>"><?= h(t('log.back_to_edit_user')) ?></a>
        </div>
    </div>
</div>

<div class="card">
    <div class="doc-header">
        <h2><?= h(t('log.heading')) ?></h2>
        <form method="get" style="display:flex; align-items:center; gap:8px;">
            <input type="hidden" name="id" value="<?= (int) $targetUser['id'] ?>">
            <label style="display:flex; align-items:center; gap:8px; font-size:13px;" class="muted">
                <?= h(t('log.filter.label')) ?>
                <select name="filter" onchange="this.form.submit()">
                    <option value="all" <?= $filter === 'all' ? 'selected' : '' ?>><?= h(t('log.filter.all')) ?></option>
                    <option value="access" <?= $filter === 'access' ? 'selected' : '' ?>><?= h(t('log.filter.access')) ?></option>
                    <option value="offboarding" <?= $filter === 'offboarding' ? 'selected' : '' ?>><?= h(t('log.filter.offboarding')) ?></option>
                </select>
            </label>
        </form>
    </div>
    <p class="muted"><?= h(t('log.intro.user')) ?></p>

    <div style="display:flex; gap:16px; flex-wrap:wrap; margin:12px 0 20px; padding-bottom:16px; border-bottom:1px solid #EEF0F3;">
        <?php foreach (['account' => 'neutral', 'access' => 'info', 'offboarding' => 'warn', 'reactivated' => 'success'] as $cat => $tone): ?>
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
