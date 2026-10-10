<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

$user = require_role(['admin']);
$db = get_db();

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $name = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $validity = $_POST['validity_months'] !== '' ? (int) $_POST['validity_months'] : null;

        if ($name === '') {
            $error = t('qualifications.error.required');
        } else {
            $stmt = $db->prepare('SELECT COALESCE(MAX(sort_order), 0) + 1 AS next_order FROM qualifications WHERE organization_id = ?');
            $stmt->execute([$user['organization_id']]);
            $nextOrder = (int) $stmt->fetch()['next_order'];

            $stmt = $db->prepare('INSERT INTO qualifications (organization_id, name, description, validity_months, sort_order) VALUES (?, ?, ?, ?, ?)');
            $stmt->execute([$user['organization_id'], $name, $description ?: null, $validity, $nextOrder]);
        }
    } elseif ($action === 'save') {
        $itemId = (int) ($_POST['item_id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $validity = $_POST['validity_months'] !== '' ? (int) $_POST['validity_months'] : null;

        if ($name === '') {
            $error = t('qualifications.error.required');
        } else {
            $stmt = $db->prepare('UPDATE qualifications SET name = ?, description = ?, validity_months = ? WHERE id = ? AND organization_id = ?');
            $stmt->execute([$name, $description ?: null, $validity, $itemId, $user['organization_id']]);
        }
    } elseif ($action === 'toggle') {
        $itemId = (int) ($_POST['item_id'] ?? 0);
        $stmt = $db->prepare('UPDATE qualifications SET active = NOT active WHERE id = ? AND organization_id = ?');
        $stmt->execute([$itemId, $user['organization_id']]);
    }

    if (!$error) {
        header('Location: qualifications.php');
        exit;
    }
}

$stmt = $db->prepare('SELECT * FROM qualifications WHERE organization_id = ? ORDER BY sort_order, id');
$stmt->execute([$user['organization_id']]);
$items = $stmt->fetchAll();

render_header(t('qualifications.title'));
?>
<p class="breadcrumb"><a href="competence.php"><?= h(t('competence.title')) ?></a> &raquo; <?= h(t('qualifications.title')) ?></p>

<div class="page-heading"><h1><?= h(t('qualifications.title')) ?></h1></div>

<?php if ($error): ?><p class="alert alert-danger"><?= h($error) ?></p><?php endif; ?>

<div class="card">
    <table class="doc-table">
        <thead>
        <tr>
            <th><?= h(t('qualifications.col.name')) ?></th>
            <th><?= h(t('qualifications.col.description')) ?></th>
            <th style="width:120px;"><?= h(t('qualifications.col.validity')) ?></th>
            <th style="width:100px;"><?= h(t('qualifications.col.status')) ?></th>
            <th style="width:160px;"></th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($items as $item): ?>
            <?php $editFormId = 'edit-' . $item['id']; $toggleFormId = 'toggle-' . $item['id']; ?>
            <form id="<?= h($editFormId) ?>" method="post"><?= csrf_field() ?></form>
            <form id="<?= h($toggleFormId) ?>" method="post"><?= csrf_field() ?></form>
            <tr>
                <td><input form="<?= h($editFormId) ?>" type="text" name="name" value="<?= h($item['name']) ?>" style="margin:0;"></td>
                <td><input form="<?= h($editFormId) ?>" type="text" name="description" value="<?= h($item['description'] ?? '') ?>" style="margin:0;"></td>
                <td><input form="<?= h($editFormId) ?>" type="number" name="validity_months" min="1" max="600" value="<?= h($item['validity_months'] ?? '') ?>" placeholder="<?= h(t('qualifications.never_expires')) ?>" style="margin:0;"></td>
                <td><span class="<?= $item['active'] ? 'badge badge-success' : 'badge badge-neutral' ?>"><?= $item['active'] ? h(t('qualifications.status.active')) : h(t('qualifications.status.inactive')) ?></span></td>
                <td style="display:flex; gap:6px;">
                    <input form="<?= h($editFormId) ?>" type="hidden" name="action" value="save">
                    <input form="<?= h($editFormId) ?>" type="hidden" name="item_id" value="<?= (int) $item['id'] ?>">
                    <button form="<?= h($editFormId) ?>" type="submit" class="button-small"><?= h(t('qualifications.action.save')) ?></button>

                    <input form="<?= h($toggleFormId) ?>" type="hidden" name="action" value="toggle">
                    <input form="<?= h($toggleFormId) ?>" type="hidden" name="item_id" value="<?= (int) $item['id'] ?>">
                    <button form="<?= h($toggleFormId) ?>" type="submit" class="button-small button-secondary"><?= $item['active'] ? h(t('qualifications.action.deactivate')) : h(t('qualifications.action.activate')) ?></button>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<h2><?= h(t('qualifications.add_title')) ?></h2>
<div class="card">
    <form method="post" class="inline-grid-form">
    <?= csrf_field() ?>
        <input type="hidden" name="action" value="add">
        <input type="text" name="name" placeholder="<?= h(t('qualifications.field.name_ph')) ?>" required>
        <input type="text" name="description" placeholder="<?= h(t('qualifications.field.description_ph')) ?>">
        <input type="number" name="validity_months" min="1" max="600" placeholder="<?= h(t('qualifications.field.validity_ph')) ?>">
        <button type="submit"><?= h(t('qualifications.add_button')) ?></button>
    </form>
</div>

<?php render_footer(); ?>
