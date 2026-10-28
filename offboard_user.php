<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

$user = require_role(['admin']);
$db = get_db();

$id = (int) ($_GET['id'] ?? $_POST['target_user_id'] ?? 0);
$stmt = $db->prepare('SELECT * FROM users WHERE id = ? AND organization_id = ?');
$stmt->execute([$id, $user['organization_id']]);
$targetUser = $stmt->fetch();

if (!$targetUser) {
    http_response_code(404);
    render_header(t('edituser.not_found'));
    echo '<div class="card">' . h(t('edituser.not_found')) . '</div>';
    render_footer();
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $targetUser['deactivation_requested_at'] && (int) $targetUser['active'] === 1) {
    $action = $_POST['action'] ?? '';

    if ($action === 'reassign_item') {
        $itemType = $_POST['item_type'] ?? '';
        $itemId = (int) ($_POST['item_id'] ?? 0);
        $newOwnerId = (int) ($_POST['new_owner_id'] ?? 0);
        $openItems = get_user_open_items($db, $targetUser);
        $match = null;
        foreach ($openItems as $it) {
            if ($it['item_type'] === $itemType && $it['item_id'] === $itemId) {
                $match = $it;
                break;
            }
        }
        $stmt = $db->prepare('SELECT id FROM users WHERE id = ? AND organization_id = ? AND active = 1');
        $stmt->execute([$newOwnerId, $user['organization_id']]);
        $validOwner = $stmt->fetch();

        if (!$match) {
            $error = t('offboard.error.item_not_open');
        } elseif (!$validOwner || $newOwnerId === $id) {
            $error = t('offboard.error.owner_required');
        } else {
            reassign_user_open_item($db, $id, $match, $newOwnerId, $user);
            header('Location: offboard_user.php?id=' . $id);
            exit;
        }
    } elseif ($action === 'take_over_all') {
        $openItems = get_user_open_items($db, $targetUser);
        foreach ($openItems as $it) {
            reassign_user_open_item($db, $id, $it, (int) $user['id'], $user);
        }
        header('Location: offboard_user.php?id=' . $id);
        exit;
    } elseif ($action === 'deactivate') {
        $openItems = get_user_open_items($db, $targetUser);
        if ($openItems) {
            $error = t('offboard.error.items_remaining');
        } else {
            $stmt = $db->prepare('UPDATE users SET active = 0, deactivation_requested_at = NULL, deactivation_requested_by = NULL WHERE id = ? AND organization_id = ?');
            $stmt->execute([$id, $user['organization_id']]);
            log_offboarding_action('user', $id, 'deactivation', $id, $targetUser['name'], 'deactivated', null, null, $user);
            header('Location: users.php?msg=deactivated');
            exit;
        }
    } elseif ($action === 'cancel') {
        $stmt = $db->prepare('UPDATE users SET deactivation_requested_at = NULL, deactivation_requested_by = NULL WHERE id = ? AND organization_id = ?');
        $stmt->execute([$id, $user['organization_id']]);
        log_offboarding_action('user', $id, 'request', $id, $targetUser['name'], 'cancelled', null, null, $user);
        header('Location: users.php?msg=offboarding_cancelled');
        exit;
    }
}

$openItems = ((int) $targetUser['active'] === 1 && $targetUser['deactivation_requested_at']) ? get_user_open_items($db, $targetUser) : [];

$stmt = $db->prepare('SELECT id, name FROM users WHERE organization_id = ? AND active = 1 AND id != ? ORDER BY name');
$stmt->execute([$user['organization_id'], $id]);
$ownerOptions = $stmt->fetchAll();

$stmt = $db->prepare('
    SELECT ol.*, u.name AS performed_by_name, nu.name AS new_owner_name
    FROM offboarding_log ol
    JOIN users u ON u.id = ol.performed_by
    LEFT JOIN users nu ON nu.id = ol.new_owner_id
    WHERE ol.subject_type = \'user\' AND ol.subject_id = ?
    ORDER BY ol.created_at DESC
');
$stmt->execute([$id]);
$log = $stmt->fetchAll();

render_header($targetUser['name']);
?>
<p class="breadcrumb"><a href="users.php"><?= h(t('users.title')) ?></a> &raquo; <?= h($targetUser['name']) ?></p>

<div class="page-heading"><h1><?= h(t('offboard.user.title', ['name' => $targetUser['name']])) ?></h1></div>

<?php if ($error): ?><p class="alert alert-danger"><?= h($error) ?></p><?php endif; ?>

<div class="card">
    <div class="doc-header">
        <div>
            <h2 style="margin:0;"><?= h($targetUser['name']) ?></h2>
            <p class="muted"><?= h($targetUser['email']) ?> · <?= h(t('users.table.role')) ?>: <?= h(role_label($targetUser['role'])) ?></p>
        </div>
        <span class="<?= (int) $targetUser['active'] === 1 ? 'badge badge-success' : 'badge badge-neutral' ?>"><?= (int) $targetUser['active'] === 1 ? h(t('users.active')) : h(t('users.inactive')) ?></span>
    </div>
</div>

<?php if ((int) $targetUser['active'] === 0): ?>
    <p class="alert alert-success"><?= h(t('offboard.already_done')) ?></p>
<?php elseif (!$targetUser['deactivation_requested_at']): ?>
    <p class="alert alert-success"><?= h(t('offboard.nothing_pending')) ?></p>
<?php else: ?>

    <?php if ($openItems): ?>
        <div class="alert alert-warning">
            <?= h(t('offboard.user.intro')) ?>
        </div>
    <?php endif; ?>

    <div class="card" style="padding:0; overflow:hidden;">
        <?php foreach ($openItems as $it): ?>
            <div style="padding:16px 20px; border-bottom:1px solid var(--border);">
                <p class="muted" style="margin:0; font-size:12px; font-weight:700; text-transform:uppercase; letter-spacing:.03em; color: var(--accent);"><?= h($it['type_label']) ?></p>
                <p style="margin:3px 0 10px;"><?= h($it['label']) ?></p>
                <form method="post" class="inline-grid-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="reassign_item">
                    <input type="hidden" name="target_user_id" value="<?= (int) $id ?>">
                    <input type="hidden" name="item_type" value="<?= h($it['item_type']) ?>">
                    <input type="hidden" name="item_id" value="<?= (int) $it['item_id'] ?>">
                    <select name="new_owner_id" required style="flex:1 1 auto; min-width:0;">
                        <option value=""><?= h(t('offboard.field.choose_owner')) ?></option>
                        <?php foreach ($ownerOptions as $o): ?>
                            <option value="<?= (int) $o['id'] ?>"><?= h($o['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit"><?= h(t('offboard.action.reassign')) ?></button>
                </form>
            </div>
        <?php endforeach; ?>
        <?php if (!$openItems): ?>
            <p class="muted" style="padding:16px 20px; margin:0;"><?= h(t('offboard.all_resolved')) ?></p>
        <?php endif; ?>
    </div>

    <?php if ($openItems): ?>
    <div class="card" style="border-style:dashed; display:flex; justify-content:space-between; align-items:center; gap:12px;">
        <p class="muted" style="margin:0;"><?= h(t('offboard.user.quick_exit')) ?></p>
        <form method="post" class="inline-form">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="take_over_all">
            <input type="hidden" name="target_user_id" value="<?= (int) $id ?>">
            <button type="submit" class="button-secondary"><?= h(t('offboard.action.take_over_all')) ?></button>
        </form>
    </div>
    <?php endif; ?>

    <div class="button-row" style="margin-top:16px; justify-content:flex-end;">
        <form method="post" class="inline-form" onsubmit="return confirm('<?= h(t('offboard.cancel_confirm')) ?>');">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="cancel">
            <input type="hidden" name="target_user_id" value="<?= (int) $id ?>">
            <button type="submit" class="button-secondary"><?= h(t('offboard.action.cancel')) ?></button>
        </form>
        <form method="post" class="inline-form" onsubmit="return confirm('<?= h(t('offboard.deactivate_confirm_user', ['name' => $targetUser['name']])) ?>');">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="deactivate">
            <input type="hidden" name="target_user_id" value="<?= (int) $id ?>">
            <button type="submit" <?= $openItems ? 'disabled' : '' ?>><?= h(t('offboard.action.deactivate_user')) ?></button>
        </form>
    </div>

<?php endif; ?>

<?php if ($log): ?>
<h2><?= h(t('offboard.log_title')) ?></h2>
<div class="card">
    <?php foreach ($log as $entry): ?>
        <div class="timeline-row">
            <div>
                <p><strong><?= h(t('offboard.log.action.' . $entry['action'])) ?></strong> — <?= h($entry['item_label']) ?><?= $entry['new_owner_name'] ? ' → ' . h($entry['new_owner_name']) : '' ?></p>
                <?php if ($entry['note']): ?><p class="muted">"<?= h($entry['note']) ?>"</p><?php endif; ?>
                <p class="muted"><?= h($entry['performed_by_name']) ?>, <?= h($entry['created_at']) ?></p>
            </div>
        </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php render_footer(); ?>
