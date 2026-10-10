<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

$user = require_permission('risks', 'create');
$db = get_db();

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $category = $_POST['category'] ?? 'Andet';
    $ownerId = (int) ($_POST['owner_id'] ?? 0);
    $probability = max(1, min(5, (int) ($_POST['probability'] ?? 1)));
    $consequence = max(1, min(5, (int) ($_POST['consequence'] ?? 1)));
    $contractReference = trim($_POST['contract_reference'] ?? '');

    $stmt = $db->prepare('SELECT id FROM users WHERE id = ? AND organization_id = ?');
    $stmt->execute([$ownerId, $user['organization_id']]);
    $validOwner = $stmt->fetch();

    if ($title === '' || !$validOwner) {
        $error = t('newrisk.error.required');
    } else {
        $stmt = $db->prepare('
            INSERT INTO risks (organization_id, title, description, category, owner_id, probability, consequence, created_by, contract_reference)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute([$user['organization_id'], $title, $description ?: null, $category, $ownerId, $probability, $consequence, $user['id'], $contractReference ?: null]);
        $riskId = (int) $db->lastInsertId();

        log_risk_action($riskId, 'Risiko registreret', 'Score: ' . ($probability * $consequence));

        header('Location: risks.php');
        exit;
    }
}

$stmt = $db->prepare("SELECT id, name FROM users WHERE organization_id = ? AND active = 1 ORDER BY name");
$stmt->execute([$user['organization_id']]);
$orgUsers = $stmt->fetchAll();

$categories = list_active_values('risk_category');

render_header(t('newrisk.title'));
?>
<div class="card login-card">
    <h1><?= h(t('newrisk.title')) ?></h1>
    <?php if ($error): ?><p class="alert alert-danger"><?= h($error) ?></p><?php endif; ?>
    <form method="post" data-preserve="newrisk">
    <?= csrf_field() ?>
        <label><?= h(t('newrisk.field.title')) ?>
            <input type="text" name="title" required>
        </label>
        <label><?= h(t('newrisk.field.description')) ?>
            <textarea name="description" rows="3"></textarea>
        </label>
        <label><?= h(t('newrisk.field.category')) ?>
            <select name="category">
                <?php foreach ($categories as $cat): ?>
                    <option value="<?= h($cat) ?>"><?= h(risk_category_label($cat)) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label><?= h(t('newrisk.field.owner')) ?>
            <select name="owner_id" required>
                <?php foreach ($orgUsers as $u): ?>
                    <option value="<?= (int) $u['id'] ?>"><?= h($u['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label><?= h(t('newrisk.field.probability')) ?>
            <select name="probability">
                <?php for ($i = 1; $i <= 5; $i++): ?>
                    <option value="<?= $i ?>" <?= $i === 3 ? 'selected' : '' ?>><?= $i ?></option>
                <?php endfor; ?>
            </select>
        </label>
        <label><?= h(t('newrisk.field.consequence')) ?>
            <select name="consequence">
                <?php for ($i = 1; $i <= 5; $i++): ?>
                    <option value="<?= $i ?>" <?= $i === 3 ? 'selected' : '' ?>><?= $i ?></option>
                <?php endfor; ?>
            </select>
        </label>
        <label><?= h(t('risk.field.contract_reference')) ?>
            <select name="contract_reference">
                <option value=""><?= h(t('newcase.field.contract_reference_choose')) ?></option>
                <?php foreach (list_active_values('contract_reference') as $ref): ?>
                    <option value="<?= h($ref) ?>"><?= h($ref) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <button type="submit"><?= h(t('newrisk.submit')) ?></button>
    </form>
</div>
<?php render_footer(); ?>
