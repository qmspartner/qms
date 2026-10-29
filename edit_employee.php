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

$isLocked = (int) $employee['active'] === 0 && !$employee['deactivation_requested_at'];

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'update';

    if ($action === 'reactivate') {
        if (!$isLocked) {
            $error = t('offboard.error.locked');
        } else {
            $stmt = $db->prepare('UPDATE employees SET active = 1, deactivation_requested_at = NULL, deactivation_requested_by = NULL WHERE id = ? AND organization_id = ?');
            $stmt->execute([$id, $user['organization_id']]);
            log_employee_action($id, 'Medarbejder genaktiveret');
            log_offboarding_action('employee', $id, 'employee', $id, $employee['name'], 'reactivated', null, null, $user);

            header('Location: edit_employee.php?id=' . $id . '&msg=reactivated');
            exit;
        }
    } elseif ($action === 'update' && $isLocked) {
        $error = t('offboard.error.locked');
    } elseif ($action === 'update') {
        $name = trim($_POST['name'] ?? '');
        $title = trim($_POST['title'] ?? '');
        $department = trim($_POST['department'] ?? '');
        $managerUserId = (int) ($_POST['manager_user_id'] ?? 0) ?: null;
        $active = isset($_POST['active']) ? 1 : 0;

        if ($name === '') {
            $error = t('employees.error.required');
        } elseif ($employee['deactivation_requested_at']) {
            // Under fratrædelse-gennemgang styres aktiv-status fra offboard_employee.php, ikke herfra.
            $stmt = $db->prepare('UPDATE employees SET name = ?, title = ?, department = ?, manager_user_id = ? WHERE id = ? AND organization_id = ?');
            $stmt->execute([$name, $title ?: null, $department ?: null, $managerUserId, $id, $user['organization_id']]);
            log_employee_action($id, 'Medarbejder opdateret');

            header('Location: edit_employee.php?id=' . $id);
            exit;
        } else {
            if ((int) $employee['active'] === 1 && $active === 0) {
                $openItems = get_employee_open_items($db, $employee);
                if ($openItems) {
                    $stmt = $db->prepare('UPDATE employees SET name = ?, title = ?, department = ?, manager_user_id = ?, deactivation_requested_at = NOW(), deactivation_requested_by = ? WHERE id = ? AND organization_id = ?');
                    $stmt->execute([$name, $title ?: null, $department ?: null, $managerUserId, $user['id'], $id, $user['organization_id']]);
                    log_employee_action($id, 'Fratrædelse anmodet');
                    log_offboarding_action('employee', $id, 'request', $id, $name, 'requested', null, null, $user);

                    header('Location: edit_employee.php?id=' . $id . '&msg=offboarding_requested');
                    exit;
                }
            }

            $stmt = $db->prepare('UPDATE employees SET name = ?, title = ?, department = ?, manager_user_id = ?, active = ? WHERE id = ? AND organization_id = ?');
            $stmt->execute([$name, $title ?: null, $department ?: null, $managerUserId, $active, $id, $user['organization_id']]);
            log_employee_action($id, 'Medarbejder opdateret');
            if ((int) $employee['active'] === 1 && $active === 0) {
                log_offboarding_action('employee', $id, 'deactivation', $id, $name, 'deactivated', null, null, $user);
            }

            header('Location: edit_employee.php?id=' . $id);
            exit;
        }
    } elseif ($action === 'add_qualification' && $isLocked) {
        $error = t('offboard.error.locked');
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
    } elseif ($action === 'add_attachment' && $isLocked) {
        $error = t('offboard.error.locked');
    } elseif ($action === 'add_attachment') {
        $label = trim($_POST['label'] ?? '');
        $file = $_FILES['attachment'] ?? null;

        if ($label === '') {
            $error = t('attachments.error.label_required');
        } elseif (!$file || ($file['error'] === UPLOAD_ERR_NO_FILE)) {
            $error = t('upload.error.choose_file');
        } elseif ($file['error'] !== UPLOAD_ERR_OK) {
            $error = t('upload.error.generic');
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
                } else {
                    $stmt = $db->prepare('
                        INSERT INTO employee_attachments (organization_id, target_employee_id, label, stored_filename, original_filename, uploaded_by)
                        VALUES (?, ?, ?, ?, ?, ?)
                    ');
                    $stmt->execute([$user['organization_id'], $id, $label, $storedName, $file['name'], $user['id']]);
                    log_employee_action($id, 'Fil vedhæftet', $label . ' (' . $file['name'] . ')');

                    header('Location: edit_employee.php?id=' . $id . '&msg=attachment_added');
                    exit;
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

$stmt = $db->prepare('
    SELECT a.*, u.name AS uploader_name
    FROM employee_attachments a
    JOIN users u ON u.id = a.uploaded_by
    WHERE a.target_employee_id = ? AND a.organization_id = ?
    ORDER BY a.created_at DESC
');
$stmt->execute([$id, $user['organization_id']]);
$attachments = $stmt->fetchAll();

render_header($employee['name']);
?>
<p class="breadcrumb"><a href="competence.php"><?= h(t('competence.title')) ?></a> &raquo; <a href="employees.php"><?= h(t('employees.title')) ?></a> &raquo; <?= h($employee['name']) ?></p>

<?php if ($error): ?><p class="alert alert-danger"><?= h($error) ?></p><?php endif; ?>
<?php if (($_GET['msg'] ?? '') === 'offboarding_requested'): ?><p class="alert alert-success"><?= h(t('offboard.msg.requested')) ?></p><?php endif; ?>
<?php if (($_GET['msg'] ?? '') === 'offboarding_cancelled'): ?><p class="alert alert-success"><?= h(t('offboard.msg.cancelled')) ?></p><?php endif; ?>
<?php if (($_GET['msg'] ?? '') === 'deactivated'): ?><p class="alert alert-success"><?= h(t('offboard.msg.deactivated')) ?></p><?php endif; ?>
<?php if (($_GET['msg'] ?? '') === 'reactivated'): ?><p class="alert alert-success"><?= h(t('offboard.msg.reactivated')) ?></p><?php endif; ?>
<?php if (($_GET['msg'] ?? '') === 'attachment_added'): ?><p class="alert alert-success"><?= h(t('attachments.msg.added')) ?></p><?php endif; ?>

<?php if ($employee['deactivation_requested_at']): ?>
    <p class="alert alert-warning">
        <?= h(t('offboard.employee.pending_banner', ['date' => format_date(substr($employee['deactivation_requested_at'], 0, 10))])) ?>
        <a href="offboard_employee.php?id=<?= (int) $employee['id'] ?>"><?= h(t('offboard.action.review')) ?></a>
    </p>
<?php elseif ($isLocked): ?>
    <p class="alert alert-warning">
        <?= h(t('offboard.employee.locked_hint')) ?>
    </p>
<?php endif; ?>

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
            <input type="text" name="name" value="<?= h($employee['name']) ?>" <?= $isLocked ? 'disabled' : '' ?> required>
        </label>
        <label><?= h(t('employees.field.title_ph')) ?>
            <input type="text" name="title" value="<?= h($employee['title'] ?? '') ?>" <?= $isLocked ? 'disabled' : '' ?>>
        </label>
        <label><?= h(t('employees.field.department_ph')) ?>
            <input type="text" name="department" value="<?= h($employee['department'] ?? '') ?>" <?= $isLocked ? 'disabled' : '' ?>>
        </label>
        <label><?= h(t('employees.field.manager')) ?>
            <select name="manager_user_id" <?= $isLocked ? 'disabled' : '' ?>>
                <option value=""><?= h(t('employees.field.manager_choose')) ?></option>
                <?php foreach ($managerOptions as $m): ?>
                    <option value="<?= (int) $m['id'] ?>" <?= (int) $employee['manager_user_id'] === (int) $m['id'] ? 'selected' : '' ?>><?= h($m['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label style="display:flex; align-items:center; gap:8px; flex-direction:row;">
            <input type="checkbox" name="active" value="1" <?= $employee['active'] ? 'checked' : '' ?> <?= ($employee['deactivation_requested_at'] || $isLocked) ? 'disabled' : '' ?> style="width:auto;">
            <?= h(t('employees.field.active')) ?>
        </label>
        <?php if ($employee['deactivation_requested_at']): ?>
            <p class="muted" style="font-size:13px;"><?= h(t('offboard.employee.active_disabled_hint')) ?></p>
        <?php endif; ?>
        <?php if (!$isLocked): ?>
            <button type="submit"><?= h(t('employees.save')) ?></button>
        <?php endif; ?>
    </form>

    <div class="button-row">
        <?php if ($isLocked): ?>
            <form method="post" class="inline-form"
                  onsubmit="return confirm('<?= h(t('offboard.reactivate_confirm', ['name' => $employee['name']])) ?>');">
    <?= csrf_field() ?>
                <input type="hidden" name="action" value="reactivate">
                <button type="submit" class="button-secondary"><?= h(t('offboard.action.reactivate')) ?></button>
            </form>
        <?php endif; ?>
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

<?php if ($availableQualifications && !$isLocked): ?>
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

<h2><?= h(t('attachments.title')) ?></h2>
<div class="card">
    <table class="doc-table">
        <thead><tr>
            <th><?= h(t('attachments.col.label')) ?></th>
            <th><?= h(t('attachments.col.file')) ?></th>
            <th><?= h(t('attachments.col.uploaded')) ?></th>
            <th></th>
        </tr></thead>
        <tbody>
        <?php foreach ($attachments as $a): ?>
            <tr>
                <td><?= h($a['label']) ?></td>
                <td><a href="download_employee_attachment.php?id=<?= (int) $a['id'] ?>"><?= h($a['original_filename']) ?></a></td>
                <td class="muted" style="font-size:13px;"><?= h($a['uploader_name']) ?> · <?= h(format_date(substr($a['created_at'], 0, 10))) ?></td>
                <td>
                    <?php if (!$isLocked): ?>
                        <form method="post" action="delete_employee_attachment.php" class="inline-form" onsubmit="return confirm('<?= h(t('attachments.delete_confirm')) ?>');">
    <?= csrf_field() ?>
                            <input type="hidden" name="id" value="<?= (int) $a['id'] ?>">
                            <input type="hidden" name="employee_id" value="<?= (int) $id ?>">
                            <button type="submit" class="button-small button-danger"><?= h(t('attachments.delete')) ?></button>
                        </form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$attachments): ?>
            <tr><td colspan="4" class="muted"><?= h(t('attachments.empty')) ?></td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<?php if (!$isLocked): ?>
<h2><?= h(t('attachments.add')) ?></h2>
<div class="card">
    <form method="post" enctype="multipart/form-data">
    <?= csrf_field() ?>
        <input type="hidden" name="action" value="add_attachment">
        <label><?= h(t('attachments.field.label')) ?>
            <input type="text" name="label" required>
        </label>
        <label><?= h(t('attachments.field.file')) ?>
            <input type="file" name="attachment" required>
        </label>
        <button type="submit"><?= h(t('attachments.add')) ?></button>
    </form>
</div>
<?php endif; ?>

<?php render_footer(); ?>
