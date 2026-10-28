<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

$db = get_db();
$token = $_GET['token'] ?? $_POST['token'] ?? '';
$tokenHash = $token !== '' ? hash('sha256', $token) : '';

$validUser = null;
if ($tokenHash !== '') {
    $stmt = $db->prepare('SELECT * FROM users WHERE reset_token_hash = ? AND reset_token_expires > NOW() AND active = 1');
    $stmt->execute([$tokenHash]);
    $validUser = $stmt->fetch();
}

$error = '';
$success = false;

if ($validUser && $_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $password = $_POST['password'] ?? '';
    $passwordConfirm = $_POST['password_confirm'] ?? '';

    if (strlen($password) < 8) {
        $error = t('resetpw.error.too_short');
    } elseif ($password !== $passwordConfirm) {
        $error = t('resetpw.error.mismatch');
    } else {
        $stmt = $db->prepare('UPDATE users SET password_hash = ?, reset_token_hash = NULL, reset_token_expires = NULL WHERE id = ?');
        $stmt->execute([password_hash($password, PASSWORD_DEFAULT), $validUser['id']]);
        $stmt = $db->prepare('INSERT INTO user_log (organization_id, target_user_id, action, details, performed_by) VALUES (?, ?, ?, ?, ?)');
        $stmt->execute([$validUser['organization_id'], $validUser['id'], 'Adgangskode nulstillet (selvbetjening)', '', $validUser['id']]);
        $success = true;
    }
}

render_header(t('resetpw.title'));
?>
<div class="card login-card">
    <h1><?= h(t('resetpw.title')) ?></h1>
    <?php if (!$validUser): ?>
        <p class="alert alert-danger"><?= h(t('resetpw.error.invalid_token')) ?></p>
        <a class="button" href="forgot_password.php"><?= h(t('forgotpw.title')) ?></a>
    <?php elseif ($success): ?>
        <p class="alert alert-success"><?= h(t('resetpw.success')) ?></p>
        <a class="button" href="login.php"><?= h(t('resetpw.go_to_login')) ?></a>
    <?php else: ?>
        <?php if ($error): ?><p class="alert alert-danger"><?= h($error) ?></p><?php endif; ?>
        <form method="post">
    <?= csrf_field() ?>
            <input type="hidden" name="token" value="<?= h($token) ?>">
            <label><?= h(t('resetpw.new_password')) ?>
                <input type="password" name="password" required minlength="8">
            </label>
            <label><?= h(t('resetpw.confirm_password')) ?>
                <input type="password" name="password_confirm" required minlength="8">
            </label>
            <button type="submit"><?= h(t('resetpw.submit')) ?></button>
        </form>
    <?php endif; ?>
</div>
<?php render_footer(); ?>
