<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

$user = require_permission('cases', 'create');
$db = get_db();

$error = '';

$auditId = (int) ($_GET['audit_id'] ?? $_POST['audit_id'] ?? 0);
$linkedAudit = null;
if ($auditId) {
    $stmt = $db->prepare('SELECT id, title FROM audits WHERE id = ? AND organization_id = ?');
    $stmt->execute([$auditId, $user['organization_id']]);
    $linkedAudit = $stmt->fetch();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = trim($_POST['title'] ?? '');
    $type = $_POST['type'] ?? '';
    $severity = $_POST['severity'] ?? '';
    $description = trim($_POST['description'] ?? '');
    $linkedDocumentId = (int) ($_POST['linked_document_id'] ?? 0);
    $linkedSupplierId = (int) ($_POST['supplier_id'] ?? 0);
    $customerId = (int) ($_POST['customer_id'] ?? 0);
    $requiresNotification = isset($_POST['requires_customer_notification']) ? 1 : 0;
    $contractReference = trim($_POST['contract_reference'] ?? '');
    $allowedTypes = ['Afvigelse', 'Forslag', 'Observation', 'Audit fund', 'Kundeklage', 'Mistænkt forfalsket del'];
    $allowedSeverities = ['', 'Høj', 'Mellem', 'Lav'];

    if ($type === 'Forslag') {
        $severity = '';
    }

    $uploadedFiles = $_FILES['attachments'] ?? null;
    $filesToSave = [];
    if ($uploadedFiles && !empty(array_filter($uploadedFiles['name']))) {
        foreach ($uploadedFiles['name'] as $i => $originalName) {
            if ($originalName === '') {
                continue;
            }
            if ($uploadedFiles['error'][$i] !== UPLOAD_ERR_OK) {
                $error = t('newcase.error.upload', ['file' => $originalName]);
                break;
            }
            if ($uploadedFiles['size'][$i] > MAX_FILE_SIZE) {
                $error = t('newcase.error.too_large', ['file' => $originalName]);
                break;
            }
            $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
            if (!in_array($ext, ALLOWED_EXTENSIONS, true)) {
                $error = t('newcase.error.bad_type', ['file' => $originalName, 'types' => implode(', ', ALLOWED_EXTENSIONS)]);
                break;
            }
            $filesToSave[] = [
                'tmp_name' => $uploadedFiles['tmp_name'][$i],
                'original_name' => $originalName,
            ];
        }
    }

    if ($title === '' || $description === '') {
        $error = t('newcase.error.required');
    } elseif (!in_array($type, $allowedTypes, true)) {
        $error = t('newcase.error.type');
    } elseif (!in_array($severity, $allowedSeverities, true)) {
        $error = t('newcase.error.severity');
    } elseif ($requiresNotification && $contractReference === '') {
        $error = t('newcase.error.contract_reference_required');
    } elseif ($type === 'Kundeklage' && $customerId === 0) {
        $error = t('newcase.error.customer_required');
    } elseif ($type === 'Mistænkt forfalsket del' && (int) ($_POST['supplier_id'] ?? 0) === 0) {
        $error = t('newcase.error.supplier_required_counterfeit');
    }

    if (!$error) {
        $linkedDocId = null;
        if ($linkedDocumentId) {
            $stmt = $db->prepare('SELECT id FROM documents WHERE id = ? AND organization_id = ?');
            $stmt->execute([$linkedDocumentId, $user['organization_id']]);
            if ($stmt->fetch()) {
                $linkedDocId = $linkedDocumentId;
            }
        }

        $linkedSupplierIdValid = null;
        if ($linkedSupplierId) {
            $stmt = $db->prepare('SELECT id FROM suppliers WHERE id = ? AND organization_id = ?');
            $stmt->execute([$linkedSupplierId, $user['organization_id']]);
            if ($stmt->fetch()) {
                $linkedSupplierIdValid = $linkedSupplierId;
            }
        }

        $customerIdValid = null;
        if ($customerId) {
            $stmt = $db->prepare('SELECT id FROM customers WHERE id = ? AND organization_id = ?');
            $stmt->execute([$customerId, $user['organization_id']]);
            if ($stmt->fetch()) {
                $customerIdValid = $customerId;
            }
        }

        $stmt = $db->prepare('
            INSERT INTO cases (organization_id, title, type, severity, description, reported_by, linked_document_id, audit_id, requires_customer_notification, supplier_id, contract_reference, customer_id)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute([$user['organization_id'], $title, $type, $severity ?: null, $description, $user['id'], $linkedDocId, $linkedAudit ? $linkedAudit['id'] : null, $requiresNotification, $linkedSupplierIdValid, $requiresNotification ? $contractReference : null, $customerIdValid]);
        $caseId = (int) $db->lastInsertId();

        foreach ($filesToSave as $file) {
            $ext = strtolower(pathinfo($file['original_name'], PATHINFO_EXTENSION));
            $storedName = bin2hex(random_bytes(16)) . '.' . $ext;
            if (move_uploaded_file($file['tmp_name'], UPLOAD_DIR . $storedName)) {
                $stmt = $db->prepare('
                    INSERT INTO case_attachments (organization_id, case_id, stored_filename, original_filename, uploaded_by)
                    VALUES (?, ?, ?, ?, ?)
                ');
                $stmt->execute([$user['organization_id'], $caseId, $storedName, $file['original_name'], $user['id']]);
            }
        }

        $logDetails = 'Type: ' . $type . ($filesToSave ? ' — ' . count($filesToSave) . ' bilag vedhæftet' : '');
        log_case_action($caseId, 'Kvalitetshændelse registreret', $logDetails);

        // Opgradér leverandørens status til "Under vurdering", hvis den i dag er "Godkendt"
        if ($type === 'Mistænkt forfalsket del' && $linkedSupplierIdValid) {
            $stmt = $db->prepare("UPDATE suppliers SET status = 'Under vurdering' WHERE id = ? AND organization_id = ? AND status = 'Godkendt'");
            $stmt->execute([$linkedSupplierIdValid, $user['organization_id']]);
        }

        header('Location: ' . ($linkedAudit ? 'audit.php?id=' . $linkedAudit['id'] : 'cases.php'));
        exit;
    }
}

$stmt = $db->prepare('SELECT id, title, doc_number FROM documents WHERE organization_id = ? ORDER BY title');
$stmt->execute([$user['organization_id']]);
$documents = $stmt->fetchAll();

$stmt = $db->prepare('SELECT id, name FROM suppliers WHERE organization_id = ? ORDER BY name');
$stmt->execute([$user['organization_id']]);
$suppliersForCase = $stmt->fetchAll();

$stmt = $db->prepare('SELECT id, name FROM customers WHERE organization_id = ? ORDER BY name');
$stmt->execute([$user['organization_id']]);
$customersForCase = $stmt->fetchAll();

render_header(t('newcase.title'));
?>
<div class="card login-card">
    <h1><?= h(t('newcase.title')) ?></h1>
    <?php if ($linkedAudit): ?>
        <p class="muted"><?= h(t('audit.found_under')) ?>: <?= h($linkedAudit['title']) ?></p>
    <?php endif; ?>
    <?php if ($error): ?><p class="alert alert-danger"><?= h($error) ?></p><?php endif; ?>
    <?php
        // Genbrug tidligere indtastede værdier, hvis formularen vises igen pga. en fejl
        $defaultType = $linkedAudit ? 'Audit fund' : 'Afvigelse';
        $vType = $_POST['type'] ?? $defaultType;
        $vSeverity = $_POST['severity'] ?? '';
        $vTitle = $_POST['title'] ?? '';
        $vDescription = $_POST['description'] ?? '';
        $vLinkedDoc = (int) ($_POST['linked_document_id'] ?? 0);
        $vCustomerId = (int) ($_POST['customer_id'] ?? 0);
        $vSupplierId = (int) ($_POST['supplier_id'] ?? 0);
        $vRequiresNotif = isset($_POST['requires_customer_notification']);
        $vContractRef = $_POST['contract_reference'] ?? '';
    ?>
    <?php if ($error): ?>
        <p class="muted"><?= h(t('newcase.attachments_note')) ?></p>
    <?php endif; ?>
    <form method="post" enctype="multipart/form-data">
    <?= csrf_field() ?>
        <input type="hidden" name="audit_id" value="<?= (int) $auditId ?>">
        <label><?= h(t('newcase.field.title')) ?>
            <input type="text" name="title" value="<?= h($vTitle) ?>" required>
        </label>
        <label><?= h(t('newcase.field.type')) ?>
            <select name="type" id="type-select" onchange="toggleSeverity(this.value)" required>
                <option value="Afvigelse" <?= $vType === 'Afvigelse' ? 'selected' : '' ?>><?= h(type_label('Afvigelse')) ?></option>
                <option value="Forslag" <?= $vType === 'Forslag' ? 'selected' : '' ?>><?= h(t('cases.tab.suggestion')) ?></option>
                <option value="Observation" <?= $vType === 'Observation' ? 'selected' : '' ?>><?= h(type_label('Observation')) ?></option>
                <option value="Audit fund" <?= $vType === 'Audit fund' ? 'selected' : '' ?>><?= h(type_label('Audit fund')) ?></option>
                <option value="Kundeklage" <?= $vType === 'Kundeklage' ? 'selected' : '' ?>><?= h(type_label('Kundeklage')) ?></option>
                <option value="Mistænkt forfalsket del" <?= $vType === 'Mistænkt forfalsket del' ? 'selected' : '' ?>><?= h(type_label('Mistænkt forfalsket del')) ?></option>
            </select>
        </label>
        <div id="severity-field">
            <label><?= h(t('newcase.field.severity')) ?>
                <select name="severity">
                    <option value=""><?= h(t('newcase.field.severity_none')) ?></option>
                    <option value="Høj" <?= $vSeverity === 'Høj' ? 'selected' : '' ?>><?= h(severity_label('Høj')) ?></option>
                    <option value="Mellem" <?= $vSeverity === 'Mellem' ? 'selected' : '' ?>><?= h(severity_label('Mellem')) ?></option>
                    <option value="Lav" <?= $vSeverity === 'Lav' ? 'selected' : '' ?>><?= h(severity_label('Lav')) ?></option>
                </select>
            </label>
        </div>
        <label><?= h(t('newcase.field.description')) ?>
            <textarea name="description" rows="4" required><?= h($vDescription) ?></textarea>
        </label>
        <label><?= h(t('newcase.field.linked_doc')) ?>
            <select name="linked_document_id">
                <option value=""><?= h(t('newcase.field.linked_doc_none')) ?></option>
                <?php foreach ($documents as $doc): ?>
                    <option value="<?= (int) $doc['id'] ?>" <?= $vLinkedDoc === (int) $doc['id'] ? 'selected' : '' ?>><?= h($doc['doc_number']) ?> — <?= h($doc['title']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <div id="customer-field" style="display:none;">
            <label><?= h(t('newcase.field.customer')) ?>
                <select name="customer_id">
                    <option value=""><?= h(t('newcase.field.customer_choose')) ?></option>
                    <?php foreach ($customersForCase as $cust): ?>
                        <option value="<?= (int) $cust['id'] ?>" <?= $vCustomerId === (int) $cust['id'] ? 'selected' : '' ?>><?= h($cust['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
        </div>
        <label><?= h(t('newcase.field.supplier')) ?>
            <select name="supplier_id">
                <option value=""><?= h(t('newcase.field.supplier_none')) ?></option>
                <?php foreach ($suppliersForCase as $sup): ?>
                    <option value="<?= (int) $sup['id'] ?>" <?= $vSupplierId === (int) $sup['id'] ? 'selected' : '' ?>><?= h($sup['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label><?= h(t('newcase.field.attachments', ['types' => implode(', ', ALLOWED_EXTENSIONS)])) ?>
            <input type="file" name="attachments[]" multiple>
        </label>
        <label style="display:flex; align-items:center; gap:8px; flex-direction:row;">
            <input type="checkbox" name="requires_customer_notification" value="1" style="width:auto;" <?= $vRequiresNotif ? 'checked' : '' ?> onchange="document.getElementById('case-contract-ref-field').style.display = this.checked ? 'block' : 'none';">
            <?= h(t('newcase.field.customer_notification')) ?>
        </label>
        <div id="case-contract-ref-field" style="display:<?= $vRequiresNotif ? 'block' : 'none' ?>;">
            <label><?= h(t('newcase.field.contract_reference')) ?>
                <select name="contract_reference">
                    <option value=""><?= h(t('newcase.field.contract_reference_choose')) ?></option>
                    <?php foreach (list_active_values('contract_reference') as $ref): ?>
                        <option value="<?= h($ref) ?>" <?= $vContractRef === $ref ? 'selected' : '' ?>><?= h($ref) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
        </div>
        <button type="submit"><?= h(t('newcase.submit')) ?></button>
    </form>
</div>
<script>
function toggleSeverity(type) {
    document.getElementById('severity-field').style.display = (type === 'Forslag') ? 'none' : 'block';
    document.getElementById('customer-field').style.display = (type === 'Kundeklage') ? 'block' : 'none';
}
toggleSeverity(document.getElementById('type-select').value);
</script>
<?php render_footer(); ?>
