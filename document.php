<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

$user = require_permission_allow_gqar('documents');
$db = get_db();

$id = (int) ($_GET['id'] ?? 0);
$stmt = $db->prepare('SELECT d.*, u.name AS owner_name FROM documents d JOIN users u ON u.id = d.owner_id WHERE d.id = ? AND d.organization_id = ?');
$stmt->execute([$id, $user['organization_id']]);
$doc = $stmt->fetch();

if (!$doc) {
    http_response_code(404);
    render_header(t('doc.not_found'));
    echo '<div class="card">' . h(t('doc.not_found')) . '</div>';
    render_footer();
    exit;
}

if (is_gqar($user) && (!$doc['is_configuration_item'] || $doc['status'] !== 'Godkendt' || !user_has_contract($user, $doc['baseline_reference']))) {
    http_response_code(403);
    render_header(t('error.forbidden'));
    echo '<div class="card">' . h(t('error.forbidden')) . '</div>';
    render_footer();
    exit;
}

$renameError = '';
if ($user['role'] === 'admin' && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'rename') {
    $newTitle = trim($_POST['title'] ?? '');
    if ($newTitle === '') {
        $renameError = t('doc.error.title_required');
    } else {
        $stmt = $db->prepare('UPDATE documents SET title = ? WHERE id = ? AND organization_id = ?');
        $stmt->execute([$newTitle, $id, $user['organization_id']]);
        if ($newTitle !== $doc['title']) {
            log_action($id, null, t('doc.log.renamed'), $doc['title'] . ' → ' . $newTitle);
        }
        $doc['title'] = $newTitle;
    }
}

$rejectError = ($_GET['error'] ?? '') === 'reason_required';
$configApprovalError = ($_GET['error'] ?? '') === 'config_approval_restricted';

$stmt = $db->prepare('
    SELECT v.*, up.name AS uploaded_by_name, ap.name AS approved_by_name, aa.name AS assigned_approver_name
    FROM document_versions v
    JOIN users up ON up.id = v.uploaded_by
    LEFT JOIN users ap ON ap.id = v.approved_by
    LEFT JOIN users aa ON aa.id = v.assigned_approver_id
    WHERE v.document_id = ?
    ORDER BY v.uploaded_at DESC
');
$stmt->execute([$id]);
$versions = $stmt->fetchAll();

$stmt = $db->prepare('
    SELECT a.*, u.name AS user_name
    FROM audit_log a
    JOIN users u ON u.id = a.user_id
    WHERE a.document_id = ?
    ORDER BY a.created_at DESC
');
$stmt->execute([$id]);
$logEntries = $stmt->fetchAll();

$canApprove = can_approve_documents($user) && (!$doc['is_configuration_item'] || $user['role'] === 'admin');
$canEdit = can_edit_documents($user);

render_header($doc['title']);
?>
<p class="breadcrumb"><a href="index.php"><?= h(t('nav.documents')) ?></a> &raquo; <?= h(category_label($doc['category'])) ?> &raquo; <?= h($doc['doc_number']) ?></p>

<?php if ($rejectError): ?>
    <p class="alert alert-danger"><?= h(t('doc.reject_error_banner')) ?></p>
<?php endif; ?>
<?php if ($configApprovalError): ?>
    <p class="alert alert-danger"><?= h(t('doc.config_approval_restricted')) ?></p>
<?php endif; ?>
<?php if ($renameError): ?>
    <p class="alert alert-danger"><?= h($renameError) ?></p>
<?php endif; ?>

<div class="card">
    <div class="doc-header">
        <div>
            <?php if ($user['role'] === 'admin'): ?>
                <form method="post" class="inline-grid-form" style="margin-bottom:6px; max-width:520px;">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="rename">
                    <label style="margin-bottom:0;">
                        <input type="text" name="title" value="<?= h($doc['title']) ?>" style="font-size:20px; font-weight:600; padding:6px 10px;">
                    </label>
                    <button type="submit" class="button-small button-secondary" style="align-self:center;"><?= h(t('doc.rename_save')) ?></button>
                </form>
            <?php else: ?>
                <h1><?= h($doc['title']) ?></h1>
            <?php endif; ?>
            <p class="muted"><?= h($doc['doc_number']) ?> &middot; <?= h(category_label($doc['category'])) ?><?= $doc['iso_clause'] ? ' &middot; ' . h($doc['iso_clause']) : '' ?></p>
        </div>
        <span class="<?= status_badge_class($doc['status']) ?>"><?= h(status_label($doc['status'])) ?></span>
    </div>

    <div class="meta-grid">
        <div><p class="meta-label"><?= h(t('doc.owner')) ?></p><p><?= h($doc['owner_name']) ?></p></div>
        <div><p class="meta-label"><?= h(t('doc.next_review')) ?></p><p><?= format_date($doc['next_review_date']) ?></p></div>
        <div><p class="meta-label"><?= h(t('doc.current_version')) ?></p><p><?= $versions ? h($versions[0]['version_number']) : '—' ?></p></div>
        <?php if ($doc['is_configuration_item']): ?>
            <div><p class="meta-label"><?= h(t('doc.config_item')) ?></p><p><?= h(t('common.yes')) ?><?= $doc['baseline_reference'] ? ' — ' . h($doc['baseline_reference']) : '' ?></p></div>
        <?php endif; ?>
        <?php if ($doc['section'] || $doc['operation']): ?>
            <div><p class="meta-label"><?= h(t('doc.section_operation')) ?></p><p><?= h($doc['section'] ?: '—') ?> / <?= h($doc['operation'] ?: '—') ?></p></div>
        <?php endif; ?>
        <?php if ($doc['is_imported']): ?>
            <div><p class="meta-label"><?= h(t('doc.imported')) ?></p><p><?= h(t('common.yes')) ?><?= $doc['original_issue_date'] ? ' — ' . format_date($doc['original_issue_date']) : '' ?></p></div>
        <?php endif; ?>
    </div>

    <div class="button-row">
        <?php if ($versions && $versions[0]['stored_filename']): ?>
            <a class="button" href="download.php?version_id=<?= (int)$versions[0]['id'] ?>"><?= h(t('doc.download_latest')) ?></a>
        <?php endif; ?>
        <?php if ($doc['category'] === 'Leverance-kvalitetsplan' && $versions): ?>
            <a class="button" href="quality_plan_detail.php?version_id=<?= (int)$versions[0]['id'] ?>"><?= h(t('doc.open_quality_plan')) ?></a>
        <?php endif; ?>
        <?php $isQualityPlan = $doc['category'] === 'Leverance-kvalitetsplan'; ?>
        <?php if ($canEdit): ?>
            <a class="button button-secondary" href="<?= $isQualityPlan ? 'new_quality_plan.php' : 'upload_version.php' ?>?document_id=<?= (int)$doc['id'] ?>"><?= h(t('doc.upload_new_version')) ?></a>
        <?php endif; ?>
        <?php if ($user['role'] === 'admin'): ?>
            <form method="post" action="delete_document.php" class="inline-form"
                  onsubmit="return confirm('<?= h(t('doc.delete_confirm')) ?>
');">
    <?= csrf_field() ?>
                <input type="hidden" name="document_id" value="<?= (int)$doc['id'] ?>">
                <button type="submit" class="button-secondary button-danger"><?= h(t('doc.delete')) ?></button>
            </form>
        <?php endif; ?>
    </div>
</div>

<h2><?= h(t('doc.version_history')) ?></h2>
<div class="card">
    <?php foreach ($versions as $v): ?>
        <div class="timeline-row">
            <div>
                <p><strong><?= h(t('doc.version')) ?> <?= h($v['version_number']) ?></strong> <?= h(t('doc.uploaded_by')) ?> <?= h($v['uploaded_by_name']) ?></p>
                <p class="muted"><?= h($v['uploaded_at']) ?></p>
                <?php if ($v['comment']): ?><p class="muted">"<?= h($v['comment']) ?>"</p><?php endif; ?>
                <?php if ($v['status'] === 'Afvist' && $v['approved_by_name']): ?>
                    <p class="muted"><?= h(t('doc.rejected_by')) ?> <?= h($v['approved_by_name']) ?>, <?= h($v['approved_at']) ?></p>
                    <?php if ($v['review_comment']): ?>
                        <p class="reject-reason"><?= h(t('doc.reason')) ?>: <?= h($v['review_comment']) ?></p>
                    <?php endif; ?>
                <?php elseif ($v['approved_by_name']): ?>
                    <p class="muted"><?= h(t('doc.approved_by')) ?> <?= h($v['approved_by_name']) ?>, <?= h($v['approved_at']) ?></p>
                    <?php if ($v['review_comment']): ?>
                        <p class="muted"><?= h(t('doc.note')) ?>: <?= h($v['review_comment']) ?></p>
                    <?php endif; ?>
                <?php elseif ($v['original_approver'] || $v['original_approval_date']): ?>
                    <p class="muted"><?= h(t('doc.original_approval')) ?><?= $v['original_approver'] ? ' ' . h($v['original_approver']) : '' ?><?= $v['original_approval_date'] ? ', ' . format_date($v['original_approval_date']) : '' ?></p>
                <?php elseif ($v['assigned_approver_name']): ?>
                    <p class="muted"><?= h(t('doc.awaiting_approval')) ?> <?= h($v['assigned_approver_name']) ?></p>
                <?php endif; ?>
            </div>
            <div class="timeline-actions">
                <span class="<?= status_badge_class($v['status']) ?>"><?= h(status_label($v['status'])) ?></span>
                <?php if ($isQualityPlan): ?>
                    <a href="quality_plan_detail.php?version_id=<?= (int)$v['id'] ?>"><?= h(t('doc.open_quality_plan')) ?></a>
                <?php endif; ?>
                <?php if ($v['stored_filename']): ?>
                    <a href="download.php?version_id=<?= (int)$v['id'] ?>"><?= h(t('doc.download')) ?></a>
                <?php endif; ?>
                <?php if ($canApprove && $v['status'] === 'Til godkendelse'): ?>
                    <form method="post" action="approve.php" class="inline-form">
    <?= csrf_field() ?>
                        <input type="hidden" name="version_id" value="<?= (int)$v['id'] ?>">
                        <input type="hidden" name="document_id" value="<?= (int)$doc['id'] ?>">
                        <input type="hidden" name="reason" value="">
                        <button type="submit" name="decision" value="approve" class="button-small"><?= h(t('doc.approve')) ?></button>
                        <button type="button" class="button-small button-danger" onclick="rejectVersion(this.form)"><?= h(t('doc.reject')) ?></button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    <?php endforeach; ?>
    <?php if (!$versions): ?><p class="muted"><?= h(t('doc.no_versions')) ?></p><?php endif; ?>
</div>

<h2><?= h(t('doc.activity_log')) ?></h2>
<div class="card">
    <?php foreach ($logEntries as $entry): ?>
        <div class="log-row">
            <p><strong><?= h($entry['user_name']) ?></strong> — <?= h($entry['action']) ?><?= $entry['details'] ? ': ' . h($entry['details']) : '' ?></p>
            <p class="muted"><?= h($entry['created_at']) ?></p>
        </div>
    <?php endforeach; ?>
    <?php if (!$logEntries): ?><p class="muted"><?= h(t('doc.no_log')) ?></p><?php endif; ?>
</div>

<script>
function rejectVersion(form) {
    var reason = prompt('<?= addslashes(t('doc.reject_prompt')) ?>');
    if (reason === null) {
        return;
    }
    if (reason.trim() === '') {
        alert('<?= addslashes(t('doc.reject_required')) ?>');
        return;
    }
    form.reason.value = reason.trim();
    var decisionField = document.createElement('input');
    decisionField.type = 'hidden';
    decisionField.name = 'decision';
    decisionField.value = 'reject';
    form.appendChild(decisionField);
    form.submit();
}
</script>
<?php render_footer(); ?>
