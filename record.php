<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

$user = require_permission('records', 'view');
$db = get_db();

$id = (int) ($_GET['id'] ?? 0);
$stmt = $db->prepare('SELECT r.*, u.name AS owner_name FROM records r JOIN users u ON u.id = r.owner_id WHERE r.id = ? AND r.organization_id = ?');
$stmt->execute([$id, $user['organization_id']]);
$record = $stmt->fetch();

if (!$record) {
    http_response_code(404);
    render_header(t('record.not_found'));
    echo '<div class="card">' . h(t('record.not_found')) . '</div>';
    render_footer();
    exit;
}

$stmt = $db->prepare('
    SELECT v.*, up.name AS uploaded_by_name
    FROM record_versions v
    JOIN users up ON up.id = v.uploaded_by
    WHERE v.record_id = ?
    ORDER BY v.uploaded_at DESC
');
$stmt->execute([$id]);
$versions = $stmt->fetchAll();

$stmt = $db->prepare('
    SELECT l.*, u.name AS user_name
    FROM record_log l
    JOIN users u ON u.id = l.user_id
    WHERE l.record_id = ?
    ORDER BY l.created_at DESC
');
$stmt->execute([$id]);
$logEntries = $stmt->fetchAll();

$canManage = can_manage_records($user);
$isManagementReview = $record['type'] === 'Ledelsens evaluering';
$isMeetingMinutes = $record['type'] === 'Mødereferat';
$uploadUrl = match (true) {
    $isManagementReview => 'new_management_review.php?record_id=' . (int) $record['id'],
    $isMeetingMinutes => 'new_meeting_minutes.php?record_id=' . (int) $record['id'],
    default => null,
};
$uploadLabel = match (true) {
    $isManagementReview => t('record.register_review'),
    $isMeetingMinutes => t('record.register_meeting'),
    default => t('record.upload_new_version'),
};

$isMeetingHome = $isManagementReview || $isMeetingMinutes;

render_header($record['title']);
?>
<p class="breadcrumb">
    <?php if ($isMeetingHome): ?>
        <a href="meetings.php"><?= h(t('nav.meetings')) ?></a>
        &raquo;
    <?php endif; ?>
    <?= h(record_type_label($record['type'])) ?>
</p>

<div class="card">
    <div class="doc-header">
        <div>
            <h1><?= h($record['title']) ?></h1>
            <p class="muted"><?= h(record_type_label($record['type'])) ?></p>
        </div>
    </div>

    <div class="meta-grid">
        <div><p class="meta-label"><?= h(t('record.owner')) ?></p><p><?= h($record['owner_name']) ?></p></div>
        <div><p class="meta-label"><?= h(t('record.next_update')) ?></p><p><?= format_date($record['next_update_date']) ?></p></div>
        <div><p class="meta-label"><?= h(t('record.retention')) ?></p><p><?= $record['retention_years'] ? h(t('record.retention_years', ['years' => $record['retention_years']])) : '—' ?></p></div>
        <div><p class="meta-label"><?= h(t('record.current_version')) ?></p><p><?= $versions ? h($versions[0]['version_number']) : '—' ?></p></div>
    </div>

    <div class="button-row">
        <?php if ($versions && $versions[0]['stored_filename']): ?>
            <a class="button" href="download_record_attachment.php?id=<?= (int)$versions[0]['id'] ?>"><?= h(t('doc.download_latest')) ?></a>
        <?php endif; ?>
        <?php if ($isManagementReview && $versions): ?>
            <a class="button" href="management_review_detail.php?version_id=<?= (int)$versions[0]['id'] ?>"><?= h(t('mgmtreview.view_title')) ?></a>
        <?php endif; ?>
        <?php if ($isMeetingMinutes && $versions): ?>
            <a class="button" href="meeting_minutes_detail.php?version_id=<?= (int)$versions[0]['id'] ?>"><?= h(t('meetingminutes.view_title')) ?></a>
        <?php endif; ?>
        <?php if ($canManage && $uploadUrl): ?>
            <a class="button button-secondary" href="<?= h($uploadUrl) ?>"><?= h($uploadLabel) ?></a>
        <?php endif; ?>
        <?php if ($user['role'] === 'admin'): ?>
            <form method="post" action="delete_record.php" class="inline-form"
                  onsubmit="return confirm('<?= h(t('record.delete_confirm')) ?>
');">
    <?= csrf_field() ?>
                <input type="hidden" name="record_id" value="<?= (int)$record['id'] ?>">
                <button type="submit" class="button-secondary button-danger"><?= h(t('record.delete')) ?></button>
            </form>
        <?php endif; ?>
    </div>
</div>

<h2><?= h(t('record.version_history')) ?></h2>
<div class="card">
    <?php foreach ($versions as $v): ?>
        <div class="timeline-row">
            <div>
                <p><strong><?= h(t('doc.version')) ?> <?= h($v['version_number']) ?></strong> <?= h(t('doc.uploaded_by')) ?> <?= h($v['uploaded_by_name']) ?></p>
                <p class="muted"><?= h($v['uploaded_at']) ?></p>
                <?php if ($v['comment']): ?><p class="muted">"<?= h($v['comment']) ?>"</p><?php endif; ?>
            </div>
            <div class="timeline-actions">
                <?php if ($isManagementReview): ?>
                    <a href="management_review_detail.php?version_id=<?= (int)$v['id'] ?>"><?= h(t('mgmtreview.view_title')) ?></a>
                <?php endif; ?>
                <?php if ($isMeetingMinutes): ?>
                    <a href="meeting_minutes_detail.php?version_id=<?= (int)$v['id'] ?>"><?= h(t('meetingminutes.view_title')) ?></a>
                <?php endif; ?>
                <?php if ($v['stored_filename']): ?>
                    <a href="download_record_attachment.php?id=<?= (int)$v['id'] ?>"><?= h(t('doc.download')) ?></a>
                <?php endif; ?>
            </div>
        </div>
    <?php endforeach; ?>
    <?php if (!$versions): ?><p class="muted"><?= h(t('record.no_versions')) ?></p><?php endif; ?>
</div>

<h2><?= h(t('record.activity_log')) ?></h2>
<div class="card">
    <?php foreach ($logEntries as $entry): ?>
        <div class="log-row">
            <p><strong><?= h($entry['user_name']) ?></strong> — <?= h($entry['action']) ?><?= $entry['details'] ? ': ' . h($entry['details']) : '' ?></p>
            <p class="muted"><?= h($entry['created_at']) ?></p>
        </div>
    <?php endforeach; ?>
    <?php if (!$logEntries): ?><p class="muted"><?= h(t('record.no_log')) ?></p><?php endif; ?>
</div>

<?php render_footer(); ?>
