<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

$user = require_permission('customers', 'edit');
$db = get_db();

$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$stmt = $db->prepare('SELECT * FROM customers WHERE id = ? AND organization_id = ?');
$stmt->execute([$id, $user['organization_id']]);
$customer = $stmt->fetch();

if (!$customer) {
    http_response_code(404);
    render_header(t('customers.not_found'));
    echo '<div class="card">' . h(t('customers.not_found')) . '</div>';
    render_footer();
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $contactPerson = trim($_POST['contact_person'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');

    if ($name === '') {
        $error = t('newcustomer.error.required');
    } else {
        $stmt = $db->prepare('UPDATE customers SET name = ?, contact_person = ?, email = ?, phone = ? WHERE id = ? AND organization_id = ?');
        $stmt->execute([$name, $contactPerson ?: null, $email ?: null, $phone ?: null, $id, $user['organization_id']]);

        log_customer_action($id, 'Kunde opdateret', $name);

        header('Location: customers.php');
        exit;
    }
}

render_header(t('editcustomer.title'));
?>
<div class="card login-card">
    <h1><?= h(t('editcustomer.title')) ?></h1>
    <?php if ($error): ?><p class="alert alert-danger"><?= h($error) ?></p><?php endif; ?>
    <form method="post">
    <?= csrf_field() ?>
        <label><?= h(t('newcustomer.field.name')) ?>
            <input type="text" name="name" value="<?= h($customer['name']) ?>" required>
        </label>
        <label><?= h(t('newcustomer.field.contact_person')) ?>
            <input type="text" name="contact_person" value="<?= h($customer['contact_person'] ?? '') ?>">
        </label>
        <label><?= h(t('newcustomer.field.email')) ?>
            <input type="email" name="email" value="<?= h($customer['email'] ?? '') ?>">
        </label>
        <label><?= h(t('newcustomer.field.phone')) ?>
            <input type="text" name="phone" value="<?= h($customer['phone'] ?? '') ?>">
        </label>
        <div class="button-row">
            <button type="submit"><?= h(t('editcustomer.save')) ?></button>
        </div>
    </form>
    <?php if ($user['role'] === 'admin'): ?>
        <form method="post" action="delete_customer.php" class="inline-form" style="margin-top:14px;"
              onsubmit="return confirm('<?= h(t('customers.delete_confirm')) ?>
');">
    <?= csrf_field() ?>
            <input type="hidden" name="customer_id" value="<?= (int) $customer['id'] ?>">
            <button type="submit" class="button-secondary button-danger"><?= h(t('customers.delete')) ?></button>
        </form>
    <?php endif; ?>
</div>
<?php render_footer(); ?>
