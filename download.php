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

// Samme adgangsregler som dokumentsiden: en direkte fil-URL må ikke kunne
// omgå dem. GQAR må kun hente godkendte konfigurationsstyrede dokumenter
// inden for egen kontrakt; almindelige brugere må kun hente godkendte filer.
if (is_gqar($user) && (!$version['is_configuration_item'] || $version['document_status'] !== 'Godkendt' || !user_has_contract($user, $version['baseline_reference']))) {
    http_response_code(403);
    exit(t('error.forbidden'));
}
if (!is_gqar($user) && !can_view_all_document_statuses($user) && $version['document_status'] !== 'Godkendt') {
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
