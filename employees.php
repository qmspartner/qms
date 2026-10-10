<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

$user = require_role(['admin']);
$db = get_db();

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $title = trim($_POST['title'] ?? '');
    $department = trim($_POST['department'] ?? '');

    if ($name === '') {
        $error = t('employees.error.required');
    } else {
        $stmt = $db->prepare('INSERT INTO employees (organization_id, name, title, department) VALUES (?, ?, ?, ?)');
        $stmt->execute([$user['organization_id'], $name, $title ?: null, $department ?: null]);
        $newId = (int) $db->lastInsertId();
        log_employee_action($newId, 'Medarbejder oprettet');

        header('Location: employees.php');
        exit;
    }
}

$stmt = $db->prepare('SELECT * FROM employees WHERE organization_id = ? ORDER BY active DESC, name');
$stmt->execute([$user['organization_id']]);
$employees = $stmt->fetchAll();

render_header(t('employees.title'));
?>
<p class="breadcrumb"><a href="competence.php"><?= h(t('competence.title')) ?></a> &raquo; <?= h(t('employees.title')) ?></p>

<div class="page-heading"><h1><?= h(t('employees.title')) ?></h1></div>

<?php if ($error): ?><p class="alert alert-danger"><?= h($error) ?></p><?php endif; ?>

<div class="card">
    <h2><?= h(t('employees.create_title')) ?></h2>
    <form method="post" class="inline-grid-form" data-preserve="newemployee">
    <?= csrf_field() ?>
        <input type="text" name="name" placeholder="<?= h(t('employees.field.name_ph')) ?>" required>
        <input type="text" name="title" placeholder="<?= h(t('employees.field.title_ph')) ?>">
        <input type="text" name="department" placeholder="<?= h(t('employees.field.department_ph')) ?>">
        <button type="submit"><?= h(t('employees.create')) ?></button>
    </form>
</div>

<table class="doc-table">
    <thead><tr>
        <th><?= h(t('employees.table.name')) ?></th>
        <th><?= h(t('employees.table.title')) ?></th>
        <th><?= h(t('employees.table.department')) ?></th>
        <th><?= h(t('employees.table.status')) ?></th>
        <th></th>
    </tr></thead>
    <tbody>
    <?php foreach ($employees as $e): ?>
        <tr onclick="window.location='edit_employee.php?id=<?= (int) $e['id'] ?>'">
            <td><?= h($e['name']) ?></td>
            <td><?= h($e['title'] ?: '—') ?></td>
            <td><?= h($e['department'] ?: '—') ?></td>
            <td><?= $e['active'] ? h(t('employees.active')) : h(t('employees.inactive')) ?></td>
            <td><a href="edit_employee.php?id=<?= (int) $e['id'] ?>"><?= h(t('employees.edit')) ?></a></td>
        </tr>
    <?php endforeach; ?>
    <?php if (!$employees): ?>
        <tr><td colspan="5" class="muted"><?= h(t('employees.empty')) ?></td></tr>
    <?php endif; ?>
    </tbody>
</table>

<?php render_footer(); ?>
