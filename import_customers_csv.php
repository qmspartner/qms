<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

$user = require_permission('customers', 'create');
$db = get_db();

$error = '';
$doneCount = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm'])) {
    $validRows = $_SESSION['customer_import_valid'] ?? [];
    foreach ($validRows as $row) {
        $stmt = $db->prepare('INSERT INTO customers (organization_id, name, contact_person, email, phone) VALUES (?, ?, ?, ?, ?)');
        $stmt->execute([$user['organization_id'], $row['name'], $row['contact_person'] ?: null, $row['email'] ?: null, $row['phone'] ?: null]);
        $newId = (int) $db->lastInsertId();
        log_customer_action($newId, 'Kunde importeret fra CSV', $row['name']);
    }
    $doneCount = count($validRows);
    unset($_SESSION['customer_import_valid'], $_SESSION['customer_import_preview']);
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $file = $_FILES['csv_file'] ?? null;
    if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
        $error = t('supplierimport.error.no_file');
    } else {
        $content = file_get_contents($file['tmp_name']);
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);
        $lines = preg_split('/\r\n|\r|\n/', trim($content));
        $rows = array_map(fn($line) => str_getcsv($line, ';'), $lines);

        if (count($rows) < 2) {
            $error = t('supplierimport.error.empty');
        } else {
            $header = array_map('trim', $rows[0]);
            $colIndex = array_flip($header);
            $dataRows = array_slice($rows, 1);

            $preview = [];
            $validRows = [];

            foreach ($dataRows as $r) {
                $get = fn($col) => isset($colIndex[$col], $r[$colIndex[$col]]) ? trim($r[$colIndex[$col]]) : '';
                $name = $get('Navn');

                $rowError = $name === '' ? t('customerimport.error.name_missing') : '';

                $rowData = [
                    'name' => $name,
                    'contact_person' => $get('Kontaktperson'),
                    'email' => $get('E-mail'),
                    'phone' => $get('Telefon'),
                ];

                $preview[] = ['data' => $rowData, 'error' => $rowError];
                if ($rowError === '') {
                    $validRows[] = $rowData;
                }
            }

            $_SESSION['customer_import_valid'] = $validRows;
            $_SESSION['customer_import_preview'] = $preview;
        }
    }
}

$preview = $_SESSION['customer_import_preview'] ?? null;
$validCount = $preview ? count(array_filter($preview, fn($p) => $p['error'] === '')) : 0;
$totalCount = $preview ? count($preview) : 0;
$invalidCount = $totalCount - $validCount;

render_header(t('customerimport.title'));
?>
<div class="card login-card">
    <h1><?= h(t('customerimport.title')) ?></h1>
    <?php if ($error): ?><p class="alert alert-danger"><?= h($error) ?></p><?php endif; ?>
    <?php if ($doneCount !== null): ?>
        <p class="alert alert-success"><?= h(t('customerimport.done', ['count' => $doneCount])) ?></p>
        <a class="button" href="customers.php"><?= h(t('customers.title')) ?></a>
    <?php elseif (!$preview): ?>
        <form method="post" enctype="multipart/form-data">
    <?= csrf_field() ?>
            <label><?= h(t('supplierimport.choose_file')) ?>
                <input type="file" name="csv_file" accept=".csv" required>
            </label>
            <button type="submit"><?= h(t('supplierimport.upload')) ?></button>
        </form>
    <?php endif; ?>
</div>

<?php if ($preview): ?>
    <h2><?= h(t('supplierimport.preview_title')) ?></h2>
    <div class="card">
        <table class="doc-table">
            <thead>
            <tr>
                <th></th>
                <th><?= h(t('customers.table.name')) ?></th>
                <th><?= h(t('customers.table.contact')) ?></th>
                <th><?= h(t('customers.table.email')) ?></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($preview as $p): ?>
                <tr style="<?= $p['error'] ? 'background:var(--danger-bg);' : '' ?>">
                    <td><?= $p['error'] ? '✗' : '✓' ?></td>
                    <td><?= $p['error'] ? h($p['error']) : h($p['data']['name']) ?></td>
                    <td><?= h($p['data']['contact_person']) ?></td>
                    <td><?= h($p['data']['email']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <div style="display:flex; align-items:center; justify-content:space-between; margin-top:1rem;">
            <p class="muted" style="margin:0;"><?= h(t('supplierimport.summary', ['valid' => $validCount, 'total' => $totalCount, 'invalid' => $invalidCount])) ?></p>
            <?php if ($validCount > 0): ?>
                <form method="post">
    <?= csrf_field() ?>
                    <input type="hidden" name="confirm" value="1">
                    <button type="submit"><?= h(t('supplierimport.confirm', ['valid' => $validCount])) ?></button>
                </form>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<?php render_footer(); ?>
