<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/tasks.php';

$user = require_permission_allow_gqar('documents');
$myTasks = get_my_tasks($user);
$db = get_db();

$search = trim($_GET['q'] ?? '');
$selectedCategories = array_values(array_filter((array) ($_GET['category'] ?? [])));
$selectedStatuses = array_values(array_filter((array) ($_GET['status'] ?? [])));
$showTemplates = ($_GET['show_templates'] ?? '') === '1';

$sortColumns = [
    'title' => 'd.title',
    'doc_number' => 'd.doc_number',
    'category' => 'd.category',
    'owner_name' => 'u.name',
    'status' => 'd.status',
    'next_review_date' => 'd.next_review_date',
];
$sortCol = $_GET['sort'] ?? 'doc_number';
if (!isset($sortColumns[$sortCol])) {
    $sortCol = 'doc_number';
}
$sortDir = ($_GET['dir'] ?? 'asc') === 'desc' ? 'DESC' : 'ASC';

$sql = 'SELECT d.*, u.name AS owner_name FROM documents d JOIN users u ON u.id = d.owner_id WHERE d.organization_id = ?';
$params = [$user['organization_id']];

if ($search !== '') {
    $sql .= ' AND (d.title LIKE ? OR d.doc_number LIKE ?)';
    $params[] = "%$search%";
    $params[] = "%$search%";
}
if ($selectedCategories) {
    $sql .= ' AND d.category IN (' . implode(',', array_fill(0, count($selectedCategories), '?')) . ')';
    $params = array_merge($params, $selectedCategories);
}
if ($selectedStatuses) {
    $sql .= ' AND d.status IN (' . implode(',', array_fill(0, count($selectedStatuses), '?')) . ')';
    $params = array_merge($params, $selectedStatuses);
}
if (is_gqar($user)) {
    [$clause, $clauseParams] = contract_in_clause('d.baseline_reference', $user['gqar_contract_references']);
    $sql .= " AND d.is_configuration_item = 1 AND d.status = 'Godkendt' AND {$clause}";
    $params = array_merge($params, $clauseParams);
}
if (!$showTemplates) {
    $sql .= ' AND d.is_template = 0';
}
$sql .= ' ORDER BY ' . $sortColumns[$sortCol] . ' ' . $sortDir;

$stmt = $db->prepare($sql);
$stmt->execute($params);
$documents = $stmt->fetchAll();

$stmt = $db->prepare("
    SELECT
        SUM(status = 'Godkendt') AS active,
        SUM(status = 'Til godkendelse') AS pending,
        SUM(next_review_date IS NOT NULL AND next_review_date <= CURDATE() + INTERVAL 60 DAY) AS expiring
    FROM documents
    WHERE organization_id = ?
");
$stmt->execute([$user['organization_id']]);
$stats = $stmt->fetch();

$categories = list_active_values('document_category');
$statuses = ['Kladde', 'Til godkendelse', 'Godkendt', 'Udgået'];

$activeChips = [];
if ($search !== '') {
    $activeChips[] = ['label' => t('documents.search.placeholder') . ': ' . $search, 'href' => basename($_SERVER['PHP_SELF']) . '?' . query_merge(['q' => null])];
}
foreach ($selectedCategories as $val) {
    $activeChips[] = ['label' => t('documents.table.category') . ': ' . category_label($val), 'href' => remove_array_filter_href('category', $selectedCategories, $val)];
}
foreach ($selectedStatuses as $val) {
    $activeChips[] = ['label' => t('documents.table.status') . ': ' . status_label($val), 'href' => remove_array_filter_href('status', $selectedStatuses, $val)];
}

// Levende søgning: ?partial=1 bruges af JS (fetch) til kun at hente det opdaterede
// filter-chip-/tabel-fragment, uden hele sidens skal (header/nav/stat-grid).
if (isset($_GET['partial'])) {
    header('Content-Type: text/html; charset=utf-8');
    require __DIR__ . '/includes/partials/document_results.php';
    exit;
}

render_header(t('documents.title'));
?>
<div class="page-heading-row">
<div class="page-heading">
    <h1><?= h(t('documents.title')) ?></h1>
    <?php if (can_edit_documents($user)): ?>
        <a class="button button-secondary" href="import_documents.php"><?= h(t('bulkimport.nav')) ?></a>
        <a class="button" href="upload_version.php"><?= h(t('documents.new')) ?></a>
    <?php endif; ?>
</div>
<div></div>
</div>

<div class="page-layout">
<div>
<div class="stat-grid">
    <div class="stat-card"><p class="stat-label"><?= h(t('documents.stat.approved')) ?></p><p class="stat-value"><?= (int)($stats['active'] ?? 0) ?></p></div>
    <div class="stat-card"><p class="stat-label"><?= h(t('documents.stat.pending')) ?></p><p class="stat-value stat-warning"><?= (int)($stats['pending'] ?? 0) ?></p></div>
    <div class="stat-card"><p class="stat-label"><?= h(t('documents.stat.expiring')) ?></p><p class="stat-value stat-danger"><?= (int)($stats['expiring'] ?? 0) ?></p></div>
</div>

<form method="get" class="filter-bar" id="doc-search-form">
    <?php hidden_inputs_for_query(['q']); ?>
    <input type="text" name="q" id="doc-search-input" placeholder="<?= h(t('documents.search.placeholder')) ?>" value="<?= h($search) ?>" autocomplete="off">
    <button type="submit"><?= h(t('documents.search.submit')) ?></button>
    <span id="doc-search-status" class="muted" style="display:none; font-size:13px;"><?= h(t('documents.search.searching')) ?></span>
</form>

<p style="margin:4px 0 0;">
    <a href="<?= h(basename($_SERVER['PHP_SELF']) . '?' . query_merge(['show_templates' => $showTemplates ? null : '1'])) ?>" style="font-size:13px; text-decoration:none;">
        <?= $showTemplates ? h(t('documents.hide_templates')) : h(t('documents.show_templates')) ?>
    </a>
</p>

<div id="doc-results">
<?php require __DIR__ . '/includes/partials/document_results.php'; ?>
</div>
</div>
<?php render_task_panel($myTasks); ?>
</div>

<script>
(function () {
    var form = document.getElementById('doc-search-form');
    var input = document.getElementById('doc-search-input');
    var results = document.getElementById('doc-results');
    var status = document.getElementById('doc-search-status');
    if (!form || !input || !results) {
        return;
    }

    var debounceTimer = null;
    var requestId = 0;

    function paramsWith(extra) {
        var params = new URLSearchParams(window.location.search);
        params.delete('partial');
        if (input.value === '') {
            params.delete('q');
        } else {
            params.set('q', input.value);
        }
        for (var key in extra) {
            if (extra[key] === null) {
                params.delete(key);
            } else {
                params.set(key, extra[key]);
            }
        }
        return params;
    }

    function runSearch() {
        var thisRequest = ++requestId;
        var fetchParams = paramsWith({ partial: '1' });

        if (status) {
            status.style.display = '';
        }

        fetch(window.location.pathname + '?' + fetchParams.toString(), {
            headers: { 'X-Requested-With': 'fetch' }
        })
            .then(function (response) { return response.text(); })
            .then(function (html) {
                if (thisRequest !== requestId) {
                    return; // et nyere søgeord er allerede undervejs - ignorer dette svar
                }
                results.innerHTML = html;
                if (status) {
                    status.style.display = 'none';
                }
                var displayParams = paramsWith({});
                var query = displayParams.toString();
                var newUrl = window.location.pathname + (query ? '?' + query : '');
                window.history.replaceState(null, '', newUrl);
            })
            .catch(function () {
                if (thisRequest !== requestId) {
                    return;
                }
                if (status) {
                    status.style.display = 'none';
                }
                // Fald tilbage til almindeligt sideskift, hvis AJAX-kaldet fejler.
                form.submit();
            });
    }

    input.addEventListener('input', function () {
        if (debounceTimer) {
            clearTimeout(debounceTimer);
        }
        debounceTimer = setTimeout(runSearch, 300);
    });

    // Tryk på Enter/"Søg"-knappen afbryder en evt. ventende debounce og søger med det samme
    // via samme AJAX-vej, så almindeligt sideskift kun sker hvis JS er deaktiveret.
    form.addEventListener('submit', function (e) {
        e.preventDefault();
        if (debounceTimer) {
            clearTimeout(debounceTimer);
        }
        runSearch();
    });
})();
</script>

<?php render_footer(); ?>
