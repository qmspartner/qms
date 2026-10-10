<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

$user = require_permission('equipment', 'edit');
$db = get_db();

$equipmentId = (int) ($_GET['equipment_id'] ?? $_POST['equipment_id'] ?? 0);
$stmt = $db->prepare('SELECT * FROM equipment WHERE id = ? AND organization_id = ?');
$stmt->execute([$equipmentId, $user['organization_id']]);
$equipment = $stmt->fetch();

if (!$equipment || !$equipment['requires_calibration']) {
    http_response_code(404);
    exit(t('equipment.not_found'));
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $calibrationDate = trim($_POST['calibration_date'] ?? '');
    $result = trim($_POST['result'] ?? '');
    $traceability = trim($_POST['traceability'] ?? '');
    $uncertainty = trim($_POST['measurement_uncertainty'] ?? '');
    $outcome = in_array($_POST['outcome'] ?? '', ['Godkendt', 'Ikke godkendt', 'Justeret'], true) ? $_POST['outcome'] : 'Godkendt';
    $file = $_FILES['certificate'] ?? null;

    if ($calibrationDate === '') {
        $error = t('newcalibration.error.required');
    } else {
        $storedName = null;
        $originalName = null;

        if ($file && $file['size'] > 0) {
            if ($file['error'] !== UPLOAD_ERR_OK) {
                $error = t('upload.error.choose_file');
            } elseif ($file['size'] > MAX_FILE_SIZE) {
                $error = t('upload.error.too_large');
            } else {
                $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                if (!in_array($ext, ALLOWED_EXTENSIONS, true)) {
                    $error = t('upload.error.bad_type') . ' ' . implode(', ', ALLOWED_EXTENSIONS);
                } else {
                    $storedName = bin2hex(random_bytes(16)) . '.' . $ext;
                    if (!move_uploaded_file($file['tmp_name'], UPLOAD_DIR . $storedName)) {
                        $error = t('upload.error.save_failed');
                        $storedName = null;
                    } else {
                        $originalName = $file['name'];
                    }
                }
            }
        }

        if (!$error) {
            $db->beginTransaction();
            try {
                $stmt = $db->prepare('
                    INSERT INTO equipment_calibrations (equipment_id, calibration_date, result, stored_filename, original_filename, performed_by, traceability, measurement_uncertainty, outcome)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                ');
                $stmt->execute([$equipmentId, $calibrationDate, $result ?: null, $storedName, $originalName, $user['id'], $traceability ?: null, $uncertainty ?: null, $outcome]);

                $nextDate = null;
                if ($equipment['calibration_interval_months']) {
                    $nextDate = date('Y-m-d', strtotime($calibrationDate . ' + ' . (int)$equipment['calibration_interval_months'] . ' months'));
                }

                $stmt = $db->prepare('UPDATE equipment SET last_calibration_date = ?, next_calibration_date = ? WHERE id = ?');
                $stmt->execute([$calibrationDate, $nextDate, $equipmentId]);

                $db->commit();
                log_equipment_action($equipmentId, 'Kalibrering registreret', $calibrationDate);

                header('Location: equipment_detail.php?id=' . $equipmentId);
                exit;
            } catch (Exception $e) {
                $db->rollBack();
                $error = t('upload.error.generic');
            }
        }
    }
}

render_header(t('newcalibration.title'));
?>
<div class="card login-card">
    <h1><?= h(t('newcalibration.title')) ?> — <?= h($equipment['name']) ?></h1>
    <?php if ($error): ?><p class="alert alert-danger"><?= h($error) ?></p><?php endif; ?>
    <form method="post" enctype="multipart/form-data" data-preserve="newcalibration">
    <?= csrf_field() ?>
        <input type="hidden" name="equipment_id" value="<?= (int) $equipmentId ?>">
        <label><?= h(t('newcalibration.field.date')) ?>
            <input type="date" name="calibration_date" value="<?= h(date('Y-m-d')) ?>" required>
        </label>
        <label><?= h(t('newcalibration.field.outcome')) ?>
            <select name="outcome">
                <option value="Godkendt"><?= h(equipment_outcome_label('Godkendt')) ?></option>
                <option value="Ikke godkendt"><?= h(equipment_outcome_label('Ikke godkendt')) ?></option>
                <option value="Justeret"><?= h(equipment_outcome_label('Justeret')) ?></option>
            </select>
        </label>
        <label><?= h(t('newcalibration.field.result')) ?>
            <textarea name="result" rows="3"></textarea>
        </label>
        <label><?= h(t('newcalibration.field.traceability')) ?>
            <input type="text" name="traceability">
        </label>
        <label><?= h(t('newcalibration.field.uncertainty')) ?>
            <input type="text" name="measurement_uncertainty">
        </label>
        <label><?= h(t('newcalibration.field.certificate')) ?>
            <input type="file" name="certificate">
        </label>
        <button type="submit"><?= h(t('newcalibration.submit')) ?></button>
    </form>
</div>
<?php render_footer(); ?>
