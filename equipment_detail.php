<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

$user = require_permission('equipment', 'view');
$db = get_db();

$id = (int) ($_GET['id'] ?? 0);
$stmt = $db->prepare('SELECT e.*, u.name AS responsible_name FROM equipment e JOIN users u ON u.id = e.responsible_id WHERE e.id = ? AND e.organization_id = ?');
$stmt->execute([$id, $user['organization_id']]);
$equipment = $stmt->fetch();

if (!$equipment) {
    http_response_code(404);
    render_header(t('equipment.not_found'));
    echo '<div class="card">' . h(t('equipment.not_found')) . '</div>';
    render_footer();
    exit;
}

$canManage = can_manage_equipment($user);
$error = '';

if ($canManage && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $location = trim($_POST['location'] ?? '');
    $responsibleId = (int) ($_POST['responsible_id'] ?? 0);
    $criticality = $_POST['criticality'] ?? 'Ikke-kritisk';
    $requiresCalibration = isset($_POST['requires_calibration']) ? 1 : 0;
    $interval = $_POST['calibration_interval_months'] !== '' ? (int) $_POST['calibration_interval_months'] : null;
    $nextCalibrationDate = trim($_POST['next_calibration_date'] ?? '');
    $status = $_POST['status'] ?? 'Aktiv';

    $stmt = $db->prepare('SELECT id FROM users WHERE id = ? AND organization_id = ?');
    $stmt->execute([$responsibleId, $user['organization_id']]);
    $validResponsible = $stmt->fetch();

    if ($name === '' || !$validResponsible) {
        $error = t('newequipment.error.required');
    } else {
        $stmt = $db->prepare('
            UPDATE equipment
            SET name = ?, location = ?, responsible_id = ?, criticality = ?, requires_calibration = ?,
                calibration_interval_months = ?, next_calibration_date = ?, status = ?
            WHERE id = ? AND organization_id = ?
        ');
        $stmt->execute([
            $name, $location ?: null, $responsibleId, $criticality, $requiresCalibration,
            $requiresCalibration ? $interval : null, $requiresCalibration ? ($nextCalibrationDate ?: null) : null,
            $status, $id, $user['organization_id'],
        ]);

        $contracts = array_values(array_filter((array) ($_POST['contracts'] ?? [])));
        save_entity_contracts('equipment_contracts', 'equipment_id', $id, $contracts);

        log_equipment_action($id, 'Udstyr opdateret', 'Status: ' . $status);

        header('Location: equipment_detail.php?id=' . $id);
        exit;
    }
}

$stmt = $db->prepare('
    SELECT c.*, u.name AS performed_by_name
    FROM equipment_calibrations c
    JOIN users u ON u.id = c.performed_by
    WHERE c.equipment_id = ?
    ORDER BY c.calibration_date DESC
');
$stmt->execute([$id]);
$calibrations = $stmt->fetchAll();

$stmt = $db->prepare("SELECT id, name FROM users WHERE organization_id = ? AND active = 1 ORDER BY name");
$stmt->execute([$user['organization_id']]);
$orgUsers = $stmt->fetchAll();

render_header($equipment['name']);
?>
<p class="breadcrumb"><a href="equipment.php"><?= h(t('equipment.title')) ?></a> &raquo; <?= h($equipment['name']) ?></p>

<?php if ($error): ?><p class="alert alert-danger"><?= h($error) ?></p><?php endif; ?>

<div class="card">
    <div class="doc-header">
        <div>
            <h1><?= h($equipment['name']) ?></h1>
            <p class="muted"><?= h($equipment['location'] ?: '—') ?></p>
        </div>
        <div style="display:flex; gap:8px;">
            <span class="<?= equipment_criticality_class($equipment['criticality']) ?>"><?= h(equipment_criticality_label($equipment['criticality'])) ?></span>
            <span class="<?= equipment_status_class($equipment['status']) ?>"><?= h(equipment_status_label($equipment['status'])) ?></span>
        </div>
    </div>

    <div class="meta-grid">
        <div><p class="meta-label"><?= h(t('equipment.responsible')) ?></p><p><?= h($equipment['responsible_name']) ?></p></div>
        <div><p class="meta-label"><?= h(t('equipment.requires_calibration')) ?></p><p><?= $equipment['requires_calibration'] ? h(t('equipment.yes')) : h(t('equipment.no')) ?></p></div>
        <?php if ($equipment['requires_calibration']): ?>
            <div><p class="meta-label"><?= h(t('equipment.last_calibration')) ?></p><p><?= format_date($equipment['last_calibration_date']) ?></p></div>
            <div><p class="meta-label"><?= h(t('equipment.next_calibration')) ?></p><p><?= format_date($equipment['next_calibration_date']) ?></p></div>
        <?php endif; ?>
        <?php $equipmentContracts = get_entity_contracts('equipment_contracts', 'equipment_id', (int) $equipment['id']); ?>
        <?php if ($equipmentContracts): ?>
            <div><p class="meta-label"><?= h(t('equipment.contracts')) ?></p><p><?= h(implode(', ', $equipmentContracts)) ?></p></div>
        <?php endif; ?>
    </div>

    <?php if ($equipment['requires_calibration'] && !empty($calibrations)): ?>
        <div style="border-top:0.5px solid var(--border); padding-top:1rem; margin-top:1rem;">
            <p class="meta-label"><?= h(t('equipment.latest_outcome')) ?></p>
            <p><span class="<?= equipment_outcome_class($calibrations[0]['outcome']) ?>"><?= h(equipment_outcome_label($calibrations[0]['outcome'])) ?></span></p>
        </div>
    <?php endif; ?>

    <?php if ($canManage && $equipment['requires_calibration']): ?>
        <div class="button-row">
            <a class="button" href="new_calibration.php?equipment_id=<?= (int)$equipment['id'] ?>"><?= h(t('equipment.register_calibration')) ?></a>
        </div>
    <?php endif; ?>

    <?php if ($user['role'] === 'admin'): ?>
        <div class="button-row">
            <form method="post" action="delete_equipment.php" class="inline-form"
                  onsubmit="return confirm('<?= h(t('equipment.delete_confirm')) ?>
');">
    <?= csrf_field() ?>
                <input type="hidden" name="equipment_id" value="<?= (int)$equipment['id'] ?>">
                <button type="submit" class="button-secondary button-danger"><?= h(t('equipment.delete')) ?></button>
            </form>
        </div>
    <?php endif; ?>
</div>

<?php if ($canManage): ?>
<h2><?= h(t('equipment.update_title')) ?></h2>
<div class="card">
    <form method="post">
    <?= csrf_field() ?>
        <label><?= h(t('newequipment.field.name')) ?>
            <input type="text" name="name" value="<?= h($equipment['name']) ?>" required>
        </label>
        <label><?= h(t('newequipment.field.location')) ?>
            <input type="text" name="location" value="<?= h($equipment['location'] ?? '') ?>">
        </label>
        <label><?= h(t('newequipment.field.responsible')) ?>
            <select name="responsible_id" required>
                <?php foreach ($orgUsers as $u): ?>
                    <option value="<?= (int) $u['id'] ?>" <?= (int)$equipment['responsible_id'] === (int)$u['id'] ? 'selected' : '' ?>><?= h($u['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label><?= h(t('newequipment.field.criticality')) ?>
            <select name="criticality">
                <option value="Ikke-kritisk" <?= $equipment['criticality'] === 'Ikke-kritisk' ? 'selected' : '' ?>><?= h(equipment_criticality_label('Ikke-kritisk')) ?></option>
                <option value="Kritisk" <?= $equipment['criticality'] === 'Kritisk' ? 'selected' : '' ?>><?= h(equipment_criticality_label('Kritisk')) ?></option>
            </select>
        </label>
        <label><?= h(t('equipment.table.status')) ?>
            <select name="status">
                <option value="Aktiv" <?= $equipment['status'] === 'Aktiv' ? 'selected' : '' ?>><?= h(equipment_status_label('Aktiv')) ?></option>
                <option value="Ikke aktiv" <?= $equipment['status'] === 'Ikke aktiv' ? 'selected' : '' ?>><?= h(equipment_status_label('Ikke aktiv')) ?></option>
            </select>
        </label>
        <label style="display:flex; align-items:center; gap:8px; flex-direction:row;">
            <input type="checkbox" name="requires_calibration" id="requires_calibration_edit" value="1" <?= $equipment['requires_calibration'] ? 'checked' : '' ?> style="width:auto;" onchange="document.getElementById('interval-field-edit').style.display = this.checked ? 'block' : 'none';">
            <?= h(t('newequipment.field.requires_calibration')) ?>
        </label>
        <div id="interval-field-edit" style="<?= $equipment['requires_calibration'] ? '' : 'display:none;' ?>">
            <label><?= h(t('newequipment.field.interval')) ?>
                <input type="number" name="calibration_interval_months" min="1" max="120" value="<?= h($equipment['calibration_interval_months'] ?? '12') ?>">
            </label>
            <label><?= h(t('equipment.next_calibration')) ?>
                <input type="date" name="next_calibration_date" value="<?= h($equipment['next_calibration_date'] ?? '') ?>">
            </label>
        </div>
        <label><?= h(t('equipment.contracts')) ?>
            <select name="contracts[]" multiple size="4">
                <?php foreach (list_active_values('contract_reference') as $ref): ?>
                    <option value="<?= h($ref) ?>" <?= in_array($ref, $equipmentContracts, true) ? 'selected' : '' ?>><?= h($ref) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <button type="submit"><?= h(t('equipment.save')) ?></button>
    </form>
</div>
<?php endif; ?>

<?php if ($equipment['requires_calibration']): ?>
<h2><?= h(t('equipment.calibration_history')) ?></h2>
<div class="card">
    <?php foreach ($calibrations as $c): ?>
        <div class="timeline-row">
            <div>
                <p><strong><?= format_date($c['calibration_date']) ?></strong> — <?= h(t('equipment.performed_by')) ?> <?= h($c['performed_by_name']) ?> — <span class="<?= equipment_outcome_class($c['outcome']) ?>"><?= h(equipment_outcome_label($c['outcome'])) ?></span></p>
                <?php if ($c['result']): ?><p class="muted"><?= nl2br(h($c['result'])) ?></p><?php endif; ?>
                <?php if ($c['traceability']): ?><p class="muted"><?= h(t('equipment.traceability')) ?>: <?= h($c['traceability']) ?></p><?php endif; ?>
                <?php if ($c['measurement_uncertainty']): ?><p class="muted"><?= h(t('equipment.uncertainty')) ?>: <?= h($c['measurement_uncertainty']) ?></p><?php endif; ?>
            </div>
            <div class="timeline-actions">
                <?php if ($c['stored_filename']): ?>
                    <a href="download_calibration.php?id=<?= (int)$c['id'] ?>"><?= h(t('doc.download')) ?></a>
                <?php endif; ?>
            </div>
        </div>
    <?php endforeach; ?>
    <?php if (!$calibrations): ?><p class="muted"><?= h(t('equipment.no_calibrations')) ?></p><?php endif; ?>
</div>
<?php endif; ?>

<?php render_footer(); ?>
