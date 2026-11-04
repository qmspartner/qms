<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/quality_objectives.php';

$user = require_permission('quality_objectives', 'create');
$db = get_db();

$stmt = $db->prepare("SELECT id, name FROM users WHERE organization_id = ? AND active = 1 ORDER BY name");
$stmt->execute([$user['organization_id']]);
$orgUsers = $stmt->fetchAll();

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $ownerId = (int) ($_POST['owner_user_id'] ?? 0);
    $direction = ($_POST['target_direction'] ?? '<=') === '>=' ? '>=' : '<=';
    $targetValue = str_replace(',', '.', trim($_POST['target_value'] ?? ''));
    $unit = trim($_POST['unit'] ?? '');
    $frequency = in_array($_POST['measurement_frequency'] ?? '', ['Ugentligt', 'Månedligt', 'Kvartalsvist'], true) ? $_POST['measurement_frequency'] : 'Kvartalsvist';
    $reminderLeadDays = (int) ($_POST['reminder_lead_days'] ?? 7);
    $startDate = trim($_POST['start_date'] ?? '');
    $targetDate = trim($_POST['target_date'] ?? '');

    $stmt = $db->prepare('SELECT id FROM users WHERE id = ? AND organization_id = ?');
    $stmt->execute([$ownerId, $user['organization_id']]);
    $validOwner = $stmt->fetch();

    if ($title === '' || !$validOwner || !is_numeric($targetValue) || $startDate === '' || $reminderLeadDays < 0) {
        $error = t('newqualityobjective.error.required');
    } else {
        $stmt = $db->prepare('
            INSERT INTO quality_objectives
                (organization_id, title, description, owner_user_id, target_direction, target_value, unit,
                 measurement_frequency, reminder_lead_days, start_date, target_date, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, \'Ny\')
        ');
        $stmt->execute([
            $user['organization_id'], $title, $description ?: null, $ownerId, $direction, (float) $targetValue, $unit ?: null,
            $frequency, $reminderLeadDays, $startDate, $targetDate ?: null,
        ]);
        $newId = (int) $db->lastInsertId();

        header('Location: quality_objective_detail.php?id=' . $newId);
        exit;
    }
}

render_header(t('newqualityobjective.title'));
?>
<div class="card login-card">
    <h1><?= h(t('newqualityobjective.title')) ?></h1>
    <?php if ($error): ?><p class="alert alert-danger"><?= h($error) ?></p><?php endif; ?>
    <form method="post" data-preserve="newqualityobjective">
    <?= csrf_field() ?>
        <label><?= h(t('newqualityobjective.field.title')) ?>
            <input type="text" name="title" required>
        </label>
        <label><?= h(t('newqualityobjective.field.description')) ?>
            <textarea name="description" rows="2"></textarea>
        </label>
        <label><?= h(t('newqualityobjective.field.owner')) ?>
            <select name="owner_user_id" required>
                <option value=""></option>
                <?php foreach ($orgUsers as $u): ?>
                    <option value="<?= (int) $u['id'] ?>" <?= (int) $user['id'] === (int) $u['id'] ? 'selected' : '' ?>><?= h($u['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <div style="display:flex; gap:12px;">
            <label style="flex:0 0 100px;"><?= h(t('newqualityobjective.field.direction')) ?>
                <select name="target_direction">
                    <option value="<="><?= h(t('newqualityobjective.direction.max')) ?></option>
                    <option value=">="><?= h(t('newqualityobjective.direction.min')) ?></option>
                </select>
            </label>
            <label style="flex:1;"><?= h(t('newqualityobjective.field.target_value')) ?>
                <input type="text" name="target_value" inputmode="decimal" required>
            </label>
            <label style="flex:1;"><?= h(t('newqualityobjective.field.unit')) ?>
                <input type="text" name="unit" placeholder="%, dage, stk...">
            </label>
        </div>
        <label><?= h(t('newqualityobjective.field.frequency')) ?>
            <select name="measurement_frequency">
                <option value="Ugentligt"><?= h(t('qualityobjective.frequency.Ugentligt')) ?></option>
                <option value="Månedligt"><?= h(t('qualityobjective.frequency.Månedligt')) ?></option>
                <option value="Kvartalsvist" selected><?= h(t('qualityobjective.frequency.Kvartalsvist')) ?></option>
            </select>
        </label>
        <label><?= h(t('newqualityobjective.field.reminder_lead_days')) ?> <?php help_tip('help.tip.qualityobjective_reminder'); ?>
            <input type="number" name="reminder_lead_days" value="7" min="0" max="90">
        </label>
        <label><?= h(t('newqualityobjective.field.start_date')) ?>
            <input type="date" name="start_date" value="<?= h(date('Y-m-d')) ?>" required>
        </label>
        <label><?= h(t('newqualityobjective.field.target_date')) ?>
            <input type="date" name="target_date">
        </label>
        <div class="button-row">
            <button type="submit"><?= h(t('newqualityobjective.submit')) ?></button>
        </div>
    </form>
</div>
<?php render_footer(); ?>
