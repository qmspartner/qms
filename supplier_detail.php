<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

$user = require_permission('suppliers', 'view');
$db = get_db();

$id = (int) ($_GET['id'] ?? 0);
$stmt = $db->prepare('SELECT s.*, u.name AS owner_name FROM suppliers s JOIN users u ON u.id = s.owner_id WHERE s.id = ? AND s.organization_id = ?');
$stmt->execute([$id, $user['organization_id']]);
$supplier = $stmt->fetch();

if (!$supplier) {
    http_response_code(404);
    render_header(t('supplier.not_found'));
    echo '<div class="card">' . h(t('supplier.not_found')) . '</div>';
    render_footer();
    exit;
}

$canManage = can_manage_suppliers($user);
$error = '';
$openCounterfeitCount = count_open_counterfeit_cases($id);

if ($canManage && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $status = $_POST['status'] ?? 'Under vurdering';
    $criticality = $_POST['criticality'] ?? 'Ikke-kritisk';
    $interval = $_POST['evaluation_interval_months'] !== '' ? (int) $_POST['evaluation_interval_months'] : null;
    $nextEvaluationDate = trim($_POST['next_evaluation_date'] ?? '');
    $qualityPassedOn = isset($_POST['quality_requirements_passed_on']) ? 1 : 0;
    if ($openCounterfeitCount > 0) {
        // Status kan ikke ændres manuelt, så længe der er åbne mistanke-sager — ignorer evt. forsøg på at omgå det i selve formularen
        $status = $supplier['status'];
    }

    $stmt = $db->prepare('
        UPDATE suppliers
        SET status = ?, criticality = ?, evaluation_interval_months = ?, next_evaluation_date = ?, quality_requirements_passed_on = ?
        WHERE id = ? AND organization_id = ?
    ');
    $stmt->execute([$status, $criticality, $interval, $nextEvaluationDate ?: null, $qualityPassedOn, $id, $user['organization_id']]);

    $contracts = array_values(array_filter((array) ($_POST['contracts'] ?? [])));
    save_entity_contracts('supplier_contracts', 'supplier_id', $id, $contracts);

    log_supplier_action($id, 'Leverandør opdateret', 'Status: ' . $status);

    header('Location: supplier_detail.php?id=' . $id);
    exit;
}

$stmt = $db->prepare('
    SELECT e.*, u.name AS performed_by_name
    FROM supplier_evaluations e
    JOIN users u ON u.id = e.performed_by
    WHERE e.supplier_id = ?
    ORDER BY e.evaluation_date DESC
');
$stmt->execute([$id]);
$evaluations = $stmt->fetchAll();

render_header($supplier['name']);
?>
<p class="breadcrumb"><a href="suppliers.php"><?= h(t('suppliers.title')) ?></a> &raquo; <?= h($supplier['name']) ?></p>

<?php if ($error): ?><p class="alert alert-danger"><?= h($error) ?></p><?php endif; ?>

<div class="card">
    <div class="doc-header">
        <div>
            <h1><?= h($supplier['name']) ?></h1>
            <p class="muted"><?= h(supplier_category_label($supplier['category'])) ?></p>
        </div>
        <div style="display:flex; gap:8px;">
            <span class="<?= equipment_criticality_class($supplier['criticality']) ?>"><?= h(equipment_criticality_label($supplier['criticality'])) ?></span>
            <span class="<?= supplier_status_class($supplier['status']) ?>"><?= h(supplier_status_label($supplier['status'])) ?></span>
        </div>
    </div>

    <div class="meta-grid">
        <div><p class="meta-label"><?= h(t('supplier.contact')) ?></p><p><?= h($supplier['contact_person'] ?: '—') ?><?= $supplier['email'] ? ' · ' . h($supplier['email']) : '' ?><?= $supplier['phone'] ? ' · ' . h($supplier['phone']) : '' ?></p></div>
        <div><p class="meta-label"><?= h(t('supplier.owner')) ?></p><p><?= h($supplier['owner_name']) ?></p></div>
        <div><p class="meta-label"><?= h(t('supplier.last_evaluation')) ?></p><p><?= format_date($supplier['last_evaluation_date']) ?></p></div>
        <div><p class="meta-label"><?= h(t('supplier.next_evaluation')) ?></p><p><?= format_date($supplier['next_evaluation_date']) ?></p></div>
        <div><p class="meta-label"><?= h(t('supplier.quality_requirements')) ?></p><p><?= $supplier['quality_requirements_passed_on'] ? h(t('common.yes')) : '—' ?></p></div>
        <?php $supplierContracts = get_entity_contracts('supplier_contracts', 'supplier_id', (int) $supplier['id']); ?>
        <?php if ($supplierContracts): ?>
            <div><p class="meta-label"><?= h(t('supplier.contracts')) ?></p><p><?= h(implode(', ', $supplierContracts)) ?></p></div>
        <?php endif; ?>
    </div>

    <?php if ($canManage): ?>
        <div class="button-row">
            <a class="button" href="new_supplier_evaluation.php?supplier_id=<?= (int)$supplier['id'] ?>"><?= h(t('supplier.register_evaluation')) ?></a>
        </div>
    <?php endif; ?>

    <?php if ($user['role'] === 'admin'): ?>
        <div class="button-row">
            <form method="post" action="delete_supplier.php" class="inline-form"
                  onsubmit="return confirm('<?= h(t('supplier.delete_confirm')) ?>
');">
    <?= csrf_field() ?>
                <input type="hidden" name="supplier_id" value="<?= (int)$supplier['id'] ?>">
                <button type="submit" class="button-secondary button-danger"><?= h(t('supplier.delete')) ?></button>
            </form>
        </div>
    <?php endif; ?>
</div>

        <?php if ($openCounterfeitCount > 0): ?>
            <div class="alert alert-danger">
                <?= h(t('supplier.counterfeit_warning', ['count' => $openCounterfeitCount])) ?>
                <a href="cases.php?<?= http_build_query(['type' => ['Mistænkt forfalsket del']]) ?>&supplier_id=<?= (int) $supplier['id'] ?>"><?= h(t('supplier.counterfeit_see_cases')) ?></a>
                <?php help_tip('help.tip.supplier_locked'); ?>
            </div>
        <?php endif; ?>

<?php if ($canManage): ?>
<h2><?= h(t('supplier.update_title')) ?></h2>
<div class="card">
    <form method="post">
    <?= csrf_field() ?>
        <label><?= h(t('supplier.field.status')) ?>
            <select name="status" <?= $openCounterfeitCount > 0 ? 'disabled' : '' ?>>
                <?php foreach (['Godkendt', 'Under vurdering', 'Afvist', 'Udfaset'] as $s): ?>
                    <option value="<?= h($s) ?>" <?= $supplier['status'] === $s ? 'selected' : '' ?>><?= h(supplier_status_label($s)) ?></option>
                <?php endforeach; ?>
            </select>
            <?php if ($openCounterfeitCount > 0): ?>
                <span class="muted" style="font-size:13px;"><?= h(t('supplier.status_locked')) ?></span>
            <?php endif; ?>
        </label>
        <label><?= h(t('supplier.field.criticality')) ?>
            <select name="criticality">
                <option value="Ikke-kritisk" <?= $supplier['criticality'] === 'Ikke-kritisk' ? 'selected' : '' ?>><?= h(equipment_criticality_label('Ikke-kritisk')) ?></option>
                <option value="Kritisk" <?= $supplier['criticality'] === 'Kritisk' ? 'selected' : '' ?>><?= h(equipment_criticality_label('Kritisk')) ?></option>
            </select>
        </label>
        <label><?= h(t('supplier.field.interval')) ?>
            <input type="number" name="evaluation_interval_months" min="1" max="60" value="<?= h($supplier['evaluation_interval_months'] ?? '') ?>">
        </label>
        <label><?= h(t('supplier.field.next_evaluation')) ?>
            <input type="date" name="next_evaluation_date" value="<?= h($supplier['next_evaluation_date'] ?? '') ?>">
        </label>
        <label style="display:flex; align-items:center; gap:8px; flex-direction:row;">
            <input type="checkbox" name="quality_requirements_passed_on" value="1" <?= $supplier['quality_requirements_passed_on'] ? 'checked' : '' ?> style="width:auto;">
            <?= h(t('supplier.field.quality_requirements')) ?>
        </label>
        <label><?= h(t('supplier.contracts')) ?>
            <select name="contracts[]" multiple size="4">
                <?php foreach (list_active_values('contract_reference') as $ref): ?>
                    <option value="<?= h($ref) ?>" <?= in_array($ref, $supplierContracts, true) ? 'selected' : '' ?>><?= h($ref) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <button type="submit"><?= h(t('supplier.save')) ?></button>
    </form>
</div>
<?php endif; ?>

<h2><?= h(t('supplier.evaluation_history')) ?></h2>
<div class="card">
    <?php foreach ($evaluations as $e): ?>
        <div class="timeline-row">
            <div>
                <p><strong><?= format_date($e['evaluation_date']) ?></strong> — <?= h(t('supplier.evaluated_by')) ?> <?= h($e['performed_by_name']) ?></p>
                <?php if ($e['result']): ?><p class="muted"><?= nl2br(h($e['result'])) ?></p><?php endif; ?>
            </div>
            <div class="timeline-actions">
                <?php if ($e['stored_filename']): ?>
                    <a href="download_supplier_evaluation.php?id=<?= (int)$e['id'] ?>"><?= h(t('doc.download')) ?></a>
                <?php endif; ?>
            </div>
        </div>
    <?php endforeach; ?>
    <?php if (!$evaluations): ?><p class="muted"><?= h(t('supplier.no_evaluations')) ?></p><?php endif; ?>
</div>

<?php render_footer(); ?>
