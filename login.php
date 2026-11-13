<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

if (current_user()) {
    header('Location: home.php');
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    if ($email === '' || $password === '') {
        $error = t('login.error.empty');
    } elseif (is_login_locked($email, $_SERVER['REMOTE_ADDR'] ?? '')) {
        $error = t('login.error.locked');
    } elseif (attempt_login($email, $password)) {
        header('Location: home.php');
        exit;
    } else {
        $error = t('login.error.invalid');
    }
}

render_header(t('login.title'), true);
?>
<div class="card login-card">
    <p class="login-company"><a href="https://qmspartner.dk">QMSPartner</a> — <?= h(t('brand.name')) ?></p>
    <h1><?= h(t('login.title')) ?></h1>
    <?php if ($error): ?>
        <p class="alert alert-danger"><?= h($error) ?></p>
    <?php endif; ?>
    <form method="post">
    <?= csrf_field() ?>
        <label><?= h(t('login.email')) ?>
            <input type="email" name="email" required autofocus>
        </label>
        <label><?= h(t('login.password')) ?>
            <input type="password" name="password" required>
        </label>
        <button type="submit"><?= h(t('login.submit')) ?></button>
    </form>
    <p style="margin-top:14px;"><a href="forgot_password.php"><?= h(t('login.forgot_password')) ?></a></p>
    <p style="margin-top:14px;"><a href="https://qmspartner.dk"><?= h(t('login.back_to_site')) ?></a></p>
</div>
<?php render_footer(); ?>
