<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/cases.php';
require_once __DIR__ . '/includes/action_items.php';

$user = require_permission_allow_gqar('cases');
$db = get_db();

$id = (int) ($_GET['id'] ?? 0);
$case = case_find($db, $id, $user['organization_id']);

if (!$case) {
    http_response_code(404);
    render_header(t('case.not_found'));
    echo '<div class="card">' . h(t('case.not_found')) . '</div>';
    render_footer();
    exit;
}

// Central synlighedsregel (GQAR-kontraktbegrænsning / fuld adgang for andre roller) —
// se case_is_visible() i includes/cases.php, det ene sted reglen er defineret.
if (!case_is_visible($user, $case)) {
    http_response_code(403);
    render_header(t('error.forbidden'));
    echo '<div class="card">' . h(t('error.forbidden')) . '</div>';
    render_footer();
    exit;
}

$canManage = can_manage_cases($user);
$error = '';
$effectivenessError = '';
// Disse sagstyper skal, ved lukning, have en dokumenteret rodårsag samt en planlagt dato
// for effektivitetstjek af handlingen (ISO 9001 pkt. 10.2). De skal desuden have mindst én
// korrigerende og én forebyggende underaktion, alle afsluttet (med bevis) eller markeret
// N/A — se $blocksClosure. For øvrige sagstyper (Forslag, Observation, Mistænkt forfalsket
// del) er underaktioner et valgfrit værktøj, ikke en lukke-betingelse.
$requiresClosureDocumentation = in_array($case['type'], ['Audit fund', 'Afvigelse', 'Kundeklage', 'Leverandør reklamation'], true);
$hasOpenActionItems = action_item_has_open_for_case($db, $id);
$categoryCounts = action_item_category_counts_for_case($db, $id);
$blocksClosure = $requiresClosureDocumentation
    && ($hasOpenActionItems || $categoryCounts['Korrigerende'] === 0 || $categoryCounts['Forebyggende'] === 0);

if ($canManage && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['effectiveness_action'])) {
    $effectivenessAction = $_POST['effectiveness_action'];

    if ($effectivenessAction === 'set_due' && in_array(effectiveness_status($case), ['pending_date', 'planned', 'due_soon', 'overdue'], true)) {
        $reviewDue = trim($_POST['effectiveness_review_due'] ?? '');
        if ($reviewDue === '') {
            $effectivenessError = t('case.effectiveness.error.date_required');
        } else {
            $stmt = $db->prepare('UPDATE cases SET effectiveness_review_due = ? WHERE id = ? AND organization_id = ?');
            $stmt->execute([$reviewDue, $id, $user['organization_id']]);
            log_case_action($id, t('case.effectiveness.log.date_set'), format_date($reviewDue));
            header('Location: case.php?id=' . $id);
            exit;
        }
    } elseif ($effectivenessAction === 'record_result' && in_array(effectiveness_status($case), ['planned', 'due_soon', 'overdue'], true)) {
        $result = $_POST['effectiveness_result'] ?? '';
        $notes = trim($_POST['effectiveness_notes'] ?? '');
        if (!in_array($result, ['Effektiv', 'Ikke effektiv'], true)) {
            $effectivenessError = t('case.effectiveness.error.result_required');
        } elseif ($result === 'Effektiv') {
            $stmt = $db->prepare('UPDATE cases SET effectiveness_result = ?, effectiveness_reviewed_at = CURDATE(), effectiveness_notes = ? WHERE id = ? AND organization_id = ?');
            $stmt->execute([$result, $notes ?: null, $id, $user['organization_id']]);
            log_case_action($id, t('case.effectiveness.log.effective'), $notes);
            header('Location: case.php?id=' . $id);
            exit;
        } else {
            // Ikke effektiv: sagen genåbnes og fortsætter i samme historik (case_log),
            // og effektivitetstjek-felterne nulstilles så en ny tjek-cyklus kan starte,
            // når sagen lukkes igen efter en justeret/ny korrigerende handling.
            $stmt = $db->prepare("
                UPDATE cases
                SET status = 'Handling igangsat', closed_at = NULL,
                    effectiveness_review_due = NULL, effectiveness_result = NULL,
                    effectiveness_reviewed_at = NULL, effectiveness_notes = NULL
                WHERE id = ? AND organization_id = ?
            ");
            $stmt->execute([$id, $user['organization_id']]);
            log_case_action($id, t('case.effectiveness.log.ineffective'), $notes);
            header('Location: case.php?id=' . $id);
            exit;
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_item_action'])) {
    $actionItemAction = $_POST['action_item_action'];

    if ($actionItemAction === 'add' && $canManage) {
        $category = $_POST['category'] ?? '';
        $title = trim($_POST['title'] ?? '');
        $ownerUserId = (int) ($_POST['owner_user_id'] ?? 0);
        $dueDate = trim($_POST['due_date'] ?? '');
        if (!in_array($category, ['Korrigerende', 'Forebyggende'], true)) {
            $error = t('case.error.invalid');
        } elseif ($title === '') {
            $error = t('case.actionitems.error.title_required');
        } else {
            action_item_add($db, $user['organization_id'], $id, $category, $title, $ownerUserId ?: null, $dueDate ?: null, (int) $user['id']);
            log_case_action($id, t('case.actionitems.log.added'), $title);
            header('Location: case.php?id=' . $id);
            exit;
        }
    } elseif (in_array($actionItemAction, ['close', 'reopen', 'mark_na'], true)) {
        $itemId = (int) ($_POST['item_id'] ?? 0);
        $items = action_item_list_for_case($db, $id);
        $match = null;
        foreach ($items as $it) {
            if ((int) $it['id'] === $itemId) {
                $match = $it;
                break;
            }
        }
        $isOwner = $match && (int) ($match['owner_user_id'] ?? 0) === (int) $user['id'];
        if ($match && ($canManage || $isOwner)) {
            if ($actionItemAction === 'close') {
                // Bevis er kun en betingelse for at afslutte en underaktion på de
                // sagstyper der kræver lukningsdokumentation — ellers kan den afsluttes
                // frit, som før.
                $hasEvidence = (bool) action_item_evidence_find_for_item($db, $itemId);
                if ($requiresClosureDocumentation && !$hasEvidence) {
                    $error = t('case.actionitems.error.evidence_required');
                } else {
                    action_item_close($db, $itemId, $user['organization_id']);
                    log_case_action($id, t('case.actionitems.log.closed'), $match['title']);
                    header('Location: case.php?id=' . $id);
                    exit;
                }
            } elseif ($actionItemAction === 'reopen') {
                action_item_reopen($db, $itemId, $user['organization_id']);
                log_case_action($id, t('case.actionitems.log.reopened'), $match['title']);
                header('Location: case.php?id=' . $id);
                exit;
            } elseif ($actionItemAction === 'mark_na' && $canManage) {
                // N/A er en undtagelse fra selve bevis-kravet, ikke blot en afslutning af
                // ens egen opgave — derfor kræves fuld sagsadgang her, i modsætning til
                // close/reopen/upload_evidence, som ejeren også selv kan udføre.
                $naReason = trim($_POST['na_reason'] ?? '');
                if ($naReason === '') {
                    $error = t('case.actionitems.error.na_reason_required');
                } else {
                    action_item_mark_na($db, $itemId, $user['organization_id'], $naReason, (int) $user['id']);
                    log_case_action($id, t('case.actionitems.log.marked_na'), $match['title'] . ' — ' . $naReason);
                    header('Location: case.php?id=' . $id);
                    exit;
                }
            }
        }
    } elseif ($actionItemAction === 'upload_evidence') {
        $itemId = (int) ($_POST['item_id'] ?? 0);
        $items = action_item_list_for_case($db, $id);
        $match = null;
        foreach ($items as $it) {
            if ((int) $it['id'] === $itemId) {
                $match = $it;
                break;
            }
        }
        $isOwner = $match && (int) ($match['owner_user_id'] ?? 0) === (int) $user['id'];
        $file = $_FILES['evidence'] ?? null;
        if ($match && ($canManage || $isOwner)) {
            if (!$file || $file['error'] === UPLOAD_ERR_NO_FILE) {
                $error = t('case.actionitems.error.evidence_file_required');
            } elseif ($file['error'] !== UPLOAD_ERR_OK) {
                $error = t('newcase.error.upload', ['file' => $file['name']]);
            } elseif ($file['size'] > MAX_FILE_SIZE) {
                $error = t('newcase.error.too_large', ['file' => $file['name']]);
            } else {
                $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                if (!in_array($ext, ALLOWED_EXTENSIONS, true)) {
                    $error = t('newcase.error.bad_type', ['file' => $file['name'], 'types' => implode(', ', ALLOWED_EXTENSIONS)]);
                } else {
                    $storedName = bin2hex(random_bytes(16)) . '.' . $ext;
                    if (move_uploaded_file($file['tmp_name'], UPLOAD_DIR . $storedName)) {
                        // Ét bevis ad gangen — et nyt upload erstatter det forrige
                        // (både databaserækken og selve filen på disk).
                        foreach (action_item_evidence_delete_for_item($db, $itemId, $user['organization_id']) as $oldStoredFilename) {
                            @unlink(UPLOAD_DIR . $oldStoredFilename);
                        }
                        action_item_evidence_add($db, $user['organization_id'], $itemId, $storedName, $file['name'], (int) $user['id']);
                        log_case_action($id, t('case.actionitems.log.evidence_uploaded'), $match['title']);
                        header('Location: case.php?id=' . $id);
                        exit;
                    }
                }
            }
        }
    } elseif ($actionItemAction === 'delete' && $canManage) {
        $itemId = (int) ($_POST['item_id'] ?? 0);
        foreach (action_item_evidence_delete_for_item($db, $itemId, $user['organization_id']) as $storedFilename) {
            @unlink(UPLOAD_DIR . $storedFilename);
        }
        action_item_delete($db, $itemId, $user['organization_id']);
        header('Location: case.php?id=' . $id);
        exit;
    }
}

if ($canManage && $_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['effectiveness_action']) && !isset($_POST['action_item_action'])) {
    $status = $_POST['status'] ?? '';
    $severity = $_POST['severity'] ?? '';
    $description = trim($_POST['description'] ?? '');
    $rootCause = trim($_POST['root_cause'] ?? '');
    $immediateAction = trim($_POST['immediate_action'] ?? '');
    $assignedTo = (int) ($_POST['assigned_to'] ?? 0);
    $dueDate = trim($_POST['due_date'] ?? '');
    $requiresNotification = isset($_POST['requires_customer_notification']) ? 1 : 0;
    $contractReference = trim($_POST['contract_reference'] ?? '');
    $customerResponseDate = trim($_POST['customer_response_date'] ?? '');
    $effectivenessReviewDue = trim($_POST['effectiveness_review_due'] ?? '');
    $allowedStatuses = ['Registreret', 'Under undersøgelse', 'Handling igangsat', 'Lukket'];
    $allowedSeverities = ['', 'Høj', 'Mellem', 'Lav'];
    // Skærpede krav gælder kun når sagen rent faktisk lukkes her i denne handling.
    // Sager der allerede var lukket, før disse krav blev indført, røres ikke bagudrettet —
    // de kan fortsat redigeres (fx skifte ansvarlig) uden at skulle eftervise dokumentation.
    $isClosingNow = $status === 'Lukket' && $case['status'] !== 'Lukket';
    $wasAlreadyClosed = $case['status'] === 'Lukket';

    if (!in_array($status, $allowedStatuses, true) || !in_array($severity, $allowedSeverities, true) || $description === '') {
        $error = t('case.error.invalid');
    } elseif ($isClosingNow && $blocksClosure) {
        $error = t('case.error.closure_requires_closed_action_items');
    } elseif ($isClosingNow && $requiresClosureDocumentation && $rootCause === '') {
        $error = t('case.error.closure_requires_root_cause');
    } elseif ($isClosingNow && $requiresClosureDocumentation && $effectivenessReviewDue === '') {
        $error = t('case.error.closure_requires_effectiveness_date');
    } elseif ($requiresNotification && $contractReference === '') {
        $error = t('newcase.error.contract_reference_required');
    } else {
        $assignedToId = null;
        $assignedUser = null;
        if ($assignedTo) {
            $stmt = $db->prepare('SELECT id, name FROM users WHERE id = ? AND organization_id = ?');
            $stmt->execute([$assignedTo, $user['organization_id']]);
            $assignedUser = $stmt->fetch();
            if ($assignedUser) {
                $assignedToId = $assignedUser['id'];
            }
        }

        if ($status === 'Lukket' && $case['status'] !== 'Lukket') {
            $closedAt = date('Y-m-d H:i:s');
        } elseif ($status !== 'Lukket') {
            $closedAt = null;
        } else {
            $closedAt = $case['closed_at'];
        }

        // Feltet vises kun i formularen for de sagstyper, der kræver effektivitetstjek,
        // så det ikke utilsigtet nulstilles for en type hvor det sættes via den separate
        // effektivitetstjek-sektion i stedet.
        $validEffectivenessReviewDue = $requiresClosureDocumentation ? ($effectivenessReviewDue ?: null) : $case['effectiveness_review_due'];

        $stmt = $db->prepare('
            UPDATE cases
            SET status = ?, severity = ?, description = ?, root_cause = ?, immediate_action = ?, assigned_to = ?, due_date = ?, requires_customer_notification = ?, contract_reference = ?, customer_response_date = ?, closed_at = ?, effectiveness_review_due = ?
            WHERE id = ? AND organization_id = ?
        ');
        $stmt->execute([
            $status, $severity ?: null, $description, $rootCause ?: null, $immediateAction ?: null,
            $assignedToId, $dueDate ?: null, $requiresNotification, $requiresNotification ? $contractReference : null,
            $case['type'] === 'Kundeklage' ? ($customerResponseDate ?: null) : null, $closedAt,
            $validEffectivenessReviewDue,
            $id, $user['organization_id'],
        ]);

        $details = 'Status: ' . $status . ($assignedUser ? ' — Tildelt: ' . $assignedUser['name'] : '');
        log_case_action($id, 'Kvalitetshændelse opdateret', $details);

        header('Location: cases.php');
        exit;
    }
}

$stmt = $db->prepare('
    SELECT a.*, u.name AS uploaded_by_name
    FROM case_attachments a
    JOIN users u ON u.id = a.uploaded_by
    WHERE a.case_id = ?
    ORDER BY a.uploaded_at
');
$stmt->execute([$id]);
$attachments = $stmt->fetchAll();

$stmt = $db->prepare('
    SELECT l.*, u.name AS user_name
    FROM case_log l
    JOIN users u ON u.id = l.user_id
    WHERE l.case_id = ?
    ORDER BY l.created_at DESC
');
$stmt->execute([$id]);
$logEntries = $stmt->fetchAll();

$stmt = $db->prepare("SELECT id, name FROM users WHERE organization_id = ? AND active = 1 ORDER BY name");
$stmt->execute([$user['organization_id']]);
$orgUsers = $stmt->fetchAll();

$correctiveItems = action_item_list_for_case($db, $id, 'Korrigerende');
$preventiveItems = action_item_list_for_case($db, $id, 'Forebyggende');

render_header($case['title']);
?>
<p class="breadcrumb"><a href="cases.php"><?= h(t('case.breadcrumb.register')) ?></a> &raquo; <?= h(type_label($case['type'])) ?> &raquo; SAG-<?= str_pad((string)$case['id'], 4, '0', STR_PAD_LEFT) ?></p>

<?php if ($error): ?><p class="alert alert-danger"><?= h($error) ?></p><?php endif; ?>

<div class="card">
    <div class="doc-header">
        <div>
            <h1><?= h($case['title']) ?></h1>
            <p class="muted">SAG-<?= str_pad((string)$case['id'], 4, '0', STR_PAD_LEFT) ?> &middot; <?= h(type_label($case['type'])) ?></p>
        </div>
        <span class="<?= case_status_class($case['status']) ?>"><?= h(case_status_label($case['status'])) ?></span>
    </div>

    <div class="meta-grid">
        <div><p class="meta-label"><?= h(t('case.reported_by')) ?></p><p><?= h($case['reported_by_name']) ?></p></div>
        <div><p class="meta-label"><?= h(t('case.responsible')) ?></p><p><?= h($case['assigned_to_name'] ?? t('cases.unassigned')) ?></p></div>
        <div><p class="meta-label"><?= h(t('case.severity')) ?></p><p><?= $case['severity'] ? '<span class="' . severity_class($case['severity']) . '">' . h(severity_label($case['severity'])) . '</span>' : '—' ?></p></div>
        <div><p class="meta-label"><?= h(t('case.deadline')) ?></p><p><?= format_date($case['due_date']) ?></p></div>
        <?php if ($case['customer_name']): ?>
            <div><p class="meta-label"><?= h(t('case.customer')) ?></p><p><?= h($case['customer_name']) ?></p></div>
        <?php endif; ?>
        <?php if ($case['customer_response_date']): ?>
            <div><p class="meta-label"><?= h(t('case.customer_response_date')) ?></p><p><?= format_date($case['customer_response_date']) ?></p></div>
        <?php endif; ?>
        <?php if ($case['requires_customer_notification']): ?>
            <div><p class="meta-label"><?= h(t('case.customer_notification')) ?></p><p><?= h(t('common.yes')) ?></p></div>
            <?php if ($case['contract_reference']): ?>
                <div><p class="meta-label"><?= h(t('case.contract_reference')) ?></p><p><?= h($case['contract_reference']) ?></p></div>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <div style="border-top:0.5px solid var(--border); padding-top:1rem; margin-top:1rem;">
        <p class="meta-label"><?= h(t('case.description')) ?></p>
        <p style="margin:0 0 12px;"><?= nl2br(h($case['description'])) ?></p>
        <?php if ($case['immediate_action']): ?>
            <p class="meta-label"><?= h(t('case.immediate_action')) ?></p>
            <p style="margin:0 0 12px;"><?= nl2br(h($case['immediate_action'])) ?></p>
        <?php endif; ?>
        <?php if ($case['root_cause']): ?>
            <p class="meta-label"><?= h(t('case.root_cause')) ?></p>
            <p style="margin:0;"><?= nl2br(h($case['root_cause'])) ?></p>
        <?php endif; ?>
    </div>

    <div class="button-row">
        <?php if ($case['linked_document_title']): ?>
            <a class="button button-secondary" href="document.php?id=<?= (int)$case['linked_document_id'] ?>">
                <?= h(t('case.linked_document')) ?>: <?= h($case['linked_document_number']) ?>
            </a>
        <?php endif; ?>
        <?php if ($case['linked_audit_title']): ?>
            <a class="button button-secondary" href="audit.php?id=<?= (int)$case['audit_id'] ?>">
                <?= h(t('audit.found_under')) ?>: <?= h($case['linked_audit_title']) ?>
            </a>
        <?php endif; ?>
        <?php if ($case['linked_supplier_name']): ?>
            <a class="button button-secondary" href="supplier_detail.php?id=<?= (int)$case['supplier_id'] ?>">
                <?= h(t('supplier.linked_to_case')) ?>: <?= h($case['linked_supplier_name']) ?>
            </a>
        <?php endif; ?>
        <?php if ($case['source_record_title']): ?>
            <a class="button button-secondary" href="record.php?id=<?= (int)$case['source_record_id'] ?>">
                <?= h(t('case.source_record')) ?>: <?= h($case['source_record_title']) ?>
            </a>
        <?php endif; ?>
        <?php if ($user['role'] === 'admin'): ?>
            <form method="post" action="delete_case.php" class="inline-form"
                  onsubmit="return confirm('<?= h(t('case.delete_confirm')) ?>
');">
    <?= csrf_field() ?>
                <input type="hidden" name="case_id" value="<?= (int)$case['id'] ?>">
                <button type="submit" class="button-secondary button-danger"><?= h(t('case.delete')) ?></button>
            </form>
        <?php endif; ?>
    </div>

    <?php if ($attachments): ?>
        <div style="border-top:0.5px solid var(--border); padding-top:1rem; margin-top:1rem;">
            <p class="meta-label"><?= h(t('case.attachments')) ?></p>
            <?php foreach ($attachments as $a): ?>
                <p style="margin:4px 0;">
                    <a href="download_case_attachment.php?id=<?= (int)$a['id'] ?>"><?= h($a['original_filename']) ?></a>
                    <span class="muted"> — <?= h(t('case.uploaded_by')) ?> <?= h($a['uploaded_by_name']) ?></span>
                </p>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<?php if ($canManage): ?>
<h2><?= h(t('case.update_title')) ?></h2>
<div class="card">
    <form method="post">
    <?= csrf_field() ?>
        <div class="inline-grid-form" style="margin-bottom:14px;">
            <label><?= h(t('case.field.status')) ?>
                <select name="status">
                    <?php foreach (['Registreret', 'Under undersøgelse', 'Handling igangsat', 'Lukket'] as $s): ?>
                        <?php $blockThis = $s === 'Lukket' && $case['status'] !== 'Lukket' && $blocksClosure; ?>
                        <option value="<?= h($s) ?>" <?= $case['status'] === $s ? 'selected' : '' ?> <?= $blockThis ? 'disabled' : '' ?>><?= h(case_status_label($s)) ?><?= $blockThis ? ' — ' . h(t('case.actionitems.blocks_closure_option_suffix')) : '' ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label><?= h(t('case.field.severity')) ?>
                <select name="severity">
                    <option value=""><?= h(t('case.field.severity_none')) ?></option>
                    <?php foreach (['Høj', 'Mellem', 'Lav'] as $s): ?>
                        <option value="<?= h($s) ?>" <?= $case['severity'] === $s ? 'selected' : '' ?>><?= h(severity_label($s)) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label><?= h(t('case.field.responsible')) ?>
                <select name="assigned_to">
                    <option value=""><?= h(t('case.field.responsible_none')) ?></option>
                    <?php foreach ($orgUsers as $u): ?>
                        <option value="<?= (int) $u['id'] ?>" <?= (int)$case['assigned_to'] === (int)$u['id'] ? 'selected' : '' ?>><?= h($u['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label><?= h(t('case.field.deadline')) ?>
                <input type="date" name="due_date" value="<?= h($case['due_date'] ?? '') ?>">
            </label>
        </div>
        <label><?= h(t('case.field.description')) ?>
            <textarea name="description" rows="4" required><?= h($case['description']) ?></textarea>
        </label>
        <?php if ($case['type'] === 'Kundeklage'): ?>
            <label><?= h(t('case.field.customer_response_date')) ?>
                <input type="date" name="customer_response_date" value="<?= h($case['customer_response_date'] ?? '') ?>">
            </label>
        <?php endif; ?>
        <label><?= h(t('case.field.immediate_action')) ?>
            <textarea name="immediate_action" rows="2"><?= h($case['immediate_action'] ?? '') ?></textarea>
        </label>
        <label><?= h(t('case.field.root_cause')) ?>
            <textarea name="root_cause" rows="3"><?= h($case['root_cause'] ?? '') ?></textarea>
        </label>
        <p class="muted" style="margin:-4px 0 16px; font-size:12px;"><?= h(t('case.field.action_items_hint')) ?></p>
        <?php if ($requiresClosureDocumentation): ?>
            <label><?= h(t('case.field.effectiveness_review_due')) ?>
                <input type="date" name="effectiveness_review_due" value="<?= h($case['effectiveness_review_due'] ?? '') ?>">
            </label>
            <p class="muted" style="margin:-8px 0 16px;"><?= h(t('case.field.closure_requirements_hint')) ?></p>
        <?php endif; ?>
        <label style="display:flex; align-items:center; gap:8px; flex-direction:row;">
            <input type="checkbox" name="requires_customer_notification" id="case-notify-checkbox" value="1" <?= $case['requires_customer_notification'] ? 'checked' : '' ?> style="width:auto;" onchange="document.getElementById('case-contract-ref-field-edit').style.display = this.checked ? 'block' : 'none';">
            <?= h(t('case.field.customer_notification')) ?>
        </label>
        <div id="case-contract-ref-field-edit" style="<?= $case['requires_customer_notification'] ? '' : 'display:none;' ?>">
            <label><?= h(t('case.field.contract_reference')) ?>
                <select name="contract_reference">
                    <option value=""><?= h(t('newcase.field.contract_reference_choose')) ?></option>
                    <?php foreach (list_active_values('contract_reference') as $ref): ?>
                        <option value="<?= h($ref) ?>" <?= $case['contract_reference'] === $ref ? 'selected' : '' ?>><?= h($ref) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
        </div>
        <button type="submit"><?= h(t('case.save')) ?></button>
    </form>

    <div style="padding-top:20px; border-top:1px solid var(--border); margin-top:20px; display:flex; flex-direction:column; gap:16px;">

        <div class="actionitems-heading">
            <svg width="17" height="17" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M9 11l3 3L22 4" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
            <h3><?= h(t('case.actionitems.title')) ?></h3>
            <span><?= h($requiresClosureDocumentation ? t('case.actionitems.section_subtitle_required') : t('case.actionitems.section_subtitle_optional')) ?></span>
        </div>

        <div class="actionitems-columns">
        <?php foreach (['Korrigerende' => ['items' => $correctiveItems, 'label' => t('case.field.corrective_action')], 'Forebyggende' => ['items' => $preventiveItems, 'label' => t('case.field.preventive_action')]] as $category => $categoryDef): ?>
            <?php
                $items = $categoryDef['items'];
                $total = count($items);
                $doneCount = count(array_filter($items, fn ($it) => $it['status'] !== 'Åben'));
                $progressPct = $total > 0 ? round(($doneCount / $total) * 100) : 0;
            ?>
            <div class="actionitem-panel">
                <div class="actionitem-panel-head">
                    <p class="actionitem-panel-title">
                        <?= h($categoryDef['label']) ?>
                        <?php if (!$requiresClosureDocumentation): ?>
                            <span class="badge-optional">(<?= h(t('case.actionitems.optional_badge')) ?>)</span>
                        <?php endif; ?>
                    </p>
                    <?php if ($items): ?>
                        <p class="actionitem-panel-progress"><?= h(t('case.actionitems.progress', ['done' => $doneCount, 'total' => $total])) ?></p>
                    <?php endif; ?>
                </div>
                <?php if ($items): ?>
                    <div class="actionitem-progress-track"><div class="actionitem-progress-fill" style="width:<?= (int) $progressPct ?>%;"></div></div>
                <?php endif; ?>
                <p class="actionitem-intro"><?= h($requiresClosureDocumentation ? t('case.actionitems.intro_required') : t('case.actionitems.intro_optional')) ?></p>

                <?php foreach ($items as $it): ?>
                    <?php
                        $isClosed = $it['status'] === 'Afsluttet';
                        $isNa = $it['status'] === 'N/A';
                        $isResolved = $isClosed || $isNa;
                        $isOwnItem = (int) ($it['owner_user_id'] ?? 0) === (int) $user['id'];
                        $isOverdue = !$isResolved && $it['due_date'] && $it['due_date'] < date('Y-m-d');
                        $evidence = action_item_evidence_find_for_item($db, (int) $it['id']);
                        $statusClass = $isClosed ? ' actionitem-status-done' : ($isNa ? ' actionitem-status-na' : '');
                    ?>
                    <div class="actionitem-row">
                        <?php if ($canManage || $isOwnItem): ?>
                            <form method="post" class="inline-form" style="margin:0;">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action_item_action" value="<?= $isResolved ? 'reopen' : 'close' ?>">
                                <input type="hidden" name="item_id" value="<?= (int) $it['id'] ?>">
                                <button type="submit" class="actionitem-status<?= $statusClass ?>" aria-label="<?= h(t($isResolved ? 'case.actionitems.reopen' : 'case.actionitems.close')) ?>"
                                    <?php if (!$isResolved && $requiresClosureDocumentation && !$evidence): ?>title="<?= h(t('case.actionitems.error.evidence_required')) ?>"<?php endif; ?>>
                                    <?php if ($isClosed): ?>
                                        <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="20 6 9 17 4 12"></polyline></svg>
                                    <?php endif; ?>
                                </button>
                            </form>
                        <?php else: ?>
                            <div class="actionitem-status<?= $statusClass ?>" aria-hidden="true">
                                <?php if ($isClosed): ?>
                                    <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="20 6 9 17 4 12"></polyline></svg>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                        <div class="actionitem-body">
                            <p class="actionitem-title<?= $isResolved ? ' actionitem-title-resolved' : '' ?>"><?= h($it['title']) ?></p>
                            <p class="actionitem-meta"><?= h($it['owner_name'] ?? t('case.actionitems.no_owner')) ?><?php if (!$isResolved && $it['due_date']): ?> &middot; <?= $isOverdue ? '<span class="actionitem-side-overdue">' . h(format_date($it['due_date'])) . '</span>' : h(format_date($it['due_date'])) ?><?php endif; ?></p>
                            <?php if ($isNa): ?>
                                <p class="actionitem-na-note"><?= h(t('case.actionitems.na_logged', ['reason' => $it['na_reason']])) ?></p>
                            <?php elseif ($evidence): ?>
                                <a href="download_action_item_evidence.php?id=<?= (int) $evidence['id'] ?>" class="actionitem-evidence-chip">
                                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" stroke="currentColor" stroke-width="2"/><path d="M14 2v6h6" stroke="currentColor" stroke-width="2"/></svg>
                                    <?= h($evidence['original_filename']) ?>
                                </a>
                            <?php elseif (!$isResolved && ($canManage || $isOwnItem)): ?>
                                <div class="actionitem-actions-row">
                                    <form method="post" enctype="multipart/form-data" class="inline-form" style="margin:0;">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action_item_action" value="upload_evidence">
                                        <input type="hidden" name="item_id" value="<?= (int) $it['id'] ?>">
                                        <label class="actionitem-upload-btn">
                                            <?= h($requiresClosureDocumentation ? t('case.actionitems.upload_evidence') : t('case.actionitems.upload_evidence_optional')) ?>
                                            <input type="file" name="evidence" onchange="this.form.submit();" style="display:none;">
                                        </label>
                                    </form>
                                    <?php if ($canManage && $requiresClosureDocumentation): ?>
                                        <form method="post" class="actionitem-na-form">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action_item_action" value="mark_na">
                                            <input type="hidden" name="item_id" value="<?= (int) $it['id'] ?>">
                                            <input type="text" name="na_reason" placeholder="<?= h(t('case.actionitems.na_reason_placeholder')) ?>" required>
                                            <button type="submit" class="button-small button-secondary" style="padding:3px 9px; font-size:11.5px; white-space:nowrap;"><?= h(t('case.actionitems.mark_na')) ?></button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                        <?php if ($isResolved): ?>
                            <span class="actionitem-side actionitem-side-resolved"><?= $isClosed ? h(t('case.actionitems.status_done')) : h(t('case.actionitems.status_na')) ?></span>
                        <?php endif; ?>
                        <?php if ($canManage): ?>
                            <form method="post" class="inline-form" style="margin:0;" onsubmit="return confirm('<?= h(t('case.actionitems.delete_confirm')) ?>');">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action_item_action" value="delete">
                                <input type="hidden" name="item_id" value="<?= (int) $it['id'] ?>">
                                <button type="submit" class="button-small button-secondary actionitem-delete-btn" aria-label="<?= h(t('case.actionitems.delete')) ?>">&times;</button>
                            </form>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
                <?php if (!$items): ?>
                    <p class="muted" style="margin:0; padding:4px 0;"><?= h(t('case.actionitems.none')) ?></p>
                <?php endif; ?>

                <?php if ($canManage): ?>
                <form method="post" class="actionitem-add-row">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action_item_action" value="add">
                    <input type="hidden" name="category" value="<?= h($category) ?>">
                    <div class="actionitem-add-row-title">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M12 5v14M5 12h14" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
                        <input type="text" name="title" placeholder="<?= h(t('case.actionitems.new_placeholder')) ?>" required>
                    </div>
                    <div class="actionitem-add-row-controls">
                        <select name="owner_user_id">
                            <option value=""><?= h(t('case.field.responsible_none')) ?></option>
                            <?php foreach ($orgUsers as $u): ?>
                                <option value="<?= (int) $u['id'] ?>"><?= h($u['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <input type="date" name="due_date">
                        <button type="submit" class="button-small"><?= h(t('case.actionitems.add')) ?></button>
                    </div>
                </form>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
        </div>

        <?php if ($blocksClosure): ?>
            <div class="actionitem-closure-hint">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M12 9v4M12 17h.01" stroke="currentColor" stroke-width="2" stroke-linecap="round"/><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="2"/></svg>
                <p><?= h(t('case.actionitems.blocks_closure_hint')) ?></p>
            </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<?php $effStatus = effectiveness_status($case); ?>
<?php if ($effStatus !== 'none'): ?>
<h2><?= h(t('case.effectiveness.title')) ?></h2>
<div class="card">
    <p class="muted" style="margin-top:0;"><?= h(t('case.effectiveness.intro')) ?></p>

    <?php if ($effectivenessError): ?><p class="alert alert-danger"><?= h($effectivenessError) ?></p><?php endif; ?>

    <div class="meta-grid">
        <div><p class="meta-label"><?= h(t('case.effectiveness.status_label')) ?></p>
            <p><span class="<?= effectiveness_status_class($effStatus) ?>"><?= h(effectiveness_status_label($effStatus)) ?></span></p></div>
        <?php if ($case['effectiveness_review_due']): ?>
            <div><p class="meta-label"><?= h(t('case.effectiveness.due_date_label')) ?></p><p><?= format_date($case['effectiveness_review_due']) ?></p></div>
        <?php endif; ?>
        <?php if ($case['effectiveness_reviewed_at']): ?>
            <div><p class="meta-label"><?= h(t('case.effectiveness.reviewed_at_label')) ?></p><p><?= format_date($case['effectiveness_reviewed_at']) ?></p></div>
        <?php endif; ?>
    </div>
    <?php if ($case['effectiveness_notes']): ?>
        <p class="meta-label"><?= h(t('case.effectiveness.notes_label')) ?></p>
        <p style="margin:0 0 12px;"><?= nl2br(h($case['effectiveness_notes'])) ?></p>
    <?php endif; ?>

    <?php if ($canManage && $effStatus === 'pending_date'): ?>
        <form method="post" style="margin-top:12px;">
            <?= csrf_field() ?>
            <input type="hidden" name="effectiveness_action" value="set_due">
            <label><?= h(t('case.effectiveness.set_date_label')) ?>
                <input type="date" name="effectiveness_review_due" required>
            </label>
            <button type="submit"><?= h(t('case.effectiveness.set_date_button')) ?></button>
        </form>
    <?php elseif ($canManage && in_array($effStatus, ['planned', 'due_soon', 'overdue'], true)): ?>
        <form method="post" style="margin-top:12px; border-top:0.5px solid var(--border); padding-top:12px;">
            <?= csrf_field() ?>
            <input type="hidden" name="effectiveness_action" value="set_due">
            <label><?= h(t('case.effectiveness.due_date_label')) ?>
                <input type="date" name="effectiveness_review_due" value="<?= h($case['effectiveness_review_due']) ?>" required>
            </label>
            <button type="submit" class="button-secondary"><?= h(t('case.effectiveness.set_date_button')) ?></button>
        </form>
        <form method="post" style="margin-top:12px; border-top:0.5px solid var(--border); padding-top:12px;">
            <?= csrf_field() ?>
            <input type="hidden" name="effectiveness_action" value="record_result">
            <label><?= h(t('case.effectiveness.result_label')) ?>
                <select name="effectiveness_result" required>
                    <option value=""><?= h(t('case.effectiveness.result_choose')) ?></option>
                    <option value="Effektiv"><?= h(t('case.effectiveness.result_effective')) ?></option>
                    <option value="Ikke effektiv"><?= h(t('case.effectiveness.result_ineffective')) ?></option>
                </select>
            </label>
            <label><?= h(t('case.effectiveness.notes_label')) ?>
                <textarea name="effectiveness_notes" rows="3"></textarea>
            </label>
            <button type="submit"><?= h(t('case.effectiveness.record_button')) ?></button>
        </form>
    <?php endif; ?>
</div>
<?php endif; ?>

<h2><?= h(t('case.status_history')) ?></h2>
<div class="card">
    <?php foreach ($logEntries as $entry): ?>
        <div class="log-row">
            <p><strong><?= h($entry['user_name']) ?></strong> — <?= h($entry['action']) ?><?= $entry['details'] ? ': ' . h($entry['details']) : '' ?></p>
            <p class="muted"><?= h($entry['created_at']) ?></p>
        </div>
    <?php endforeach; ?>
    <?php if (!$logEntries): ?><p class="muted"><?= h(t('case.no_log')) ?></p><?php endif; ?>
</div>

<?php render_footer(); ?>
