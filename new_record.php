<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

$user = require_permission('records', 'create');
$db = get_db();

$recordId = (int) ($_GET['record_id'] ?? $_POST['record_id'] ?? 0);
$existingRecord = null;
if ($recordId) {
    $stmt = $db->prepare('SELECT * FROM records WHERE id = ? AND organization_id = ?');
    $stmt->execute([$recordId, $user['organization_id']]);
    $existingRecord = $stmt->fetch();
    if (!$existingRecord) {
        http_response_code(404);
        exit(t('record.not_found'));
    }
}

$error = '';
$types = list_active_values('record_type');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = trim($_POST['title'] ?? '');
    $type = $existingRecord ? $existingRecord['type'] : ($_POST['type'] ?? '');
    $nextUpdateDate = trim($_POST['next_update_date'] ?? '');
    $retentionYears = $_POST['retention_years'] !== '' ? (int) $_POST['retention_years'] : null;
    $versionNumber = trim($_POST['version_number'] ?? '');
    $comment = trim($_POST['comment'] ?? '');
    $file = $_FILES['file'] ?? null;

    if ($title === '' && !$existingRecord) {
        $error = t('newrecord.error.required');
    } elseif (!$existingRecord && !in_array($type, $types, true)) {
        $error = t('newrecord.error.type');
    } elseif (!$file || $file['error'] !== UPLOAD_ERR_OK) {
        $error = t('upload.error.choose_file');
    } elseif ($file['size'] > MAX_FILE_SIZE) {
        $error = t('upload.error.too_large');
    } else {
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ALLOWED_EXTENSIONS, true)) {
            $error = t('upload.error.bad_type') . ' ' . implode(', ', ALLOWED_EXTENSIONS);
        } else {
            $storedName = bin2hex(random_bytes(16)) . '.' . $ext;
            if (!move_uploaded_file($file['tmp_name'], UPLOAD_DIR . $storedName)) {
                $error = t('upload.error.save_failed');
            } else {
                $db->beginTransaction();
                try {
                    if ($existingRecord) {
                        $recId = $existingRecord['id'];
                        $stmt = $db->prepare('UPDATE records SET next_update_date = ?, retention_years = ? WHERE id = ?');
                        $stmt->execute([$nextUpdateDate ?: null, $retentionYears, $recId]);
                    } else {
                        $stmt = $db->prepare('
                            INSERT INTO records (organization_id, title, type, owner_id, next_update_date, retention_years)
                            VALUES (?, ?, ?, ?, ?, ?)
                        ');
                        $stmt->execute([$user['organization_id'], $title, $type, $user['id'], $nextUpdateDate ?: null, $retentionYears]);
                        $recId = (int) $db->lastInsertId();
                    }

                    $stmt = $db->prepare('
                        INSERT INTO record_versions (record_id, version_number, stored_filename, original_filename, uploaded_by, comment)
                        VALUES (?, ?, ?, ?, ?, ?)
                    ');
                    $stmt->execute([$recId, $versionNumber ?: date('Y-m-d'), $storedName, $file['name'], $user['id'], $comment ?: null]);
                    $versionId = (int) $db->lastInsertId();

                    $stmt = $db->prepare('UPDATE records SET current_version_id = ? WHERE id = ?');
                    $stmt->execute([$versionId, $recId]);

                    $db->commit();
                    log_record_action($recId, $existingRecord ? 'Ny version uploadet' : 'Optegnelse oprettet', 'Version ' . ($versionNumber ?: date('Y-m-d')));

                    header('Location: records.php');
                    exit;
                } catch (Exception $e) {
                    $db->rollBack();
                    $error = t('upload.error.generic');
                }
            }
        }
    }
}

render_header($existingRecord ? t('newrecord.title_new_version') : t('newrecord.title_new'));
?>
<div class="card login-card">
    <h1><?= $existingRecord ? h(t('newrecord.title_version_of')) . ' ' . h($existingRecord['title']) : h(t('newrecord.title_new')) ?></h1>
    <?php if ($error): ?><p class="alert alert-danger"><?= h($error) ?></p><?php endif; ?>
    <form method="post" enctype="multipart/form-data" data-preserve="newrecord">
    <?= csrf_field() ?>
        <input type="hidden" name="record_id" value="<?= (int) $recordId ?>">

        <?php if (!$existingRecord): ?>
            <label><?= h(t('newrecord.field.title')) ?>
                <input type="text" name="title" required>
            </label>
            <label><?= h(t('newrecord.field.type')) ?>
                <select name="type" id="type-select" onchange="if (this.value === 'Ledelsens evaluering') { window.location = 'new_management_review.php'; } else { applyDefaults(this.value); }" required>
                    <?php foreach ($types as $ty): ?>
                        <option value="<?= h($ty) ?>"><?= h(record_type_label($ty)) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
        <?php else: ?>
            <p class="muted"><?= h(t('records.table.type')) ?>: <?= h(record_type_label($existingRecord['type'])) ?></p>
        <?php endif; ?>

        <label><?= h(t('newrecord.field.next_update')) ?>
            <input type="date" name="next_update_date" id="next_update_date" value="<?= h($existingRecord['next_update_date'] ?? '') ?>">
        </label>
        <label><?= h(t('newrecord.field.retention')) ?>
            <input type="number" name="retention_years" id="retention_years" min="1" max="50" value="<?= h($existingRecord['retention_years'] ?? '') ?>">
        </label>
        <label><?= h(t('upload.field.version')) ?>
            <input type="text" name="version_number" placeholder="fx 1.0">
        </label>
        <label><?= h(t('newrecord.field.comment')) ?>
            <textarea name="comment" rows="3"></textarea>
        </label>
        <label><?= h(t('newrecord.field.file')) ?>
            <input type="file" name="file" required>
        </label>
        <button type="submit"><?= h(t('newrecord.submit')) ?></button>
    </form>
</div>
<?php if (!$existingRecord): ?>
<script>
var typeDefaults = {
    'Kompetencematrix': { retention: 3, months: 3 },
    'Kalibreringsbevis': { retention: 5, months: 12 },
    'Mødereferat': { retention: 5, months: 0 },
    'Træningsbevis': { retention: 5, months: 12 }
};
function applyDefaults(type) {
    var d = typeDefaults[type];
    if (!d) return;
    document.getElementById('retention_years').value = d.retention;
    if (d.months > 0) {
        var date = new Date();
        date.setMonth(date.getMonth() + d.months);
        document.getElementById('next_update_date').value = date.toISOString().slice(0, 10);
    } else {
        document.getElementById('next_update_date').value = '';
    }
}
applyDefaults(document.getElementById('type-select').value);
</script>
<?php endif; ?>
<?php render_footer(); ?>
