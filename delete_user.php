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
    // Faldt tilbage til at deaktivere kontoen i stedet, så personen ikke kan logge ind,
    // men historikken (hvem der har oprettet/godkendt hvad) bevares.
    $stmt = $db->prepare('UPDATE users SET active = 0 WHERE id = ? AND organization_id = ?');
    $stmt->execute([$targetId, $user['organization_id']]);
    header('Location: users.php?msg=deactivated');
}
exit;
