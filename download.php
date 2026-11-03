<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

$user = require_permission_allow_gqar('documents');
$db = get_db();

$versionId = (int) ($_GET['version_id'] ?? 0);
$stmt = $db->prepare('
    SELECT v.*, d.current_version_id, d.title AS document_title, d.status AS document_status,
           d.is_configuration_item, d.baseline_reference
    FROM document_versions v
    JOIN documents d ON d.id = v.document_id
    WHERE v.id = ? AND d.organization_id = ?
');
$stmt->execute([$versionId, $user['organization_id']]);
$version = $stmt->fetch();

if (!$version) {
    http_response_code(404);
    exit('Filen blev ikke fundet.');
}

// Samme centrale synlighedsregel som dokumentsiden (document_is_visible() i
// includes/functions.php) — en direkte fil-URL må ikke kunne omgå den. $version
// har dokumentets status under et andet navn (document_status), fordi v.* allerede
// bringer versionens EGEN status med som 'status' — byg derfor et lille
// dokument-formet array til funktionen i stedet for at genbruge $version direkte.
$docForVisibility = [
    'status' => $version['document_status'],
    'is_configuration_item' => $version['is_configuration_item'],
    'baseline_reference' => $version['baseline_reference'],
];
if (!document_is_visible($user, $docForVisibility)) {
    http_response_code(403);
    exit(t('error.forbidden'));
}

$path = UPLOAD_DIR . $version['stored_filename'];
if (!is_file($path)) {
    http_response_code(404);
    exit('Filen mangler på serveren.');
}

$isObsolete = (int) $version['id'] !== (int) $version['current_version_id'];
if ($isObsolete && !isset($_GET['confirm'])) {
    render_header($version['document_title']);
    ?>
    <div class="card login-card">
        <p class="alert alert-danger"><?= h(t('doc.obsolete_warning')) ?></p>
        <div class="button-row">
            <a class="button button-danger" href="download.php?version_id=<?= (int) $versionId ?>&confirm=1"><?= h(t('doc.download_anyway')) ?></a>
            <a class="button button-secondary" href="document.php?id=<?= (int) $version['document_id'] ?>"><?= h(t('doc.back_to_document')) ?></a>
        </div>
    </div>
    <?php
    render_footer();
    exit;
}

log_action($version['document_id'], $version['id'], 'Fil downloadet' . ($isObsolete ? ' (forældet version)' : ''));

header('Content-Description: File Transfer');
header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . basename($version['original_filename']) . '"');
header('Content-Length: ' . filesize($path));
readfile($path);
exit;
