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
<div class="login-wrap">

    <!-- Venstre: "titelblok"-stempel-sprog fra teknisk dokumentation, jf.
         godkendt mock-up (Retning A), her med sitets eget system-font i
         stedet for mock-uppens display-skrifttype. -->
    <div class="login-hero">
        <div class="login-stamp-block" aria-hidden="true">
            <div class="login-stamp-doc"></div>
            <div class="login-stamp-line login-stamp-line1"></div>
            <div class="login-stamp-line login-stamp-line2"></div>
            <div class="login-stamp-line login-stamp-line3"></div>
            <div class="login-stamp-badge">
                <span><?= h(t('login.hero.stamp')) ?><br><small><?= h(t('login.hero.stamp_rev')) ?></small></span>
            </div>
        </div>
        <div>
            <h1><?= h(t('login.hero.headline')) ?></h1>
            <p><?= h(t('login.hero.subtitle')) ?></p>
        </div>
    </div>

    <!-- Højre: login-titelblok -->
    <div class="login-formside">
        <div class="login-formside-inner">
            <div class="login-titleblock">
                <div class="login-titleblock-head">
                    <span><?= h(t('login.title')) ?></span>
                    <span>qmspartner.dk</span>
                </div>
                <form method="post">
                <?= csrf_field() ?>
                    <?php if ($error): ?>
                        <p class="alert alert-danger"><?= h($error) ?></p>
                    <?php endif; ?>
                    <label><?= h(t('login.email')) ?>
                        <input type="email" name="email" placeholder="navn@virksomhed.dk" required autofocus>
                    </label>
                    <label><?= h(t('login.password')) ?>
                        <input type="password" name="password" required>
                    </label>
                    <button type="submit"><?= h(t('login.submit')) ?></button>
                </form>
            </div>
            <div class="login-links-row">
                <a href="forgot_password.php"><?= h(t('login.forgot_password')) ?></a>
                <a href="https://qmspartner.dk" class="login-back">← <?= h(t('login.back_to_site')) ?></a>
            </div>
        </div>
    </div>

</div>
<?php render_footer(); ?>
