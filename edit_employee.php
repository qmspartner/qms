<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

$user = require_role(['admin']);
$db = get_db();

$id = (int) ($_GET['id'] ?? 0);
$stmt = $db->prepare('SELECT * FROM employees WHERE id = ? AND organization_id = ?');
$stmt->execute([$id, $user['organization_id']]);
$employee = $stmt->fetch();

if (!$employee) {
    http_response_code(404);
    render_header(t('employees.not_found'));
    echo '<div class="card">' . h(t('employees.not_found')) . '</div>';
    render_footer();
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'update';

    if ($action === 'update') {
        $name = trim($_POST['name'] ?? '');
        $title = trim($_POST['title'] ?? '');
        $department = trim($_POST['department'] ?? '');
        $managerUserId = (int) ($_POST['manager_user_id'] ?? 0) ?: null;
        $active = isset($_POST['active']) ? 1 : 0;

        if ($name === '') {
            $error = t('employees.error.required');
        } else {
            $stmt = $db->prepare('UPDATE employees SET name = ?, title = ?, department = ?, manager_user_id = ?, active = ? WHERE id = ? AND organization_id = ?');
            $stmt->execute([$name, $title ?: null, $department ?: null, $managerUserId, $active, $id, $user['organization_id']]);
            log_employee_action($id, 'Medarbejder opdateret');

            header('Location: edit_employee.php?id=' . $id);
            exit;
        }
    } elseif ($action === 'add_qualification') {
        $qualificationId = (int) ($_POST['qualification_id'] ?? 0);
        $plannedDate = trim($_POST['planned_date'] ?? '');
        $obtainedDate = trim($_POST['obtained_date'] ?? '');
        $notes = trim($_POST['notes'] ?? '');

        $stmt = $db->prepare('SELECT * FROM qualifications WHERE id = ? AND organization_id = ? AND active = 1');
        $stmt->execute([$qualificationId, $user['organization_id']]);
        $qualification = $stmt->fetch();

        if (!$qualification) {
            $error = t('employees.error.required');
        } else {
            $expiryDate = null;
            if ($obtainedDate && $qualification['validity_months']) {
                $expiryDate = date('Y-m-d', strtotime($obtainedDate . ' + ' . (int) $qualification['validity_months'] . ' months'));
            }

            $storedName = null;
            $originalName = null;
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
                try {
                    $stmt = $db->prepare('
                        INSERT INTO employee_qualifications
                            (organization_id, employee_id, qualification_id, planned_date, obtained_date, expiry_date, stored_filename, original_filename, notes, created_by)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                        ON DUPLICATE KEY UPDATE
                            planned_date = VALUES(planned_date), obtained_date = VALUES(obtained_date), expiry_date = VALUES(expiry_date),
                            stored_filename = COALESCE(VALUES(stored_filename), stored_filename),
                            original_filename = COALESCE(VALUES(original_filename), original_filename),
                            notes = VALUES(notes)
                    ');
                    $stmt->execute([
                        $user['organization_id'], $id, $qualificationId, $plannedDate ?: null, $obtainedDate ?: null,
                        $expiryDate, $storedName, $originalName, $notes ?: null, $user['id'],
                    ]);
                    log_employee_action($id, 'Kvalifikation tildelt', $qualification['name']);

                    header('Location: edit_employee.php?id=' . $id);
                    exit;
                } catch (PDOException $e) {
                    $error = t('upload.error.generic');
                }
            }
        }
    }
}

$stmt = $db->prepare('
    SELECT eq.*, q.name AS qualification_name
    FROM employee_qualifications eq
    JOIN qualifications q ON q.id = eq.qualification_id
    WHERE eq.employee_id = ? AND eq.organization_id = ?
    ORDER BY q.name
');
$stmt->execute([$id, $user['organization_id']]);
$assignments = $stmt->fetchAll();

$assignedQualificationIds = array_column($assignments, 'qualification_id');
$stmt = $db->prepare('SELECT * FROM qualifications WHERE organization_id = ? AND active = 1 ORDER BY name');
$stmt->execute([$user['organization_id']]);
$allQualifications = $stmt->fetchAll();
$availableQualifications = array_filter($allQualifications, fn($q) => !in_array((int) $q['id'], $assignedQualificationIds, true));

$stmt = $db->prepare("SELECT id, name FROM users WHERE organization_id = ? AND active = 1 ORDER BY name");
$stmt->execute([$user['organization_id']]);
$managerOptions = $stmt->fetchAll();

render_header($employee['name']);
?>
<p class="breadcrumb"><a href="competence.php"><?= h(t('competence.title')) ?></a> &raquo; <a href="employees.php"><?= h(t('employees.title')) ?></a> &raquo; <?= h($employee['name']) ?></p>

<?php if ($error): ?><p class="alert alert-danger"><?= h($error) ?></p><?php endif; ?>

<div class="card">
    <div class="doc-header">
        <div>
            <h1><?= h($employee['name']) ?></h1>
            <p class="muted"><?= h($employee['title'] ?: '—') ?><?= $employee['department'] ? ' · ' . h($employee['department']) : '' ?></p>
        </div>
        <span class="<?= $employee['active'] ? 'badge badge-success' : 'badge badge-neutral' ?>"><?= $employee['active'] ? h(t('employees.active')) : h(t('employees.inactive')) ?></span>
    </div>

    <form method="post" style="margin-top:1rem;">
    <?= csrf_field() ?>
        <input type="hidden" name="action" value="update">
        <label><?= h(t('employees.field.name_ph')) ?>
            <input type="text" name="name" value="<?= h($employee['name']) ?>" required>
        </label>
        <label><?= h(t('employees.field.title_ph')) ?>
            <input type="text" name="title" value="<?= h($employee['title'] ?? '') ?>">
        </label>
        <label><?= h(t('employees.field.department_ph')) ?>
            <input type="text" name="department" value="<?= h($employee['department'] ?? '') ?>">
        </label>
        <label><?= h(t('employees.field.manager')) ?>
            <select name="manager_user_id">
                <option value=""><?= h(t('employees.field.manager_choose')) ?></option>
                <?php foreach ($managerOptions as $m): ?>
                    <option value="<?= (int) $m['id'] ?>" <?= (int) $employee['manager_user_id'] === (int) $m['id'] ? 'selected' : '' ?>><?= h($m['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label style="display:flex; align-items:center; gap:8px; flex-direction:row;">
            <input type="checkbox" name="active" value="1" <?= $employee['active'] ? 'checked' : '' ?> style="width:auto;">
            <?= h(t('employees.field.active')) ?>
        </label>
        <button type="submit"><?= h(t('employees.save')) ?></button>
    </form>

    <div class="button-row">
        <form method="post" action="delete_employee.php" class="inline-form"
              onsubmit="return confirm('<?= h(t('employees.delete_confirm')) ?>
');">
    <?= csrf_field() ?>
            <input type="hidden" name="employee_id" value="<?= (int) $employee['id'] ?>">
            <button type="submit" class="button-secondary button-danger"><?= h(t('employees.delete')) ?></button>
        </form>
    </div>
</div>

<h2><?= h(t('employees.qualifications_title')) ?></h2>
<div class="card">
    <table class="doc-table">
        <thead><tr>
            <th><?= h(t('qualifications.col.name')) ?></th>
            <th><?= h(t('competence.status.valid')) ?></th>
            <th><?= h(t('employee_qualification.field.obtained_date')) ?></th>
            <th><?= h(t('employee_qualification.field.expiry_date')) ?></th>
            <th></th>
        </tr></thead>
        <tbody>
        <?php foreach ($assignments as $a): ?>
            <?php $status = qualification_status($a); ?>
            <tr onclick="window.location='employee_qualification.php?employee_id=<?= $id ?>&qualification_id=<?= (int) $a['qualification_id'] ?>'">
                <td><?= h($a['qualification_name']) ?></td>
                <td><span class="<?= qualification_status_class($status) ?>"><?= h(qualification_status_label($status)) ?></span></td>
                <td><?= $a['obtained_date'] ? format_date($a['obtained_date']) : '—' ?></td>
                <td><?= $a['expiry_date'] ? format_date($a['expiry_date']) : '—' ?></td>
                <td><a href="employee_qualification.php?employee_id=<?= $id ?>&qualification_id=<?= (int) $a['qualification_id'] ?>"><?= h(t('employees.edit')) ?></a></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$assignments): ?>
            <tr><td colspan="5" class="muted"><?= h(t('employees.no_qualifications')) ?></td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<?php if ($availableQualifications): ?>
<h2><?= h(t('employees.add_qualification')) ?></h2>
<div class="card">
    <form method="post" enctype="multipart/form-data">
    <?= csrf_field() ?>
        <input type="hidden" name="action" value="add_qualification">
        <label><?= h(t('employees.field.qualification')) ?>
            <select name="qualification_id" required>
                <option value=""><?= h(t('employees.field.qualification_choose')) ?></option>
                <?php foreach ($availableQualifications as $q): ?>
                    <option value="<?= (int) $q['id'] ?>"><?= h($q['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label><?= h(t('employee_qualification.field.planned_date')) ?>
            <input type="date" name="planned_date">
        </label>
        <label><?= h(t('employee_qualification.field.obtained_date')) ?>
            <input type="date" name="obtained_date">
        </label>
        <label><?= h(t('employee_qualification.field.notes')) ?>
            <textarea name="notes" rows="2"></textarea>
        </label>
        <label><?= h(t('employee_qualification.field.evidence')) ?>
            <input type="file" name="evidence">
        </label>
        <button type="submit"><?= h(t('employees.add_qualification')) ?></button>
    </form>
</div>
<?php endif; ?>

<?php render_footer(); ?>
