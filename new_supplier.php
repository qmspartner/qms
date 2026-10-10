<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

$user = require_permission('suppliers', 'create');
$db = get_db();

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $contactPerson = trim($_POST['contact_person'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $category = $_POST['category'] ?? 'Andet';
    $criticality = $_POST['criticality'] ?? 'Ikke-kritisk';
    $ownerId = (int) ($_POST['owner_id'] ?? 0);
    $qualityPassedOn = isset($_POST['quality_requirements_passed_on']) ? 1 : 0;

    $stmt = $db->prepare('SELECT id FROM users WHERE id = ? AND organization_id = ?');
    $stmt->execute([$ownerId, $user['organization_id']]);
    $validOwner = $stmt->fetch();

    if ($name === '' || !$validOwner) {
        $error = t('newsupplier.error.required');
    } else {
        $interval = $criticality === 'Kritisk' ? 6 : 12;

        $stmt = $db->prepare('
            INSERT INTO suppliers (organization_id, name, contact_person, email, phone, category, criticality, owner_id, evaluation_interval_months, quality_requirements_passed_on)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute([$user['organization_id'], $name, $contactPerson ?: null, $email ?: null, $phone ?: null, $category, $criticality, $ownerId, $interval, $qualityPassedOn]);
        $supplierId = (int) $db->lastInsertId();

        $contracts = array_values(array_filter((array) ($_POST['contracts'] ?? [])));
        save_entity_contracts('supplier_contracts', 'supplier_id', $supplierId, $contracts);

        log_supplier_action($supplierId, 'Leverandør oprettet', $name);

        header('Location: suppliers.php');
        exit;
    }
}

$stmt = $db->prepare("SELECT id, name FROM users WHERE organization_id = ? AND active = 1 ORDER BY name");
$stmt->execute([$user['organization_id']]);
$orgUsers = $stmt->fetchAll();

$categories = supplier_category_options();

render_header(t('newsupplier.title'));
?>
<div class="card login-card">
    <h1><?= h(t('newsupplier.title')) ?></h1>
    <?php if ($error): ?><p class="alert alert-danger"><?= h($error) ?></p><?php endif; ?>
    <form method="post" data-preserve="newsupplier">
    <?= csrf_field() ?>
        <label><?= h(t('newsupplier.field.name')) ?>
            <input type="text" name="name" required>
        </label>
        <label><?= h(t('newsupplier.field.contact_person')) ?>
            <input type="text" name="contact_person">
        </label>
        <label><?= h(t('newsupplier.field.email')) ?>
            <input type="email" name="email">
        </label>
        <label><?= h(t('newsupplier.field.phone')) ?>
            <input type="text" name="phone">
        </label>
        <label><?= h(t('newsupplier.field.category')) ?>
            <select name="category">
                <?php foreach ($categories as $cat): ?>
                    <option value="<?= h($cat) ?>"><?= h(supplier_category_label($cat)) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label><?= h(t('newsupplier.field.criticality')) ?>
            <select name="criticality">
                <option value="Ikke-kritisk"><?= h(equipment_criticality_label('Ikke-kritisk')) ?></option>
                <option value="Kritisk"><?= h(equipment_criticality_label('Kritisk')) ?></option>
            </select>
        </label>
        <label><?= h(t('newsupplier.field.owner')) ?>
            <select name="owner_id" required>
                <?php foreach ($orgUsers as $u): ?>
                    <option value="<?= (int) $u['id'] ?>"><?= h($u['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label style="display:flex; align-items:center; gap:8px; flex-direction:row;">
            <input type="checkbox" name="quality_requirements_passed_on" value="1" style="width:auto;">
            <?= h(t('newsupplier.field.quality_requirements')) ?>
        </label>
        <label><?= h(t('supplier.contracts')) ?>
            <select name="contracts[]" multiple size="4">
                <?php foreach (list_active_values('contract_reference') as $ref): ?>
                    <option value="<?= h($ref) ?>"><?= h($ref) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <button type="submit"><?= h(t('newsupplier.submit')) ?></button>
    </form>
</div>
<?php render_footer(); ?>
