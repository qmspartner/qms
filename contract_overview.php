<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

$user = require_login();
$db = get_db();
$isGqar = is_gqar($user);

$availableContracts = $isGqar ? ($user['gqar_contract_references'] ?? []) : list_active_values('contract_reference');

$selectedContract = $_GET['contract'] ?? '';
if ($selectedContract === '' || !in_array($selectedContract, $availableContracts, true)) {
    $selectedContract = $availableContracts[0] ?? '';
}

$documents = $audits = $cases = $risks = $suppliers = $equipmentList = $softwareReleases = $faiRecords = [];
$faiTemplate = null;
$isAdmin = $user['role'] === 'admin';

if ($selectedContract !== '') {
    $docStatusClause = $isGqar ? "AND status = 'Godkendt'" : '';
    $stmt = $db->prepare("
        SELECT * FROM documents
        WHERE organization_id = ? AND is_configuration_item = 1 AND baseline_reference = ? {$docStatusClause}
        ORDER BY doc_number
    ");
    $stmt->execute([$user['organization_id'], $selectedContract]);
    $documents = $stmt->fetchAll();

    $stmt = $db->prepare("
        SELECT * FROM audits
        WHERE organization_id = ? AND audit_type = 'Eksternt' AND contract_reference = ?
        ORDER BY planned_date DESC
    ");
    $stmt->execute([$user['organization_id'], $selectedContract]);
    $audits = $stmt->fetchAll();

    $stmt = $db->prepare("
        SELECT * FROM cases
        WHERE organization_id = ? AND requires_customer_notification = 1 AND contract_reference = ?
        ORDER BY status, due_date
    ");
    $stmt->execute([$user['organization_id'], $selectedContract]);
    $cases = $stmt->fetchAll();

    if (organization_delivers_software()) {
        $stmt = $db->prepare('
            SELECT sr.*, d.doc_number, d.title AS doc_title
            FROM software_releases sr
            LEFT JOIN documents d ON d.id = sr.linked_document_id
            WHERE sr.organization_id = ? AND sr.contract_reference = ?
            ORDER BY sr.delivery_date DESC, sr.id DESC
        ');
        $stmt->execute([$user['organization_id'], $selectedContract]);
        $softwareReleases = $stmt->fetchAll();
    }

    if (organization_has_fai()) {
        $stmt = $db->prepare('
            SELECT * FROM fai_records
            WHERE organization_id = ? AND contract_reference = ?
            ORDER BY part_number
        ');
        $stmt->execute([$user['organization_id'], $selectedContract]);
        $faiRecords = $stmt->fetchAll();
    }
    $faiTemplate = ($isAdmin && organization_has_fai()) ? get_latest_template_document(FAI_TEMPLATE_CATEGORY) : null;

    if (!$isGqar) {
        $stmt = $db->prepare('SELECT * FROM risks WHERE organization_id = ? AND contract_reference = ? ORDER BY (probability * consequence) DESC');
        $stmt->execute([$user['organization_id'], $selectedContract]);
        $risks = $stmt->fetchAll();

        $stmt = $db->prepare('
            SELECT s.* FROM suppliers s
            JOIN supplier_contracts sc ON sc.supplier_id = s.id
            WHERE s.organization_id = ? AND sc.contract_reference = ?
            ORDER BY s.name
        ');
        $stmt->execute([$user['organization_id'], $selectedContract]);
        $suppliers = $stmt->fetchAll();

        $stmt = $db->prepare('
            SELECT e.* FROM equipment e
            JOIN equipment_contracts ec ON ec.equipment_id = e.id
            WHERE e.organization_id = ? AND ec.contract_reference = ?
            ORDER BY e.name
        ');
        $stmt->execute([$user['organization_id'], $selectedContract]);
        $equipmentList = $stmt->fetchAll();
    }
}

$releaseError = $_GET['error'] ?? '';
$faiError = $_GET['fai_error'] ?? '';

render_header(t('contractoverview.title'));
?>
<div class="page-heading">
    <h1><?= h(t('contractoverview.title')) ?> <?php help_tip('help.tip.contract_overview'); ?></h1>
</div>

<?php if ($releaseError === 'build_version_required'): ?>
    <p class="alert alert-danger"><?= h(t('contractoverview.software.error.build_version_required')) ?></p>
<?php elseif ($releaseError === 'bad_date'): ?>
    <p class="alert alert-danger"><?= h(t('contractoverview.software.error.bad_date')) ?></p>
<?php endif; ?>

<?php if ($faiError): ?>
    <p class="alert alert-danger"><?= h(t('contractoverview.fai.error.' . $faiError)) ?></p>
<?php endif; ?>

<?php if (!$availableContracts): ?>
    <p class="alert alert-danger"><?= h(t('contractoverview.no_contracts')) ?></p>
<?php else: ?>
    <form method="get" class="inline-grid-form" style="margin-bottom:1.5rem;">
        <select name="contract" <?= count($availableContracts) <= 1 ? 'disabled' : '' ?>>
            <?php foreach ($availableContracts as $ref): ?>
                <option value="<?= h($ref) ?>" <?= $selectedContract === $ref ? 'selected' : '' ?>><?= h($ref) ?></option>
            <?php endforeach; ?>
        </select>
        <?php if (count($availableContracts) > 1): ?>
            <button type="submit"><?= h(t('contractoverview.show')) ?></button>
        <?php endif; ?>
    </form>

    <h2><?= h(t('contractoverview.section.documents')) ?></h2>
    <div class="card">
        <?php if ($documents): ?>
            <table class="doc-table">
                <thead><tr><th><?= h(t('documents.table.id')) ?></th><th><?= h(t('documents.table.document')) ?></th><th><?= h(t('documents.table.status')) ?></th></tr></thead>
                <tbody>
                <?php foreach ($documents as $d): ?>
                    <tr onclick="window.location='document.php?id=<?= (int)$d['id'] ?>'">
                        <td><?= h($d['doc_number']) ?></td>
                        <td><?= h($d['title']) ?></td>
                        <td><span class="<?= status_badge_class($d['status']) ?>"><?= h(status_label($d['status'])) ?></span></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?><p class="muted"><?= h(t('contractoverview.empty')) ?></p><?php endif; ?>
    </div>

    <h2><?= h(t('contractoverview.section.audits')) ?></h2>
    <div class="card">
        <?php if ($audits): ?>
            <table class="doc-table">
                <thead><tr><th><?= h(t('audits.table.title')) ?></th><th><?= h(t('audits.table.planned_date')) ?></th><th><?= h(t('audits.table.status')) ?></th></tr></thead>
                <tbody>
                <?php foreach ($audits as $a): ?>
                    <tr onclick="window.location='audit.php?id=<?= (int)$a['id'] ?>'">
                        <td><?= h($a['title']) ?></td>
                        <td><?= format_date($a['planned_date']) ?></td>
                        <td><span class="<?= audit_status_class($a['status']) ?>"><?= h(audit_status_label($a['status'])) ?></span></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?><p class="muted"><?= h(t('contractoverview.empty')) ?></p><?php endif; ?>
    </div>

    <h2><?= h(t('contractoverview.section.cases')) ?></h2>
    <div class="card">
        <?php if ($cases): ?>
            <table class="doc-table">
                <thead><tr><th><?= h(t('cases.table.case')) ?></th><th><?= h(t('cases.table.type')) ?></th><th><?= h(t('cases.table.status')) ?></th></tr></thead>
                <tbody>
                <?php foreach ($cases as $c): ?>
                    <tr onclick="window.location='case.php?id=<?= (int)$c['id'] ?>'">
                        <td><?= h($c['title']) ?></td>
                        <td><?= h(type_label($c['type'])) ?></td>
                        <td><span class="<?= status_badge_class($c['status']) ?>"><?= h(case_status_label($c['status'])) ?></span></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?><p class="muted"><?= h(t('contractoverview.empty')) ?></p><?php endif; ?>
    </div>

    <?php if (organization_delivers_software()): ?>
    <div style="display:flex; align-items:center; gap:8px; margin:24px 0 4px;">
        <h2 style="margin:0;"><?= h(t('contractoverview.section.software_releases')) ?></h2>
        <?php help_tip('help.tip.software_releases'); ?>
    </div>
    <p class="muted" style="margin:0 0 10px;"><?= h(t('contractoverview.software.intro')) ?></p>
    <div class="card" style="<?= $isAdmin ? 'border-color:var(--accent);' : '' ?>">
        <?php if ($softwareReleases): ?>
            <table class="doc-table">
                <thead>
                <tr>
                    <th><?= h(t('contractoverview.software.col.version')) ?></th>
                    <th><?= h(t('contractoverview.software.col.external_reference')) ?></th>
                    <th><?= h(t('contractoverview.software.col.delivery_date')) ?></th>
                    <th><?= h(t('contractoverview.software.col.document')) ?></th>
                    <th><?= h(t('contractoverview.software.col.notes')) ?></th>
                    <?php if ($isAdmin): ?><th></th><?php endif; ?>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($softwareReleases as $sr): ?>
                    <tr>
                        <td><strong><?= h($sr['build_version']) ?></strong></td>
                        <td><?php if ($sr['external_reference']): ?><a href="<?= h($sr['external_reference']) ?>" target="_blank" rel="noopener"><?= h($sr['external_reference']) ?></a><?php else: ?>—<?php endif; ?></td>
                        <td><?= format_date($sr['delivery_date']) ?></td>
                        <td><?php if ($sr['linked_document_id']): ?><a href="document.php?id=<?= (int)$sr['linked_document_id'] ?>"><?= h($sr['doc_number']) ?></a><?php else: ?>—<?php endif; ?></td>
                        <td><?= $sr['notes'] ? h($sr['notes']) : '—' ?></td>
                        <?php if ($isAdmin): ?>
                            <td>
                                <form method="post" action="delete_software_release.php" class="inline-form" onsubmit="return confirm('<?= h(t('contractoverview.software.delete_confirm')) ?>
');">
    <?= csrf_field() ?>
                                    <input type="hidden" name="release_id" value="<?= (int)$sr['id'] ?>">
                                    <button type="submit" class="button-secondary button-danger" style="padding:4px 10px; font-size:12px;">&times;</button>
                                </form>
                            </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?><p class="muted"><?= h(t('contractoverview.empty')) ?></p><?php endif; ?>

        <?php if ($isAdmin): ?>
            <details class="nav-dropdown" style="margin-top:14px;">
                <summary style="display:inline-flex; align-items:center; gap:6px; cursor:pointer; color:var(--accent); font-size:13px; font-weight:600;">
                    + <?= h(t('contractoverview.software.add')) ?>
                </summary>
                <form method="post" action="new_software_release.php" style="margin-top:12px; max-width:480px;">
    <?= csrf_field() ?>
                    <input type="hidden" name="contract_reference" value="<?= h($selectedContract) ?>">
                    <label><?= h(t('contractoverview.software.field.version')) ?>
                        <input type="text" name="build_version" placeholder="fx v2.4.1" required>
                    </label>
                    <label><?= h(t('contractoverview.software.field.external_reference')) ?>
                        <input type="text" name="external_reference" placeholder="https://github.com/…/releases/…">
                    </label>
                    <label><?= h(t('contractoverview.software.field.delivery_date')) ?>
                        <input type="date" name="delivery_date">
                    </label>
                    <?php if ($documents): ?>
                        <label><?= h(t('contractoverview.software.field.document')) ?>
                            <select name="linked_document_id">
                                <option value=""><?= h(t('contractoverview.software.field.document_none')) ?></option>
                                <?php foreach ($documents as $d): ?>
                                    <option value="<?= (int)$d['id'] ?>"><?= h($d['doc_number']) ?> — <?= h($d['title']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                    <?php endif; ?>
                    <label><?= h(t('contractoverview.software.field.notes')) ?>
                        <textarea name="notes" rows="2"></textarea>
                    </label>
                    <button type="submit"><?= h(t('contractoverview.software.save')) ?></button>
                </form>
            </details>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if (organization_has_fai()): ?>
    <div style="display:flex; align-items:center; gap:8px; margin:24px 0 4px;">
        <h2 style="margin:0;"><?= h(t('contractoverview.section.fai')) ?></h2>
        <?php help_tip('help.tip.fai'); ?>
    </div>
    <p class="muted" style="margin:0 0 10px;"><?= h(t('contractoverview.fai.intro')) ?></p>
    <div class="card" style="<?= $isAdmin ? 'border-color:var(--accent);' : '' ?>">
        <?php if ($faiRecords): ?>
            <table class="doc-table">
                <thead>
                <tr>
                    <th><?= h(t('contractoverview.fai.col.part_number')) ?></th>
                    <th><?= h(t('contractoverview.fai.col.drawing_revision')) ?></th>
                    <th><?= h(t('contractoverview.fai.col.result')) ?></th>
                    <th><?= h(t('contractoverview.fai.col.inspection_date')) ?></th>
                    <th><?= h(t('contractoverview.fai.col.report')) ?></th>
                    <th><?= h(t('contractoverview.fai.col.notes')) ?></th>
                    <?php if ($isAdmin): ?><th></th><?php endif; ?>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($faiRecords as $fr): ?>
                    <tr>
                        <td><strong><?= h($fr['part_number']) ?></strong></td>
                        <td><?= $fr['drawing_revision'] ? h($fr['drawing_revision']) : '—' ?></td>
                        <td><span class="<?= status_badge_class($fr['result']) ?>"><?= h($fr['result']) ?></span></td>
                        <td><?= format_date($fr['inspection_date']) ?></td>
                        <td><?php if ($fr['stored_filename']): ?><a href="download_fai_record.php?id=<?= (int)$fr['id'] ?>"><?= h($fr['original_filename']) ?></a><?php else: ?>—<?php endif; ?></td>
                        <td><?= $fr['notes'] ? h($fr['notes']) : '—' ?></td>
                        <?php if ($isAdmin): ?>
                            <td>
                                <form method="post" action="delete_fai_record.php" class="inline-form" onsubmit="return confirm('<?= h(t('contractoverview.fai.delete_confirm')) ?>
');">
    <?= csrf_field() ?>
                                    <input type="hidden" name="fai_id" value="<?= (int)$fr['id'] ?>">
                                    <button type="submit" class="button-secondary button-danger" style="padding:4px 10px; font-size:12px;">&times;</button>
                                </form>
                            </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?><p class="muted"><?= h(t('contractoverview.empty')) ?></p><?php endif; ?>

        <?php if ($isAdmin): ?>
            <details class="nav-dropdown" style="margin-top:14px;">
                <summary style="display:inline-flex; align-items:center; gap:6px; cursor:pointer; color:var(--accent); font-size:13px; font-weight:600;">
                    + <?= h(t('contractoverview.fai.add')) ?>
                </summary>
                <form method="post" action="new_fai_record.php" enctype="multipart/form-data" style="margin-top:12px; max-width:480px;">
    <?= csrf_field() ?>
                    <input type="hidden" name="contract_reference" value="<?= h($selectedContract) ?>">

                    <?php if ($faiTemplate): ?>
                        <div style="background:var(--accent-bg); border-radius:6px; padding:10px 12px; display:flex; align-items:center; justify-content:space-between; gap:10px; flex-wrap:wrap; margin-bottom:14px;">
                            <span style="font-size:13px;"><strong><?= h(t('contractoverview.fai.template_box.title')) ?></strong> <?= h($faiTemplate['title']) ?> <?= $faiTemplate['version_number'] ? 'v' . h($faiTemplate['version_number']) : '' ?> <span class="muted">— <?= h(t('contractoverview.fai.template_box.approved')) ?> <?= format_date($faiTemplate['approved_at'] ?: $faiTemplate['original_approval_date']) ?></span></span>
                            <a href="download_template.php?version_id=<?= (int)$faiTemplate['current_version_id'] ?>" class="button-secondary" style="padding:5px 10px; font-size:12px;"><?= h(t('contractoverview.fai.template_box.download')) ?></a>
                        </div>
                    <?php else: ?>
                        <p class="muted" style="font-size:12px; margin-top:0;">
                            <?= h(t('contractoverview.fai.template_box.missing', ['category' => FAI_TEMPLATE_CATEGORY])) ?>
                            <?php if (has_permission($user, 'templates')): ?><a href="templates.php"><?= h(t('templates.title')) ?></a><?php endif; ?>
                        </p>
                    <?php endif; ?>

                    <label><?= h(t('contractoverview.fai.field.part_number')) ?>
                        <input type="text" name="part_number" required>
                    </label>
                    <label><?= h(t('contractoverview.fai.field.drawing_revision')) ?>
                        <input type="text" name="drawing_revision">
                    </label>
                    <label><?= h(t('contractoverview.fai.field.result')) ?>
                        <select name="result">
                            <option value="Godkendt"><?= h(t('contractoverview.fai.result.approved')) ?></option>
                            <option value="Afvist"><?= h(t('contractoverview.fai.result.rejected')) ?></option>
                            <option value="Under behandling" selected><?= h(t('contractoverview.fai.result.pending')) ?></option>
                        </select>
                    </label>
                    <label><?= h(t('contractoverview.fai.field.inspection_date')) ?>
                        <input type="date" name="inspection_date">
                    </label>
                    <label><?= h(t('contractoverview.fai.field.file')) ?>
                        <input type="file" name="file" required>
                    </label>
                    <label><?= h(t('contractoverview.fai.field.notes')) ?>
                        <textarea name="notes" rows="2"></textarea>
                    </label>
                    <p class="muted" style="font-size:12px;"><?= h(t('contractoverview.fai.replace_note')) ?></p>

                    <label style="display:flex; align-items:flex-start; gap:8px; flex-direction:row; background:#fdf8ec; border:1px solid #e9d9a6; border-radius:6px; padding:10px 12px; font-weight:400; font-size:12.5px; color:var(--text); margin-bottom:14px;">
                        <input type="checkbox" name="template_confirmed" value="1" required style="width:auto; margin-top:2px;">
                        <span><?= h(t('contractoverview.fai.confirm_label')) ?></span>
                    </label>

                    <button type="submit"><?= h(t('contractoverview.fai.save')) ?></button>
                </form>
            </details>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if (!$isGqar): ?>
        <h2><?= h(t('contractoverview.section.risks')) ?></h2>
        <div class="card">
            <?php if ($risks): ?>
                <table class="doc-table">
                    <thead><tr><th><?= h(t('risks.table.title')) ?></th><th><?= h(t('risks.table.score')) ?></th><th><?= h(t('risks.table.status')) ?></th></tr></thead>
                    <tbody>
                    <?php foreach ($risks as $r): ?>
                        <?php $score = risk_score((int)$r['probability'], (int)$r['consequence']); $band = risk_band($score); ?>
                        <tr onclick="window.location='risk.php?id=<?= (int)$r['id'] ?>'">
                            <td><?= h($r['title']) ?></td>
                            <td><span class="<?= risk_band_class($band) ?>"><?= $score ?> — <?= h(risk_band_label($band)) ?></span></td>
                            <td><span class="<?= risk_status_class($r['status']) ?>"><?= h(risk_status_label($r['status'])) ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?><p class="muted"><?= h(t('contractoverview.empty')) ?></p><?php endif; ?>
        </div>

        <h2><?= h(t('contractoverview.section.suppliers')) ?></h2>
        <div class="card">
            <?php if ($suppliers): ?>
                <table class="doc-table">
                    <thead><tr><th><?= h(t('suppliers.table.name')) ?></th><th><?= h(t('suppliers.table.status')) ?></th></tr></thead>
                    <tbody>
                    <?php foreach ($suppliers as $s): ?>
                        <tr onclick="window.location='supplier_detail.php?id=<?= (int)$s['id'] ?>'">
                            <td><?= h($s['name']) ?></td>
                            <td><span class="<?= supplier_status_class($s['status']) ?>"><?= h(supplier_status_label($s['status'])) ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?><p class="muted"><?= h(t('contractoverview.empty')) ?></p><?php endif; ?>
        </div>

        <h2><?= h(t('contractoverview.section.equipment')) ?></h2>
        <div class="card">
            <?php if ($equipmentList): ?>
                <table class="doc-table">
                    <thead><tr><th><?= h(t('equipment.table.name')) ?></th><th><?= h(t('equipment.table.status')) ?></th></tr></thead>
                    <tbody>
                    <?php foreach ($equipmentList as $e): ?>
                        <tr onclick="window.location='equipment_detail.php?id=<?= (int)$e['id'] ?>'">
                            <td><?= h($e['name']) ?></td>
                            <td><span class="<?= equipment_status_class($e['status']) ?>"><?= h(equipment_status_label($e['status'])) ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?><p class="muted"><?= h(t('contractoverview.empty')) ?></p><?php endif; ?>
        </div>
    <?php endif; ?>
<?php endif; ?>

<?php render_footer(); ?>
