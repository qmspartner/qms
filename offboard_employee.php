<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

$user = require_login();
$db = get_db();

$id = (int) ($_GET['id'] ?? $_POST['employee_id'] ?? 0);
$stmt = $db->prepare('
    SELECT e.*, u.name AS manager_name
    FROM employees e
    LEFT JOIN users u ON u.id = e.manager_user_id
    WHERE e.id = ? AND e.organization_id = ?
');
$stmt->execute([$id, $user['organization_id']]);
$employee = $stmt->fetch();

if (!$employee) {
    http_response_code(404);
    render_header(t('employees.not_found'));
    echo '<div class="card">' . h(t('employees.not_found')) . '</div>';
    render_footer();
    exit;
}

if (!can_manage_employee_offboarding($user, $employee)) {
    http_response_code(403);
    render_header(t('error.forbidden'));
    echo '<div class="card">' . h(t('error.forbidden')) . '</div>';
    render_footer();
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $employee['deactivation_requested_at'] && (int) $employee['active'] === 1) {
    $action = $_POST['action'] ?? '';

    if ($action === 'close_item') {
        $itemType = $_POST['item_type'] ?? '';
        $itemId = (int) ($_POST['item_id'] ?? 0);
        $note = trim($_POST['note'] ?? '');
        $openItems = get_employee_open_items($db, $employee);
        $match = null;
        foreach ($openItems as $it) {
            if ($it['item_type'] === $itemType && $it['item_id'] === $itemId) {
                $match = $it;
                break;
            }
        }
        if (!$match) {
            $error = t('offboard.error.item_not_open');
        } elseif ($note === '') {
            $error = t('offboard.error.note_required');
        } else {
            log_offboarding_action('employee', $id, $itemType, $itemId, $match['label'], 'closed_not_relevant', null, $note, $user);
            header('Location: offboard_employee.php?id=' . $id);
            exit;
        }
    } elseif ($action === 'bulk_close') {
        $note = trim($_POST['note'] ?? '');
        if ($note === '') {
            $error = t('offboard.error.note_required');
        } else {
            $openItems = get_employee_open_items($db, $employee);
            foreach ($openItems as $it) {
                log_offboarding_action('employee', $id, $it['item_type'], $it['item_id'], $it['label'], 'closed_not_relevant', null, $note, $user);
            }
            header('Location: offboard_employee.php?id=' . $id);
            exit;
        }
    } elseif ($action === 'deactivate') {
        $openItems = get_employee_open_items($db, $employee);
        if ($openItems) {
            $error = t('offboard.error.items_remaining');
        } else {
            $stmt = $db->prepare('UPDATE employees SET active = 0, deactivation_requested_at = NULL, deactivation_requested_by = NULL WHERE id = ? AND organization_id = ?');
            $stmt->execute([$id, $user['organization_id']]);
            log_offboarding_action('employee', $id, 'deactivation', $id, $employee['name'], 'deactivated', null, null, $user);
            header('Location: edit_employee.php?id=' . $id . '&msg=deactivated');
            exit;
        }
    } elseif ($action === 'cancel') {
        $stmt = $db->prepare('UPDATE employees SET deactivation_requested_at = NULL, deactivation_requested_by = NULL WHERE id = ? AND organization_id = ?');
        $stmt->execute([$id, $user['organization_id']]);
        log_offboarding_action('employee', $id, 'request', $id, $employee['name'], 'cancelled', null, null, $user);
        header('Location: edit_employee.php?id=' . $id . '&msg=offboarding_cancelled');
        exit;
    }
}

$openItems = ((int) $employee['active'] === 1 && $employee['deactivation_requested_at']) ? get_employee_open_items($db, $employee) : [];

$stmt = $db->prepare('
    SELECT ol.*, u.name AS performed_by_name
    FROM offboarding_log ol
    JOIN users u ON u.id = ol.performed_by
    WHERE ol.subject_type = \'employee\' AND ol.subject_id = ?
    ORDER BY ol.created_at DESC
');
$stmt->execute([$id]);
$log = $stmt->fetchAll();

render_header($employee['name']);
?>
<p class="breadcrumb"><a href="employees.php"><?= h(t('employees.title')) ?></a> &raquo; <?= h($employee['name']) ?></p>

<div class="page-heading"><h1><?= h(t('offboard.employee.title', ['name' => $employee['name']])) ?></h1></div>

<?php if ($error): ?><p class="alert alert-danger"><?= h($error) ?></p><?php endif; ?>

<div class="card">
    <div class="doc-header">
        <div>
            <h2 style="margin:0;"><?= h($employee['name']) ?></h2>
            <p class="muted"><?= h($employee['title'] ?: '—') ?><?= $employee['department'] ? ' · ' . h($employee['department']) : '' ?><?= $employee['manager_name'] ? ' · ' . h(t('employees.field.manager')) . ': ' . h($employee['manager_name']) : '' ?></p>
        </div>
        <span class="<?= (int) $employee['active'] === 1 ? 'badge badge-success' : 'badge badge-neutral' ?>"><?= (int) $employee['active'] === 1 ? h(t('employees.active')) : h(t('employees.inactive')) ?></span>
    </div>
</div>

<?php if ((int) $employee['active'] === 0): ?>
    <p class="alert alert-success"><?= h(t('offboard.already_done')) ?></p>
<?php elseif (!$employee['deactivation_requested_at']): ?>
    <p class="alert alert-success"><?= h(t('offboard.nothing_pending')) ?></p>
<?php else: ?>

    <?php if ($openItems): ?>
        <div class="alert alert-warning">
            <?= h(t('offboard.employee.intro')) ?>
        </div>
    <?php endif; ?>

    <div class="card" style="padding:0; overflow:hidden;">
        <?php foreach ($openItems as $it): ?>
            <div style="padding:16px 20px; border-bottom:1px solid var(--border);">
                <p class="muted" style="margin:0; font-size:12px; font-weight:700; text-transform:uppercase; letter-spacing:.03em; color: <?= $it['urgent'] ? 'var(--danger)' : 'var(--warning)' ?>;"><?= h($it['type_label']) ?></p>
                <p style="margin:3px 0 10px;"><?= h($it['label']) ?></p>
                <form method="post" class="inline-grid-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="close_item">
                    <input type="hidden" name="employee_id" value="<?= (int) $id ?>">
                    <input type="hidden" name="item_type" value="<?= h($it['item_type']) ?>">
                    <input type="hidden" name="item_id" value="<?= (int) $it['item_id'] ?>">
                    <input type="text" name="note" placeholder="<?= h(t('offboard.field.note_placeholder')) ?>" required style="flex:1 1 auto; min-width:0;">
                    <button type="submit"><?= h(t('offboard.action.close_item')) ?></button>
                </form>
            </div>
        <?php endforeach; ?>
        <?php if (!$openItems): ?>
            <p class="muted" style="padding:16px 20px; margin:0;"><?= h(t('offboard.all_resolved')) ?></p>
        <?php endif; ?>
    </div>

    <?php if ($openItems): ?>
    <div class="card" style="border-style:dashed; display:flex; gap:10px; align-items:center;">
        <form method="post" class="inline-grid-form" style="flex:1 1 auto; display:flex; gap:8px;">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="bulk_close">
            <input type="hidden" name="employee_id" value="<?= (int) $id ?>">
            <input type="text" name="note" placeholder="<?= h(t('offboard.field.bulk_note_placeholder')) ?>" required style="flex:1 1 auto; min-width:0;">
            <button type="submit" class="button-secondary"><?= h(t('offboard.action.bulk_close')) ?></button>
        </form>
    </div>
    <?php endif; ?>

    <div class="button-row" style="margin-top:16px; justify-content:flex-end;">
        <form method="post" class="inline-form" onsubmit="return confirm('<?= h(t('offboard.cancel_confirm')) ?>');">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="cancel">
            <input type="hidden" name="employee_id" value="<?= (int) $id ?>">
            <button type="submit" class="button-secondary"><?= h(t('offboard.action.cancel')) ?></button>
        </form>
        <form method="post" class="inline-form" onsubmit="return confirm('<?= h(t('offboard.deactivate_confirm_employee', ['name' => $employee['name']])) ?>');">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="deactivate">
            <input type="hidden" name="employee_id" value="<?= (int) $id ?>">
            <button type="submit" <?= $openItems ? 'disabled' : '' ?>><?= h(t('offboard.action.deactivate_employee')) ?></button>
        </form>
    </div>

<?php endif; ?>

<?php if ($log): ?>
<h2><?= h(t('offboard.log_title')) ?></h2>
<div class="card">
    <?php foreach ($log as $entry): ?>
        <div class="timeline-row">
            <div>
                <p><strong><?= h(t('offboard.log.action.' . $entry['action'])) ?></strong> — <?= h($entry['item_label']) ?></p>
                <?php if ($entry['note']): ?><p class="muted">"<?= h($entry['note']) ?>"</p><?php endif; ?>
                <p class="muted"><?= h($entry['performed_by_name']) ?>, <?= h($entry['created_at']) ?></p>
            </div>
        </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php render_footer(); ?>
