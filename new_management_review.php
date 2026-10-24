<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

$user = require_permission('records', 'create');
deny_gqar($user);
$db = get_db();

$recordId = (int) ($_GET['record_id'] ?? $_POST['record_id'] ?? 0);
$existingRecord = null;
if ($recordId) {
    $stmt = $db->prepare("SELECT * FROM records WHERE id = ? AND organization_id = ? AND type = 'Ledelsens evaluering'");
    $stmt->execute([$recordId, $user['organization_id']]);
    $existingRecord = $stmt->fetch();
    if (!$existingRecord) {
        http_response_code(404);
        exit(t('record.not_found'));
    }
}

// Find datoen for den seneste evaluering (hvis nogen), så vi kan afgrænse "siden sidst".
$previousReviewDate = null;
if ($existingRecord) {
    $stmt = $db->prepare('
        SELECT mrd.review_date
        FROM management_review_details mrd
        JOIN record_versions v ON v.id = mrd.version_id
        WHERE v.record_id = ?
        ORDER BY mrd.review_date DESC
        LIMIT 1
    ');
    $stmt->execute([$recordId]);
    $row = $stmt->fetch();
    $previousReviewDate = $row['review_date'] ?? null;
}

function build_system_value(int $count, ?string $previousDate): string {
    return $previousDate
        ? t('mgmtreview.system_count_text', ['count' => $count, 'date' => format_date($previousDate)])
        : t('mgmtreview.system_count_text_first', ['count' => $count]);
}

// Beregn systemværdier for de fire kvantificerbare områder.
$orgId = $user['organization_id'];
$sinceClause = $previousReviewDate ? ' AND created_at >= ?' : '';
$sinceParam = $previousReviewDate ? [$previousReviewDate] : [];

$stmt = $db->prepare("SELECT COUNT(*) AS c FROM cases WHERE organization_id = ? AND type IN ('Afvigelse', 'Audit fund')" . $sinceClause);
$stmt->execute(array_merge([$orgId], $sinceParam));
$deviationsCount = (int) $stmt->fetch()['c'];

$auditsDateClause = $previousReviewDate ? ' AND completed_date >= ?' : '';
$stmt = $db->prepare("SELECT COUNT(*) AS c FROM audits WHERE organization_id = ? AND status = 'Gennemført'" . $auditsDateClause);
$stmt->execute($previousReviewDate ? [$orgId, $previousReviewDate] : [$orgId]);
$auditsCount = (int) $stmt->fetch()['c'];

$supplierDateClause = $previousReviewDate ? ' AND se.evaluation_date >= ?' : '';
$stmt = $db->prepare("
    SELECT COUNT(*) AS c FROM supplier_evaluations se
    JOIN suppliers s ON s.id = se.supplier_id
    WHERE s.organization_id = ?" . $supplierDateClause
);
$stmt->execute($previousReviewDate ? [$orgId, $previousReviewDate] : [$orgId]);
$suppliersCount = (int) $stmt->fetch()['c'];

$stmt = $db->prepare("SELECT COUNT(*) AS c FROM risks WHERE organization_id = ?" . $sinceClause);
$stmt->execute(array_merge([$orgId], $sinceParam));
$risksCount = (int) $stmt->fetch()['c'];

$deviationsSystemValue = build_system_value($deviationsCount, $previousReviewDate);
$auditsSystemValue = build_system_value($auditsCount, $previousReviewDate);
$suppliersSystemValue = build_system_value($suppliersCount, $previousReviewDate);
$risksSystemValue = build_system_value($risksCount, $previousReviewDate);

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = trim($_POST['title'] ?? '');
    $reviewDate = trim($_POST['review_date'] ?? '');
    $nextUpdateDate = trim($_POST['next_update_date'] ?? '');
    $retentionYears = $_POST['retention_years'] !== '' ? (int) $_POST['retention_years'] : null;
    $file = $_FILES['file'] ?? null;

    if (($title === '' && !$existingRecord) || $reviewDate === '') {
        $error = t('mgmtreview.error.required');
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
                if ($existingRecord) {
                    $recId = $existingRecord['id'];
                    $stmt = $db->prepare('UPDATE records SET next_update_date = ?, retention_years = ? WHERE id = ?');
                    $stmt->execute([$nextUpdateDate ?: null, $retentionYears, $recId]);
                } else {
                    $stmt = $db->prepare("
                        INSERT INTO records (organization_id, title, type, owner_id, next_update_date, retention_years)
                        VALUES (?, ?, 'Ledelsens evaluering', ?, ?, ?)
                    ");
                    $stmt->execute([$orgId, $title, $user['id'], $nextUpdateDate ?: null, $retentionYears]);
                    $recId = (int) $db->lastInsertId();
                }

                $stmt = $db->prepare('
                    INSERT INTO record_versions (record_id, version_number, stored_filename, original_filename, uploaded_by)
                    VALUES (?, ?, ?, ?, ?)
                ');
                $stmt->execute([$recId, $reviewDate, $storedName, $originalName, $user['id']]);
                $versionId = (int) $db->lastInsertId();

                $stmt = $db->prepare('UPDATE records SET current_version_id = ? WHERE id = ?');
                $stmt->execute([$versionId, $recId]);

                $stmt = $db->prepare('
                    INSERT INTO management_review_details (
                        version_id, review_date, previous_review_date, participants,
                        followup_previous_actions, external_internal_changes,
                        customer_satisfaction, quality_objectives,
                        deviations_system_value, deviations_system_count, deviations_manual_count, deviations_note,
                        audits_system_value, audits_system_count, audits_manual_count, audits_note,
                        suppliers_system_value, suppliers_system_count, suppliers_manual_count, suppliers_note,
                        risks_system_value, risks_system_count, risks_manual_count, risks_note,
                        resource_adequacy, improvement_opportunities,
                        decisions_improvements, decisions_system_changes, decisions_resources
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ');
                $stmt->execute([
                    $versionId, $reviewDate, $previousReviewDate, trim($_POST['participants'] ?? '') ?: null,
                    trim($_POST['followup_previous_actions'] ?? '') ?: null, trim($_POST['external_internal_changes'] ?? '') ?: null,
                    trim($_POST['customer_satisfaction'] ?? '') ?: null, trim($_POST['quality_objectives'] ?? '') ?: null,
                    $deviationsSystemValue, $deviationsCount, $_POST['deviations_manual_count'] !== '' ? (int) $_POST['deviations_manual_count'] : null, trim($_POST['deviations_note'] ?? '') ?: null,
                    $auditsSystemValue, $auditsCount, $_POST['audits_manual_count'] !== '' ? (int) $_POST['audits_manual_count'] : null, trim($_POST['audits_note'] ?? '') ?: null,
                    $suppliersSystemValue, $suppliersCount, $_POST['suppliers_manual_count'] !== '' ? (int) $_POST['suppliers_manual_count'] : null, trim($_POST['suppliers_note'] ?? '') ?: null,
                    $risksSystemValue, $risksCount, $_POST['risks_manual_count'] !== '' ? (int) $_POST['risks_manual_count'] : null, trim($_POST['risks_note'] ?? '') ?: null,
                    trim($_POST['resource_adequacy'] ?? '') ?: null, trim($_POST['improvement_opportunities'] ?? '') ?: null,
                    trim($_POST['decisions_improvements'] ?? '') ?: null, trim($_POST['decisions_system_changes'] ?? '') ?: null, trim($_POST['decisions_resources'] ?? '') ?: null,
                ]);

                $db->commit();
                log_record_action($recId, $existingRecord ? 'Ny ledelsesevaluering registreret' : 'Ledelsesevaluering oprettet', $reviewDate);

                header('Location: meetings.php');
                exit;
            } catch (Exception $e) {
                $db->rollBack();
                $error = t('upload.error.generic');
            }
        }
    }
}

render_header($existingRecord ? t('mgmtreview.title_new_version') : t('mgmtreview.title_new'));
$suggestedNextDate = date('Y-m-d', strtotime('+12 months'));
?>
<div class="card login-card">
    <h1><?= $existingRecord ? h(t('mgmtreview.title_new_version')) . ' — ' . h($existingRecord['title']) : h(t('mgmtreview.title_new')) ?></h1>
    <?php if ($error): ?><p class="alert alert-danger"><?= h($error) ?></p><?php endif; ?>
    <p class="muted">
        <?= $previousReviewDate
            ? h(t('mgmtreview.previous_review_label', ['date' => format_date($previousReviewDate)]))
            : h(t('mgmtreview.first_review_label')) ?>
    </p>

    <form method="post" enctype="multipart/form-data" data-preserve="newmanagementreview">
    <?= csrf_field() ?>
        <input type="hidden" name="record_id" value="<?= (int) $recordId ?>">

        <?php if (!$existingRecord): ?>
            <label><?= h(t('mgmtreview.field.title')) ?>
                <input type="text" name="title" required>
            </label>
        <?php endif; ?>
        <label><?= h(t('mgmtreview.field.review_date')) ?>
            <input type="date" name="review_date" value="<?= h(date('Y-m-d')) ?>" required>
        </label>
        <label><?= h(t('mgmtreview.field.participants')) ?>
            <input type="text" name="participants">
        </label>
        <label><?= h(t('mgmtreview.field.next_update')) ?>
            <input type="date" name="next_update_date" value="<?= h($existingRecord['next_update_date'] ?? $suggestedNextDate) ?>">
        </label>
        <label><?= h(t('mgmtreview.field.retention')) ?>
            <input type="number" name="retention_years" min="1" max="50" value="<?= h($existingRecord['retention_years'] ?? '5') ?>">
        </label>

        <h2><?= h(t('mgmtreview.section.followup')) ?></h2>
        <label><textarea name="followup_previous_actions" rows="3"></textarea></label>

        <h2><?= h(t('mgmtreview.section.changes')) ?></h2>
        <label><textarea name="external_internal_changes" rows="3"></textarea></label>

        <h2><?= h(t('mgmtreview.section.performance')) ?></h2>

        <label><?= h(t('mgmtreview.field.customer_satisfaction')) ?>
            <textarea name="customer_satisfaction" rows="2"></textarea>
        </label>
        <label><?= h(t('mgmtreview.field.quality_objectives')) ?>
            <textarea name="quality_objectives" rows="2"></textarea>
        </label>

        <label><?= h(t('mgmtreview.field.deviations')) ?></label>
        <p class="muted" style="margin-top:-10px;"><?= h(t('mgmtreview.system_value')) ?>: <?= h($deviationsSystemValue) ?></p>
        <div class="inline-grid-form" style="margin-bottom:14px;">
            <input type="number" name="deviations_manual_count" placeholder="<?= h(t('mgmtreview.manual_value')) ?>">
            <input type="text" name="deviations_note" placeholder="<?= h(t('mgmtreview.note')) ?>">
        </div>

        <label><?= h(t('mgmtreview.field.audits')) ?></label>
        <p class="muted" style="margin-top:-10px;"><?= h(t('mgmtreview.system_value')) ?>: <?= h($auditsSystemValue) ?></p>
        <div class="inline-grid-form" style="margin-bottom:14px;">
            <input type="number" name="audits_manual_count" placeholder="<?= h(t('mgmtreview.manual_value')) ?>">
            <input type="text" name="audits_note" placeholder="<?= h(t('mgmtreview.note')) ?>">
        </div>

        <label><?= h(t('mgmtreview.field.suppliers')) ?></label>
        <p class="muted" style="margin-top:-10px;"><?= h(t('mgmtreview.system_value')) ?>: <?= h($suppliersSystemValue) ?></p>
        <div class="inline-grid-form" style="margin-bottom:14px;">
            <input type="number" name="suppliers_manual_count" placeholder="<?= h(t('mgmtreview.manual_value')) ?>">
            <input type="text" name="suppliers_note" placeholder="<?= h(t('mgmtreview.note')) ?>">
        </div>

        <h2><?= h(t('mgmtreview.field.risks')) ?></h2>
        <p class="muted" style="margin-top:-10px;"><?= h(t('mgmtreview.system_value')) ?>: <?= h($risksSystemValue) ?></p>
        <div class="inline-grid-form" style="margin-bottom:14px;">
            <input type="number" name="risks_manual_count" placeholder="<?= h(t('mgmtreview.manual_value')) ?>">
            <input type="text" name="risks_note" placeholder="<?= h(t('mgmtreview.note')) ?>">
        </div>

        <h2><?= h(t('mgmtreview.section.resources')) ?></h2>
        <label><textarea name="resource_adequacy" rows="2"></textarea></label>

        <h2><?= h(t('mgmtreview.section.improvements')) ?></h2>
        <label><textarea name="improvement_opportunities" rows="2"></textarea></label>

        <h2><?= h(t('mgmtreview.section.decisions')) ?></h2>
        <label><?= h(t('mgmtreview.field.decisions_improvements')) ?>
            <textarea name="decisions_improvements" rows="2"></textarea>
        </label>
        <label><?= h(t('mgmtreview.field.decisions_system_changes')) ?>
            <textarea name="decisions_system_changes" rows="2"></textarea>
        </label>
        <label><?= h(t('mgmtreview.field.decisions_resources')) ?>
            <textarea name="decisions_resources" rows="2"></textarea>
        </label>

        <h2><?= h(t('mgmtreview.section.attachment')) ?></h2>
        <label><?= h(t('mgmtreview.field.attachment')) ?>
            <input type="file" name="file">
        </label>

        <button type="submit"><?= h(t('mgmtreview.submit')) ?></button>
    </form>
</div>
<?php render_footer(); ?>
