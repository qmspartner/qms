<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

$user = require_role(['admin']);
$db = get_db();

$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$stmt = $db->prepare('SELECT * FROM users WHERE id = ? AND organization_id = ?');
$stmt->execute([$id, $user['organization_id']]);
$editUser = $stmt->fetch();

if (!$editUser) {
    http_response_code(404);
    render_header(t('edituser.not_found'));
    echo '<div class="card">' . h(t('edituser.not_found')) . '</div>';
    render_footer();
    exit;
}

$isLocked = (int) $editUser['active'] === 0 && !$editUser['deactivation_requested_at'];

$stmt = $db->prepare('SELECT id, name, active FROM users WHERE organization_id = ? ORDER BY name');
$stmt->execute([$user['organization_id']]);
$allOrgUsers = $stmt->fetchAll();
$userNameById = array_column($allOrgUsers, 'name', 'id');
$managerOptions = array_values(array_filter($allOrgUsers, fn($u) => (int) $u['active'] === 1 && (int) $u['id'] !== (int) $editUser['id']));

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'update';

    if ($action === 'reactivate') {
        if (!$isLocked) {
            $error = t('offboard.error.locked');
        } else {
            $stmt = $db->prepare('UPDATE users SET active = 1, deactivation_requested_at = NULL, deactivation_requested_by = NULL WHERE id = ? AND organization_id = ?');
            $stmt->execute([$editUser['id'], $user['organization_id']]);
            log_offboarding_action('user', (int) $editUser['id'], 'user', (int) $editUser['id'], $editUser['name'], 'reactivated', null, null, $user);

            header('Location: edit_user.php?id=' . $editUser['id'] . '&msg=reactivated');
            exit;
        }
    } elseif ($action === 'update' && $isLocked) {
        $error = t('offboard.error.locked');
    } elseif ($action === 'update') {
        $name = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $role = $_POST['role'] ?? 'user';
        $active = isset($_POST['active']) ? 1 : 0;
        $newPassword = $_POST['password'] ?? '';
        $gqarContracts = array_values(array_filter((array) ($_POST['gqar_contracts'] ?? [])));
        $allowedRoles = ['admin', 'editor', 'approver', 'user', 'gqar'];

        $isSelf = (int) $editUser['id'] === (int) $user['id'];

        $managerUserId = (int) ($_POST['manager_user_id'] ?? 0) ?: null;
        if ($managerUserId === (int) $editUser['id']) {
            $managerUserId = null;
        }

        if ($name === '' || $email === '') {
            $error = t('edituser.error.required');
        } elseif (!in_array($role, $allowedRoles, true)) {
            $error = t('edituser.error.role');
        } elseif ($isSelf && ($role !== 'admin' || $active === 0)) {
            $error = t('edituser.error.self');
        } elseif ($newPassword !== '' && strlen($newPassword) < 8) {
            $error = t('edituser.error.password_length');
        } elseif ($role === 'gqar' && !$gqarContracts) {
            $error = t('users.error.gqar_contract_required');
        } else {
            $changes = [];
            if ($name !== $editUser['name']) {
                $changes[] = ['Navn ændret', $editUser['name'] . ' → ' . $name];
            }
            if ($email !== $editUser['email']) {
                $changes[] = ['E-mail ændret', $editUser['email'] . ' → ' . $email];
            }
            if ($role !== $editUser['role']) {
                $changes[] = ['Rolle ændret', role_label($editUser['role']) . ' → ' . role_label($role)];
            }
            $oldManagerId = (int) ($editUser['manager_user_id'] ?? 0);
            if ($oldManagerId !== (int) $managerUserId) {
                $oldManagerName = $oldManagerId ? ($userNameById[$oldManagerId] ?? '—') : '—';
                $newManagerName = $managerUserId ? ($userNameById[$managerUserId] ?? '—') : '—';
                $changes[] = ['Nærmeste leder ændret', $oldManagerName . ' → ' . $newManagerName];
            }
            if ($newPassword !== '') {
                $changes[] = ['Adgangskode nulstillet', ''];
            }

            try {
                if ($editUser['deactivation_requested_at']) {
                    // Under fratrædelse-gennemgang styres aktiv-status fra offboard_user.php, ikke herfra.
                    if ($newPassword !== '') {
                        $stmt = $db->prepare('UPDATE users SET name = ?, email = ?, role = ?, manager_user_id = ?, password_hash = ? WHERE id = ? AND organization_id = ?');
                        $stmt->execute([$name, $email, $role, $managerUserId, password_hash($newPassword, PASSWORD_DEFAULT), $editUser['id'], $user['organization_id']]);
                    } else {
                        $stmt = $db->prepare('UPDATE users SET name = ?, email = ?, role = ?, manager_user_id = ? WHERE id = ? AND organization_id = ?');
                        $stmt->execute([$name, $email, $role, $managerUserId, $editUser['id'], $user['organization_id']]);
                    }
                    save_entity_contracts('gqar_contracts', 'user_id', (int) $editUser['id'], $role === 'gqar' ? $gqarContracts : []);
                    foreach ($changes as [$changeAction, $changeDetails]) {
                        log_user_action((int) $editUser['id'], $changeAction, $changeDetails);
                    }
                    header('Location: edit_user.php?id=' . $editUser['id']);
                    exit;
                }

                if ((int) $editUser['active'] === 1 && $active === 0) {
                    $openItems = get_user_open_items($db, $editUser);
                    if ($openItems) {
                        if ($newPassword !== '') {
                            $stmt = $db->prepare('UPDATE users SET name = ?, email = ?, role = ?, manager_user_id = ?, password_hash = ?, deactivation_requested_at = NOW(), deactivation_requested_by = ? WHERE id = ? AND organization_id = ?');
                            $stmt->execute([$name, $email, $role, $managerUserId, password_hash($newPassword, PASSWORD_DEFAULT), $user['id'], $editUser['id'], $user['organization_id']]);
                        } else {
                            $stmt = $db->prepare('UPDATE users SET name = ?, email = ?, role = ?, manager_user_id = ?, deactivation_requested_at = NOW(), deactivation_requested_by = ? WHERE id = ? AND organization_id = ?');
                            $stmt->execute([$name, $email, $role, $managerUserId, $user['id'], $editUser['id'], $user['organization_id']]);
                        }
                        save_entity_contracts('gqar_contracts', 'user_id', (int) $editUser['id'], $role === 'gqar' ? $gqarContracts : []);
                        foreach ($changes as [$changeAction, $changeDetails]) {
                            log_user_action((int) $editUser['id'], $changeAction, $changeDetails);
                        }
                        log_offboarding_action('user', (int) $editUser['id'], 'request', (int) $editUser['id'], $name, 'requested', null, null, $user);
                        header('Location: offboard_user.php?id=' . $editUser['id']);
                        exit;
                    }
                }

                if ($newPassword !== '') {
                    $stmt = $db->prepare('UPDATE users SET name = ?, email = ?, role = ?, manager_user_id = ?, active = ?, password_hash = ? WHERE id = ? AND organization_id = ?');
                    $stmt->execute([$name, $email, $role, $managerUserId, $active, password_hash($newPassword, PASSWORD_DEFAULT), $editUser['id'], $user['organization_id']]);
                } else {
                    $stmt = $db->prepare('UPDATE users SET name = ?, email = ?, role = ?, manager_user_id = ?, active = ? WHERE id = ? AND organization_id = ?');
                    $stmt->execute([$name, $email, $role, $managerUserId, $active, $editUser['id'], $user['organization_id']]);
                }
                save_entity_contracts('gqar_contracts', 'user_id', (int) $editUser['id'], $role === 'gqar' ? $gqarContracts : []);
                foreach ($changes as [$changeAction, $changeDetails]) {
                    log_user_action((int) $editUser['id'], $changeAction, $changeDetails);
                }
                if ((int) $editUser['active'] === 1 && $active === 0) {
                    log_offboarding_action('user', (int) $editUser['id'], 'deactivation', (int) $editUser['id'], $name, 'deactivated', null, null, $user);
                }
                header('Location: users.php?msg=updated');
                exit;
            } catch (PDOException $e) {
                $error = t('edituser.error.update_failed');
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
                        INSERT INTO user_attachments (organization_id, target_user_id, label, stored_filename, original_filename, uploaded_by)
                        VALUES (?, ?, ?, ?, ?, ?)
                    ');
                    $stmt->execute([$user['organization_id'], $editUser['id'], $label, $storedName, $file['name'], $user['id']]);
                    log_user_action((int) $editUser['id'], 'Fil vedhæftet', $label . ' (' . $file['name'] . ')');

                    header('Location: edit_user.php?id=' . $editUser['id'] . '&msg=attachment_added');
                    exit;
                }
            }
        }
    }
}

$stmt = $db->prepare('
    SELECT a.*, u.name AS uploader_name
    FROM user_attachments a
    JOIN users u ON u.id = a.uploaded_by
    WHERE a.target_user_id = ? AND a.organization_id = ?
    ORDER BY a.created_at DESC
');
$stmt->execute([$editUser['id'], $user['organization_id']]);
$attachments = $stmt->fetchAll();

render_header(t('edituser.title'));
?>
<?php if ($editUser['deactivation_requested_at']): ?>
    <p class="alert alert-warning">
        <?= h(t('offboard.employee.pending_banner', ['date' => format_date(substr($editUser['deactivation_requested_at'], 0, 10))])) ?>
        <a href="offboard_user.php?id=<?= (int) $editUser['id'] ?>"><?= h(t('offboard.action.review')) ?></a>
    </p>
<?php elseif ($isLocked): ?>
    <p class="alert alert-warning">
        <?= h(t('offboard.user.locked_hint')) ?>
    </p>
<?php endif; ?>
<?php if (($_GET['msg'] ?? '') === 'reactivated'): ?><p class="alert alert-success"><?= h(t('offboard.msg.reactivated')) ?></p><?php endif; ?>
<?php if (($_GET['msg'] ?? '') === 'attachment_added'): ?><p class="alert alert-success"><?= h(t('attachments.msg.added')) ?></p><?php endif; ?>
<div class="card login-card">
    <h1><?= h(t('edituser.title')) ?></h1>
    <?php if ($error): ?><p class="alert alert-danger"><?= h($error) ?></p><?php endif; ?>
    <form method="post">
    <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= (int) $editUser['id'] ?>">
        <input type="hidden" name="action" value="update">
        <label><?= h(t('edituser.field.name')) ?>
            <input type="text" name="name" value="<?= h($editUser['name']) ?>" <?= $isLocked ? 'disabled' : '' ?> required>
        </label>
        <label><?= h(t('edituser.field.email')) ?>
            <input type="email" name="email" value="<?= h($editUser['email']) ?>" <?= $isLocked ? 'disabled' : '' ?> required>
        </label>
        <label><?= h(t('edituser.field.password')) ?>
            <input type="password" name="password" minlength="8" <?= $isLocked ? 'disabled' : '' ?>>
        </label>
        <label><?= h(t('edituser.field.role')) ?>
            <select name="role" id="edit-user-role" <?= $isLocked ? 'disabled' : '' ?>>
                <option value="user" <?= $editUser['role'] === 'user' ? 'selected' : '' ?>><?= h(role_label('user')) ?></option>
                <option value="editor" <?= $editUser['role'] === 'editor' ? 'selected' : '' ?>><?= h(role_label('editor')) ?></option>
                <option value="approver" <?= $editUser['role'] === 'approver' ? 'selected' : '' ?>><?= h(role_label('approver')) ?></option>
                <option value="admin" <?= $editUser['role'] === 'admin' ? 'selected' : '' ?>><?= h(role_label('admin')) ?></option>
                <option value="gqar" <?= $editUser['role'] === 'gqar' ? 'selected' : '' ?>><?= h(role_label('gqar')) ?></option>
            </select>
        </label>
        <?php $currentGqarContracts = get_entity_contracts('gqar_contracts', 'user_id', (int) $editUser['id']); ?>
        <div id="edit-user-gqar-contracts" class="checkbox-group" style="display:none;">
            <span class="muted" style="font-size:12px; display:block; margin-bottom:4px;"><?= h(t('users.field.contract_reference')) ?></span>
            <?php foreach (list_active_values('contract_reference') as $ref): ?>
                <label style="display:flex; align-items:center; gap:6px; font-weight:normal; font-size:13px; margin-bottom:2px;">
                    <input type="checkbox" name="gqar_contracts[]" value="<?= h($ref) ?>" <?= in_array($ref, $currentGqarContracts, true) ? 'checked' : '' ?> <?= $isLocked ? 'disabled' : '' ?> style="width:auto;">
                    <?= h($ref) ?>
                </label>
            <?php endforeach; ?>
        </div>
        <label><?= h(t('users.field.manager')) ?>
            <select name="manager_user_id" <?= $isLocked ? 'disabled' : '' ?>>
                <option value=""><?= h(t('users.field.manager_choose')) ?></option>
                <?php foreach ($managerOptions as $m): ?>
                    <option value="<?= (int) $m['id'] ?>" <?= (int) ($editUser['manager_user_id'] ?? 0) === (int) $m['id'] ? 'selected' : '' ?>><?= h($m['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label style="display:flex; align-items:center; gap:8px; flex-direction:row;">
            <input type="checkbox" name="active" value="1" style="width:auto;" <?= $editUser['active'] ? 'checked' : '' ?> <?= ($editUser['deactivation_requested_at'] || $isLocked) ? 'disabled' : '' ?>>
            <?= h(t('edituser.field.active')) ?>
        </label>
        <?php if ($editUser['deactivation_requested_at']): ?>
            <p class="muted" style="font-size:13px;"><?= h(t('offboard.employee.active_disabled_hint')) ?></p>
        <?php endif; ?>
        <div class="button-row">
            <?php if (!$isLocked): ?>
                <button type="submit"><?= h(t('edituser.save')) ?></button>
            <?php endif; ?>
            <a class="button button-secondary" href="users.php"><?= h(t('edituser.cancel')) ?></a>
        </div>
    </form>
    <?php if ($isLocked): ?>
        <form method="post" class="inline-form" style="margin-top:1rem;"
              onsubmit="return confirm('<?= h(t('offboard.reactivate_confirm', ['name' => $editUser['name']])) ?>');">
    <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= (int) $editUser['id'] ?>">
            <input type="hidden" name="action" value="reactivate">
            <button type="submit" class="button-secondary"><?= h(t('offboard.action.reactivate')) ?></button>
        </form>
    <?php endif; ?>
</div>

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
                <td><a href="download_user_attachment.php?id=<?= (int) $a['id'] ?>"><?= h($a['original_filename']) ?></a></td>
                <td class="muted" style="font-size:13px;"><?= h($a['uploader_name']) ?> · <?= h(format_date(substr($a['created_at'], 0, 10))) ?></td>
                <td>
                    <?php if (!$isLocked): ?>
                        <form method="post" action="delete_user_attachment.php" class="inline-form" onsubmit="return confirm('<?= h(t('attachments.delete_confirm')) ?>');">
    <?= csrf_field() ?>
                            <input type="hidden" name="id" value="<?= (int) $a['id'] ?>">
                            <input type="hidden" name="user_id" value="<?= (int) $editUser['id'] ?>">
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
        <input type="hidden" name="id" value="<?= (int) $editUser['id'] ?>">
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

<script>
(function () {
    var roleSelect = document.getElementById('edit-user-role');
    var contractsGroup = document.getElementById('edit-user-gqar-contracts');
    if (!roleSelect || !contractsGroup) {
        return;
    }
    function toggle() {
        contractsGroup.style.display = roleSelect.value === 'gqar' ? '' : 'none';
    }
    roleSelect.addEventListener('change', toggle);
    toggle();
})();
</script>

<?php render_footer(); ?>
