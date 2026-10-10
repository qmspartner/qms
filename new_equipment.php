<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

$user = require_permission('equipment', 'create');
$db = get_db();

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $location = trim($_POST['location'] ?? '');
    $responsibleId = (int) ($_POST['responsible_id'] ?? 0);
    $criticality = $_POST['criticality'] ?? 'Ikke-kritisk';
    $requiresCalibration = isset($_POST['requires_calibration']) ? 1 : 0;
    $interval = $_POST['calibration_interval_months'] !== '' ? (int) $_POST['calibration_interval_months'] : null;

    $stmt = $db->prepare('SELECT id FROM users WHERE id = ? AND organization_id = ?');
    $stmt->execute([$responsibleId, $user['organization_id']]);
    $validResponsible = $stmt->fetch();

    if ($name === '' || !$validResponsible) {
        $error = t('newequipment.error.required');
    } else {
        $stmt = $db->prepare('
            INSERT INTO equipment (organization_id, name, location, responsible_id, criticality, requires_calibration, calibration_interval_months)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute([$user['organization_id'], $name, $location ?: null, $responsibleId, $criticality, $requiresCalibration, $requiresCalibration ? $interval : null]);
        $equipmentId = (int) $db->lastInsertId();

        $contracts = array_values(array_filter((array) ($_POST['contracts'] ?? [])));
        save_entity_contracts('equipment_contracts', 'equipment_id', $equipmentId, $contracts);

        log_equipment_action($equipmentId, 'Udstyr oprettet', $name);

        header('Location: equipment.php');
        exit;
    }
}

$stmt = $db->prepare("SELECT id, name FROM users WHERE organization_id = ? AND active = 1 ORDER BY name");
$stmt->execute([$user['organization_id']]);
$orgUsers = $stmt->fetchAll();

render_header(t('newequipment.title'));
?>
<div class="card login-card">
    <h1><?= h(t('newequipment.title')) ?></h1>
    <?php if ($error): ?><p class="alert alert-danger"><?= h($error) ?></p><?php endif; ?>
    <form method="post" data-preserve="newequipment">
    <?= csrf_field() ?>
        <label><?= h(t('newequipment.field.name')) ?>
            <input type="text" name="name" required>
        </label>
        <label><?= h(t('newequipment.field.location')) ?>
            <input type="text" name="location">
        </label>
        <label><?= h(t('newequipment.field.responsible')) ?>
            <select name="responsible_id" required>
                <?php foreach ($orgUsers as $u): ?>
                    <option value="<?= (int) $u['id'] ?>"><?= h($u['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label><?= h(t('newequipment.field.criticality')) ?>
            <select name="criticality">
                <option value="Ikke-kritisk"><?= h(equipment_criticality_label('Ikke-kritisk')) ?></option>
                <option value="Kritisk"><?= h(equipment_criticality_label('Kritisk')) ?></option>
            </select>
        </label>
        <label style="display:flex; align-items:center; gap:8px; flex-direction:row;">
            <input type="checkbox" name="requires_calibration" id="requires_calibration" value="1" checked style="width:auto;" onchange="document.getElementById('interval-field').style.display = this.checked ? 'block' : 'none';">
            <?= h(t('newequipment.field.requires_calibration')) ?>
        </label>
        <div id="interval-field">
            <label><?= h(t('newequipment.field.interval')) ?>
                <input type="number" name="calibration_interval_months" min="1" max="120" value="12">
            </label>
        </div>
        <label><?= h(t('equipment.contracts')) ?>
            <select name="contracts[]" multiple size="4">
                <?php foreach (list_active_values('contract_reference') as $ref): ?>
                    <option value="<?= h($ref) ?>"><?= h($ref) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <button type="submit"><?= h(t('newequipment.submit')) ?></button>
    </form>
</div>
<?php render_footer(); ?>
