<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

$user = require_role(['admin']);
$db = get_db();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: users.php');
    exit;
}

$targetId = (int) ($_POST['user_id'] ?? 0);

if ($targetId === (int) $user['id']) {
    header('Location: users.php');
    exit;
}

$stmt = $db->prepare('SELECT id FROM users WHERE id = ? AND organization_id = ?');
$stmt->execute([$targetId, $user['organization_id']]);
if (!$stmt->fetch()) {
    http_response_code(404);
    exit('Brugeren blev ikke fundet.');
}

try {
    // Forsøg en rigtig sletning. Dette fejler, hvis brugeren har tilknyttet historik
    // (fx ejer et dokument, har uploadet eller godkendt en version) - databasen
    // beskytter bevidst mod at slette den slags, så historikken ikke går tabt.
    $stmt = $db->prepare('DELETE FROM users WHERE id = ? AND organization_id = ?');
    $stmt->execute([$targetId, $user['organization_id']]);
    header('Location: users.php?msg=deleted');
} catch (PDOException $e) {
    // Kan ikke slettes - tjek om brugeren reelt ejer/er tildelt noget aktivt lige nu.
    $stmt = $db->prepare('SELECT * FROM users WHERE id = ? AND organization_id = ?');
    $stmt->execute([$targetId, $user['organization_id']]);
    $targetUser = $stmt->fetch();

    $openItems = $targetUser ? get_user_open_items($db, $targetUser) : [];

    if ($openItems) {
        // Åbne punkter skal omfordeles først - deaktiver ikke endnu.
        $stmt = $db->prepare('UPDATE users SET deactivation_requested_at = NOW(), deactivation_requested_by = ? WHERE id = ? AND organization_id = ?');
        $stmt->execute([$user['id'], $targetId, $user['organization_id']]);
        log_offboarding_action('user', $targetId, 'request', $targetId, $targetUser['name'], 'requested', null, null, $user);
        header('Location: offboard_user.php?id=' . $targetId);
    } else {
        // Kun historiske referencer uden reelt åbent ejerskab - deaktiver med det samme som hidtil.
        $stmt = $db->prepare('UPDATE users SET active = 0 WHERE id = ? AND organization_id = ?');
        $stmt->execute([$targetId, $user['organization_id']]);
        log_offboarding_action('user', $targetId, 'deactivation', $targetId, $targetUser['name'], 'deactivated', null, null, $user);
        header('Location: users.php?msg=deactivated');
    }
}
exit;
