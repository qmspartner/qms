<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

$user = require_permission('records', 'view');
$db = get_db();

$versionId = (int) ($_GET['version_id'] ?? 0);
$stmt = $db->prepare('
    SELECT mrd.*, v.record_id, v.uploaded_at, v.stored_filename, v.original_filename, r.title AS record_title, u.name AS uploaded_by_name
    FROM management_review_details mrd
    JOIN record_versions v ON v.id = mrd.version_id
    JOIN records r ON r.id = v.record_id
    JOIN users u ON u.id = v.uploaded_by
    WHERE mrd.version_id = ? AND r.organization_id = ?
');
$stmt->execute([$versionId, $user['organization_id']]);
$review = $stmt->fetch();

if (!$review) {
    http_response_code(404);
    render_header(t('mgmtreview.not_found'));
    echo '<div class="card">' . h(t('mgmtreview.not_found')) . '</div>';
    render_footer();
    exit;
}

function review_row(string $label, ?string $value): void {
    if ($value === null || $value === '') {
        return;
    }
    echo '<p class="meta-label">' . h($label) . '</p><p style="margin:0 0 12px;">' . nl2br(h($value)) . '</p>';
}

function review_quant_row(string $label, ?string $systemValue, ?int $systemCount, ?int $manualCount, ?string $note): void {
    echo '<p class="meta-label">' . h($label) . '</p>';
    echo '<p style="margin:0 0 4px;">' . h(t('mgmtreview.system_value')) . ': ' . h($systemValue ?? '—') . '</p>';
    echo '<p style="margin:0 0 4px;">' . h(t('mgmtreview.manual_value')) . ': ' . ($manualCount !== null ? (int) $manualCount : '—')
        . ' <span class="' . mismatch_class($systemCount, $manualCount) . '">' . h(mismatch_label($systemCount, $manualCount)) . '</span></p>';
    if ($note) {
        echo '<p class="muted" style="margin:0 0 14px;">' . nl2br(h($note)) . '</p>';
    } else {
        echo '<div style="margin-bottom:10px;"></div>';
    }
}

render_header($review['record_title']);
?>
<p class="breadcrumb"><a href="meetings.php"><?= h(t('nav.meetings')) ?></a> &raquo; <a href="record.php?id=<?= (int)$review['record_id'] ?>"><?= h($review['record_title']) ?></a> &raquo; <?= format_date($review['review_date']) ?></p>

<div class="card">
    <div class="doc-header">
        <div>
            <h1><?= h(t('mgmtreview.view_title')) ?> — <?= format_date($review['review_date']) ?></h1>
            <p class="muted"><?= h(t('doc.uploaded_by')) ?> <?= h($review['uploaded_by_name']) ?>, <?= h($review['uploaded_at']) ?></p>
        </div>
    </div>

    <?php if ($review['stored_filename']): ?>
        <div class="button-row">
            <a class="button button-secondary" href="download_record_attachment.php?id=<?= (int)$versionId ?>"><?= h(t('doc.download')) ?></a>
        </div>
    <?php endif; ?>

    <div style="border-top:0.5px solid var(--border); padding-top:1rem; margin-top:1rem;">
        <?php review_row(t('mgmtreview.field.participants'), $review['participants']); ?>
        <?php review_row(t('mgmtreview.section.followup'), $review['followup_previous_actions']); ?>
        <?php review_row(t('mgmtreview.section.changes'), $review['external_internal_changes']); ?>
    </div>
</div>

<h2><?= h(t('mgmtreview.section.performance')) ?></h2>
<div class="card">
    <?php review_row(t('mgmtreview.field.customer_satisfaction'), $review['customer_satisfaction']); ?>
    <?php review_row(t('mgmtreview.field.quality_objectives'), $review['quality_objectives']); ?>
    <?php review_quant_row(t('mgmtreview.field.deviations'), $review['deviations_system_value'], $review['deviations_system_count'], $review['deviations_manual_count'], $review['deviations_note']); ?>
    <?php review_quant_row(t('mgmtreview.field.audits'), $review['audits_system_value'], $review['audits_system_count'], $review['audits_manual_count'], $review['audits_note']); ?>
    <?php review_quant_row(t('mgmtreview.field.suppliers'), $review['suppliers_system_value'], $review['suppliers_system_count'], $review['suppliers_manual_count'], $review['suppliers_note']); ?>
</div>

<h2><?= h(t('mgmtreview.field.risks')) ?></h2>
<div class="card">
    <?php review_quant_row(t('mgmtreview.field.risks'), $review['risks_system_value'], $review['risks_system_count'], $review['risks_manual_count'], $review['risks_note']); ?>
</div>

<h2><?= h(t('mgmtreview.section.resources')) ?></h2>
<div class="card"><?= $review['resource_adequacy'] ? nl2br(h($review['resource_adequacy'])) : '<span class="muted">—</span>' ?></div>

<h2><?= h(t('mgmtreview.section.improvements')) ?></h2>
<div class="card"><?= $review['improvement_opportunities'] ? nl2br(h($review['improvement_opportunities'])) : '<span class="muted">—</span>' ?></div>

<h2><?= h(t('mgmtreview.section.decisions')) ?></h2>
<div class="card">
    <?php review_row(t('mgmtreview.field.decisions_improvements'), $review['decisions_improvements']); ?>
    <?php review_row(t('mgmtreview.field.decisions_system_changes'), $review['decisions_system_changes']); ?>
    <?php review_row(t('mgmtreview.field.decisions_resources'), $review['decisions_resources']); ?>
</div>

<?php render_footer(); ?>
