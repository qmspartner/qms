<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

$lang = $_GET['lang'] ?? '';

if (isset(AVAILABLE_LANGUAGES[$lang])) {
    $_SESSION['lang'] = $lang;

    $user = current_user();
    if ($user) {
        $db = get_db();
        $stmt = $db->prepare('UPDATE users SET language = ? WHERE id = ?');
        $stmt->execute([$lang, $user['id']]);
        $_SESSION['user']['language'] = $lang;
    }
}

$redirect = $_SERVER['HTTP_REFERER'] ?? 'home.php';
header('Location: ' . $redirect);
exit;
