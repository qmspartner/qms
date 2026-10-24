<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

$user = require_permission('records', 'view');
$db = get_db();

$versionId = (int) ($_GET['version_id'] ?? 0);
$stmt = $db->prepare('
    SELECT mmd.*, v.record_id, v.uploaded_at, up.name AS uploaded_by_name, r.title AS record_title
    FROM meeting_minutes_details mmd
    JOIN record_versions v ON v.id = mmd.version_id
    JOIN records r ON r.id = v.record_id
    JOIN users up ON up.id = v.uploaded_by
    WHERE mmd.version_id = ? AND r.organization_id = ?
');
$stmt->execute([$versionId, $user['organization_id']]);
$meeting = $stmt->fetch();

if (!$meeting) {
    http_response_code(404);
    render_header(t('meetingminutes.not_found'));
    echo '<div class="card">' . h(t('meetingminutes.not_found')) . '</div>';
    render_footer();
    exit;
}

$stmt = $db->prepare('SELECT * FROM meeting_minutes_agenda_items WHERE details_id = ? ORDER BY position');
$stmt->execute([$meeting['id']]);
$agendaItems = $stmt->fetchAll();

$stmt = $db->prepare("
    SELECT c.*, u.name AS assigned_to_name
    FROM cases c
    LEFT JOIN users u ON u.id = c.assigned_to
    WHERE c.organization_id = ? AND c.source_record_id = ?
    ORDER BY c.status = 'Lukket', c.due_date IS NULL, c.due_date
");
$stmt->execute([$user['organization_id'], $meeting['record_id']]);
$actions = $stmt->fetchAll();

render_header($meeting['record_title']);
?>
<p class="breadcrumb"><a href="meetings.php"><?= h(t('nav.meetings')) ?></a> &raquo; <a href="record.php?id=<?= (int)$meeting['record_id'] ?>"><?= h($meeting['record_title']) ?></a> &raquo; <?= format_date($meeting['meeting_date']) ?></p>

<div class="card">
    <div class="doc-header">
        <div>
            <h1><?= h(t('meetingminutes.view_title')) ?> — <?= format_date($meeting['meeting_date']) ?></h1>
            <p class="muted">
                <?= $meeting['meeting_type'] ? h($meeting['meeting_type']) . ' — ' : '' ?><?= h(t('doc.uploaded_by')) ?> <?= h($meeting['uploaded_by_name']) ?>, <?= h($meeting['uploaded_at']) ?>
            </p>
        </div>
    </div>
    <div style="border-top:0.5px solid var(--border); padding-top:1rem; margin-top:1rem;">
        <?php if ($meeting['participants']): ?>
            <p class="meta-label"><?= h(t('meetingminutes.field.participants')) ?></p>
            <p style="margin:0 0 12px;"><?= h($meeting['participants']) ?></p>
        <?php endif; ?>
        <?php if ($meeting['absentees']): ?>
            <p class="meta-label"><?= h(t('meetingminutes.field.absentees')) ?></p>
            <p style="margin:0 0 12px;"><?= h($meeting['absentees']) ?></p>
        <?php endif; ?>
    </div>
</div>

<h2><?= h(t('meetingminutes.section.agenda')) ?></h2>
<div class="card">
    <?php foreach ($agendaItems as $i => $item): ?>
        <div style="margin-bottom:14px;">
            <p style="margin:0 0 2px;"><strong><?= (int) ($i + 1) ?>. <?= h($item['topic'] ?: '—') ?></strong></p>
            <?php if ($item['notes']): ?><p class="muted" style="margin:0;"><?= nl2br(h($item['notes'])) ?></p><?php endif; ?>
        </div>
    <?php endforeach; ?>
    <?php if (!$agendaItems): ?><p class="muted"><?= h(t('meetingminutes.no_agenda')) ?></p><?php endif; ?>
</div>

<h2><?= h(t('meetingminutes.section.actions')) ?></h2>
<div class="card">
    <?php if ($actions): ?>
        <table class="doc-table">
            <thead>
            <tr>
                <th><?= h(t('meetingminutes.actions.what')) ?></th>
                <th><?= h(t('meetingminutes.actions.owner')) ?></th>
                <th><?= h(t('meetingminutes.actions.due')) ?></th>
                <th><?= h(t('cases.table.status')) ?></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($actions as $a): ?>
                <tr onclick="window.location='case.php?id=<?= (int)$a['id'] ?>'">
                    <td><?= h($a['title']) ?></td>
                    <td><?= h($a['assigned_to_name'] ?? t('cases.unassigned')) ?></td>
                    <td><?= format_date($a['due_date']) ?></td>
                    <td><?= h(case_status_label($a['status'])) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php else: ?>
        <p class="muted"><?= h(t('meetingminutes.no_actions')) ?></p>
    <?php endif; ?>
</div>

<?php render_footer(); ?>
