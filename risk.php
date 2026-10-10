<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

$user = require_permission('risks', 'view');
$db = get_db();

$id = (int) ($_GET['id'] ?? 0);
$stmt = $db->prepare('
    SELECT r.*, u.name AS owner_name, d.title AS linked_document_title, d.doc_number AS linked_document_number
    FROM risks r
    JOIN users u ON u.id = r.owner_id
    LEFT JOIN documents d ON d.id = r.linked_document_id
    WHERE r.id = ? AND r.organization_id = ?
');
$stmt->execute([$id, $user['organization_id']]);
$risk = $stmt->fetch();

if (!$risk) {
    http_response_code(404);
    render_header(t('risk.not_found'));
    echo '<div class="card">' . h(t('risk.not_found')) . '</div>';
    render_footer();
    exit;
}

$canManage = can_manage_risks($user);
$error = '';

if ($canManage && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $status = $_POST['status'] ?? '';
    $probability = max(1, min(5, (int) ($_POST['probability'] ?? $risk['probability'])));
    $consequence = max(1, min(5, (int) ($_POST['consequence'] ?? $risk['consequence'])));
    $treatmentPlan = trim($_POST['treatment_plan'] ?? '');
    $residualProbability = $_POST['residual_probability'] !== '' ? max(1, min(5, (int) $_POST['residual_probability'])) : null;
    $residualConsequence = $_POST['residual_consequence'] !== '' ? max(1, min(5, (int) $_POST['residual_consequence'])) : null;
    $nextReviewDate = trim($_POST['next_review_date'] ?? '');
    $contractReference = trim($_POST['contract_reference'] ?? '');
    $allowedStatuses = ['Identificeret', 'Under behandling', 'Lukket'];

    if (!in_array($status, $allowedStatuses, true)) {
        $error = t('newrisk.error.required');
    } else {
        $oldProbability = (int) $risk['probability'];
        $oldConsequence = (int) $risk['consequence'];
        $oldStatus = $risk['status'];
        $oldTreatmentPlan = (string) ($risk['treatment_plan'] ?? '');
        $oldResidualProbability = $risk['residual_probability'];
        $oldResidualConsequence = $risk['residual_consequence'];
        $oldNextReviewDate = $risk['next_review_date'];

        $stmt = $db->prepare('
            UPDATE risks
            SET status = ?, probability = ?, consequence = ?, treatment_plan = ?, residual_probability = ?, residual_consequence = ?, next_review_date = ?, contract_reference = ?
            WHERE id = ? AND organization_id = ?
        ');
        $stmt->execute([$status, $probability, $consequence, $treatmentPlan ?: null, $residualProbability, $residualConsequence, $nextReviewDate ?: null, $contractReference ?: null, $id, $user['organization_id']]);

        $changes = [];
        if ($oldStatus !== $status) {
            $changes[] = 'Status: ' . risk_status_label($oldStatus) . ' → ' . risk_status_label($status);
        }
        if ($oldProbability !== $probability || $oldConsequence !== $consequence) {
            $changes[] = 'Vurdering: ' . $oldProbability . '×' . $oldConsequence . ' (' . ($oldProbability * $oldConsequence) . ') → ' . $probability . '×' . $consequence . ' (' . ($probability * $consequence) . ')';
        }
        $oldResidualDisplay = ($oldResidualProbability !== null && $oldResidualConsequence !== null) ? $oldResidualProbability . '×' . $oldResidualConsequence : '—';
        $newResidualDisplay = ($residualProbability !== null && $residualConsequence !== null) ? $residualProbability . '×' . $residualConsequence : '—';
        if ($oldResidualDisplay !== $newResidualDisplay) {
            $changes[] = 'Restvurdering: ' . $oldResidualDisplay . ' → ' . $newResidualDisplay;
        }
        if (trim($oldTreatmentPlan) !== trim($treatmentPlan)) {
            $changes[] = 'Handlingsplan opdateret';
        }
        if (($oldNextReviewDate ?: '') !== ($nextReviewDate ?: '')) {
            $changes[] = 'Næste revurdering: ' . ($oldNextReviewDate ?: '—') . ' → ' . ($nextReviewDate ?: '—');
        }

        if ($changes) {
            log_risk_action($id, 'Risiko opdateret', implode(' | ', $changes));
        }

        header('Location: risks.php');
        exit;
    }
}

$stmt = $db->prepare('
    SELECT l.*, u.name AS user_name
    FROM risk_log l
    JOIN users u ON u.id = l.user_id
    WHERE l.risk_id = ?
    ORDER BY l.created_at DESC
');
$stmt->execute([$id]);
$logEntries = $stmt->fetchAll();

$grossScore = risk_score((int) $risk['probability'], (int) $risk['consequence']);
$grossBand = risk_band($grossScore);
$hasResidual = $risk['residual_probability'] !== null && $risk['residual_consequence'] !== null;
$residualScore = $hasResidual ? risk_score((int) $risk['residual_probability'], (int) $risk['residual_consequence']) : null;
$residualBand = $hasResidual ? risk_band($residualScore) : null;

render_header($risk['title']);
?>
<p class="breadcrumb"><a href="risks.php"><?= h(t('risks.title')) ?></a> &raquo; <?= h(risk_category_label($risk['category'])) ?></p>

<?php if ($error): ?><p class="alert alert-danger"><?= h($error) ?></p><?php endif; ?>

<div class="card">
    <div class="doc-header">
        <div>
            <h1><?= h($risk['title']) ?></h1>
        </div>
        <span class="<?= risk_status_class($risk['status']) ?>"><?= h(risk_status_label($risk['status'])) ?></span>
    </div>

    <div class="meta-grid">
        <div><p class="meta-label"><?= h(t('risk.owner')) ?></p><p><?= h($risk['owner_name']) ?></p></div>
        <div><p class="meta-label"><?= h(t('risk.category')) ?></p><p><?= h(risk_category_label($risk['category'])) ?></p></div>
        <div><p class="meta-label"><?= h(t('risk.score')) ?></p><p><span class="<?= risk_band_class($grossBand) ?>"><?= $grossScore ?> — <?= h(risk_band_label($grossBand)) ?></span></p></div>
        <div><p class="meta-label"><?= h(t('risk.residual_score')) ?></p><p>
            <?php if ($hasResidual): ?>
                <span class="<?= risk_band_class($residualBand) ?>"><?= $residualScore ?> — <?= h(risk_band_label($residualBand)) ?></span>
            <?php else: ?>
                <?= h(t('risk.no_residual')) ?>
            <?php endif; ?>
        </p></div>
        <?php if ($risk['contract_reference']): ?>
            <div><p class="meta-label"><?= h(t('risk.field.contract_reference')) ?></p><p><?= h($risk['contract_reference']) ?></p></div>
        <?php endif; ?>
    </div>

    <?php if ($risk['description']): ?>
        <div style="border-top:0.5px solid var(--border); padding-top:1rem; margin-top:1rem;">
            <p class="meta-label"><?= h(t('risk.description')) ?></p>
            <p style="margin:0;"><?= nl2br(h($risk['description'])) ?></p>
        </div>
    <?php endif; ?>

    <?php if ($risk['treatment_plan']): ?>
        <div style="border-top:0.5px solid var(--border); padding-top:1rem; margin-top:1rem;">
            <p class="meta-label"><?= h(t('risk.treatment_plan')) ?></p>
            <p style="margin:0;"><?= nl2br(h($risk['treatment_plan'])) ?></p>
        </div>
    <?php endif; ?>

    <?php if ($risk['linked_document_title']): ?>
        <div class="button-row">
            <a class="button button-secondary" href="document.php?id=<?= (int)$risk['linked_document_id'] ?>">
                <?= h(t('risk.linked_document')) ?>: <?= h($risk['linked_document_number']) ?>
            </a>
        </div>
    <?php endif; ?>

    <?php if ($user['role'] === 'admin'): ?>
        <div class="button-row">
            <form method="post" action="delete_risk.php" class="inline-form"
                  onsubmit="return confirm('<?= h(t('risk.delete_confirm')) ?>
');">
    <?= csrf_field() ?>
                <input type="hidden" name="risk_id" value="<?= (int)$risk['id'] ?>">
                <button type="submit" class="button-secondary button-danger"><?= h(t('risk.delete')) ?></button>
            </form>
        </div>
    <?php endif; ?>
</div>

<?php if ($canManage): ?>
<h2><?= h(t('risk.update_title')) ?></h2>
<div class="card">
    <form method="post">
    <?= csrf_field() ?>
        <label><?= h(t('risk.field.status')) ?>
            <select name="status">
                <?php foreach (['Identificeret', 'Under behandling', 'Lukket'] as $s): ?>
                    <option value="<?= h($s) ?>" <?= $risk['status'] === $s ? 'selected' : '' ?>><?= h(risk_status_label($s)) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label><?= h(t('newrisk.field.probability')) ?>
            <select name="probability">
                <?php for ($i = 1; $i <= 5; $i++): ?>
                    <option value="<?= $i ?>" <?= (int)$risk['probability'] === $i ? 'selected' : '' ?>><?= $i ?></option>
                <?php endfor; ?>
            </select>
        </label>
        <label><?= h(t('newrisk.field.consequence')) ?>
            <select name="consequence">
                <?php for ($i = 1; $i <= 5; $i++): ?>
                    <option value="<?= $i ?>" <?= (int)$risk['consequence'] === $i ? 'selected' : '' ?>><?= $i ?></option>
                <?php endfor; ?>
            </select>
        </label>
        <label><?= h(t('risk.field.treatment_plan')) ?>
            <textarea name="treatment_plan" rows="3"><?= h($risk['treatment_plan'] ?? '') ?></textarea>
        </label>
        <label><?= h(t('risk.field.residual_probability')) ?>
            <select name="residual_probability">
                <option value="">—</option>
                <?php for ($i = 1; $i <= 5; $i++): ?>
                    <option value="<?= $i ?>" <?= (int)($risk['residual_probability'] ?? 0) === $i ? 'selected' : '' ?>><?= $i ?></option>
                <?php endfor; ?>
            </select>
        </label>
        <label><?= h(t('risk.field.residual_consequence')) ?>
            <select name="residual_consequence">
                <option value="">—</option>
                <?php for ($i = 1; $i <= 5; $i++): ?>
                    <option value="<?= $i ?>" <?= (int)($risk['residual_consequence'] ?? 0) === $i ? 'selected' : '' ?>><?= $i ?></option>
                <?php endfor; ?>
            </select>
        </label>
        <label><?= h(t('risk.field.next_review')) ?>
            <input type="date" name="next_review_date" value="<?= h($risk['next_review_date'] ?? '') ?>">
        </label>
        <label><?= h(t('risk.field.contract_reference')) ?>
            <select name="contract_reference">
                <option value=""><?= h(t('newcase.field.contract_reference_choose')) ?></option>
                <?php foreach (list_active_values('contract_reference') as $ref): ?>
                    <option value="<?= h($ref) ?>" <?= $risk['contract_reference'] === $ref ? 'selected' : '' ?>><?= h($ref) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <button type="submit"><?= h(t('risk.save')) ?></button>
    </form>
</div>
<?php endif; ?>

<h2><?= h(t('risk.history')) ?></h2>
<div class="card">
    <?php foreach ($logEntries as $entry): ?>
        <div class="log-row">
            <p><strong><?= h($entry['user_name']) ?></strong> — <?= h($entry['action']) ?><?= $entry['details'] ? ': ' . h($entry['details']) : '' ?></p>
            <p class="muted"><?= h($entry['created_at']) ?></p>
        </div>
    <?php endforeach; ?>
    <?php if (!$logEntries): ?><p class="muted"><?= h(t('risk.no_log')) ?></p><?php endif; ?>
</div>

<?php render_footer(); ?>
