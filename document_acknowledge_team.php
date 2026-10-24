<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

$user = require_login();
$db = get_db();

$documentId = (int) ($_GET['document_id'] ?? $_POST['document_id'] ?? 0);
$stmt = $db->prepare('SELECT * FROM documents WHERE id = ? AND organization_id = ?');
$stmt->execute([$documentId, $user['organization_id']]);
$doc = $stmt->fetch();

if (!$doc || !$doc['requires_acknowledgment'] || !$doc['current_version_id']) {
    http_response_code(404);
    render_header(t('doc.not_found'));
    echo '<div class="card">' . h(t('doc.not_found')) . '</div>';
    render_footer();
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $contextNote = trim($_POST['context_note'] ?? '');
    $employeeIds = array_map('intval', $_POST['employee_ids'] ?? []);

    if (!$employeeIds) {
        $error = t('docteam.error.none_selected');
    } else {
        // Bekræft at de valgte medarbejdere rent faktisk refererer til denne bruger
        $placeholders = implode(',', array_fill(0, count($employeeIds), '?'));
        $stmt = $db->prepare("
            SELECT id, name FROM employees
            WHERE organization_id = ? AND manager_user_id = ? AND active = 1 AND id IN ($placeholders)
        ");
        $stmt->execute(array_merge([$user['organization_id'], $user['id']], $employeeIds));
        $confirmedEmployees = $stmt->fetchAll();

        if ($confirmedEmployees) {
            $stmt = $db->prepare('
                INSERT IGNORE INTO document_acknowledgments (organization_id, document_id, version_id, employee_id, acknowledged_by, method, context_note)
                VALUES (?, ?, ?, ?, ?, \'manager_proxy\', ?)
            ');
            $names = [];
            foreach ($confirmedEmployees as $emp) {
                $stmt->execute([$user['organization_id'], $documentId, $doc['current_version_id'], $emp['id'], $user['id'], $contextNote ?: null]);
                $names[] = $emp['name'];
            }
            $details = implode(', ', $names) . ($contextNote !== '' ? ' — ' . $contextNote : '');
            log_action($documentId, (int) $doc['current_version_id'], t('doc.log.team_acknowledged'), $details);

            header('Location: document_acknowledge_team.php?document_id=' . $documentId);
            exit;
        }
    }
}

$stmt = $db->prepare('
    SELECT e.id, e.name, da.created_at, da.context_note
    FROM employees e
    LEFT JOIN document_acknowledgments da ON da.version_id = ? AND da.employee_id = e.id
    WHERE e.organization_id = ? AND e.manager_user_id = ? AND e.active = 1
    ORDER BY (da.id IS NULL) DESC, e.name
');
$stmt->execute([(int) $doc['current_version_id'], $user['organization_id'], $user['id']]);
$employees = $stmt->fetchAll();
$pendingEmployees = array_filter($employees, fn($e) => !$e['created_at']);

render_header($doc['title']);
?>
<p class="breadcrumb"><a href="document.php?id=<?= (int) $documentId ?>"><?= h($doc['title']) ?></a> &raquo; <?= h(t('docteam.title')) ?></p>

<div class="page-heading"><h1><?= h(t('docteam.title')) ?></h1></div>
<p class="muted" style="margin:-8px 0 20px;"><?= h(t('docteam.intro')) ?></p>

<?php if ($error): ?><p class="alert alert-danger"><?= h($error) ?></p><?php endif; ?>

<?php if (!$employees): ?>
    <div class="card"><p class="muted"><?= h(t('docteam.no_reports')) ?></p></div>
<?php else: ?>

<div class="card">
    <table class="doc-table">
        <thead><tr>
            <th></th>
            <th><?= h(t('doc.acknowledge.col.name')) ?></th>
            <th><?= h(t('doc.acknowledge.col.status')) ?></th>
            <th><?= h(t('doc.acknowledge.col.note')) ?></th>
        </tr></thead>
        <tbody>
        <?php foreach ($employees as $e): ?>
            <tr>
                <td><?php if (!$e['created_at']): ?><input type="checkbox" name="employee_ids[]" value="<?= (int) $e['id'] ?>" form="ack-team-form" style="width:auto;"><?php endif; ?></td>
                <td><?= h($e['name']) ?></td>
                <td>
                    <?php if ($e['created_at']): ?>
                        <span class="badge badge-success"><?= h(t('doc.acknowledge.confirmed')) ?></span> <span class="muted"><?= h(format_date($e['created_at'])) ?></span>
                    <?php else: ?>
                        <span class="badge badge-neutral"><?= h(t('doc.acknowledge.pending')) ?></span>
                    <?php endif; ?>
                </td>
                <td class="muted"><?= $e['context_note'] ? h($e['context_note']) : '—' ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php if ($pendingEmployees): ?>
<div class="card">
    <form method="post" id="ack-team-form">
    <?= csrf_field() ?>
        <input type="hidden" name="document_id" value="<?= (int) $documentId ?>">
        <label><?= h(t('docteam.field.context_note')) ?>
            <input type="text" name="context_note" placeholder="<?= h(t('docteam.field.context_note_ph')) ?>">
        </label>
        <button type="submit"><?= h(t('docteam.submit')) ?></button>
    </form>
</div>
<?php endif; ?>

<?php endif; ?>

<?php render_footer(); ?>
