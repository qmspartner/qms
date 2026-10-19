<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

$user = require_role(['admin']);
$db = get_db();

$validTypes = ['document_category', 'record_type', 'risk_category', 'supplier_category', 'contract_reference'];
$type = $_GET['type'] ?? '';
if (!in_array($type, $validTypes, true)) {
    http_response_code(404);
    exit('Listen blev ikke fundet.');
}
$isContractList = $type === 'contract_reference';
$isDocumentCategoryList = $type === 'document_category';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $labelDa = trim($_POST['label_da'] ?? '');
        $labelEn = $isContractList ? $labelDa : trim($_POST['label_en'] ?? '');
        $prefix = $isDocumentCategoryList ? (trim($_POST['prefix'] ?? '') ?: null) : null;
        $templateCategoryId = ($isDocumentCategoryList && !is_template_category_label($labelDa))
            ? ((int) ($_POST['template_category_id'] ?? 0) ?: null) : null;
        if ($labelDa === '' || $labelEn === '') {
            $error = t('lists.error.required');
        } else {
            $stmt = $db->prepare('SELECT id FROM list_items WHERE organization_id = ? AND list_type = ? AND label_da = ?');
            $stmt->execute([$user['organization_id'], $type, $labelDa]);
            if ($stmt->fetch()) {
                $error = t('lists.error.duplicate');
            } else {
                $stmt = $db->prepare('SELECT COALESCE(MAX(sort_order), 0) + 1 AS next_order FROM list_items WHERE organization_id = ? AND list_type = ?');
                $stmt->execute([$user['organization_id'], $type]);
                $nextOrder = (int) $stmt->fetch()['next_order'];

                $stmt = $db->prepare('INSERT INTO list_items (organization_id, list_type, label_da, label_en, sort_order, prefix, template_category_id) VALUES (?, ?, ?, ?, ?, ?, ?)');
                $stmt->execute([$user['organization_id'], $type, $labelDa, $labelEn, $nextOrder, $prefix, $templateCategoryId]);
            }
        }
    } elseif ($action === 'save') {
        $itemId = (int) ($_POST['item_id'] ?? 0);
        $labelDa = trim($_POST['label_da'] ?? '');
        $labelEn = $isContractList ? $labelDa : trim($_POST['label_en'] ?? '');
        $sortOrder = (int) ($_POST['sort_order'] ?? 0);
        $prefix = $isDocumentCategoryList ? (trim($_POST['prefix'] ?? '') ?: null) : null;
        $templateCategoryId = ($isDocumentCategoryList && !is_template_category_label($labelDa))
            ? ((int) ($_POST['template_category_id'] ?? 0) ?: null) : null;
        if ($labelDa === '' || $labelEn === '') {
            $error = t('lists.error.required');
        } else {
            $stmt = $db->prepare('UPDATE list_items SET label_da = ?, label_en = ?, sort_order = ?, prefix = ?, template_category_id = ? WHERE id = ? AND organization_id = ? AND list_type = ?');
            $stmt->execute([$labelDa, $labelEn, $sortOrder, $prefix, $templateCategoryId, $itemId, $user['organization_id'], $type]);
        }
    } elseif ($action === 'toggle') {
        $itemId = (int) ($_POST['item_id'] ?? 0);
        $stmt = $db->prepare('UPDATE list_items SET active = NOT active WHERE id = ? AND organization_id = ? AND list_type = ?');
        $stmt->execute([$itemId, $user['organization_id'], $type]);
    }

    if (!$error) {
        header('Location: list_detail.php?type=' . $type);
        exit;
    }
}

$stmt = $db->prepare('SELECT * FROM list_items WHERE organization_id = ? AND list_type = ? ORDER BY sort_order, id');
$stmt->execute([$user['organization_id'], $type]);
$items = $stmt->fetchAll();

$templateCategoryOptions = $isDocumentCategoryList ? list_template_categories() : [];

render_header(list_type_label($type));
?>
<p class="breadcrumb"><a href="lists.php"><?= h(t('lists.title')) ?></a> &raquo; <?= h(list_type_label($type)) ?></p>

<?php if ($error): ?><p class="alert alert-danger"><?= h($error) ?></p><?php endif; ?>

<?php if ($isDocumentCategoryList): ?>
<style>
.list-detail-table th, .list-detail-table td { padding: 6px 8px; font-size: 13px; }
.list-detail-table input[type="text"], .list-detail-table input[type="number"], .list-detail-table select { margin: 0; width: 100%; font-size: 13px; padding: 4px 6px; box-sizing: border-box; }
.list-detail-table .button-small { padding: 4px 8px; font-size: 12px; }
.list-detail-table td[data-actions] { display: flex; gap: 4px; }
</style>
<?php endif; ?>

<div class="card">
    <table class="doc-table<?= $isDocumentCategoryList ? ' list-detail-table' : '' ?>">
        <thead>
        <tr>
            <th><?= h(t('lists.col.da')) ?></th>
            <?php if (!$isContractList): ?><th><?= h(t('lists.col.en')) ?></th><?php endif; ?>
            <?php if ($isDocumentCategoryList): ?>
                <th style="width:60px;"><?= h(t('lists.col.prefix')) ?></th>
                <th style="width:130px;"><?= h(t('lists.col.template_category')) ?></th>
            <?php endif; ?>
            <th style="width:50px;"><?= h(t('lists.sort_order')) ?></th>
            <th style="width:80px;"><?= h(t('lists.col.status')) ?></th>
            <th style="width:120px;"></th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($items as $item): ?>
            <?php $editFormId = 'edit-' . $item['id']; $toggleFormId = 'toggle-' . $item['id']; ?>
            <form id="<?= h($editFormId) ?>" method="post"><?= csrf_field() ?></form>
            <form id="<?= h($toggleFormId) ?>" method="post"><?= csrf_field() ?></form>
            <tr>
                <td><input form="<?= h($editFormId) ?>" type="text" name="label_da" value="<?= h($item['label_da']) ?>" style="margin:0;"></td>
                <?php if (!$isContractList): ?>
                    <td><input form="<?= h($editFormId) ?>" type="text" name="label_en" value="<?= h($item['label_en']) ?>" style="margin:0;"></td>
                <?php else: ?>
                    <input form="<?= h($editFormId) ?>" type="hidden" name="label_en" value="<?= h($item['label_da']) ?>">
                <?php endif; ?>
                <?php if ($isDocumentCategoryList): ?>
                    <td><input form="<?= h($editFormId) ?>" type="text" name="prefix" value="<?= h($item['prefix'] ?? '') ?>" placeholder="fx SOP"></td>
                    <td>
                        <?php if (is_template_category_label($item['label_da'])): ?>
                            <span class="muted">&mdash;</span>
                        <?php else: ?>
                            <select form="<?= h($editFormId) ?>" name="template_category_id">
                                <option value=""><?= h(t('lists.template_category.none')) ?></option>
                                <?php foreach ($templateCategoryOptions as $opt): ?>
                                    <option value="<?= (int) $opt['id'] ?>" <?= ((int) ($item['template_category_id'] ?? 0) === (int) $opt['id']) ? 'selected' : '' ?>><?= h($opt['label_da']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        <?php endif; ?>
                    </td>
                <?php endif; ?>
                <td><input form="<?= h($editFormId) ?>" type="number" name="sort_order" value="<?= (int) $item['sort_order'] ?>" style="margin:0; width:60px;"></td>
                <td><span class="<?= $item['active'] ? 'badge badge-success' : 'badge badge-neutral' ?>"><?= $item['active'] ? h(t('lists.status.active')) : h(t('lists.status.inactive')) ?></span></td>
                <td data-actions style="display:flex; gap:6px;">
                    <input form="<?= h($editFormId) ?>" type="hidden" name="action" value="save">
                    <input form="<?= h($editFormId) ?>" type="hidden" name="item_id" value="<?= (int) $item['id'] ?>">
                    <button form="<?= h($editFormId) ?>" type="submit" class="button-small"><?= h(t('lists.action.save')) ?></button>

                    <input form="<?= h($toggleFormId) ?>" type="hidden" name="action" value="toggle">
                    <input form="<?= h($toggleFormId) ?>" type="hidden" name="item_id" value="<?= (int) $item['id'] ?>">
                    <button form="<?= h($toggleFormId) ?>" type="submit" class="button-small button-secondary"><?= $item['active'] ? h(t('lists.action.deactivate')) : h(t('lists.action.activate')) ?></button>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<h2><?= h(t('lists.add_title')) ?></h2>
<div class="card">
    <form method="post" class="inline-grid-form">
    <?= csrf_field() ?>
        <input type="hidden" name="action" value="add">
        <input type="text" name="label_da" placeholder="<?= h(t('lists.field.da')) ?>" required>
        <?php if (!$isContractList): ?>
            <input type="text" name="label_en" placeholder="<?= h(t('lists.field.en')) ?>" required>
        <?php endif; ?>
        <?php if ($isDocumentCategoryList): ?>
            <input type="text" name="prefix" placeholder="<?= h(t('lists.field.prefix')) ?>">
            <select name="template_category_id">
                <option value=""><?= h(t('lists.template_category.none')) ?></option>
                <?php foreach ($templateCategoryOptions as $opt): ?>
                    <option value="<?= (int) $opt['id'] ?>"><?= h($opt['label_da']) ?></option>
                <?php endforeach; ?>
            </select>
        <?php endif; ?>
        <button type="submit"><?= h(t('lists.add_button')) ?></button>
    </form>
</div>

<?php render_footer(); ?>
