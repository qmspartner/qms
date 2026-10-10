<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

$user = require_permission('suppliers', 'edit');
$db = get_db();

$supplierId = (int) ($_GET['supplier_id'] ?? $_POST['supplier_id'] ?? 0);
$stmt = $db->prepare('SELECT * FROM suppliers WHERE id = ? AND organization_id = ?');
$stmt->execute([$supplierId, $user['organization_id']]);
$supplier = $stmt->fetch();

if (!$supplier) {
    http_response_code(404);
    exit(t('supplier.not_found'));
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $evaluationDate = trim($_POST['evaluation_date'] ?? '');
    $result = trim($_POST['result'] ?? '');
    $file = $_FILES['attachment'] ?? null;

    if ($evaluationDate === '') {
        $error = t('newsupplierevaluation.error.required');
    } else {
        $storedName = null;
        $originalName = null;

        if ($file && $file['size'] > 0) {
            if ($file['error'] !== UPLOAD_ERR_OK) {
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
                        $storedName = null;
                    } else {
                        $originalName = $file['name'];
                    }
                }
            }
        }

        if (!$error) {
            $db->beginTransaction();
            try {
                $stmt = $db->prepare('
                    INSERT INTO supplier_evaluations (supplier_id, evaluation_date, result, stored_filename, original_filename, performed_by)
                    VALUES (?, ?, ?, ?, ?, ?)
                ');
                $stmt->execute([$supplierId, $evaluationDate, $result ?: null, $storedName, $originalName, $user['id']]);

                $nextDate = null;
                if ($supplier['evaluation_interval_months']) {
                    $nextDate = date('Y-m-d', strtotime($evaluationDate . ' + ' . (int)$supplier['evaluation_interval_months'] . ' months'));
                }

                $stmt = $db->prepare('UPDATE suppliers SET last_evaluation_date = ?, next_evaluation_date = ? WHERE id = ?');
                $stmt->execute([$evaluationDate, $nextDate, $supplierId]);

                $db->commit();
                log_supplier_action($supplierId, 'Evaluering registreret', $evaluationDate);

                header('Location: supplier_detail.php?id=' . $supplierId);
                exit;
            } catch (Exception $e) {
                $db->rollBack();
                $error = t('upload.error.generic');
            }
        }
    }
}

render_header(t('newsupplierevaluation.title'));
?>
<div class="card login-card">
    <h1><?= h(t('newsupplierevaluation.title')) ?> — <?= h($supplier['name']) ?></h1>
    <?php if ($error): ?><p class="alert alert-danger"><?= h($error) ?></p><?php endif; ?>
    <form method="post" enctype="multipart/form-data" data-preserve="newsupplierevaluation">
    <?= csrf_field() ?>
        <input type="hidden" name="supplier_id" value="<?= (int) $supplierId ?>">
        <label><?= h(t('newsupplierevaluation.field.date')) ?>
            <input type="date" name="evaluation_date" value="<?= h(date('Y-m-d')) ?>" required>
        </label>
        <label><?= h(t('newsupplierevaluation.field.result')) ?>
            <textarea name="result" rows="3"></textarea>
        </label>
        <label><?= h(t('newsupplierevaluation.field.attachment')) ?>
            <input type="file" name="attachment">
        </label>
        <button type="submit"><?= h(t('newsupplierevaluation.submit')) ?></button>
    </form>
</div>
<?php render_footer(); ?>
