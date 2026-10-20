<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

$user = require_permission('documents', 'create');
$db = get_db();

$documentId = (int) ($_GET['document_id'] ?? $_POST['document_id'] ?? 0);
$prefillCategory = $_GET['category'] ?? '';
$existingDoc = null;
if ($documentId) {
    $stmt = $db->prepare('SELECT * FROM documents WHERE id = ? AND organization_id = ?');
    $stmt->execute([$documentId, $user['organization_id']]);
    $existingDoc = $stmt->fetch();
    if (!$existingDoc) {
        http_response_code(404);
        exit(t('doc.not_found'));
    }
}

$stmt = $db->prepare("SELECT id, name FROM users WHERE organization_id = ? AND role = 'approver' AND active = 1 ORDER BY name");
$stmt->execute([$user['organization_id']]);
$approvers = $stmt->fetchAll();

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = trim($_POST['title'] ?? '');
    $manualDocNumber = trim($_POST['doc_number'] ?? '');
    $category = $_POST['category'] ?? '';
    $isoClause = trim($_POST['iso_clause'] ?? '');
    $isConfigItem = isset($_POST['is_configuration_item']) ? 1 : 0;
    $baselineRef = trim($_POST['baseline_reference'] ?? '');
    $versionNumber = trim($_POST['version_number'] ?? '');
    $comment = trim($_POST['comment'] ?? '');
    $approverId = (int) ($_POST['approver_id'] ?? 0);
    $isImported = isset($_POST['is_imported']) ? 1 : 0;
    $originalIssueDate = trim($_POST['original_issue_date'] ?? '');
    $section = trim($_POST['section'] ?? '');
    $operation = trim($_POST['operation'] ?? '');
    $isTemplate = (!$existingDoc && isset($_POST['is_template'])) ? 1 : 0;

    $file = $_FILES['file'] ?? null;

    // Bekræft at den valgte godkender rent faktisk er en aktiv godkender i samme organisation
    $stmt = $db->prepare("SELECT id, name, email FROM users WHERE id = ? AND organization_id = ? AND role = 'approver' AND active = 1");
    $stmt->execute([$approverId, $user['organization_id']]);
    $selectedApprover = $stmt->fetch();

    // Beregn dokument-ID (kun relevant for nye dokumenter — ID'et er låst for eksisterende)
    // Skabeloner får altid præfikset TMP, uanset kategoriens eget præfiks (eller mangel på samme)
    $categoryPrefix = !$existingDoc ? document_category_prefix($category) : null;
    $effectivePrefix = $isTemplate ? 'TMP' : $categoryPrefix;
    $usesStructuredId = !$existingDoc && !$isImported && $effectivePrefix;

    // Hvis der allerede findes en godkendt skabelon i den valgte kategori, skal
    // brugeren bekræfte at have brugt den, før dokumentet kan oprettes.
    $currentTemplateDoc = !$existingDoc ? get_latest_template_document($category) : null;
    $templateConfirmed = isset($_POST['template_confirmed']);

    if ($existingDoc) {
        $docNumber = $existingDoc['doc_number'];
    } elseif ($usesStructuredId) {
        $docNumber = $effectivePrefix . '-' . $section . '-' . $operation;
    } else {
        $docNumber = $manualDocNumber;
    }

    $docNumberDuplicate = false;
    if (!$existingDoc && $docNumber !== '') {
        $stmt = $db->prepare('SELECT id FROM documents WHERE organization_id = ? AND doc_number = ?');
        $stmt->execute([$user['organization_id'], $docNumber]);
        $docNumberDuplicate = (bool) $stmt->fetch();
    }

    if ($isConfigItem && $baselineRef === '') {
        $error = t('upload.error.contract_reference_required');
    } elseif (!$existingDoc && $usesStructuredId && ($section === '' || $operation === '')) {
        $error = t('upload.error.section_operation_required');
    } elseif (!$existingDoc && !$usesStructuredId && $manualDocNumber === '') {
        $error = t('upload.error.doc_number_required');
    } elseif ($docNumberDuplicate) {
        $error = t('upload.error.doc_number_duplicate');
    } elseif (!$selectedApprover) {
        $error = t('upload.error.invalid_approver');
    } elseif ($existingDoc && $existingDoc['is_configuration_item'] && $comment === '') {
        $error = t('upload.error.change_description_required');
    } elseif (!$existingDoc && $currentTemplateDoc && !$templateConfirmed) {
        $error = t('upload.error.template_confirmation_required');
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
                    if ($existingDoc) {
                        $docId = $existingDoc['id'];
                    } else {
                        $stmt = $db->prepare('
                            INSERT INTO documents (organization_id, doc_number, title, category, is_template, iso_clause, owner_id, status, is_configuration_item, baseline_reference, section, operation, is_imported, original_issue_date)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                        ');
                        $stmt->execute([
                            $user['organization_id'], $docNumber, $title, $category, $isTemplate, $isoClause ?: null, $user['id'], 'Til godkendelse',
                            $isConfigItem, $isConfigItem ? ($baselineRef ?: null) : null,
                            $usesStructuredId ? $section : null, $usesStructuredId ? $operation : null,
                            $isImported, ($isImported && $originalIssueDate !== '') ? $originalIssueDate : null,
                        ]);
                        $docId = (int) $db->lastInsertId();
                    }

                    $stmt = $db->prepare('
                        INSERT INTO document_versions (document_id, version_number, stored_filename, original_filename, uploaded_by, assigned_approver_id, comment)
                        VALUES (?, ?, ?, ?, ?, ?, ?)
                    ');
                    $stmt->execute([$docId, $versionNumber, $storedName, $file['name'], $user['id'], $selectedApprover['id'], $comment ?: null]);
                    $versionId = (int) $db->lastInsertId();

                    $stmt = $db->prepare("UPDATE documents SET status = 'Til godkendelse' WHERE id = ?");
                    $stmt->execute([$docId]);

                    $db->commit();
                    log_action($docId, $versionId, 'Ny version uploadet', 'Version ' . $versionNumber . ' sendt til godkendelse hos ' . $selectedApprover['name']);

                    require_once __DIR__ . '/includes/notifications.php';
                    $docTitle = $existingDoc ? $existingDoc['title'] : $title;
                    $finalDocNumber = $existingDoc ? $existingDoc['doc_number'] : $docNumber;
                    notify_assigned_approver($selectedApprover, $docId, $docTitle, $finalDocNumber, $versionNumber, $user['name']);

                    header('Location: index.php');
                    exit;
                } catch (Exception $e) {
                    $db->rollBack();
                    $error = t('upload.error.generic');
                }
            }
        }
    }
}

render_header($existingDoc ? t('upload.title_new_version') : t('upload.title_new'));
$categories = list_active_values('document_category');
?>
<div class="card login-card">
    <h1><?= $existingDoc ? h(t('upload.title_version_of')) . ' ' . h($existingDoc['title']) : h(t('upload.title_new')) ?></h1>
    <?php if ($error): ?><p class="alert alert-danger"><?= h($error) ?></p><?php endif; ?>

    <?php if (!$approvers): ?>
        <p class="alert alert-danger"><?= h(t('upload.no_approvers')) ?></p>
    <?php else: ?>
        <form method="post" enctype="multipart/form-data" data-preserve="uploadversion">
    <?= csrf_field() ?>
            <input type="hidden" name="document_id" value="<?= (int) $documentId ?>">

            <?php if (!$existingDoc): ?>
                <label><?= h(t('upload.field.title')) ?>
                    <input type="text" name="title" required>
                </label>
                <label><?= h(t('upload.field.category')) ?>
                    <select name="category" id="category-select" required onchange="if (this.value === 'Leverance-kvalitetsplan') { window.location = 'new_quality_plan.php'; } else { updateIdFields(); }">
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?= h($cat) ?>" <?= $prefillCategory === $cat ? 'selected' : '' ?>><?= h(category_label($cat)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <div id="template-nudge-box" style="display:none; background:#f6f6f6; border-radius:6px; padding:10px 12px; margin-bottom:14px;"></div>

                <label style="display:flex; align-items:flex-start; gap:10px; background:#eef4ff; border:1px solid #c3d6fb; border-radius:8px; padding:12px 14px; margin-bottom:14px; flex-direction:row; font-weight:400;">
                    <input type="checkbox" name="is_template" id="is_template" value="1" style="width:auto; margin-top:2px;" onchange="updateIdFields();">
                    <span style="font-size:13.5px;">
                        <strong><?= h(t('upload.field.is_template')) ?></strong><br>
                        <span class="muted" style="font-size:12px;"><?= h(t('upload.field.is_template_help')) ?></span>
                    </span>
                </label>

                <label style="display:flex; align-items:center; gap:8px; flex-direction:row;">
                    <input type="checkbox" name="is_imported" id="is_imported" value="1" style="width:auto;" onchange="updateIdFields();">
                    <?= h(t('upload.field.is_imported')) ?>
                </label>

                <div id="imported-date-field" style="display:none;">
                    <label><?= h(t('upload.field.original_issue_date')) ?>
                        <input type="date" name="original_issue_date">
                    </label>
                </div>

                <div id="structured-id-field" style="display:none;">
                    <label><?= h(t('upload.field.section')) ?> <?php help_tip('help.tip.doc_id_fields'); ?>
                        <input type="text" name="section" id="section_input" oninput="updatePreview();">
                    </label>
                    <label><?= h(t('upload.field.operation')) ?>
                        <input type="text" name="operation" id="operation_input" oninput="updatePreview();">
                    </label>
                    <p class="muted" style="margin:-8px 0 14px;"><?= h(t('upload.field.id_preview')) ?>: <strong id="id-preview">—</strong></p>
                </div>

                <div id="free-docnumber-field">
                    <label><?= h(t('upload.field.docnumber')) ?>
                        <input type="text" name="doc_number" id="doc_number_input">
                    </label>
                </div>

                <label><?= h(t('upload.field.iso_clause')) ?>
                    <input type="text" name="iso_clause" placeholder="fx 7.5.3">
                </label>
                <label style="display:flex; align-items:center; gap:8px; flex-direction:row;">
                    <input type="checkbox" name="is_configuration_item" id="is_configuration_item" value="1" style="width:auto;" onchange="document.getElementById('baseline-field').style.display = this.checked ? 'block' : 'none';">
                    <?= h(t('upload.field.config_item')) ?> <?php help_tip('help.tip.config_item'); ?>
                </label>
                <div id="baseline-field" style="display:none;">
                    <label><?= h(t('upload.field.baseline_ref')) ?>
                        <select name="baseline_reference">
                            <option value=""><?= h(t('upload.field.baseline_ref_choose')) ?></option>
                            <?php foreach (list_active_values('contract_reference') as $ref): ?>
                                <option value="<?= h($ref) ?>"><?= h($ref) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                </div>
            <?php endif; ?>

            <label><?= h(t('upload.field.version')) ?>
                <input type="text" name="version_number" placeholder="fx 1.0" required>
            </label>
            <label><?= h(t('upload.field.approver')) ?>
                <select name="approver_id" required>
                    <option value=""><?= h(t('upload.field.approver_choose')) ?></option>
                    <?php foreach ($approvers as $approver): ?>
                        <option value="<?= (int) $approver['id'] ?>"><?= h($approver['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <?php $isConfigUpdate = $existingDoc && $existingDoc['is_configuration_item']; ?>
            <label><?= h($isConfigUpdate ? t('upload.field.comment_config') : t('upload.field.comment')) ?>
                <textarea name="comment" rows="3" <?= $isConfigUpdate ? 'required' : '' ?>></textarea>
            </label>
            <label><?= h(t('upload.field.file')) ?>
                <input type="file" name="file" required>
            </label>
            <button type="submit"><?= h(t('upload.submit')) ?></button>
        </form>
    <?php endif; ?>
</div>
<?php if (!$existingDoc): ?>
<script>
const categoryPrefixes = <?= json_encode(document_category_prefixes_map()) ?>;
const templateInfo = <?= json_encode(document_category_template_info_map()) ?>;

function updateIdFields() {
    const imported = document.getElementById('is_imported').checked;
    const isTemplate = document.getElementById('is_template').checked;
    const category = document.getElementById('category-select').value;
    const prefix = isTemplate ? 'TMP' : (categoryPrefixes[category] || null);
    const useStructured = !imported && !!prefix;

    document.getElementById('imported-date-field').style.display = imported ? 'block' : 'none';
    document.getElementById('structured-id-field').style.display = useStructured ? 'block' : 'none';
    document.getElementById('free-docnumber-field').style.display = useStructured ? 'none' : 'block';

    document.getElementById('doc_number_input').required = !useStructured;
    document.getElementById('section_input').required = useStructured;
    document.getElementById('operation_input').required = useStructured;

    if (useStructured) { updatePreview(); }

    updateTemplateNudge();
}

function updateTemplateNudge() {
    const category = document.getElementById('category-select').value;
    const box = document.getElementById('template-nudge-box');
    const info = templateInfo[category];

    if (!info) {
        box.style.display = 'none';
        box.innerHTML = '';
        return;
    }

    box.style.display = 'block';

    const version = info.version ? ' v' + info.version : '';
    box.innerHTML =
        '<div style="display:flex; align-items:center; justify-content:space-between; gap:10px; flex-wrap:wrap;">' +
        '<span style="font-size:13px;"><strong><?= h(t('upload.template_box.title')) ?></strong> ' +
        info.title.replace(/</g, '&lt;') + version + '</span>' +
        '<a href="download_template.php?version_id=' + info.versionId + '" class="button-secondary" style="padding:5px 10px; font-size:12px;" target="_blank"><?= h(t('upload.template_box.download')) ?></a>' +
        '</div>' +
        '<label style="display:flex; align-items:flex-start; gap:8px; margin-top:10px; font-weight:400; font-size:12.5px;">' +
        '<input type="checkbox" name="template_confirmed" value="1" required style="width:auto; margin-top:2px;">' +
        '<span><?= h(t('upload.template_box.confirm_label')) ?></span></label>';
}

function updatePreview() {
    const isTemplate = document.getElementById('is_template').checked;
    const category = document.getElementById('category-select').value;
    const prefix = isTemplate ? 'TMP' : (categoryPrefixes[category] || '');
    const section = document.getElementById('section_input').value || '…';
    const operation = document.getElementById('operation_input').value || '…';
    document.getElementById('id-preview').textContent = prefix + '-' + section + '-' + operation;
}

updateIdFields();
</script>
<?php endif; ?>
<?php render_footer(); ?>
