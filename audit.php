<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

$user = require_permission_allow_gqar('audits');
$db = get_db();

$id = (int) ($_GET['id'] ?? 0);
$stmt = $db->prepare('
    SELECT a.*, u.name AS auditor_name, d.title AS linked_document_title, d.doc_number AS linked_document_number
    FROM audits a
    JOIN users u ON u.id = a.auditor_id
    LEFT JOIN documents d ON d.id = a.linked_document_id
    WHERE a.id = ? AND a.organization_id = ?
');
$stmt->execute([$id, $user['organization_id']]);
$audit = $stmt->fetch();

if (!$audit) {
    http_response_code(404);
    render_header(t('audit.not_found'));
    echo '<div class="card">' . h(t('audit.not_found')) . '</div>';
    render_footer();
    exit;
}

if (is_gqar($user) && ($audit['audit_type'] !== 'Eksternt' || !user_has_contract($user, $audit['contract_reference']))) {
    http_response_code(403);
    render_header(t('error.forbidden'));
    echo '<div class="card">' . h(t('error.forbidden')) . '</div>';
    render_footer();
    exit;
}

$canManage = can_manage_audits($user);
$error = '';

if ($canManage && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $status = $_POST['status'] ?? '';
    $completedDate = trim($_POST['completed_date'] ?? '');
    $conclusion = trim($_POST['conclusion'] ?? '');
    $allowedStatuses = ['Planlagt', 'Gennemført', 'Aflyst'];

    if (!in_array($status, $allowedStatuses, true)) {
        $error = t('newaudit.error.required');
    } elseif ($status === 'Gennemført' && $conclusion === '') {
        $error = t('audit.error.conclusion_required');
    } else {
        $stmt = $db->prepare('UPDATE audits SET status = ?, completed_date = ?, conclusion = ? WHERE id = ? AND organization_id = ?');
        $stmt->execute([$status, $completedDate ?: null, $conclusion ?: null, $id, $user['organization_id']]);

        log_audit_action($id, 'Audit opdateret', 'Status: ' . $status);

        header('Location: audit.php?id=' . $id);
        exit;
    }
}

$stmt = $db->prepare("
    SELECT c.*, a2.name AS assigned_to_name
    FROM cases c
    LEFT JOIN users a2 ON a2.id = c.assigned_to
    WHERE c.audit_id = ?
    ORDER BY c.created_at DESC
");
$stmt->execute([$id]);
$findings = $stmt->fetchAll();

render_header($audit['title']);
?>
<p class="breadcrumb"><a href="audits.php"><?= h(t('audits.title')) ?></a> &raquo; <?= h($audit['title']) ?></p>

<?php if ($error): ?><p class="alert alert-danger"><?= h($error) ?></p><?php endif; ?>

<div class="card">
    <div class="doc-header">
        <div>
            <h1><?= h($audit['title']) ?></h1>
        </div>
        <span class="<?= audit_status_class($audit['status']) ?>"><?= h(audit_status_label($audit['status'])) ?></span>
    </div>

    <div class="meta-grid">
        <div><p class="meta-label"><?= h(t('audit.type')) ?></p><p><?= h(audit_type_label($audit['audit_type'])) ?></p></div>
        <?php if ($audit['contract_reference']): ?>
            <div><p class="meta-label"><?= h(t('audit.contract_reference')) ?></p><p><?= h($audit['contract_reference']) ?></p></div>
        <?php endif; ?>
        <div><p class="meta-label"><?= h(t('audit.auditor')) ?></p><p><?= h($audit['auditor_name']) ?></p></div>
        <div><p class="meta-label"><?= h(t('audit.planned_date')) ?></p><p><?= format_date($audit['planned_date']) ?></p></div>
        <div><p class="meta-label"><?= h(t('audit.completed_date')) ?></p><p><?= format_date($audit['completed_date']) ?></p></div>
    </div>

    <?php if ($audit['conclusion']): ?>
        <div style="border-top:0.5px solid var(--border); padding-top:1rem; margin-top:1rem;">
            <p class="meta-label"><?= h(t('audit.conclusion')) ?></p>
            <p style="margin:0;"><?= nl2br(h($audit['conclusion'])) ?></p>
        </div>
    <?php endif; ?>

    <div class="button-row">
        <?php if ($audit['linked_document_title']): ?>
            <a class="button button-secondary" href="document.php?id=<?= (int)$audit['linked_document_id'] ?>">
                <?= h(t('audit.linked_document')) ?>: <?= h($audit['linked_document_number']) ?>
            </a>
        <?php endif; ?>
        <?php if ($canManage): ?>
            <a class="button" href="new_case.php?audit_id=<?= (int)$audit['id'] ?>"><?= h(t('audit.register_finding')) ?></a>
        <?php endif; ?>
        <?php if ($user['role'] === 'admin'): ?>
            <form method="post" action="delete_audit.php" class="inline-form"
                  onsubmit="return confirm('<?= h(t('audit.delete_confirm')) ?>
');">
    <?= csrf_field() ?>
                <input type="hidden" name="audit_id" value="<?= (int)$audit['id'] ?>">
                <button type="submit" class="button-secondary button-danger"><?= h(t('audit.delete')) ?></button>
            </form>
        <?php endif; ?>
    </div>
</div>

<?php if ($canManage): ?>
<h2><?= h(t('audit.update_title')) ?></h2>
<div class="card">
    <form method="post">
    <?= csrf_field() ?>
        <label><?= h(t('audit.field.status')) ?>
            <select name="status">
                <?php foreach (['Planlagt', 'Gennemført', 'Aflyst'] as $s): ?>
                    <option value="<?= h($s) ?>" <?= $audit['status'] === $s ? 'selected' : '' ?>><?= h(audit_status_label($s)) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label><?= h(t('audit.field.completed_date')) ?>
            <input type="date" name="completed_date" value="<?= h($audit['completed_date'] ?? '') ?>">
        </label>
        <label><?= h(t('audit.field.conclusion')) ?>
            <textarea name="conclusion" rows="4"><?= h($audit['conclusion'] ?? '') ?></textarea>
        </label>
        <button type="submit"><?= h(t('audit.save')) ?></button>
    </form>
</div>
<?php endif; ?>

<h2><?= h(t('audit.findings')) ?></h2>
<div class="card">
    <?php foreach ($findings as $f): ?>
        <div class="timeline-row">
            <div>
                <p><strong><?= h($f['title']) ?></strong></p>
                <p class="muted"><?= h(type_label($f['type'])) ?> &middot; <?= h($f['assigned_to_name'] ?? t('cases.unassigned')) ?></p>
            </div>
            <div class="timeline-actions">
                <span class="<?= case_status_class($f['status']) ?>"><?= h(case_status_label($f['status'])) ?></span>
                <a href="case.php?id=<?= (int)$f['id'] ?>"><?= h(t('audit.view_finding')) ?></a>
            </div>
        </div>
    <?php endforeach; ?>
    <?php if (!$findings): ?><p class="muted"><?= h(t('audit.no_findings')) ?></p><?php endif; ?>
</div>

<?php render_footer(); ?>
