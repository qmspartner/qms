<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

$user = require_role(['admin']);
$db = get_db();

$targetId = (int) ($_GET['user_id'] ?? 0);
$stmt = $db->prepare("SELECT * FROM users WHERE id = ? AND organization_id = ? AND role != 'admin' AND active = 1");
$stmt->execute([$targetId, $user['organization_id']]);
$target = $stmt->fetch();

if (!$target) {
    http_response_code(404);
    exit(t('impersonation.user_not_found'));
}

$stmt = $db->prepare('INSERT INTO impersonation_log (organization_id, admin_user_id, target_user_id) VALUES (?, ?, ?)');
$stmt->execute([$user['organization_id'], $user['id'], $target['id']]);
$logId = (int) $db->lastInsertId();

$_SESSION['original_admin'] = $user;
$_SESSION['impersonation_log_id'] = $logId;
$_SESSION['user'] = build_session_user($target);
if (!empty($target['language'])) {
    $_SESSION['lang'] = $target['language'];
}

header('Location: home.php');
exit;
