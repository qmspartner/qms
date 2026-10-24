<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/tasks.php';

$user = require_permission('records', 'view');
$myTasks = get_my_tasks($user);
$db = get_db();
$orgId = $user['organization_id'];
$canManage = can_manage_records($user);

$view = ($_GET['view'] ?? 'meetings') === 'actions' ? 'actions' : 'meetings';

$sortColumns = [
    'title' => 'r.title',
    'type' => 'r.type',
    'owner_name' => 'u.name',
    'last_updated_at' => 'v.uploaded_at',
    'next_update_date' => 'r.next_update_date',
];
$sortCol = $_GET['sort'] ?? 'last_updated_at';
if (!isset($sortColumns[$sortCol])) {
    $sortCol = 'last_updated_at';
}
$sortDir = ($_GET['dir'] ?? 'desc') === 'asc' ? 'ASC' : 'DESC';

$stmt = $db->prepare('
    SELECT r.*, u.name AS owner_name, v.version_number, v.uploaded_at AS last_updated_at
    FROM records r
    JOIN users u ON u.id = r.owner_id
    LEFT JOIN record_versions v ON v.id = r.current_version_id
    WHERE r.organization_id = ? AND r.type IN (\'Mødereferat\', \'Ledelsens evaluering\')
    ORDER BY ' . $sortColumns[$sortCol] . ' ' . $sortDir . '
');
$stmt->execute([$orgId]);
$meetings = $stmt->fetchAll();

$stmt = $db->prepare("
    SELECT c.*, u.name AS assigned_to_name, sr.title AS meeting_title
    FROM cases c
    LEFT JOIN users u ON u.id = c.assigned_to
    LEFT JOIN records sr ON sr.id = c.source_record_id
    WHERE c.organization_id = ? AND c.type = 'Opfølgningspunkt' AND c.status != 'Lukket'
    ORDER BY c.due_date IS NULL, c.due_date
");
$stmt->execute([$orgId]);
$openActions = $stmt->fetchAll();

render_header(t('meetings.title'));
?>
<div class="page-heading-row">
<div class="page-heading">
    <h1><?= h(t('meetings.title')) ?></h1>
    <?php if ($canManage): ?>
        <div style="display:flex; gap:8px;">
            <a class="button" href="new_meeting_minutes.php"><?= h(t('meetings.new.minutes')) ?></a>
            <a class="button button-secondary" href="new_management_review.php"><?= h(t('meetings.new.review')) ?></a>
        </div>
    <?php endif; ?>
</div>
<div></div>
</div>

<div class="page-layout">
<div>
<div class="stat-grid">
    <div class="stat-card"><p class="stat-label"><?= h(t('meetings.stat.total')) ?></p><p class="stat-value"><?= count($meetings) ?></p></div>
    <div class="stat-card"><p class="stat-label"><?= h(t('meetings.stat.open_actions')) ?></p><p class="stat-value <?= $openActions ? 'stat-warning' : '' ?>"><?= count($openActions) ?></p></div>
</div>

<div class="tabs">
    <a href="<?= h(basename($_SERVER['PHP_SELF'])) ?>?view=meetings" class="tab-link <?= $view === 'meetings' ? 'tab-link-active' : '' ?>"><?= h(t('meetings.tab.meetings')) ?></a>
    <a href="<?= h(basename($_SERVER['PHP_SELF'])) ?>?view=actions" class="tab-link <?= $view === 'actions' ? 'tab-link-active' : '' ?>"><?= h(t('meetings.tab.actions')) ?></a>
</div>

<?php if ($view === 'meetings'): ?>
<table class="doc-table">
    <thead>
    <tr>
        <th><?php render_th_controls(t('meetings.table.title'), 'title', $sortCol, $sortDir === 'DESC' ? 'desc' : 'asc'); ?></th>
        <th><?php render_th_controls(t('records.table.type'), 'type', $sortCol, $sortDir === 'DESC' ? 'desc' : 'asc'); ?></th>
        <th><?php render_th_controls(t('records.table.owner'), 'owner_name', $sortCol, $sortDir === 'DESC' ? 'desc' : 'asc'); ?></th>
        <th><?php render_th_controls(t('records.table.last_updated'), 'last_updated_at', $sortCol, $sortDir === 'DESC' ? 'desc' : 'asc'); ?></th>
        <th><?php render_th_controls(t('meetings.table.next_meeting'), 'next_update_date', $sortCol, $sortDir === 'DESC' ? 'desc' : 'asc'); ?></th>
    </tr>
    </thead>
    <tbody>
    <?php foreach ($meetings as $m): ?>
        <tr onclick="window.location='record.php?id=<?= (int)$m['id'] ?>'">
            <td><?= h($m['title']) ?></td>
            <td><?= h(record_type_label($m['type'])) ?></td>
            <td><?= h($m['owner_name']) ?></td>
            <td><?= format_date($m['last_updated_at']) ?></td>
            <td><?= format_date($m['next_update_date']) ?></td>
        </tr>
    <?php endforeach; ?>
    <?php if (!$meetings): ?>
        <tr><td colspan="5" class="muted"><?= h(t('meetings.empty')) ?></td></tr>
    <?php endif; ?>
    </tbody>
</table>
<?php else: ?>
<table class="doc-table">
    <thead>
    <tr>
        <th><?= h(t('meetingminutes.actions.what')) ?></th>
        <th><?= h(t('meetingminutes.actions.owner')) ?></th>
        <th><?= h(t('meetingminutes.actions.due')) ?></th>
        <th><?= h(t('meetings.actions.table.meeting')) ?></th>
    </tr>
    </thead>
    <tbody>
    <?php foreach ($openActions as $a): $overdue = $a['due_date'] && $a['due_date'] < date('Y-m-d'); ?>
        <tr onclick="window.location='case.php?id=<?= (int)$a['id'] ?>'">
            <td><?= h($a['title']) ?></td>
            <td><?= h($a['assigned_to_name'] ?? t('cases.unassigned')) ?></td>
            <td class="<?= $overdue ? 'stat-warning' : '' ?>"><?= format_date($a['due_date']) ?></td>
            <td><?= $a['meeting_title'] ? '<a href="record.php?id=' . (int)$a['source_record_id'] . '">' . h($a['meeting_title']) . '</a>' : '—' ?></td>
        </tr>
    <?php endforeach; ?>
    <?php if (!$openActions): ?>
        <tr><td colspan="4" class="muted"><?= h(t('meetings.actions.empty')) ?></td></tr>
    <?php endif; ?>
    </tbody>
</table>
<?php endif; ?>
</div>
<?php render_task_panel($myTasks); ?>
</div>

<?php render_footer(); ?>
