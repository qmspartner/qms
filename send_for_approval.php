<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

$user = require_permission('documents', 'create');
$db = get_db();

$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$stmt = $db->prepare('SELECT * FROM documents WHERE id = ? AND organization_id = ?');
$stmt->execute([$id, $user['organization_id']]);
$doc = $stmt->fetch();

if (!$doc) {
    http_response_code(404);
    exit(t('doc.not_found'));
}

if ($doc['status'] !== 'Kladde') {
    header('Location: document.php?id=' . $id);
    exit;
}

$stmt = $db->prepare('SELECT * FROM document_versions WHERE document_id = ? ORDER BY uploaded_at DESC LIMIT 1');
$stmt->execute([$id]);
$version = $stmt->fetch();

if (!$version || $version['status'] !== 'Kladde') {
    header('Location: document.php?id=' . $id);
    exit;
}

$stmt = $db->prepare("SELECT id, name FROM users WHERE organization_id = ? AND role = 'approver' AND active = 1 ORDER BY name");
$stmt->execute([$user['organization_id']]);
$approvers = $stmt->fetchAll();

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $approverId = (int) ($_POST['approver_id'] ?? 0);
    $attachNewFile = isset($_POST['attach_new_file']);
    $file = $_FILES['file'] ?? null;

    $stmt = $db->prepare("SELECT id, name, email FROM users WHERE id = ? AND organization_id = ? AND role = 'approver' AND active = 1");
    $stmt->execute([$approverId, $user['organization_id']]);
    $selectedApprover = $stmt->fetch();

    if (!$selectedApprover) {
        $error = t('upload.error.invalid_approver');
    } elseif ($attachNewFile && (!$file || $file['error'] !== UPLOAD_ERR_OK)) {
        $error = t('upload.error.choose_file');
    } elseif ($attachNewFile && $file['size'] > MAX_FILE_SIZE) {
        $error = t('upload.error.too_large');
    } else {
        $newStoredName = null;
        $newOriginalFilename = null;
        $fileReady = true;

        if ($attachNewFile) {
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, ALLOWED_EXTENSIONS, true)) {
                $error = t('upload.error.bad_type') . ' ' . implode(', ', ALLOWED_EXTENSIONS);
                $fileReady = false;
            } else {
                $newStoredName = bin2hex(random_bytes(16)) . '.' . $ext;
                $newOriginalFilename = $file['name'];
                if (!move_uploaded_file($file['tmp_name'], UPLOAD_DIR . $newStoredName)) {
                    $error = t('upload.error.save_failed');
                    $fileReady = false;
                }
            }
        }

        if ($fileReady) {
            // "Opdater kladde" opdaterer den EKSISTERENDE kladde-version på stedet (samme række),
            // fremfor at oprette en ny — der er jo ikke tale om en ny officiel version endnu,
            // kun at den afventende kladde nu er klar og sendes til godkendelse.
            if ($attachNewFile) {
                $stmt = $db->prepare("UPDATE document_versions SET assigned_approver_id = ?, status = 'Til godkendelse', stored_filename = ?, original_filename = ? WHERE id = ?");
                $stmt->execute([$selectedApprover['id'], $newStoredName, $newOriginalFilename, $version['id']]);
            } else {
                $stmt = $db->prepare("UPDATE document_versions SET assigned_approver_id = ?, status = 'Til godkendelse' WHERE id = ?");
                $stmt->execute([$selectedApprover['id'], $version['id']]);
            }

            $stmt = $db->prepare("UPDATE documents SET status = 'Til godkendelse' WHERE id = ?");
            $stmt->execute([$id]);

            log_action($id, (int) $version['id'], 'Sendt til godkendelse', 'Sendt til godkendelse hos ' . $selectedApprover['name'] . ($attachNewFile ? ' (med opdateret fil)' : ''));

            require_once __DIR__ . '/includes/notifications.php';
            notify_assigned_approver($selectedApprover, $id, $doc['title'], $doc['doc_number'], '', $user['name']);

            header('Location: document.php?id=' . $id . '&msg=sent_for_approval');
            exit;
        }
    }
}

render_header(t('sendforapproval.title', ['title' => $doc['title']]));
?>
<p class="breadcrumb"><a href="document.php?id=<?= (int) $id ?>"><?= h($doc['title']) ?></a> &raquo; <?= h(t('sendforapproval.title', ['title' => $doc['title']])) ?></p>

<div class="card login-card">
    <h1><?= h(t('sendforapproval.title', ['title' => $doc['title']])) ?></h1>
    <?php if ($error): ?><p class="alert alert-danger"><?= h($error) ?></p><?php endif; ?>
    <p class="muted"><?= h(t('sendforapproval.intro')) ?></p>

    <?php if (!$approvers): ?>
        <p class="alert alert-danger"><?= h(t('upload.no_approvers')) ?></p>
    <?php else: ?>
        <form method="post" enctype="multipart/form-data">
    <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= (int) $id ?>">

            <?php if ($version['stored_filename']): ?>
                <div style="background: #fff; border: 1px solid #e3e5ea; border-radius: 8px; padding: 12px 14px; margin-bottom: 14px; font-size: 13px;">
                    <strong><?= h(t('sendforapproval.current_file')) ?></strong> <?= h($version['original_filename']) ?>
                </div>
            <?php endif; ?>

            <label style="display:flex; align-items:flex-start; gap:10px; border:1px solid #d7dae0; border-radius:8px; padding:12px 14px; margin-bottom:14px; cursor:pointer; font-weight:400;">
                <input type="checkbox" name="attach_new_file" id="attach_new_file" value="1" style="width:auto; margin-top:2px;" onchange="document.getElementById('new-file-field').style.display = this.checked ? 'block' : 'none';">
                <span style="font-size:13.5px;">
                    <strong><?= h(t('sendforapproval.field.attach_file')) ?></strong><br>
                    <span class="muted" style="font-size:12px;"><?= h(t('sendforapproval.field.attach_file_help')) ?></span>
                </span>
            </label>

            <div id="new-file-field" style="display:none;">
                <label><?= h(t('upload.field.file')) ?>
                    <input type="file" name="file">
                </label>
            </div>

            <label><?= h(t('upload.field.approver')) ?>
                <select name="approver_id" required>
                    <option value=""><?= h(t('upload.field.approver_choose')) ?></option>
                    <?php foreach ($approvers as $approver): ?>
                        <option value="<?= (int) $approver['id'] ?>"><?= h($approver['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <div class="button-row">
                <button type="submit"><?= h(t('sendforapproval.submit')) ?></button>
                <a class="button button-secondary" href="document.php?id=<?= (int) $id ?>"><?= h(t('edituser.cancel')) ?></a>
            </div>
        </form>
    <?php endif; ?>
</div>
<?php render_footer(); ?>
