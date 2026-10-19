<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

$user = require_permission('templates', 'view');
$db = get_db();

$stmt = $db->prepare("
    SELECT d.*, v.version_number, v.approved_at, v.original_approval_date, v.stored_filename, v.original_filename
    FROM documents d
    LEFT JOIN document_versions v ON v.id = d.current_version_id
    WHERE d.organization_id = ? AND d.is_template = 1
    ORDER BY d.category, d.title
");
$stmt->execute([$user['organization_id']]);
$rows = $stmt->fetchAll();

$grouped = [];
foreach ($rows as $row) {
    $grouped[$row['category']][] = $row;
}

// Vis også de aktive dokumentkategorier, der endnu ikke har en skabelon, så
// det er tydeligt, hvor der mangler en.
foreach (list_active_values('document_category') as $cat) {
    if (!isset($grouped[$cat])) {
        $grouped[$cat] = [];
    }
}
uksort($grouped, fn($a, $b) => strcasecmp(category_label($a), category_label($b)));

// Oprettelse af en ny skabelon foregår via den almindelige dokument-upload og
// styres derfor af dokument-modulets egen opret-rettighed, ikke af "Skabelonbibliotek"
// (som kun styrer hvem der kan SE og downloade fra biblioteket).
$canAdd = has_permission($user, 'documents', 'create');
$canSeeDocuments = has_permission($user, 'documents');

render_header(t('templates.title'));
?>
<div class="page-heading-row">
<div class="page-heading">
    <h1><?= h(t('templates.title')) ?> <?php help_tip('help.tip.templates'); ?></h1>
    <?php if ($canAdd): ?>
        <a class="button" href="upload_version.php"><?= h(t('templates.add')) ?></a>
    <?php endif; ?>
</div>
<div></div>
</div>
<p class="muted" style="margin:0 0 20px;"><?= h(t('templates.intro')) ?></p>

<?php if (!$grouped): ?>
    <p class="alert alert-danger"><?= h(t('templates.empty')) ?></p>
<?php else: ?>
    <?php foreach ($grouped as $category => $docs): ?>
        <h2><?= h(category_label($category)) ?></h2>
        <?php if (!$docs): ?>
            <p class="muted" style="margin:0 0 20px;"><?= h(t('templates.category_empty')) ?><?php if ($canAdd): ?> <a href="upload_version.php?category=<?= urlencode($category) ?>"><?= h(t('templates.add')) ?></a><?php endif; ?></p>
        <?php else: ?>
        <div class="card" style="margin-bottom:20px;">
            <table class="doc-table">
                <thead>
                <tr>
                    <th><?= h(t('templates.col.title')) ?></th>
                    <th><?= h(t('templates.col.version')) ?></th>
                    <th><?= h(t('templates.col.approved_date')) ?></th>
                    <th><?= h(t('templates.col.status')) ?></th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($docs as $d): ?>
                    <tr>
                        <td><?= h($d['title']) ?></td>
                        <td><?= $d['version_number'] ? h($d['version_number']) : '—' ?></td>
                        <td><?= format_date($d['approved_at'] ?: $d['original_approval_date']) ?></td>
                        <td><span class="<?= status_badge_class($d['status']) ?>"><?= h(status_label($d['status'])) ?></span></td>
                        <td>
                            <?php if ($d['stored_filename']): ?>
                                <a href="download_template.php?version_id=<?= (int)$d['current_version_id'] ?>"><?= h(t('templates.download')) ?></a>
                            <?php endif; ?>
                            <?php if ($canSeeDocuments): ?>
                                &nbsp;·&nbsp;<a href="document.php?id=<?= (int)$d['id'] ?>"><?= h(t('templates.history')) ?></a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    <?php endforeach; ?>
<?php endif; ?>

<?php render_footer(); ?>
