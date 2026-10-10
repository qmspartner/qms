<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

$user = require_permission('audits', 'create');
$db = get_db();

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = trim($_POST['title'] ?? '');
    $auditorId = (int) ($_POST['auditor_id'] ?? 0);
    $plannedDate = trim($_POST['planned_date'] ?? '');
    $auditType = ($_POST['audit_type'] ?? 'Internt') === 'Eksternt' ? 'Eksternt' : 'Internt';
    $contractReference = trim($_POST['contract_reference'] ?? '');
    $linkedDocumentId = (int) ($_POST['linked_document_id'] ?? 0);

    $stmt = $db->prepare('SELECT id FROM users WHERE id = ? AND organization_id = ?');
    $stmt->execute([$auditorId, $user['organization_id']]);
    $validAuditor = $stmt->fetch();

    if ($title === '' || !$validAuditor || $plannedDate === '') {
        $error = t('newaudit.error.required');
    } elseif ($auditType === 'Eksternt' && $contractReference === '') {
        $error = t('newaudit.error.contract_reference_required');
    } else {
        $linkedDocId = null;
        if ($linkedDocumentId) {
            $stmt = $db->prepare('SELECT id FROM documents WHERE id = ? AND organization_id = ?');
            $stmt->execute([$linkedDocumentId, $user['organization_id']]);
            if ($stmt->fetch()) {
                $linkedDocId = $linkedDocumentId;
            }
        }

        $stmt = $db->prepare('
            INSERT INTO audits (organization_id, title, auditor_id, planned_date, linked_document_id, created_by, audit_type, contract_reference)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute([$user['organization_id'], $title, $auditorId, $plannedDate, $linkedDocId, $user['id'], $auditType, $auditType === 'Eksternt' ? $contractReference : null]);
        $auditId = (int) $db->lastInsertId();

        log_audit_action($auditId, 'Audit oprettet', $title);

        header('Location: audits.php');
        exit;
    }
}

$stmt = $db->prepare("SELECT id, name FROM users WHERE organization_id = ? AND active = 1 ORDER BY name");
$stmt->execute([$user['organization_id']]);
$orgUsers = $stmt->fetchAll();

$stmt = $db->prepare('SELECT id, title, doc_number FROM documents WHERE organization_id = ? ORDER BY title');
$stmt->execute([$user['organization_id']]);
$documents = $stmt->fetchAll();

render_header(t('newaudit.title'));
?>
<div class="card login-card">
    <h1><?= h(t('newaudit.title')) ?></h1>
    <?php if ($error): ?><p class="alert alert-danger"><?= h($error) ?></p><?php endif; ?>
    <form method="post" data-preserve="newaudit">
    <?= csrf_field() ?>
        <label><?= h(t('newaudit.field.title')) ?>
            <input type="text" name="title" required>
        </label>
        <label><?= h(t('newaudit.field.auditor')) ?>
            <select name="auditor_id" required>
                <?php foreach ($orgUsers as $u): ?>
                    <option value="<?= (int) $u['id'] ?>"><?= h($u['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label><?= h(t('newaudit.field.planned_date')) ?>
            <input type="date" name="planned_date" required>
        </label>
        <label><?= h(t('newaudit.field.audit_type')) ?>
            <select name="audit_type" id="audit-type-select" onchange="document.getElementById('contract-ref-field').style.display = (this.value === 'Eksternt') ? 'block' : 'none';">
                <option value="Internt"><?= h(audit_type_label('Internt')) ?></option>
                <option value="Eksternt"><?= h(audit_type_label('Eksternt')) ?></option>
            </select>
        </label>
        <div id="contract-ref-field" style="display:none;">
            <label><?= h(t('newaudit.field.contract_reference')) ?>
                <select name="contract_reference">
                    <option value=""><?= h(t('newaudit.field.contract_reference_choose')) ?></option>
                    <?php foreach (list_active_values('contract_reference') as $ref): ?>
                        <option value="<?= h($ref) ?>"><?= h($ref) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
        </div>
        <label><?= h(t('newaudit.field.linked_doc')) ?>
            <select name="linked_document_id">
                <option value=""><?= h(t('newcase.field.linked_doc_none')) ?></option>
                <?php foreach ($documents as $doc): ?>
                    <option value="<?= (int) $doc['id'] ?>"><?= h($doc['doc_number']) ?> — <?= h($doc['title']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <button type="submit"><?= h(t('newaudit.submit')) ?></button>
    </form>
</div>
<?php render_footer(); ?>
