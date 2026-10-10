<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

if (!is_impersonating()) {
    header('Location: home.php');
    exit;
}

$db = get_db();
if (isset($_SESSION['impersonation_log_id'])) {
    $stmt = $db->prepare('UPDATE impersonation_log SET ended_at = NOW() WHERE id = ?');
    $stmt->execute([$_SESSION['impersonation_log_id']]);
    unset($_SESSION['impersonation_log_id']);
}

$_SESSION['user'] = $_SESSION['original_admin'];
unset($_SESSION['original_admin']);
if (!empty($_SESSION['user']['language'])) {
    $_SESSION['lang'] = $_SESSION['user']['language'];
}

header('Location: home.php');
exit;
