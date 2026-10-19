<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

$user = require_permission('documents', 'create');
$db = get_db();

const DOCIMPORT_MAX_ROWS = 20;

$tmpBaseDir = UPLOAD_DIR . 'bulk_tmp/';
if (!is_dir($tmpBaseDir)) {
    @mkdir($tmpBaseDir, 0755, true);
}

function docimport_cleanup_tmpdir(?string $dir): void {
    if ($dir && is_dir($dir) && strpos(realpath($dir) ?: '', realpath(UPLOAD_DIR . 'bulk_tmp/')) === 0) {
        foreach (glob($dir . '/*') ?: [] as $f) {
            if (is_file($f)) {
                @unlink($f);
            }
        }
        @rmdir($dir);
    }
}

$error = '';
$doneCount = null;
// Leverance-kvalitetsplan kræver sin egen quality_plan_details-række og oprettes
// via new_quality_plan.php — udelukkes derfor fra bulk-import, ligesom konfigurationsstyrede dokumenter.
$allowedCategories = array_values(array_diff(document_category_selectable_values(), ['Leverance-kvalitetsplan']));

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['cancel'])) {
    docimport_cleanup_tmpdir($_SESSION['docimport_tmpdir'] ?? null);
    unset($_SESSION['docimport_valid'], $_SESSION['docimport_preview'], $_SESSION['docimport_tmpdir']);
    header('Location: import_documents_csv.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm'])) {
    // Trin 2: bekræft import af de gyldige rækker fra sessionen
    $validRows = $_SESSION['docimport_valid'] ?? [];
    $tmpDir = $_SESSION['docimport_tmpdir'] ?? null;
    $imported = 0;

    foreach ($validRows as $row) {
        $srcPath = $tmpDir . '/' . $row['filename'];
        if (!$tmpDir || !is_file($srcPath)) {
            continue;
        }
        $ext = strtolower(pathinfo($row['filename'], PATHINFO_EXTENSION));
        $storedName = bin2hex(random_bytes(16)) . '.' . $ext;
        if (!copy($srcPath, UPLOAD_DIR . $storedName)) {
            continue;
        }

        $db->beginTransaction();
        try {
            $stmt = $db->prepare('
                INSERT INTO documents (organization_id, doc_number, title, category, iso_clause, owner_id, status, is_configuration_item, baseline_reference, section, operation, is_imported, original_issue_date)
                VALUES (?, ?, ?, ?, ?, ?, ?, 0, NULL, NULL, NULL, 1, ?)
            ');
            $stmt->execute([
                $user['organization_id'], $row['doc_number'], $row['title'], $row['category'],
                $row['iso_clause'] ?: null, $user['id'], 'Godkendt',
                $row['original_issue_date'] ?: null,
            ]);
            $docId = (int) $db->lastInsertId();

            $stmt = $db->prepare('
                INSERT INTO document_versions (document_id, version_number, stored_filename, original_filename, uploaded_by, status, original_approver, original_approval_date, comment)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ');
            $stmt->execute([
                $docId, $row['version_number'], $storedName, $row['filename'], $user['id'], 'Godkendt',
                $row['original_approver'] ?: null, $row['original_approval_date'] ?: null, t('docimport.version_comment'),
            ]);
            $versionId = (int) $db->lastInsertId();

            $stmt = $db->prepare('UPDATE documents SET current_version_id = ? WHERE id = ?');
            $stmt->execute([$versionId, $docId]);

            $db->commit();
            log_action($docId, $versionId, t('docimport.log_action'), t('docimport.log_details', ['version' => $row['version_number']]));
            $imported++;
        } catch (Exception $e) {
            $db->rollBack();
            @unlink(UPLOAD_DIR . $storedName);
        }
    }

    $doneCount = $imported;
    docimport_cleanup_tmpdir($tmpDir);
    unset($_SESSION['docimport_valid'], $_SESSION['docimport_preview'], $_SESSION['docimport_tmpdir']);

} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Trin 1: upload og validering
    docimport_cleanup_tmpdir($_SESSION['docimport_tmpdir'] ?? null);
    unset($_SESSION['docimport_valid'], $_SESSION['docimport_preview'], $_SESSION['docimport_tmpdir']);

    $csvFile = $_FILES['csv_file'] ?? null;
    $zipFile = $_FILES['zip_file'] ?? null;

    if (!$csvFile || $csvFile['error'] !== UPLOAD_ERR_OK) {
        $error = t('docimport.error.no_csv');
    } elseif (!$zipFile || $zipFile['error'] !== UPLOAD_ERR_OK) {
        $error = t('docimport.error.no_zip');
    } elseif (!class_exists('ZipArchive')) {
        $error = t('docimport.error.no_zip_support');
    } else {
        $content = file_get_contents($csvFile['tmp_name']);
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);
        $lines = preg_split('/\r\n|\r|\n/', trim($content));
        $rows = array_map(fn($line) => str_getcsv($line, ';'), $lines);

        if (count($rows) < 2) {
            $error = t('docimport.error.empty');
        } elseif ((count($rows) - 1) > DOCIMPORT_MAX_ROWS) {
            $error = t('docimport.error.too_many_rows', ['count' => count($rows) - 1, 'max' => DOCIMPORT_MAX_ROWS]);
        } else {
            $tmpDir = $tmpBaseDir . bin2hex(random_bytes(8));
            mkdir($tmpDir, 0755, true);

            $zip = new ZipArchive();
            if ($zip->open($zipFile['tmp_name']) !== true) {
                $error = t('docimport.error.bad_zip');
                docimport_cleanup_tmpdir($tmpDir);
            } else {
                $zip->extractTo($tmpDir);
                $zip->close();

                $filesInZip = [];
                $rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmpDir, FilesystemIterator::SKIP_DOTS));
                foreach ($rii as $f) {
                    if ($f->isFile()) {
                        $filesInZip[$f->getFilename()] = $f->getPathname();
                    }
                }

                $header = array_map('trim', $rows[0]);
                $colIndex = array_flip($header);
                $dataRows = array_slice($rows, 1);

                $preview = [];
                $validRows = [];
                $seenDocNumbers = [];

                foreach ($dataRows as $r) {
                    $get = fn($col) => isset($colIndex[$col], $r[$colIndex[$col]]) ? trim($r[$colIndex[$col]]) : '';

                    $category = $get('Kategori');
                    $docNumber = $get('Dokument-ID');
                    $title = $get('Titel');
                    $isoClause = $get('ISO-punkt');
                    $versionNumber = $get('Version');
                    $originalIssueDateRaw = $get('Oprindelig udstedelsesdato');
                    $originalApprover = $get('Oprindelig godkender');
                    $originalApprovalDateRaw = $get('Oprindelig godkendelsesdato');
                    $filename = $get('Filnavn');

                    $originalIssueDate = $originalIssueDateRaw !== '' ? parse_date_by_org_format($originalIssueDateRaw) : null;
                    $originalApprovalDate = $originalApprovalDateRaw !== '' ? parse_date_by_org_format($originalApprovalDateRaw) : null;

                    $rowError = '';
                    if ($category === '' || !in_array($category, $allowedCategories, true)) {
                        $rowError = t('docimport.error.bad_category');
                    } elseif ($docNumber === '') {
                        $rowError = t('docimport.error.doc_number_missing');
                    } elseif (isset($seenDocNumbers[$docNumber])) {
                        $rowError = t('docimport.error.doc_number_duplicate_in_file');
                    } elseif ($title === '') {
                        $rowError = t('docimport.error.title_missing');
                    } elseif ($versionNumber === '') {
                        $rowError = t('docimport.error.version_missing');
                    } elseif ($filename === '') {
                        $rowError = t('docimport.error.filename_missing');
                    } elseif (!isset($filesInZip[$filename])) {
                        $rowError = t('docimport.error.file_not_found');
                    } elseif (!in_array(strtolower(pathinfo($filename, PATHINFO_EXTENSION)), ALLOWED_EXTENSIONS, true)) {
                        $rowError = t('docimport.error.bad_filetype') . ' ' . implode(', ', ALLOWED_EXTENSIONS);
                    } elseif (filesize($filesInZip[$filename]) > MAX_FILE_SIZE) {
                        $rowError = t('docimport.error.too_large');
                    } elseif ($originalIssueDateRaw !== '' && $originalIssueDate === null) {
                        $rowError = t('docimport.error.bad_date', ['format' => date_format_example()]);
                    } elseif ($originalApprovalDateRaw !== '' && $originalApprovalDate === null) {
                        $rowError = t('docimport.error.bad_date', ['format' => date_format_example()]);
                    } else {
                        $stmt = $db->prepare('SELECT id FROM documents WHERE organization_id = ? AND doc_number = ?');
                        $stmt->execute([$user['organization_id'], $docNumber]);
                        if ($stmt->fetch()) {
                            $rowError = t('upload.error.doc_number_duplicate');
                        }
                    }

                    $rowData = [
                        'category' => $category,
                        'doc_number' => $docNumber,
                        'title' => $title,
                        'iso_clause' => $isoClause,
                        'version_number' => $versionNumber,
                        'original_issue_date' => $originalIssueDate,
                        'original_approver' => $originalApprover,
                        'original_approval_date' => $originalApprovalDate,
                        'filename' => $filename,
                    ];

                    $preview[] = ['data' => $rowData, 'error' => $rowError];
                    if ($rowError === '') {
                        $validRows[] = $rowData;
                        $seenDocNumbers[$docNumber] = true;
                    }
                }

                // Flyt kun filer, der rent faktisk bruges af en gyldig række, til roden af det
                // midlertidige bibliotek, så trin 2 nemt kan finde dem via filnavnet alene.
                foreach ($validRows as $vr) {
                    $src = $filesInZip[$vr['filename']];
                    $destFlat = $tmpDir . '/' . $vr['filename'];
                    if ($src !== $destFlat && !file_exists($destFlat)) {
                        @rename($src, $destFlat);
                    }
                }

                $_SESSION['docimport_valid'] = $validRows;
                $_SESSION['docimport_preview'] = $preview;
                $_SESSION['docimport_tmpdir'] = $tmpDir;
            }
        }
    }
}

$preview = $_SESSION['docimport_preview'] ?? null;
$validCount = $preview ? count(array_filter($preview, fn($p) => $p['error'] === '')) : 0;
$totalCount = $preview ? count($preview) : 0;
$invalidCount = $totalCount - $validCount;

render_header(t('docimport.title'));
?>
<div class="card login-card" style="max-width: 640px;">
    <h1><?= h(t('docimport.title')) ?></h1>
    <?php if ($error): ?><p class="alert alert-danger"><?= h($error) ?></p><?php endif; ?>

    <?php if ($doneCount !== null): ?>
        <p class="alert alert-success"><?= h(t('docimport.done', ['count' => $doneCount])) ?></p>
        <a class="button" href="index.php"><?= h(t('documents.title')) ?></a>
    <?php elseif (!$preview): ?>
        <p class="muted" style="margin-top:-8px;"><?= h(t('docimport.intro')) ?></p>

        <div class="alert" style="background:var(--accent-bg); color:var(--text);">
            <?= h(t('docimport.notice.no_config_items')) ?><br>
            <?= h(t('docimport.notice.row_limit', ['max' => DOCIMPORT_MAX_ROWS])) ?><br>
            <?= h(t('docimport.notice.date_format', ['format' => date_format_example()])) ?>
        </div>

        <form method="post" enctype="multipart/form-data">
    <?= csrf_field() ?>
            <label><?= h(t('docimport.choose_csv')) ?>
                <input type="file" name="csv_file" accept=".csv" required>
            </label>
            <label><?= h(t('docimport.choose_zip')) ?>
                <input type="file" name="zip_file" accept=".zip" required>
            </label>
            <button type="submit"><?= h(t('docimport.upload')) ?></button>
        </form>

        <h2 style="margin-top:28px;"><?= h(t('docimport.columns_title')) ?></h2>
        <p class="muted"><?= h(t('docimport.columns_intro')) ?></p>
        <table class="doc-table">
            <thead><tr><th><?= h(t('docimport.col.column')) ?></th><th><?= h(t('docimport.col.required')) ?></th></tr></thead>
            <tbody>
                <tr><td>Kategori</td><td><?= h(t('common.yes')) ?></td></tr>
                <tr><td>Dokument-ID</td><td><?= h(t('common.yes')) ?></td></tr>
                <tr><td>Titel</td><td><?= h(t('common.yes')) ?></td></tr>
                <tr><td>Version</td><td><?= h(t('common.yes')) ?></td></tr>
                <tr><td>Filnavn</td><td><?= h(t('common.yes')) ?></td></tr>
                <tr><td>ISO-punkt</td><td><?= h(t('docimport.optional')) ?></td></tr>
                <tr><td>Oprindelig udstedelsesdato</td><td><?= h(t('docimport.optional')) ?></td></tr>
                <tr><td>Oprindelig godkender</td><td><?= h(t('docimport.optional')) ?></td></tr>
                <tr><td>Oprindelig godkendelsesdato</td><td><?= h(t('docimport.optional')) ?></td></tr>
            </tbody>
        </table>
        <p class="muted"><?= h(t('docimport.columns_note', ['format' => date_format_example()])) ?></p>
    <?php endif; ?>
</div>

<?php if ($preview): ?>
    <h2><?= h(t('docimport.preview_title')) ?></h2>
    <div class="card">
        <table class="doc-table">
            <thead>
            <tr>
                <th></th>
                <th><?= h(t('docimport.col.docnumber')) ?></th>
                <th><?= h(t('docimport.col.title')) ?></th>
                <th><?= h(t('docimport.col.category')) ?></th>
                <th><?= h(t('docimport.col.version')) ?></th>
                <th><?= h(t('docimport.col.filename')) ?></th>
                <th><?= h(t('docimport.col.note')) ?></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($preview as $p): ?>
                <tr style="<?= $p['error'] ? 'background:var(--danger-bg);' : '' ?>">
                    <td><?= $p['error'] ? '✗' : '✓' ?></td>
                    <td><?= h($p['data']['doc_number']) ?></td>
                    <td><?= h($p['data']['title']) ?></td>
                    <td><?= h($p['data']['category']) ?></td>
                    <td><?= h($p['data']['version_number']) ?></td>
                    <td><?= h($p['data']['filename']) ?></td>
                    <td><?= $p['error'] ? h($p['error']) : '—' ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <div style="display:flex; align-items:center; justify-content:space-between; margin-top:1rem;">
            <p class="muted" style="margin:0;"><?= h(t('docimport.summary', ['valid' => $validCount, 'total' => $totalCount, 'invalid' => $invalidCount])) ?></p>
            <div style="display:flex; gap:10px;">
                <a class="button button-secondary" href="import_documents_csv.php?cancel=1"><?= h(t('docimport.cancel')) ?></a>
                <?php if ($validCount > 0): ?>
                    <form method="post">
    <?= csrf_field() ?>
                        <input type="hidden" name="confirm" value="1">
                        <button type="submit"><?= h(t('docimport.confirm', ['valid' => $validCount])) ?></button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php render_footer(); ?>
