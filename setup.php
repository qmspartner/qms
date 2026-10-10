<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

$db = get_db();
$userCount = (int) $db->query('SELECT COUNT(*) AS c FROM users')->fetch()['c'];

if ($userCount > 0) {
    render_header('Opsætning');
    echo '<div class="card"><p>Der findes allerede brugere i systemet. Af sikkerhedshensyn kan denne side ikke bruges igen.</p>';
    echo '<p><strong>Slet setup.php fra serveren nu.</strong></p></div>';
    render_footer();
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $orgName = trim($_POST['org_name'] ?? '');
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($orgName === '' || $name === '' || $email === '' || strlen($password) < 8) {
        $error = 'Udfyld organisationsnavn, navn og e-mail, og vælg en adgangskode på mindst 8 tegn.';
    } else {
        $db->beginTransaction();
        try {
            $stmt = $db->prepare('INSERT INTO organizations (name) VALUES (?)');
            $stmt->execute([$orgName]);
            $orgId = (int) $db->lastInsertId();

            $stmt = $db->prepare('INSERT INTO users (organization_id, name, email, password_hash, role) VALUES (?, ?, ?, ?, ?)');
            $stmt->execute([$orgId, $name, $email, password_hash($password, PASSWORD_DEFAULT), 'admin']);

            seed_default_lists($orgId);
            seed_default_permissions($orgId);

            $db->commit();
        } catch (Exception $e) {
            $db->rollBack();
            $error = 'Der opstod en fejl under oprettelsen. Prøv igen.';
        }

        if (!$error) {
            render_header('Opsætning');
            echo '<div class="card"><p>Organisationen og administrator-kontoen er oprettet.</p>';
            echo '<p><strong>Slet setup.php fra serveren nu, af sikkerhedshensyn.</strong></p>';
            echo '<p><a href="login.php">Gå til login</a></p></div>';
            render_footer();
            exit;
        }
    }
}

render_header('Opsætning');
?>
<div class="card login-card">
    <h1>Opret organisation og administrator</h1>
    <p class="muted">Dette er den første og eneste gang, du kan oprette en konto her. Slet filen bagefter.</p>
    <?php if ($error): ?>
        <p class="alert alert-danger"><?= h($error) ?></p>
    <?php endif; ?>
    <form method="post">
    <?= csrf_field() ?>
        <label>Organisationsnavn (fx firmanavn)
            <input type="text" name="org_name" required>
        </label>
        <label>Dit navn
            <input type="text" name="name" required>
        </label>
        <label>E-mail
            <input type="email" name="email" required>
        </label>
        <label>Adgangskode (min. 8 tegn)
            <input type="password" name="password" required minlength="8">
        </label>
        <button type="submit">Opret</button>
    </form>
</div>
<?php render_footer(); ?>
