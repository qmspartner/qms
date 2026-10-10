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

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $role = $_POST['role'] ?? 'user';
    $active = isset($_POST['active']) ? 1 : 0;
    $newPassword = $_POST['password'] ?? '';
    $gqarContracts = array_values(array_filter((array) ($_POST['gqar_contracts'] ?? [])));
    $allowedRoles = ['admin', 'editor', 'approver', 'user', 'gqar'];

    $isSelf = (int) $editUser['id'] === (int) $user['id'];

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
        try {
            if ($newPassword !== '') {
                $stmt = $db->prepare('UPDATE users SET name = ?, email = ?, role = ?, active = ?, password_hash = ? WHERE id = ? AND organization_id = ?');
                $stmt->execute([$name, $email, $role, $active, password_hash($newPassword, PASSWORD_DEFAULT), $editUser['id'], $user['organization_id']]);
            } else {
                $stmt = $db->prepare('UPDATE users SET name = ?, email = ?, role = ?, active = ? WHERE id = ? AND organization_id = ?');
                $stmt->execute([$name, $email, $role, $active, $editUser['id'], $user['organization_id']]);
            }
            save_entity_contracts('gqar_contracts', 'user_id', (int) $editUser['id'], $role === 'gqar' ? $gqarContracts : []);
            header('Location: users.php?msg=updated');
            exit;
        } catch (PDOException $e) {
            $error = t('edituser.error.update_failed');
        }
    }
}

render_header(t('edituser.title'));
?>
<div class="card login-card">
    <h1><?= h(t('edituser.title')) ?></h1>
    <?php if ($error): ?><p class="alert alert-danger"><?= h($error) ?></p><?php endif; ?>
    <form method="post">
    <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= (int) $editUser['id'] ?>">
        <label><?= h(t('edituser.field.name')) ?>
            <input type="text" name="name" value="<?= h($editUser['name']) ?>" required>
        </label>
        <label><?= h(t('edituser.field.email')) ?>
            <input type="email" name="email" value="<?= h($editUser['email']) ?>" required>
        </label>
        <label><?= h(t('edituser.field.password')) ?>
            <input type="password" name="password" minlength="8">
        </label>
        <label><?= h(t('edituser.field.role')) ?>
            <select name="role">
                <option value="user" <?= $editUser['role'] === 'user' ? 'selected' : '' ?>><?= h(role_label('user')) ?></option>
                <option value="editor" <?= $editUser['role'] === 'editor' ? 'selected' : '' ?>><?= h(role_label('editor')) ?></option>
                <option value="approver" <?= $editUser['role'] === 'approver' ? 'selected' : '' ?>><?= h(role_label('approver')) ?></option>
                <option value="admin" <?= $editUser['role'] === 'admin' ? 'selected' : '' ?>><?= h(role_label('admin')) ?></option>
                <option value="gqar" <?= $editUser['role'] === 'gqar' ? 'selected' : '' ?>><?= h(role_label('gqar')) ?></option>
            </select>
        </label>
        <?php $currentGqarContracts = get_entity_contracts('gqar_contracts', 'user_id', (int) $editUser['id']); ?>
        <label><?= h(t('users.field.contract_reference')) ?>
            <select name="gqar_contracts[]" multiple size="4">
                <?php foreach (list_active_values('contract_reference') as $ref): ?>
                    <option value="<?= h($ref) ?>" <?= in_array($ref, $currentGqarContracts, true) ? 'selected' : '' ?>><?= h($ref) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label style="display:flex; align-items:center; gap:8px; flex-direction:row;">
            <input type="checkbox" name="active" value="1" style="width:auto;" <?= $editUser['active'] ? 'checked' : '' ?>>
            <?= h(t('edituser.field.active')) ?>
        </label>
        <div class="button-row">
            <button type="submit"><?= h(t('edituser.save')) ?></button>
            <a class="button button-secondary" href="users.php"><?= h(t('edituser.cancel')) ?></a>
        </div>
    </form>
</div>
<?php render_footer(); ?>
