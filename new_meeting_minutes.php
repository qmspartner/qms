<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

$user = require_permission('records', 'create');
deny_gqar($user);
$db = get_db();
$orgId = $user['organization_id'];

$recordId = (int) ($_GET['record_id'] ?? $_POST['record_id'] ?? 0);
$existingRecord = null;
if ($recordId) {
    $stmt = $db->prepare("SELECT * FROM records WHERE id = ? AND organization_id = ? AND type = 'Mødereferat'");
    $stmt->execute([$recordId, $orgId]);
    $existingRecord = $stmt->fetch();
    if (!$existingRecord) {
        http_response_code(404);
        exit(t('record.not_found'));
    }
}

$stmt = $db->prepare('SELECT id, name FROM users WHERE organization_id = ? AND active = 1 ORDER BY name');
$stmt->execute([$orgId]);
$orgUsers = $stmt->fetchAll();

$openActions = [];
if ($existingRecord) {
    $stmt = $db->prepare("
        SELECT id, title, assigned_to, due_date
        FROM cases
        WHERE organization_id = ? AND source_record_id = ? AND status != 'Lukket'
        ORDER BY due_date IS NULL, due_date
    ");
    $stmt->execute([$orgId, $recordId]);
    $openActions = $stmt->fetchAll();
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = trim($_POST['title'] ?? '');
    $meetingType = trim($_POST['meeting_type'] ?? '');
    $meetingDate = trim($_POST['meeting_date'] ?? '');
    $participants = trim($_POST['participants'] ?? '');
    $absentees = trim($_POST['absentees'] ?? '');
    $nextMeetingDate = trim($_POST['next_meeting_date'] ?? '');
    $retentionYears = $_POST['retention_years'] !== '' ? (int) $_POST['retention_years'] : null;
    $file = $_FILES['file'] ?? null;

    if (($title === '' && !$existingRecord) || $meetingDate === '') {
        $error = t('meetingminutes.error.required');
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
    }

    if (!$error && $meetingDate !== '' && ($title !== '' || $existingRecord)) {
        $db->beginTransaction();
        try {
            if ($existingRecord) {
                $recId = $existingRecord['id'];
                $recTitle = $existingRecord['title'];
                $stmt = $db->prepare('UPDATE records SET next_update_date = ?, retention_years = ? WHERE id = ?');
                $stmt->execute([$nextMeetingDate ?: null, $retentionYears, $recId]);
            } else {
                $recTitle = $title;
                $stmt = $db->prepare("
                    INSERT INTO records (organization_id, title, type, owner_id, next_update_date, retention_years)
                    VALUES (?, ?, 'Mødereferat', ?, ?, ?)
                ");
                $stmt->execute([$orgId, $title, $user['id'], $nextMeetingDate ?: null, $retentionYears]);
                $recId = (int) $db->lastInsertId();
            }

            $stmt = $db->prepare('
                INSERT INTO record_versions (record_id, version_number, stored_filename, original_filename, uploaded_by)
                VALUES (?, ?, ?, ?, ?)
            ');
            $stmt->execute([$recId, $meetingDate, $storedName, $originalName, $user['id']]);
            $versionId = (int) $db->lastInsertId();

            $stmt = $db->prepare('UPDATE records SET current_version_id = ? WHERE id = ?');
            $stmt->execute([$versionId, $recId]);

            $stmt = $db->prepare('
                INSERT INTO meeting_minutes_details (version_id, meeting_type, meeting_date, participants, absentees, next_meeting_date)
                VALUES (?, ?, ?, ?, ?, ?)
            ');
            $stmt->execute([$versionId, $meetingType ?: null, $meetingDate, $participants ?: null, $absentees ?: null, $nextMeetingDate ?: null]);
            $detailsId = (int) $db->lastInsertId();

            $agendaTopics = $_POST['agenda_topic'] ?? [];
            $agendaNotes = $_POST['agenda_notes'] ?? [];
            $stmt = $db->prepare('
                INSERT INTO meeting_minutes_agenda_items (details_id, position, topic, notes)
                VALUES (?, ?, ?, ?)
            ');
            $position = 0;
            foreach ($agendaTopics as $i => $topic) {
                $topic = trim($topic);
                $notes = trim($agendaNotes[$i] ?? '');
                if ($topic === '' && $notes === '') {
                    continue;
                }
                $position++;
                $stmt->execute([$detailsId, $position, $topic ?: null, $notes ?: null]);
            }

            // Opdatér eksisterende handlinger (sager) der stammer fra tidligere møder i dette referat.
            $existingIds = $_POST['action_existing_id'] ?? [];
            $existingWhat = $_POST['action_existing_what'] ?? [];
            $existingOwner = $_POST['action_existing_owner'] ?? [];
            $existingDue = $_POST['action_existing_due'] ?? [];
            $existingDone = $_POST['action_existing_done'] ?? [];
            $stmt = $db->prepare("
                UPDATE cases SET title = ?, description = ?, assigned_to = ?, due_date = ?, status = ?, closed_at = ?
                WHERE id = ? AND organization_id = ? AND source_record_id = ?
            ");
            foreach ($existingIds as $i => $caseId) {
                $caseId = (int) $caseId;
                $what = trim($existingWhat[$i] ?? '');
                if ($what === '') {
                    continue;
                }
                $ownerId = (int) ($existingOwner[$i] ?? 0) ?: null;
                $due = trim($existingDue[$i] ?? '') ?: null;
                $done = in_array((string) $i, (array) $existingDone, true);
                $status = $done ? 'Lukket' : 'Registreret';
                $closedAt = $done ? date('Y-m-d H:i:s') : null;
                $stmt->execute([$what, $what, $ownerId, $due, $status, $closedAt, $caseId, $orgId, $recId]);
            }

            // Opret nye handlinger som rigtige sager, koblet til dette mødereferat.
            $newWhat = $_POST['action_new_what'] ?? [];
            $newOwner = $_POST['action_new_owner'] ?? [];
            $newDue = $_POST['action_new_due'] ?? [];
            $stmt = $db->prepare("
                INSERT INTO cases (organization_id, title, type, description, status, reported_by, assigned_to, due_date, source_record_id)
                VALUES (?, ?, 'Opfølgningspunkt', ?, 'Registreret', ?, ?, ?, ?)
            ");
            foreach ($newWhat as $i => $what) {
                $what = trim($what);
                if ($what === '') {
                    continue;
                }
                $ownerId = (int) ($newOwner[$i] ?? 0) ?: null;
                $due = trim($newDue[$i] ?? '') ?: null;
                $stmt->execute([$orgId, $what, $what, $user['id'], $ownerId, $due, $recId]);
                $newCaseId = (int) $db->lastInsertId();
                log_case_action($newCaseId, t('meetingminutes.case_log.created'), $recTitle . ' — ' . format_date($meetingDate));
            }

            $db->commit();
            log_record_action($recId, $existingRecord ? t('meetingminutes.record_log.new_version') : t('meetingminutes.record_log.created'), $meetingDate);

            header('Location: records.php');
            exit;
        } catch (Exception $e) {
            $db->rollBack();
            $error = t('upload.error.generic');
        }
    }
}

render_header($existingRecord ? t('meetingminutes.title_new_version') : t('meetingminutes.title_new'));
$suggestedNextDate = date('Y-m-d', strtotime('+1 month'));
$defaults = record_type_defaults('Mødereferat');
?>
<div class="card login-card">
    <h1><?= $existingRecord ? h(t('meetingminutes.title_new_version')) . ' — ' . h($existingRecord['title']) : h(t('meetingminutes.title_new')) ?></h1>
    <?php if ($error): ?><p class="alert alert-danger"><?= h($error) ?></p><?php endif; ?>

    <form method="post" enctype="multipart/form-data" data-preserve="newmeetingminutes">
    <?= csrf_field() ?>
        <input type="hidden" name="record_id" value="<?= (int) $recordId ?>">

        <?php if (!$existingRecord): ?>
            <label><?= h(t('meetingminutes.field.title')) ?>
                <input type="text" name="title" required>
            </label>
        <?php endif; ?>

        <div class="inline-grid-form">
            <label><?= h(t('meetingminutes.field.meeting_type')) ?>
                <input type="text" name="meeting_type" placeholder="<?= h(t('meetingminutes.field.meeting_type_placeholder')) ?>">
            </label>
            <label><?= h(t('meetingminutes.field.meeting_date')) ?>
                <input type="date" name="meeting_date" value="<?= h(date('Y-m-d')) ?>" required>
            </label>
        </div>

        <label><?= h(t('meetingminutes.field.participants')) ?>
            <input type="text" name="participants" placeholder="<?= h(t('meetingminutes.field.participants_placeholder')) ?>">
        </label>
        <label><?= h(t('meetingminutes.field.absentees')) ?>
            <input type="text" name="absentees">
        </label>

        <h2><?= h(t('meetingminutes.section.agenda')) ?></h2>
        <div id="agenda-rows">
            <div class="agenda-row">
                <input type="text" name="agenda_topic[]" placeholder="<?= h(t('meetingminutes.agenda.topic_placeholder')) ?>">
                <textarea name="agenda_notes[]" rows="2" placeholder="<?= h(t('meetingminutes.agenda.notes_placeholder')) ?>"></textarea>
            </div>
            <div class="agenda-row">
                <input type="text" name="agenda_topic[]" placeholder="<?= h(t('meetingminutes.agenda.topic_placeholder')) ?>">
                <textarea name="agenda_notes[]" rows="2" placeholder="<?= h(t('meetingminutes.agenda.notes_placeholder')) ?>"></textarea>
            </div>
        </div>
        <button type="button" class="button-secondary button-small" onclick="addAgendaRow()"><?= h(t('meetingminutes.add_agenda_item')) ?></button>

        <h2><?= h(t('meetingminutes.section.actions')) ?></h2>
        <?php if ($openActions): ?>
            <p class="muted"><?= h(t('meetingminutes.actions.existing_title')) ?></p>
            <table class="doc-table" style="margin-bottom:12px;">
                <thead>
                <tr>
                    <th><?= h(t('meetingminutes.actions.what')) ?></th>
                    <th><?= h(t('meetingminutes.actions.owner')) ?></th>
                    <th><?= h(t('meetingminutes.actions.due')) ?></th>
                    <th><?= h(t('meetingminutes.actions.done')) ?></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($openActions as $i => $a): ?>
                    <tr>
                        <td>
                            <input type="hidden" name="action_existing_id[]" value="<?= (int) $a['id'] ?>">
                            <input type="text" name="action_existing_what[]" value="<?= h($a['title']) ?>">
                        </td>
                        <td>
                            <select name="action_existing_owner[]">
                                <option value="0"><?= h(t('meetingminutes.actions.owner_placeholder')) ?></option>
                                <?php foreach ($orgUsers as $u): ?>
                                    <option value="<?= (int) $u['id'] ?>" <?= (int) $a['assigned_to'] === (int) $u['id'] ? 'selected' : '' ?>><?= h($u['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                        <td><input type="date" name="action_existing_due[]" value="<?= h($a['due_date']) ?>"></td>
                        <td style="text-align:center;"><input type="checkbox" name="action_existing_done[]" value="<?= (int) $i ?>" style="width:auto;"></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <table class="doc-table">
            <thead>
            <tr>
                <th><?= h(t('meetingminutes.actions.what')) ?></th>
                <th><?= h(t('meetingminutes.actions.owner')) ?></th>
                <th><?= h(t('meetingminutes.actions.due')) ?></th>
            </tr>
            </thead>
            <tbody id="action-rows">
                <tr>
                    <td><input type="text" name="action_new_what[]" placeholder="<?= h(t('meetingminutes.actions.what_placeholder')) ?>"></td>
                    <td>
                        <select name="action_new_owner[]">
                            <option value="0"><?= h(t('meetingminutes.actions.owner_placeholder')) ?></option>
                            <?php foreach ($orgUsers as $u): ?>
                                <option value="<?= (int) $u['id'] ?>"><?= h($u['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                    <td><input type="date" name="action_new_due[]"></td>
                </tr>
            </tbody>
        </table>
        <button type="button" class="button-secondary button-small" style="margin-top:8px;" onclick="addActionRow()"><?= h(t('meetingminutes.add_action')) ?></button>
        <p class="muted" style="margin-top:10px;"><?= h(t('meetingminutes.actions.hint')) ?></p>

        <h2 style="margin-top:28px;"><?= h(t('meetingminutes.section.closing')) ?></h2>
        <div class="inline-grid-form">
            <label><?= h(t('meetingminutes.field.next_meeting')) ?>
                <input type="date" name="next_meeting_date" value="<?= h($existingRecord['next_update_date'] ?? $suggestedNextDate) ?>">
            </label>
            <label><?= h(t('meetingminutes.field.retention')) ?>
                <input type="number" name="retention_years" min="1" max="50" value="<?= h($existingRecord['retention_years'] ?? (string) $defaults['retention']) ?>">
            </label>
        </div>

        <label><?= h(t('meetingminutes.field.attachment')) ?>
            <input type="file" name="file">
        </label>

        <button type="submit"><?= h(t('meetingminutes.submit')) ?></button>
    </form>
</div>

<script>
function addAgendaRow() {
    var wrap = document.getElementById('agenda-rows');
    var row = document.createElement('div');
    row.className = 'agenda-row';
    row.innerHTML =
        '<input type="text" name="agenda_topic[]" placeholder="<?= h(t('meetingminutes.agenda.topic_placeholder')) ?>">' +
        '<textarea name="agenda_notes[]" rows="2" placeholder="<?= h(t('meetingminutes.agenda.notes_placeholder')) ?>"></textarea>';
    wrap.appendChild(row);
}

function addActionRow() {
    var wrap = document.getElementById('action-rows');
    var row = document.createElement('tr');
    var ownerOptions = <?= json_encode('<option value="0">' . h(t('meetingminutes.actions.owner_placeholder')) . '</option>' . implode('', array_map(function ($u) {
        return '<option value="' . (int) $u['id'] . '">' . h($u['name']) . '</option>';
    }, $orgUsers))) ?>;
    row.innerHTML =
        '<td><input type="text" name="action_new_what[]" placeholder="<?= h(t('meetingminutes.actions.what_placeholder')) ?>"></td>' +
        '<td><select name="action_new_owner[]">' + ownerOptions + '</select></td>' +
        '<td><input type="date" name="action_new_due[]"></td>';
    wrap.appendChild(row);
}
</script>

<?php render_footer(); ?>
