<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/documents.php';

$user = require_permission_allow_gqar('documents');
$db = get_db();

// "filtered" genbruger document_list() med nøjagtig samme filterparametre som
// index.php selv læser fra querystringen — se den fil for den fulde forklaring
// af hver nøgle. "all" (default) dropper søgning/kategori-/status-filtre, men
// bevarer brugerens aktuelle skabelon-valg (show_templates), samme tanke som
// index.php's $allDocumentsCount ovenfor.
$sortColumns = [
    'title' => 'd.title',
    'doc_number' => 'd.doc_number',
    'category' => 'd.category',
    'owner_name' => 'u.name',
    'status' => 'd.status',
    'next_review_date' => 'd.next_review_date',
];
$showTemplates = ($_GET['show_templates'] ?? '') === '1';

if (($_GET['scope'] ?? 'all') === 'filtered') {
    $sortCol = $_GET['sort'] ?? 'doc_number';
    if (!isset($sortColumns[$sortCol])) {
        $sortCol = 'doc_number';
    }
    $filters = [
        'search' => trim($_GET['q'] ?? ''),
        'categories' => array_values(array_filter((array) ($_GET['category'] ?? []))),
        'statuses' => array_values(array_filter((array) ($_GET['status'] ?? []))),
        'showTemplates' => $showTemplates,
        'sortColumn' => $sortColumns[$sortCol],
        'sortDirection' => ($_GET['dir'] ?? 'asc') === 'desc' ? 'DESC' : 'ASC',
    ];
} else {
    $filters = ['showTemplates' => $showTemplates];
}

// document_list() anvender altid document_visibility_sql($user) — en almindelig
// bruger kan derfor ikke via denne eksport få kladder/afventende/udgåede
// dokumenter med, som de ikke i forvejen må se i selve listen, og GQAR er
// begrænset på samme måde som ellers.
$documents = document_list($db, $user, $filters);

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="dokumenter.csv"');

$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF");

// Samme kolonneoverskrifter (og sprog) som selve dokumenttabellen, så CSV'en
// matcher det brugeren ser på skærmen.
fputcsv($out, [
    t('documents.table.document'),
    t('documents.table.id'),
    t('documents.table.category'),
    t('documents.table.owner'),
    t('documents.table.status'),
    t('documents.table.reviewed'),
], ';');

foreach ($documents as $doc) {
    $status = status_label($doc['status']);
    if ($doc['no_file']) {
        $status .= ' (' . t('doc.skeleton.badge') . ')';
    }
    fputcsv($out, [
        $doc['title'],
        $doc['doc_number'],
        category_label($doc['category']),
        $doc['owner_name'],
        $status,
        format_date($doc['next_review_date']),
    ], ';');
}

fclose($out);
exit;
