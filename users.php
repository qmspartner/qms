<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

$user = require_role(['admin']);
$db = get_db();

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $role = $_POST['role'] ?? 'user';
    $gqarContracts = array_values(array_filter((array) ($_POST['gqar_contracts'] ?? [])));
    $allowedRoles = ['admin', 'editor', 'approver', 'user', 'gqar'];

    if ($name === '' || $email === '' || strlen($password) < 8) {
        $error = t('users.error.create');
    } elseif (!in_array($role, $allowedRoles, true)) {
        $error = t('users.error.role');
    } elseif ($role === 'gqar' && !$gqarContracts) {
        $error = t('users.error.gqar_contract_required');
    } else {
        try {
            $stmt = $db->prepare('INSERT INTO users (organization_id, name, email, password_hash, role) VALUES (?, ?, ?, ?, ?)');
            $stmt->execute([$user['organization_id'], $name, $email, password_hash($password, PASSWORD_DEFAULT), $role]);
            if ($role === 'gqar') {
                $newUserId = (int) $db->lastInsertId();
                save_entity_contracts('gqar_contracts', 'user_id', $newUserId, $gqarContracts);
            }
        } catch (PDOException $e) {
            $error = t('users.error.exists');
        }
    }
}

$stmt = $db->prepare('SELECT id, name, email, role, active, deactivation_requested_at FROM users WHERE organization_id = ? ORDER BY name');
$stmt->execute([$user['organization_id']]);
$users = $stmt->fetchAll();

$messages = [
    'updated' => t('users.msg.updated'),
    'deleted' => t('users.msg.deleted'),
    'deactivated' => t('users.msg.deactivated'),
    'offboarding_cancelled' => t('offboard.msg.cancelled'),
];
$msg = $_GET['msg'] ?? '';

render_header(t('users.title'));
?>
<div class="page-heading"><h1><?= h(t('users.title')) ?></h1></div>

<?php if ($error): ?><p class="alert alert-danger"><?= h($error) ?></p><?php endif; ?>
<?php if ($msg && isset($messages[$msg])): ?><p class="alert alert-success"><?= h($messages[$msg]) ?></p><?php endif; ?>

<div class="card">
    <h2><?= h(t('users.create_title')) ?></h2>
    <form method="post" class="inline-grid-form" data-preserve="newuser">
    <?= csrf_field() ?>
        <input type="text" name="name" placeholder="<?= h(t('users.field.name_ph')) ?>" required>
        <input type="email" name="email" placeholder="<?= h(t('users.field.email_ph')) ?>" required>
        <input type="password" name="password" placeholder="<?= h(t('users.field.password_ph')) ?>" required minlength="8">
        <select name="role">
            <option value="user"><?= h(role_label('user')) ?></option>
            <option value="editor"><?= h(role_label('editor')) ?></option>
            <option value="approver"><?= h(role_label('approver')) ?></option>
            <option value="admin"><?= h(role_label('admin')) ?></option>
            <option value="gqar"><?= h(role_label('gqar')) ?></option>
        </select>
        <select name="gqar_contracts[]" multiple size="3" title="<?= h(t('users.field.contract_reference')) ?>">
            <?php foreach (list_active_values('contract_reference') as $ref): ?>
                <option value="<?= h($ref) ?>"><?= h($ref) ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit"><?= h(t('users.create')) ?></button>
    </form>
</div>

<table class="doc-table">
    <thead><tr>
        <th><?= h(t('users.table.name')) ?></th>
        <th><?= h(t('users.table.email')) ?></th>
        <th><?= h(t('users.table.role')) ?></th>
        <th><?= h(t('users.table.status')) ?></th>
        <th><?= h(t('users.table.actions')) ?></th>
    </tr></thead>
    <tbody>
    <?php foreach ($users as $u): ?>
        <tr>
            <td><?= h($u['name']) ?></td>
            <td><?= h($u['email']) ?></td>
            <td><?= h(role_label($u['role'])) ?></td>
            <td>
                <?php if ($u['deactivation_requested_at']): ?>
                    <span class="badge badge-warning"><?= h(t('offboard.pending_badge')) ?></span>
                <?php else: ?>
                    <?= $u['active'] ? h(t('users.active')) : h(t('users.inactive')) ?>
                <?php endif; ?>
            </td>
            <td class="timeline-actions">
                <?php if ($u['deactivation_requested_at']): ?>
                    <a href="offboard_user.php?id=<?= (int) $u['id'] ?>"><?= h(t('offboard.action.review')) ?></a>
                    <form method="post" action="offboard_user.php" class="inline-form" onsubmit="return confirm('<?= h(t('offboard.cancel_confirm')) ?>');">
    <?= csrf_field() ?>
                        <input type="hidden" name="action" value="cancel">
                        <input type="hidden" name="target_user_id" value="<?= (int) $u['id'] ?>">
                        <button type="submit" class="button-small button-secondary"><?= h(t('offboard.action.cancel')) ?></button>
                    </form>
                <?php else: ?>
                    <a href="edit_user.php?id=<?= (int) $u['id'] ?>"><?= h(t('users.edit')) ?></a>
                    <?php if ($u['role'] !== 'admin' && $u['active']): ?>
                        <a href="start_impersonation.php?user_id=<?= (int) $u['id'] ?>" onclick="return confirm('<?= h(t('impersonation.confirm', ['name' => $u['name']])) ?>');"><?= h(t('impersonation.view_as')) ?></a>
                    <?php endif; ?>
                    <?php if ((int) $u['id'] !== (int) $user['id']): ?>
                        <form method="post" action="delete_user.php" class="inline-form"
                              onsubmit="return confirm('<?= h(t('users.delete_confirm')) ?>
');">
    <?= csrf_field() ?>
                            <input type="hidden" name="user_id" value="<?= (int) $u['id'] ?>">
                            <button type="submit" class="button-small button-danger"><?= h(t('users.delete')) ?></button>
                        </form>
                    <?php else: ?>
                        <span class="muted"><?= h(t('users.self')) ?></span>
                    <?php endif; ?>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php render_footer(); ?>
