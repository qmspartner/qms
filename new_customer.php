<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

$user = require_permission('customers', 'create');
$db = get_db();

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $contactPerson = trim($_POST['contact_person'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');

    if ($name === '') {
        $error = t('newcustomer.error.required');
    } else {
        $stmt = $db->prepare('INSERT INTO customers (organization_id, name, contact_person, email, phone) VALUES (?, ?, ?, ?, ?)');
        $stmt->execute([$user['organization_id'], $name, $contactPerson ?: null, $email ?: null, $phone ?: null]);
        $customerId = (int) $db->lastInsertId();

        log_customer_action($customerId, 'Kunde oprettet', $name);

        header('Location: customers.php');
        exit;
    }
}

render_header(t('newcustomer.title'));
?>
<div class="card login-card">
    <h1><?= h(t('newcustomer.title')) ?></h1>
    <?php if ($error): ?><p class="alert alert-danger"><?= h($error) ?></p><?php endif; ?>
    <form method="post" data-preserve="newcustomer">
    <?= csrf_field() ?>
        <label><?= h(t('newcustomer.field.name')) ?>
            <input type="text" name="name" required>
        </label>
        <label><?= h(t('newcustomer.field.contact_person')) ?>
            <input type="text" name="contact_person">
        </label>
        <label><?= h(t('newcustomer.field.email')) ?>
            <input type="email" name="email">
        </label>
        <label><?= h(t('newcustomer.field.phone')) ?>
            <input type="text" name="phone">
        </label>
        <button type="submit"><?= h(t('newcustomer.submit')) ?></button>
    </form>
</div>
<?php render_footer(); ?>
