<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

$user = require_role(['admin']);
$db = get_db();

$employeeId = (int) ($_GET['employee_id'] ?? $_POST['employee_id'] ?? 0);
$qualificationId = (int) ($_GET['qualification_id'] ?? $_POST['qualification_id'] ?? 0);

$stmt = $db->prepare('SELECT * FROM employees WHERE id = ? AND organization_id = ?');
$stmt->execute([$employeeId, $user['organization_id']]);
$employee = $stmt->fetch();

$stmt = $db->prepare('SELECT * FROM qualifications WHERE id = ? AND organization_id = ?');
$stmt->execute([$qualificationId, $user['organization_id']]);
$qualification = $stmt->fetch();

if (!$employee || !$qualification) {
    http_response_code(404);
    render_header(t('employee_qualification.not_found'));
    echo '<div class="card">' . h(t('employee_qualification.not_found')) . '</div>';
    render_footer();
    exit;
}

$stmt = $db->prepare('SELECT * FROM employee_qualifications WHERE employee_id = ? AND qualification_id = ? AND organization_id = ?');
$stmt->execute([$employeeId, $qualificationId, $user['organization_id']]);
$assignment = $stmt->fetch();

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'save';

    if ($action === 'delete' && $assignment) {
        if ($assignment['stored_filename']) {
            $path = UPLOAD_DIR . $assignment['stored_filename'];
            if (is_file($path)) {
                @unlink($path);
            }
        }
        $stmt = $db->prepare('DELETE FROM employee_qualifications WHERE id = ? AND organization_id = ?');
        $stmt->execute([$assignment['id'], $user['organization_id']]);
        log_employee_action($employeeId, 'Kvalifikation fjernet', $qualification['name']);

        header('Location: edit_employee.php?id=' . $employeeId);
        exit;
    }

    if ($action === 'save') {
        $plannedDate = trim($_POST['planned_date'] ?? '');
        $obtainedDate = trim($_POST['obtained_date'] ?? '');
        $expiryDateInput = trim($_POST['expiry_date'] ?? '');
        $notes = trim($_POST['notes'] ?? '');

        $expiryDate = $expiryDateInput ?: null;
        if (!$expiryDate && $obtainedDate && $qualification['validity_months']) {
            $expiryDate = date('Y-m-d', strtotime($obtainedDate . ' + ' . (int) $qualification['validity_months'] . ' months'));
        }

        $storedName = $assignment['stored_filename'] ?? null;
        $originalName = $assignment['original_filename'] ?? null;
        $file = $_FILES['evidence'] ?? null;
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
                    $newStoredName = bin2hex(random_bytes(16)) . '.' . $ext;
                    if (!move_uploaded_file($file['tmp_name'], UPLOAD_DIR . $newStoredName)) {
                        $error = t('upload.error.save_failed');
                    } else {
                        if ($storedName) {
                            $oldPath = UPLOAD_DIR . $storedName;
                            if (is_file($oldPath)) {
                                @unlink($oldPath);
                            }
                        }
                        $storedName = $newStoredName;
                        $originalName = $file['name'];
                    }
                }
            }
        }

        if (!$error) {
            $stmt = $db->prepare('
                INSERT INTO employee_qualifications
                    (organization_id, employee_id, qualification_id, planned_date, obtained_date, expiry_date, stored_filename, original_filename, notes, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    planned_date = VALUES(planned_date), obtained_date = VALUES(obtained_date), expiry_date = VALUES(expiry_date),
                    stored_filename = VALUES(stored_filename), original_filename = VALUES(original_filename), notes = VALUES(notes)
            ');
            $stmt->execute([
                $user['organization_id'], $employeeId, $qualificationId, $plannedDate ?: null, $obtainedDate ?: null,
                $expiryDate, $storedName, $originalName, $notes ?: null, $user['id'],
            ]);
            log_employee_action($employeeId, 'Kvalifikation opdateret', $qualification['name']);

            header('Location: edit_employee.php?id=' . $employeeId);
            exit;
        }
    }
}

$currentStatus = $assignment ? qualification_status($assignment) : null;

render_header($qualification['name']);
?>
<p class="breadcrumb">
    <a href="competence.php"><?= h(t('competence.title')) ?></a> &raquo;
    <a href="competence_matrix.php"><?= h(t('competence_matrix.title')) ?></a> &raquo;
    <a href="edit_employee.php?id=<?= $employeeId ?>"><?= h($employee['name']) ?></a> &raquo;
    <?= h($qualification['name']) ?>
</p>

<?php if ($error): ?><p class="alert alert-danger"><?= h($error) ?></p><?php endif; ?>

<div class="card">
    <div class="doc-header">
        <div>
            <h1><?= h($qualification['name']) ?></h1>
            <p class="muted"><?= h($employee['name']) ?></p>
        </div>
        <?php if ($currentStatus): ?>
            <span class="<?= qualification_status_class($currentStatus) ?>"><?= h(qualification_status_label($currentStatus)) ?></span>
        <?php endif; ?>
    </div>

    <?php if ($qualification['description']): ?>
        <p class="muted"><?= h($qualification['description']) ?></p>
    <?php endif; ?>

    <?php if ($assignment && $assignment['stored_filename']): ?>
        <p><span class="meta-label"><?= h(t('employee_qualification.current_evidence')) ?></span><br>
            <a href="download_qualification_evidence.php?id=<?= (int) $assignment['id'] ?>"><?= h($assignment['original_filename']) ?></a>
        </p>
    <?php endif; ?>

    <form method="post" enctype="multipart/form-data" style="margin-top:1rem;">
    <?= csrf_field() ?>
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="employee_id" value="<?= $employeeId ?>">
        <input type="hidden" name="qualification_id" value="<?= $qualificationId ?>">
        <label><?= h(t('employee_qualification.field.planned_date')) ?>
            <input type="date" name="planned_date" value="<?= h($assignment['planned_date'] ?? '') ?>">
        </label>
        <label><?= h(t('employee_qualification.field.obtained_date')) ?>
            <input type="date" name="obtained_date" value="<?= h($assignment['obtained_date'] ?? '') ?>">
        </label>
        <label><?= h(t('employee_qualification.field.expiry_date')) ?>
            <input type="date" name="expiry_date" value="<?= h($assignment['expiry_date'] ?? '') ?>">
            <span class="muted" style="font-size:12px;"><?= h(t('employee_qualification.field.expiry_hint')) ?></span>
        </label>
        <label><?= h(t('employee_qualification.field.notes')) ?>
            <textarea name="notes" rows="3"><?= h($assignment['notes'] ?? '') ?></textarea>
        </label>
        <label><?= h(t('employee_qualification.field.evidence')) ?>
            <input type="file" name="evidence">
        </label>
        <button type="submit"><?= h(t('employee_qualification.save')) ?></button>
    </form>

    <?php if ($assignment): ?>
        <div class="button-row">
            <form method="post" class="inline-form"
                  onsubmit="return confirm('<?= h(t('employee_qualification.delete_confirm')) ?>
');">
    <?= csrf_field() ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="employee_id" value="<?= $employeeId ?>">
                <input type="hidden" name="qualification_id" value="<?= $qualificationId ?>">
                <button type="submit" class="button-secondary button-danger"><?= h(t('employee_qualification.delete')) ?></button>
            </form>
        </div>
    <?php endif; ?>
</div>

<?php render_footer(); ?>
