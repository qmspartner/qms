<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

$user = require_role(['admin']);
$db = get_db();

$roles = permission_roles();
$modules = permission_modules();
$selectedRole = $_GET['role'] ?? $_POST['role'] ?? 'editor';
if (!in_array($selectedRole, $roles, true)) {
    $selectedRole = 'editor';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $stmt = $db->prepare('
        INSERT INTO role_permissions (organization_id, role, module, can_view, can_create, can_edit, can_delete)
        VALUES (?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE can_view = VALUES(can_view), can_create = VALUES(can_create), can_edit = VALUES(can_edit), can_delete = VALUES(can_delete)
    ');
    foreach ($modules as $module) {
        $view = isset($_POST['can_view'][$module]) ? 1 : 0;
        $create = isset($_POST['can_create'][$module]) ? 1 : 0;
        $edit = isset($_POST['can_edit'][$module]) ? 1 : 0;
        $delete = isset($_POST['can_delete'][$module]) ? 1 : 0;
        $stmt->execute([$user['organization_id'], $selectedRole, $module, $view, $create, $edit, $delete]);
    }
    header('Location: permissions.php?role=' . $selectedRole . '&saved=1');
    exit;
}

$stmt = $db->prepare('SELECT * FROM role_permissions WHERE organization_id = ? AND role = ?');
$stmt->execute([$user['organization_id'], $selectedRole]);
$current = [];
foreach ($stmt->fetchAll() as $row) {
    $current[$row['module']] = $row;
}

$saved = isset($_GET['saved']);

render_header(t('permissions.title'));
?>
<div class="page-heading">
    <h1><?= h(t('permissions.title')) ?> <?php help_tip('help.tip.access_control'); ?></h1>
</div>
<p class="muted" style="margin:-8px 0 20px;"><?= h(t('permissions.description')) ?></p>

<?php if ($saved): ?>
    <p class="alert alert-success"><?= h(t('permissions.saved')) ?></p>
<?php endif; ?>

<div class="tab-bar">
    <?php foreach ($roles as $r): ?>
        <a href="permissions.php?role=<?= h($r) ?>" class="tab <?= $selectedRole === $r ? 'tab-active' : '' ?>"><?= h(role_label($r)) ?></a>
    <?php endforeach; ?>
</div>

<p class="muted" style="margin:14px 0;"><?= h(t('permissions.admin_note')) ?></p>

<form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="role" value="<?= h($selectedRole) ?>">
    <div class="card">
        <table class="doc-table">
            <thead>
            <tr>
                <th><?= h(t('permissions.col.module')) ?></th>
                <th style="text-align:center; width:80px;"><?= h(t('permissions.col.view')) ?></th>
                <th style="text-align:center; width:80px;"><?= h(t('permissions.col.create')) ?></th>
                <th style="text-align:center; width:80px;"><?= h(t('permissions.col.edit')) ?></th>
                <th style="text-align:center; width:80px;"><?= h(t('permissions.col.delete')) ?></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($modules as $module): ?>
                <?php $row = $current[$module] ?? ['can_view' => 0, 'can_create' => 0, 'can_edit' => 0, 'can_delete' => 0]; ?>
                <tr>
                    <td><?= h(permission_module_label($module)) ?></td>
                    <td style="text-align:center;"><input type="checkbox" name="can_view[<?= h($module) ?>]" value="1" <?= $row['can_view'] ? 'checked' : '' ?> style="width:auto;"></td>
                    <td style="text-align:center;"><input type="checkbox" name="can_create[<?= h($module) ?>]" value="1" <?= $row['can_create'] ? 'checked' : '' ?> style="width:auto;"></td>
                    <td style="text-align:center;"><input type="checkbox" name="can_edit[<?= h($module) ?>]" value="1" <?= $row['can_edit'] ? 'checked' : '' ?> style="width:auto;"></td>
                    <td style="text-align:center;"><input type="checkbox" name="can_delete[<?= h($module) ?>]" value="1" <?= $row['can_delete'] ? 'checked' : '' ?> style="width:auto;"></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <button type="submit" style="margin-top:16px;"><?= h(t('permissions.save')) ?></button>
</form>

<?php render_footer(); ?>
