<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

if (current_user()) {
    header('Location: home.php');
    exit;
}

$sent = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $email = trim($_POST['email'] ?? '');

    if ($email !== '') {
        $db = get_db();
        $stmt = $db->prepare('SELECT * FROM users WHERE email = ? AND active = 1');
        $stmt->execute([$email]);
        $foundUser = $stmt->fetch();

        if ($foundUser) {
            $token = bin2hex(random_bytes(32));
            $tokenHash = hash('sha256', $token);
            $expires = date('Y-m-d H:i:s', time() + 3600);

            $stmt = $db->prepare('UPDATE users SET reset_token_hash = ?, reset_token_expires = ? WHERE id = ?');
            $stmt->execute([$tokenHash, $expires, $foundUser['id']]);

            require_once __DIR__ . '/includes/notifications.php';
            $link = rtrim(SITE_URL, '/') . '/reset_password.php?token=' . $token;
            notify_password_reset($foundUser, $link);
        }
    }

    // Samme besked uanset om e-mailen fandtes, så man ikke kan bruge formularen
    // til at afsløre, hvilke e-mailadresser der er registreret i systemet.
    $sent = true;
}

render_header(t('forgotpw.title'));
?>
<div class="card login-card">
    <h1><?= h(t('forgotpw.title')) ?></h1>
    <?php if ($sent): ?>
        <p class="alert alert-success"><?= h(t('forgotpw.sent')) ?></p>
        <a class="button" href="login.php"><?= h(t('forgotpw.back_to_login')) ?></a>
    <?php else: ?>
        <p class="muted"><?= h(t('forgotpw.intro')) ?></p>
        <form method="post">
    <?= csrf_field() ?>
            <label><?= h(t('login.email')) ?>
                <input type="email" name="email" required autofocus>
            </label>
            <button type="submit"><?= h(t('forgotpw.submit')) ?></button>
        </form>
        <p style="margin-top:14px;"><a href="login.php"><?= h(t('forgotpw.back_to_login')) ?></a></p>
    <?php endif; ?>
</div>
<?php render_footer(); ?>
