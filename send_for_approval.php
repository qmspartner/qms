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

    $stmt = $db->prepare("SELECT id, name, email FROM users WHERE id = ? AND organization_id = ? AND role = 'approver' AND active = 1");
    $stmt->execute([$approverId, $user['organization_id']]);
    $selectedApprover = $stmt->fetch();

    if (!$selectedApprover) {
        $error = t('upload.error.invalid_approver');
    } else {
        $stmt = $db->prepare("UPDATE document_versions SET assigned_approver_id = ?, status = 'Til godkendelse' WHERE id = ?");
        $stmt->execute([$selectedApprover['id'], $version['id']]);

        $stmt = $db->prepare("UPDATE documents SET status = 'Til godkendelse' WHERE id = ?");
        $stmt->execute([$id]);

        log_action($id, (int) $version['id'], 'Sendt til godkendelse', 'Version ' . $version['version_number'] . ' sendt til godkendelse hos ' . $selectedApprover['name']);

        require_once __DIR__ . '/includes/notifications.php';
        notify_assigned_approver($selectedApprover, $id, $doc['title'], $doc['doc_number'], $version['version_number'], $user['name']);

        header('Location: document.php?id=' . $id . '&msg=sent_for_approval');
        exit;
    }
}

render_header(t('sendforapproval.title'));
?>
<p class="breadcrumb"><a href="document.php?id=<?= (int) $id ?>"><?= h($doc['title']) ?></a> &raquo; <?= h(t('sendforapproval.title')) ?></p>

<div class="card login-card">
    <h1><?= h(t('sendforapproval.title')) ?></h1>
    <?php if ($error): ?><p class="alert alert-danger"><?= h($error) ?></p><?php endif; ?>
    <p class="muted"><?= h(t('sendforapproval.intro', ['title' => $doc['title'], 'version' => $version['version_number']])) ?></p>

    <?php if (!$approvers): ?>
        <p class="alert alert-danger"><?= h(t('upload.no_approvers')) ?></p>
    <?php else: ?>
        <form method="post">
    <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= (int) $id ?>">
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
