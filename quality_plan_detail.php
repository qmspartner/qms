<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

$user = require_permission_allow_gqar('documents');
$db = get_db();

$versionId = (int) ($_GET['version_id'] ?? 0);
$stmt = $db->prepare('
    SELECT qpd.*, v.document_id, v.version_number, v.uploaded_at, v.status AS version_status, v.stored_filename, v.original_filename,
           d.title AS doc_title, d.doc_number, d.baseline_reference, u.name AS uploaded_by_name, cust.name AS customer_name
    FROM quality_plan_details qpd
    JOIN document_versions v ON v.id = qpd.version_id
    JOIN documents d ON d.id = v.document_id
    JOIN users u ON u.id = v.uploaded_by
    LEFT JOIN customers cust ON cust.id = qpd.customer_id
    WHERE qpd.version_id = ? AND d.organization_id = ?
');
$stmt->execute([$versionId, $user['organization_id']]);
$plan = $stmt->fetch();

if (!$plan) {
    http_response_code(404);
    render_header(t('qualityplan.not_found'));
    echo '<div class="card">' . h(t('qualityplan.not_found')) . '</div>';
    render_footer();
    exit;
}

if (is_gqar($user) && (!user_has_contract($user, $plan['baseline_reference']) || $plan['version_status'] !== 'Godkendt')) {
    http_response_code(403);
    render_header(t('error.forbidden'));
    echo '<div class="card">' . h(t('error.forbidden')) . '</div>';
    render_footer();
    exit;
}

function qp_row(string $label, ?string $value): void {
    if ($value === null || $value === '') {
        return;
    }
    echo '<p class="meta-label">' . h($label) . '</p><p style="margin:0 0 14px;">' . nl2br(h($value)) . '</p>';
}

render_header($plan['doc_title']);
?>
<p class="breadcrumb"><a href="index.php"><?= h(t('documents.title')) ?></a> &raquo; <a href="document.php?id=<?= (int)$plan['document_id'] ?>"><?= h($plan['doc_title']) ?></a> &raquo; v<?= h($plan['version_number']) ?></p>

<div class="card">
    <div class="doc-header">
        <div>
            <h1><?= h(t('qualityplan.view_title')) ?> — <?= h($plan['doc_number']) ?></h1>
            <p class="muted"><?= h(t('doc.uploaded_by')) ?> <?= h($plan['uploaded_by_name']) ?>, <?= h($plan['uploaded_at']) ?></p>
        </div>
    </div>

    <div class="meta-grid">
        <div><p class="meta-label"><?= h(t('qualityplan.field.contract')) ?></p><p><?= h($plan['baseline_reference']) ?></p></div>
        <div><p class="meta-label"><?= h(t('qualityplan.field.customer')) ?></p><p><?= h($plan['customer_name'] ?? t('qualityplan.no_customer')) ?></p></div>
    </div>

    <div class="button-row">
        <a class="button button-secondary" href="contract_overview.php?contract=<?= urlencode($plan['baseline_reference']) ?>"><?= h(t('qualityplan.see_contract_overview')) ?></a>
        <?php if ($plan['stored_filename']): ?>
            <a class="button button-secondary" href="download.php?version_id=<?= (int)$versionId ?>"><?= h(t('doc.download')) ?></a>
        <?php endif; ?>
    </div>
</div>

<h2><?= h(t('qualityplan.section.scope')) ?></h2>
<div class="card">
    <?php qp_row(t('qualityplan.field.scope'), $plan['scope']); ?>
    <?php qp_row(t('qualityplan.field.organization_responsibility'), $plan['organization_responsibility']); ?>
    <?php qp_row(t('qualityplan.field.applicable_standards'), $plan['applicable_standards']); ?>
</div>

<h2><?= h(t('qualityplan.section.approach')) ?></h2>
<div class="card">
    <?php qp_row(t('qualityplan.field.configuration_management_approach'), $plan['configuration_management_approach']); ?>
    <?php qp_row(t('qualityplan.field.risk_management_approach'), $plan['risk_management_approach']); ?>
    <?php qp_row(t('qualityplan.field.supplier_management_approach'), $plan['supplier_management_approach']); ?>
    <?php qp_row(t('qualityplan.field.inspection_test_plan'), $plan['inspection_test_plan']); ?>
    <?php qp_row(t('qualityplan.field.gqar_involvement'), $plan['gqar_involvement']); ?>
</div>

<?php render_footer(); ?>
