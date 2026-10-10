<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

$user = require_permission('documents', 'create');
$db = get_db();

$documentId = (int) ($_GET['document_id'] ?? $_POST['document_id'] ?? 0);
$existingDoc = null;
if ($documentId) {
    $stmt = $db->prepare("SELECT * FROM documents WHERE id = ? AND organization_id = ? AND category = 'Leverance-kvalitetsplan'");
    $stmt->execute([$documentId, $user['organization_id']]);
    $existingDoc = $stmt->fetch();
    if (!$existingDoc) {
        http_response_code(404);
        exit(t('qualityplan.not_found'));
    }
}

$stmt = $db->prepare("SELECT id, name FROM users WHERE organization_id = ? AND role = 'approver' AND active = 1 ORDER BY name");
$stmt->execute([$user['organization_id']]);
$approvers = $stmt->fetchAll();

$stmt = $db->prepare('SELECT id, name FROM customers WHERE organization_id = ? ORDER BY name');
$stmt->execute([$user['organization_id']]);
$customers = $stmt->fetchAll();

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = trim($_POST['title'] ?? '');
    $docNumber = trim($_POST['doc_number'] ?? '');
    $contractRef = trim($_POST['contract_reference'] ?? '');
    $customerId = (int) ($_POST['customer_id'] ?? 0);
    $versionNumber = trim($_POST['version_number'] ?? '');
    $comment = trim($_POST['comment'] ?? '');
    $approverId = (int) ($_POST['approver_id'] ?? 0);
    $file = $_FILES['file'] ?? null;

    $stmt = $db->prepare("SELECT id, name, email FROM users WHERE id = ? AND organization_id = ? AND role = 'approver' AND active = 1");
    $stmt->execute([$approverId, $user['organization_id']]);
    $selectedApprover = $stmt->fetch();

    $effectiveContractRef = $existingDoc ? $existingDoc['baseline_reference'] : $contractRef;

    if (!$existingDoc && $contractRef === '') {
        $error = t('qualityplan.error.contract_required');
    } elseif (!$selectedApprover) {
        $error = t('upload.error.invalid_approver');
    } elseif ($comment === '') {
        $error = t('upload.error.change_description_required');
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
                if ($existingDoc) {
                    $docId = $existingDoc['id'];
                } else {
                    $stmt = $db->prepare("
                        INSERT INTO documents (organization_id, doc_number, title, category, owner_id, status, is_configuration_item, baseline_reference)
                        VALUES (?, ?, ?, 'Leverance-kvalitetsplan', ?, 'Til godkendelse', 1, ?)
                    ");
                    $stmt->execute([$user['organization_id'], $docNumber, $title, $user['id'], $contractRef]);
                    $docId = (int) $db->lastInsertId();
                }

                $stmt = $db->prepare('
                    INSERT INTO document_versions (document_id, version_number, stored_filename, original_filename, uploaded_by, assigned_approver_id, comment)
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ');
                $stmt->execute([$docId, $versionNumber, $storedName, $originalName, $user['id'], $selectedApprover['id'], $comment]);
                $versionId = (int) $db->lastInsertId();

                $stmt = $db->prepare("UPDATE documents SET status = 'Til godkendelse' WHERE id = ?");
                $stmt->execute([$docId]);

                $stmt = $db->prepare('
                    INSERT INTO quality_plan_details (
                        version_id, customer_id, scope, organization_responsibility, applicable_standards,
                        configuration_management_approach, risk_management_approach, supplier_management_approach,
                        inspection_test_plan, gqar_involvement
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ');
                $stmt->execute([
                    $versionId, $customerId ?: null,
                    trim($_POST['scope'] ?? '') ?: null,
                    trim($_POST['organization_responsibility'] ?? '') ?: null,
                    trim($_POST['applicable_standards'] ?? '') ?: null,
                    trim($_POST['configuration_management_approach'] ?? '') ?: null,
                    trim($_POST['risk_management_approach'] ?? '') ?: null,
                    trim($_POST['supplier_management_approach'] ?? '') ?: null,
                    trim($_POST['inspection_test_plan'] ?? '') ?: null,
                    trim($_POST['gqar_involvement'] ?? '') ?: null,
                ]);

                $db->commit();
                log_action($docId, $versionId, 'Ny version af leverance-kvalitetsplan', 'Version ' . $versionNumber . ' sendt til godkendelse hos ' . $selectedApprover['name']);

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

render_header($existingDoc ? t('qualityplan.title_new_version') : t('qualityplan.title_new'));
?>
<div class="card login-card">
    <h1><?= $existingDoc ? h(t('qualityplan.title_new_version')) . ' — ' . h($existingDoc['title']) : h(t('qualityplan.title_new')) ?></h1>
    <?php if ($error): ?><p class="alert alert-danger"><?= h($error) ?></p><?php endif; ?>

    <?php if (!$approvers): ?>
        <p class="alert alert-danger"><?= h(t('upload.no_approvers')) ?></p>
    <?php else: ?>
        <form method="post" enctype="multipart/form-data" data-preserve="newqualityplan">
    <?= csrf_field() ?>
            <input type="hidden" name="document_id" value="<?= (int) $documentId ?>">

            <?php if (!$existingDoc): ?>
                <label><?= h(t('qualityplan.field.title')) ?>
                    <input type="text" name="title" required>
                </label>
                <label><?= h(t('qualityplan.field.doc_number')) ?>
                    <input type="text" name="doc_number" required>
                </label>
                <label><?= h(t('qualityplan.field.contract')) ?>
                    <select name="contract_reference" required>
                        <option value=""><?= h(t('qualityplan.field.contract_choose')) ?></option>
                        <?php foreach (list_active_values('contract_reference') as $ref): ?>
                            <option value="<?= h($ref) ?>"><?= h($ref) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
            <?php else: ?>
                <p class="muted"><?= h(t('qualityplan.field.contract')) ?>: <?= h($existingDoc['baseline_reference']) ?></p>
            <?php endif; ?>

            <label><?= h(t('qualityplan.field.customer')) ?>
                <select name="customer_id">
                    <option value=""><?= h(t('qualityplan.field.customer_choose')) ?></option>
                    <?php foreach ($customers as $c): ?>
                        <option value="<?= (int) $c['id'] ?>"><?= h($c['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>

            <h2><?= h(t('qualityplan.section.scope')) ?></h2>
            <label><?= h(t('qualityplan.field.scope')) ?><textarea name="scope" rows="3"></textarea></label>
            <label><?= h(t('qualityplan.field.organization_responsibility')) ?><textarea name="organization_responsibility" rows="3"></textarea></label>
            <label><?= h(t('qualityplan.field.applicable_standards')) ?><textarea name="applicable_standards" rows="2"></textarea></label>

            <h2><?= h(t('qualityplan.section.approach')) ?></h2>
            <label><?= h(t('qualityplan.field.configuration_management_approach')) ?><textarea name="configuration_management_approach" rows="2"></textarea></label>
            <label><?= h(t('qualityplan.field.risk_management_approach')) ?><textarea name="risk_management_approach" rows="2"></textarea></label>
            <label><?= h(t('qualityplan.field.supplier_management_approach')) ?><textarea name="supplier_management_approach" rows="2"></textarea></label>
            <label><?= h(t('qualityplan.field.inspection_test_plan')) ?><textarea name="inspection_test_plan" rows="2"></textarea></label>
            <label><?= h(t('qualityplan.field.gqar_involvement')) ?><textarea name="gqar_involvement" rows="2"></textarea></label>

            <h2><?= h(t('qualityplan.section.approval')) ?></h2>
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
            <label><?= h(t('upload.field.comment_config')) ?>
                <textarea name="comment" rows="3" required></textarea>
            </label>
            <label><?= h(t('upload.field.file')) ?>
                <input type="file" name="file">
            </label>
            <button type="submit"><?= h(t('upload.submit')) ?></button>
        </form>
    <?php endif; ?>
</div>
<?php render_footer(); ?>
