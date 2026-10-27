<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

$user = require_permission('documents', 'create');
$db = get_db();

const BULKIMPORT_MAX_FILES = 40;

// Leverance-kvalitetsplan kræver sin egen quality_plan_details-række og oprettes
// via new_quality_plan.php — udelukkes derfor fra bulk-import, ligesom konfigurationsstyrede dokumenter.
$allowedCategories = array_values(array_diff(list_active_values('document_category'), ['Leverance-kvalitetsplan']));

$imported = [];
$skipped = [];
$didSubmit = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $didSubmit = true;

    $files = $_FILES['import_file'] ?? null;
    $docNumbers = (array) ($_POST['doc_number'] ?? []);
    $titles = (array) ($_POST['title'] ?? []);
    $categories = (array) ($_POST['category'] ?? []);
    $versions = (array) ($_POST['version'] ?? []);
    $originalApprovers = (array) ($_POST['original_approver'] ?? []);
    $sourceRefs = (array) ($_POST['source_ref'] ?? []);

    $count = $files ? min(count($files['name']), BULKIMPORT_MAX_FILES) : 0;

    for ($i = 0; $i < $count; $i++) {
        $filename = (string) ($files['name'][$i] ?? '');
        $displayName = $filename !== '' ? $filename : t('bulkimport.unnamed');
        $docNumber = trim((string) ($docNumbers[$i] ?? ''));
        $title = trim((string) ($titles[$i] ?? ''));
        $category = (string) ($categories[$i] ?? '');
        $version = trim((string) ($versions[$i] ?? '')) ?: '1';
        $originalApprover = trim((string) ($originalApprovers[$i] ?? ''));
        $sourceRef = trim((string) ($sourceRefs[$i] ?? ''));

        $reason = '';
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        if (($files['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $reason = t('bulkimport.error.upload_failed');
        } elseif ($docNumber === '') {
            $reason = t('bulkimport.error.doc_number_missing');
        } elseif ($title === '') {
            $reason = t('bulkimport.error.title_missing');
        } elseif (!in_array($category, $allowedCategories, true)) {
            $reason = t('bulkimport.error.bad_category');
        } elseif (!in_array($ext, ALLOWED_EXTENSIONS, true)) {
            $reason = t('bulkimport.error.bad_filetype') . ' ' . implode(', ', ALLOWED_EXTENSIONS);
        } elseif (($files['size'][$i] ?? 0) > MAX_FILE_SIZE) {
            $reason = t('bulkimport.error.too_large');
        } else {
            $stmt = $db->prepare('SELECT id FROM documents WHERE organization_id = ? AND doc_number = ?');
            $stmt->execute([$user['organization_id'], $docNumber]);
            if ($stmt->fetch()) {
                $reason = t('bulkimport.error.doc_number_duplicate');
            }
        }

        if ($reason !== '') {
            $skipped[] = ['filename' => $displayName, 'reason' => $reason];
            continue;
        }

        $storedName = bin2hex(random_bytes(16)) . '.' . $ext;
        if (!move_uploaded_file($files['tmp_name'][$i], UPLOAD_DIR . $storedName)) {
            $skipped[] = ['filename' => $displayName, 'reason' => t('bulkimport.error.save_failed')];
            continue;
        }

        $finalApprover = $originalApprover !== '' ? $originalApprover : t('bulkimport.auto_approver_label', ['date' => format_date(date('Y-m-d'))]);
        $finalApprovalDate = $originalApprover !== '' ? null : date('Y-m-d');

        $db->beginTransaction();
        try {
            $stmt = $db->prepare('
                INSERT INTO documents (organization_id, doc_number, title, category, owner_id, status, is_imported, original_issue_date)
                VALUES (?, ?, ?, ?, ?, ?, 1, NULL)
            ');
            $stmt->execute([$user['organization_id'], $docNumber, $title, $category, $user['id'], 'Godkendt']);
            $docId = (int) $db->lastInsertId();

            $stmt = $db->prepare('
                INSERT INTO document_versions (document_id, version_number, stored_filename, original_filename, uploaded_by, status, original_approver, original_approval_date, import_source_reference, comment)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ');
            $stmt->execute([
                $docId, $version, $storedName, $filename, $user['id'], 'Godkendt',
                $finalApprover, $finalApprovalDate, $sourceRef ?: null, t('bulkimport.version_comment'),
            ]);
            $versionId = (int) $db->lastInsertId();

            $stmt = $db->prepare('UPDATE documents SET current_version_id = ? WHERE id = ?');
            $stmt->execute([$versionId, $docId]);

            $db->commit();
            log_action($docId, $versionId, t('bulkimport.log_action'), t('bulkimport.log_details', ['version' => $version]));
            $imported[] = $title;
        } catch (Exception $e) {
            $db->rollBack();
            @unlink(UPLOAD_DIR . $storedName);
            $skipped[] = ['filename' => $displayName, 'reason' => t('bulkimport.error.save_failed')];
        }
    }
}

render_header(t('bulkimport.title'));

$categoryOptions = array_map(fn ($c) => ['value' => $c, 'label' => category_label($c)], $allowedCategories);
$prefixToCategory = array_flip(document_category_prefixes_map());
$todayFormatted = format_date(date('Y-m-d'));
?>
<p class="breadcrumb"><a href="index.php"><?= h(t('documents.title')) ?></a> &raquo; <?= h(t('bulkimport.nav')) ?></p>

<h1><?= h(t('bulkimport.title')) ?></h1>
<p class="muted"><?= h(t('bulkimport.intro')) ?></p>

<?php if ($didSubmit): ?>
    <div class="card">
        <h2><?= h(t('bulkimport.result.title')) ?></h2>
        <?php if ($imported): ?>
            <p class="alert alert-success"><?= h(t('bulkimport.result.imported', ['count' => count($imported)])) ?></p>
        <?php else: ?>
            <p class="alert alert-warning"><?= h(t('bulkimport.result.none_imported')) ?></p>
        <?php endif; ?>
        <?php if ($skipped): ?>
            <p><strong><?= h(t('bulkimport.result.skipped_title', ['count' => count($skipped)])) ?></strong></p>
            <ul>
                <?php foreach ($skipped as $s): ?>
                    <li><?= h($s['filename']) ?> — <?= h($s['reason']) ?></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
        <div class="button-row">
            <a class="button" href="import_documents.php"><?= h(t('bulkimport.result.back')) ?></a>
            <a class="button button-secondary" href="index.php"><?= h(t('documents.title')) ?></a>
        </div>
    </div>
<?php endif; ?>

<p class="alert alert-warning"><?= h(t('bulkimport.notice.no_config_items')) ?></p>

<div class="card">
    <ul style="margin:0; padding-left:18px;">
        <li><?= h(t('bulkimport.banner.no_csv')) ?></li>
        <li><?= h(t('bulkimport.banner.guess')) ?></li>
        <li><?= h(t('bulkimport.banner.ready_only')) ?></li>
        <li><?= h(t('bulkimport.banner.approver_fallback')) ?></li>
        <li><?= h(t('bulkimport.notice.row_limit', ['max' => BULKIMPORT_MAX_FILES])) ?></li>
    </ul>
</div>

<form method="post" enctype="multipart/form-data" id="bulkimport-form" onsubmit="return bulkimportPrepareSubmit();">
    <?= csrf_field() ?>
    <input type="file" name="import_file[]" id="bulkimport-submit-files" multiple hidden>
    <div id="bulkimport-hidden-fields"></div>

    <label class="bulkimport-dropzone" id="bulkimport-dropzone" for="bulkimport-picker">
        <input type="file" id="bulkimport-picker" multiple onchange="bulkimportAddFiles(this.files); this.value='';">
        <i class="ti ti-upload" style="font-size:22px; color:var(--accent);"></i>
        <div>
            <strong style="display:block;"><?= h(t('bulkimport.dropzone.title')) ?></strong>
            <span class="muted" style="font-size:13px;"><?= h(t('bulkimport.dropzone.subtitle')) ?></span>
        </div>
    </label>

    <div class="card" id="bulkimport-empty">
        <p class="muted" style="text-align:center; margin:10px 0;"><?= h(t('bulkimport.empty')) ?></p>
    </div>

    <div class="bulkimport-table-wrap" id="bulkimport-table-wrap" style="display:none;">
        <table class="doc-table bulkimport-table">
            <thead>
                <tr>
                    <th><?= h(t('bulkimport.col.status')) ?></th>
                    <th><?= h(t('bulkimport.col.file')) ?></th>
                    <th><?= h(t('bulkimport.col.docnumber')) ?></th>
                    <th><?= h(t('bulkimport.col.title')) ?></th>
                    <th><?= h(t('bulkimport.col.category')) ?></th>
                    <th><?= h(t('bulkimport.col.version')) ?></th>
                    <th><?= h(t('bulkimport.col.original_approver')) ?></th>
                    <th><?= h(t('bulkimport.col.source_ref')) ?></th>
                    <th></th>
                </tr>
            </thead>
            <tbody id="bulkimport-rows"></tbody>
        </table>
    </div>

    <div class="button-row" style="justify-content:space-between; align-items:center;">
        <p class="muted" id="bulkimport-summary" style="margin:0;"></p>
        <div style="display:flex; gap:10px;">
            <button type="button" class="button-secondary" id="bulkimport-clear" onclick="bulkimportClearAll();" disabled><?= h(t('bulkimport.clear_all')) ?></button>
            <button type="submit" id="bulkimport-submit" disabled><?= h(t('bulkimport.submit', ['count' => 0])) ?></button>
        </div>
    </div>
</form>

<script>
const BULKIMPORT_CATEGORIES = <?= json_encode($categoryOptions, JSON_UNESCAPED_UNICODE) ?>;
const BULKIMPORT_PREFIX_TO_CATEGORY = <?= json_encode($prefixToCategory, JSON_UNESCAPED_UNICODE) ?>;
const BULKIMPORT_APPROVER_PLACEHOLDER = <?= json_encode(t('bulkimport.auto_approver_label', ['date' => $todayFormatted]), JSON_UNESCAPED_UNICODE) ?>;
const BULKIMPORT_CATEGORY_CHOOSE = <?= json_encode(t('bulkimport.category_choose'), JSON_UNESCAPED_UNICODE) ?>;
const BULKIMPORT_DOCNUMBER_PLACEHOLDER = <?= json_encode(t('bulkimport.placeholder.docnumber'), JSON_UNESCAPED_UNICODE) ?>;
const BULKIMPORT_SOURCE_PLACEHOLDER = <?= json_encode(t('bulkimport.placeholder.source_ref'), JSON_UNESCAPED_UNICODE) ?>;
const BULKIMPORT_SUBMIT_LABEL = <?= json_encode(t('bulkimport.submit', ['count' => '__N__']), JSON_UNESCAPED_UNICODE) ?>;
const BULKIMPORT_SUMMARY_LABEL = <?= json_encode(t('bulkimport.summary', ['total' => '__T__', 'ready' => '__R__']), JSON_UNESCAPED_UNICODE) ?>;
const BULKIMPORT_STATUS_READY = <?= json_encode(t('bulkimport.status.ready'), JSON_UNESCAPED_UNICODE) ?>;
const BULKIMPORT_STATUS_MISSING = <?= json_encode(t('bulkimport.status.missing'), JSON_UNESCAPED_UNICODE) ?>;
const BULKIMPORT_MAX_FILES = <?= (int) BULKIMPORT_MAX_FILES ?>;

let bulkimportRows = [];
let bulkimportNextId = 1;

function bulkimportEscapeHtml(str) {
    return String(str).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}

function bulkimportParseFilename(filename) {
    const dot = filename.lastIndexOf('.');
    const ext = dot > -1 ? filename.slice(dot + 1).toUpperCase() : '';
    const base = dot > -1 ? filename.slice(0, dot) : filename;
    let rest = base;
    let docNumber = '';
    let version = '1';

    const idMatch = rest.match(/^([A-ZÆØÅa-zæøå]{2,8}-\d{1,4})[\s_-]+/);
    if (idMatch) {
        docNumber = idMatch[1].toUpperCase();
        rest = rest.slice(idMatch[0].length);
    }

    const verMatch = rest.match(/[\s_-]+v(?:ersion)?\.?\s*(\d+(?:\.\d+)?)$/i);
    if (verMatch) {
        version = verMatch[1];
        rest = rest.slice(0, rest.length - verMatch[0].length);
    }

    let title = rest.replace(/[_-]+/g, ' ').trim();
    if (!title) { title = base; }
    title = title.charAt(0).toUpperCase() + title.slice(1);

    let category = '';
    if (docNumber) {
        const prefix = docNumber.split('-')[0];
        category = BULKIMPORT_PREFIX_TO_CATEGORY[prefix] || '';
    }

    return { docNumber, title, version, category, ext };
}

function bulkimportAddFiles(fileList) {
    const files = Array.from(fileList || []);
    if (!files.length) { return; }
    const room = BULKIMPORT_MAX_FILES - bulkimportRows.length;
    files.slice(0, Math.max(room, 0)).forEach((file) => {
        const parsed = bulkimportParseFilename(file.name);
        bulkimportRows.push({
            id: bulkimportNextId++,
            file: file,
            filename: file.name,
            ext: parsed.ext,
            docNumber: parsed.docNumber,
            title: parsed.title,
            version: parsed.version,
            category: parsed.category,
            originalApprover: '',
            sourceRef: '',
        });
    });
    bulkimportRenderTable();
}

function bulkimportCategoryOptionsHtml(selected) {
    let html = '<option value="">' + bulkimportEscapeHtml(BULKIMPORT_CATEGORY_CHOOSE) + '</option>';
    BULKIMPORT_CATEGORIES.forEach((c) => {
        html += '<option value="' + bulkimportEscapeHtml(c.value) + '"' + (c.value === selected ? ' selected' : '') + '>' + bulkimportEscapeHtml(c.label) + '</option>';
    });
    return html;
}

function bulkimportRowReady(row) {
    return !!(row.docNumber && row.title && row.category);
}

function bulkimportRenderTable() {
    const tbody = document.getElementById('bulkimport-rows');
    const wrap = document.getElementById('bulkimport-table-wrap');
    const empty = document.getElementById('bulkimport-empty');

    if (!bulkimportRows.length) {
        wrap.style.display = 'none';
        empty.style.display = 'block';
        tbody.innerHTML = '';
        bulkimportUpdateFooter();
        return;
    }
    empty.style.display = 'none';
    wrap.style.display = 'block';

    tbody.innerHTML = bulkimportRows.map((row) => {
        const ready = bulkimportRowReady(row);
        return '' +
            '<tr data-row-id="' + row.id + '">' +
            '<td id="bulkimport-status-' + row.id + '"><span class="badge ' + (ready ? 'badge-success' : 'badge-warning') + '">' + (ready ? bulkimportEscapeHtml(BULKIMPORT_STATUS_READY) : bulkimportEscapeHtml(BULKIMPORT_STATUS_MISSING)) + '</span></td>' +
            '<td class="nowrap"><span class="badge badge-neutral" style="margin-right:6px;">' + bulkimportEscapeHtml(row.ext) + '</span>' + bulkimportEscapeHtml(row.filename) + '</td>' +
            '<td><input type="text" style="min-width:120px;" placeholder="' + bulkimportEscapeHtml(BULKIMPORT_DOCNUMBER_PLACEHOLDER) + '" value="' + bulkimportEscapeHtml(row.docNumber) + '" oninput="bulkimportUpdateField(' + row.id + ', \'docNumber\', this.value)"></td>' +
            '<td><input type="text" style="min-width:160px;" value="' + bulkimportEscapeHtml(row.title) + '" oninput="bulkimportUpdateField(' + row.id + ', \'title\', this.value)"></td>' +
            '<td><select style="min-width:150px;" onchange="bulkimportUpdateField(' + row.id + ', \'category\', this.value)">' + bulkimportCategoryOptionsHtml(row.category) + '</select></td>' +
            '<td><input type="text" style="min-width:60px;" value="' + bulkimportEscapeHtml(row.version) + '" oninput="bulkimportUpdateField(' + row.id + ', \'version\', this.value)"></td>' +
            '<td><input type="text" style="min-width:170px;" placeholder="' + bulkimportEscapeHtml(BULKIMPORT_APPROVER_PLACEHOLDER) + '" value="' + bulkimportEscapeHtml(row.originalApprover) + '" oninput="bulkimportUpdateField(' + row.id + ', \'originalApprover\', this.value)"></td>' +
            '<td><input type="text" style="min-width:140px;" placeholder="' + bulkimportEscapeHtml(BULKIMPORT_SOURCE_PLACEHOLDER) + '" value="' + bulkimportEscapeHtml(row.sourceRef) + '" oninput="bulkimportUpdateField(' + row.id + ', \'sourceRef\', this.value)"></td>' +
            '<td><button type="button" class="button-secondary button-small" onclick="bulkimportRemoveRow(' + row.id + ')" title="&times;">&times;</button></td>' +
            '</tr>';
    }).join('');

    bulkimportUpdateFooter();
}

function bulkimportUpdateField(rowId, field, value) {
    const row = bulkimportRows.find((r) => r.id === rowId);
    if (!row) { return; }
    row[field] = value;
    if (field === 'docNumber' || field === 'title' || field === 'category') {
        const statusCell = document.getElementById('bulkimport-status-' + rowId);
        if (statusCell) {
            const ready = bulkimportRowReady(row);
            statusCell.innerHTML = '<span class="badge ' + (ready ? 'badge-success' : 'badge-warning') + '">' + (ready ? bulkimportEscapeHtml(BULKIMPORT_STATUS_READY) : bulkimportEscapeHtml(BULKIMPORT_STATUS_MISSING)) + '</span>';
        }
        bulkimportUpdateFooter();
    }
}

function bulkimportRemoveRow(rowId) {
    bulkimportRows = bulkimportRows.filter((r) => r.id !== rowId);
    bulkimportRenderTable();
}

function bulkimportClearAll() {
    bulkimportRows = [];
    bulkimportRenderTable();
}

function bulkimportUpdateFooter() {
    const total = bulkimportRows.length;
    const ready = bulkimportRows.filter(bulkimportRowReady).length;
    document.getElementById('bulkimport-summary').textContent = BULKIMPORT_SUMMARY_LABEL.replace('__T__', total).replace('__R__', ready);
    document.getElementById('bulkimport-submit').textContent = BULKIMPORT_SUBMIT_LABEL.replace('__N__', ready);
    document.getElementById('bulkimport-submit').disabled = ready === 0;
    document.getElementById('bulkimport-clear').disabled = total === 0;
}

function bulkimportPrepareSubmit() {
    const ready = bulkimportRows.filter(bulkimportRowReady);
    if (!ready.length) { return false; }

    const dt = new DataTransfer();
    ready.forEach((row) => dt.items.add(row.file));
    document.getElementById('bulkimport-submit-files').files = dt.files;

    const container = document.getElementById('bulkimport-hidden-fields');
    container.innerHTML = '';
    const addHidden = (name, value) => {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = name;
        input.value = value;
        container.appendChild(input);
    };
    ready.forEach((row) => {
        addHidden('doc_number[]', row.docNumber);
        addHidden('title[]', row.title);
        addHidden('category[]', row.category);
        addHidden('version[]', row.version);
        addHidden('original_approver[]', row.originalApprover);
        addHidden('source_ref[]', row.sourceRef);
    });

    return true;
}

const bulkimportDropzone = document.getElementById('bulkimport-dropzone');
bulkimportDropzone.addEventListener('dragover', (e) => { e.preventDefault(); bulkimportDropzone.classList.add('drag-over'); });
bulkimportDropzone.addEventListener('dragleave', (e) => { e.preventDefault(); bulkimportDropzone.classList.remove('drag-over'); });
bulkimportDropzone.addEventListener('drop', (e) => {
    e.preventDefault();
    bulkimportDropzone.classList.remove('drag-over');
    bulkimportAddFiles(e.dataTransfer.files);
});

bulkimportUpdateFooter();
</script>

<?php render_footer(); ?>
