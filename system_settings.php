<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

$user = require_role(['admin']);
$db = get_db();

$saved = false;
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $section = $_POST['section'] ?? '';
    if ($section === 'date_format') {
        $dateFormat = $_POST['date_format'] ?? '';
        if (!in_array($dateFormat, DATE_FORMAT_OPTIONS, true)) {
            $error = t('systemsettings.error.invalid_format');
        } else {
            $stmt = $db->prepare('UPDATE organizations SET date_format = ? WHERE id = ?');
            $stmt->execute([$dateFormat, $user['organization_id']]);
            $saved = true;
        }
    } elseif ($section === 'aqap2210') {
        $enabled = isset($_POST['aqap2210_enabled']) ? 1 : 0;
        $stmt = $db->prepare('UPDATE organizations SET aqap2210_enabled = ? WHERE id = ?');
        $stmt->execute([$enabled, $user['organization_id']]);
        $saved = true;
    } elseif ($section === 'fai') {
        $enabled = isset($_POST['fai_enabled']) ? 1 : 0;
        $stmt = $db->prepare('UPDATE organizations SET fai_enabled = ? WHERE id = ?');
        $stmt->execute([$enabled, $user['organization_id']]);
        $saved = true;
    }
}

$stmt = $db->prepare('SELECT date_format, aqap2210_enabled, fai_enabled FROM organizations WHERE id = ?');
$stmt->execute([$user['organization_id']]);
$orgRow = $stmt->fetch();
$currentFormat = $orgRow['date_format'] ?: 'd-m-Y';
$aqap2210Enabled = (bool) ($orgRow['aqap2210_enabled'] ?? false);
$faiEnabled = (bool) ($orgRow['fai_enabled'] ?? false);

render_header(t('systemsettings.title'));
?>
<div class="page-heading"><h1><?= h(t('systemsettings.title')) ?></h1></div>
<p class="muted" style="margin:-8px 0 20px;"><?= h(t('systemsettings.description')) ?></p>

<?php if ($saved): ?><p class="alert alert-success"><?= h(t('systemsettings.saved')) ?></p><?php endif; ?>
<?php if ($error): ?><p class="alert alert-danger"><?= h($error) ?></p><?php endif; ?>

<div class="card login-card" style="max-width: 520px;">
    <h2><?= h(t('systemsettings.date_format_title')) ?></h2>
    <p class="muted"><?= h(t('systemsettings.date_format_desc')) ?></p>

    <form method="post">
    <?= csrf_field() ?>
        <input type="hidden" name="section" value="date_format">
        <?php foreach (DATE_FORMAT_OPTIONS as $fmt): ?>
            <label style="display:flex; align-items:center; gap:10px; flex-direction:row; margin-bottom:10px;">
                <input type="radio" name="date_format" value="<?= h($fmt) ?>" style="width:auto;" <?= $currentFormat === $fmt ? 'checked' : '' ?>>
                <span><?= h($fmt === 'd-m-Y' ? t('systemsettings.format.dmy') : t('systemsettings.format.ymd')) ?> — <strong><?= h(date_format_example($fmt)) ?></strong></span>
            </label>
        <?php endforeach; ?>
        <button type="submit" style="margin-top:10px;"><?= h(t('systemsettings.save')) ?></button>
    </form>
</div>

<div class="card login-card" style="max-width: 520px; margin-top:20px;">
    <h2><?= h(t('systemsettings.aqap2210_title')) ?></h2>
    <p class="muted"><?= h(t('systemsettings.aqap2210_desc')) ?></p>

    <form method="post">
    <?= csrf_field() ?>
        <input type="hidden" name="section" value="aqap2210">
        <label style="display:flex; align-items:center; gap:10px; flex-direction:row; margin-bottom:10px;">
            <input type="checkbox" name="aqap2210_enabled" value="1" style="width:auto;" <?= $aqap2210Enabled ? 'checked' : '' ?>>
            <span><?= h(t('systemsettings.aqap2210_label')) ?></span>
        </label>
        <button type="submit" style="margin-top:10px;"><?= h(t('systemsettings.save')) ?></button>
    </form>
</div>

<div class="card login-card" style="max-width: 520px; margin-top:20px;">
    <h2><?= h(t('systemsettings.fai_title')) ?></h2>
    <p class="muted"><?= h(t('systemsettings.fai_desc')) ?></p>

    <form method="post">
    <?= csrf_field() ?>
        <input type="hidden" name="section" value="fai">
        <label style="display:flex; align-items:center; gap:10px; flex-direction:row; margin-bottom:10px;">
            <input type="checkbox" name="fai_enabled" value="1" style="width:auto;" <?= $faiEnabled ? 'checked' : '' ?>>
            <span><?= h(t('systemsettings.fai_label')) ?></span>
        </label>
        <button type="submit" style="margin-top:10px;"><?= h(t('systemsettings.save')) ?></button>
    </form>
</div>

<?php render_footer(); ?>
