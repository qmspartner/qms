<?php

/**
 * Minimal, afhængighedsfri test-runner.
 *
 * Ingen PHPUnit, ingen databaseforbindelse — kun almindeligt PHP. Formålet er at
 * fange regressioner i de centrale, rene (DB-fri) rettigheds- og
 * synlighedsfunktioner i includes/functions.php, uden at kræve et test-skema
 * eller en kørende MySQL-forbindelse. Udvides med flere test-filer i tests/
 * efterhånden som flere rene funktioner får dækning.
 *
 * Køres manuelt med: php tests/run_tests.php
 * Køres automatisk af .github/workflows/ci.yml på hvert push.
 */

$_SESSION = [];
// Normalt sat af config.php, som denne test-runner ikke loader (ingen database, ingen
// grund til resten af config.php's opsætning) — case_stats() bruger den i sin SQL.
if (!defined('EFFECTIVENESS_DUE_SOON_DAYS')) {
    define('EFFECTIVENESS_DUE_SOON_DAYS', 60);
}
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/documents.php';
require_once __DIR__ . '/../includes/cases.php';
require_once __DIR__ . '/../includes/audits.php';
require_once __DIR__ . '/../includes/suppliers.php';
require_once __DIR__ . '/../includes/employees.php';
require_once __DIR__ . '/../includes/customers.php';
require_once __DIR__ . '/../includes/equipment.php';
require_once __DIR__ . '/../includes/quality_objectives.php';
require_once __DIR__ . '/../includes/action_items.php';
require_once __DIR__ . '/fake_pdo.php';

$passed = 0;
$failed = 0;
$failures = [];

function assert_true(bool $condition, string $label): void {
    global $passed, $failed, $failures;
    if ($condition) {
        $passed++;
    } else {
        $failed++;
        $failures[] = $label;
    }
}

function assert_equal($expected, $actual, string $label): void {
    assert_true($expected === $actual, "$label (forventede " . var_export($expected, true) . ", fik " . var_export($actual, true) . ")");
}

// --- Testbrugere, en pr. rolle ---------------------------------------------

function test_user(string $role, array $overrides = []): array {
    return array_merge([
        'id' => 1,
        'organization_id' => 1,
        'role' => $role,
        'gqar_contract_references' => [],
    ], $overrides);
}

$admin = test_user('admin');
$editor = test_user('editor');
$approver = test_user('approver');
$user = test_user('user');
$gqarWithContract = test_user('gqar', ['gqar_contract_references' => ['K-1001']]);
$gqarNoContract = test_user('gqar');

// --- is_gqar() ---------------------------------------------------------------

assert_true(is_gqar($gqarWithContract), 'is_gqar() er sand for gqar-rollen');
assert_true(!is_gqar($user), 'is_gqar() er falsk for almindelig bruger');
assert_true(!is_gqar($admin), 'is_gqar() er falsk for admin');

// --- can_view_all_document_statuses() ----------------------------------------

assert_true(can_view_all_document_statuses($admin), 'admin må se alle dokumentstatusser');
assert_true(can_view_all_document_statuses($editor), 'editor må se alle dokumentstatusser');
assert_true(can_view_all_document_statuses($approver), 'approver må se alle dokumentstatusser');
assert_true(!can_view_all_document_statuses($user), 'almindelig bruger må IKKE se alle dokumentstatusser');
assert_true(!can_view_all_document_statuses($gqarWithContract), 'gqar styres separat, tælles ikke med her');

// --- can_approve_documents() --------------------------------------------------

assert_true(can_approve_documents($admin), 'admin kan godkende dokumenter');
assert_true(can_approve_documents($approver), 'approver kan godkende dokumenter');
assert_true(!can_approve_documents($editor), 'editor kan ikke godkende dokumenter');
assert_true(!can_approve_documents($user), 'almindelig bruger kan ikke godkende dokumenter');

// --- contract_in_clause() -----------------------------------------------------

[$clause, $params] = contract_in_clause('d.baseline_reference', ['K-1001', 'K-1002']);
assert_equal('d.baseline_reference IN (?,?)', $clause, 'contract_in_clause() bygger korrekt IN-klausul');
assert_equal(['K-1001', 'K-1002'], $params, 'contract_in_clause() returnerer kontraktlisten som parametre');

[$emptyClause, $emptyParams] = contract_in_clause('d.baseline_reference', []);
assert_equal('1 = 0', $emptyClause, 'contract_in_clause() med tom kontraktliste udelukker alt (ingen kontrakt = ingen adgang)');
assert_equal([], $emptyParams, 'contract_in_clause() med tom liste har ingen parametre');

// --- user_has_contract() ------------------------------------------------------

assert_true(user_has_contract($gqarWithContract, 'K-1001'), 'gqar med kontrakten har adgang');
assert_true(!user_has_contract($gqarWithContract, 'K-9999'), 'gqar uden den specifikke kontrakt har ikke adgang');
assert_true(!user_has_contract($gqarWithContract, null), 'null kontraktreference giver ikke adgang');

// --- document_visibility_sql() ------------------------------------------------

[$sql, $params] = document_visibility_sql($admin);
assert_equal('', $sql, 'admin får intet synligheds-filter (fuld adgang)');
assert_equal([], $params, 'admin får ingen ekstra parametre');

[$sql, $params] = document_visibility_sql($user);
assert_equal(" AND d.status = 'Godkendt'", $sql, 'almindelig bruger får kun-godkendt-filter med standardalias');
assert_equal([], $params, 'almindelig bruger får ingen ekstra parametre (ingen GQAR-kontraktliste nødvendig)');

[$sql, $params] = document_visibility_sql($user, 'documents');
assert_equal(" AND documents.status = 'Godkendt'", $sql, 'synlighedsfilter respekterer et andet tabelalias (fx uden JOIN)');

[$sql, $params] = document_visibility_sql($gqarWithContract);
assert_equal(" AND d.is_configuration_item = 1 AND d.status = 'Godkendt' AND d.baseline_reference IN (?)", $sql, 'gqar får konfigurationsstyrings- og kontraktfilter');
assert_equal(['K-1001'], $params, 'gqar-filteret binder egne kontraktreferencer som parametre');

[$sql, $params] = document_visibility_sql($gqarNoContract);
assert_true(str_contains($sql, '1 = 0'), 'gqar uden nogen kontrakt udelukkes helt fra listen');

// --- document_is_visible() ----------------------------------------------------

$draftDoc = ['status' => 'Kladde', 'is_configuration_item' => 0, 'baseline_reference' => null];
$approvedDoc = ['status' => 'Godkendt', 'is_configuration_item' => 1, 'baseline_reference' => 'K-1001'];
$approvedNonConfigDoc = ['status' => 'Godkendt', 'is_configuration_item' => 0, 'baseline_reference' => null];

assert_true(document_is_visible($admin, $draftDoc), 'admin ser en kladde');
assert_true(document_is_visible($editor, $draftDoc), 'editor ser en kladde');
assert_true(!document_is_visible($user, $draftDoc), 'almindelig bruger ser IKKE en kladde');
assert_true(document_is_visible($user, $approvedDoc), 'almindelig bruger ser et godkendt dokument');

assert_true(document_is_visible($gqarWithContract, $approvedDoc), 'gqar ser et godkendt, konfigurationsstyret dokument på egen kontrakt');
assert_true(!document_is_visible($gqarWithContract, $draftDoc), 'gqar ser ikke en kladde, selv på egen kontrakt');
assert_true(!document_is_visible($gqarWithContract, $approvedNonConfigDoc), 'gqar ser ikke et godkendt dokument der ikke er konfigurationsstyret');
assert_true(!document_is_visible($gqarNoContract, $approvedDoc), 'gqar uden den konkrete kontrakt ser ikke dokumentet, selv godkendt og konfigurationsstyret');

// Felter, der helt mangler i $doc, må aldrig give en PHP-fejl eller falsk "synlig".
assert_true(!document_is_visible($user, []), 'et tomt dokument-array behandles som ikke-synligt, ikke som en fejl');

// --- includes/documents.php: dokument-repository (via FakePdo, se fake_pdo.php) ----

$db = new FakePdo();

// document_find() — bygger den forventede SQL og binder [id, organization_id].
$db->rowsToReturn = [['id' => 7, 'status' => 'Godkendt', 'owner_name' => 'Test']];
$doc = document_find($db, 7, 1);
assert_true(str_contains($db->lastStatement->sql, 'd.id = ? AND d.organization_id = ?'), 'document_find() filtrerer på id og organisation');
assert_equal([7, 1], $db->lastStatement->boundParams, 'document_find() binder [id, organization_id] i den rækkefølge');
assert_equal(7, $doc['id'] ?? null, 'document_find() returnerer den fundne række');

$db->rowsToReturn = [];
assert_equal(null, document_find($db, 999, 1), 'document_find() returnerer null når intet findes');

// document_list() — tjekker at hvert filter sætter sit forventede SQL-fragment og
// binder parametrene i den rækkefølge koden bygger dem (søgning, kategori, status,
// synlighed, skabelon, sortering).
$db->rowsToReturn = [['id' => 1], ['id' => 2]];
document_list($db, $user, [
    'search' => 'kvalitet',
    'categories' => ['Politik', 'Procedure'],
    'statuses' => ['Godkendt'],
    'showTemplates' => false,
    'sortColumn' => 'd.title',
    'sortDirection' => 'DESC',
]);
$sql = $db->lastStatement->sql;
$params = $db->lastStatement->boundParams;
assert_true(str_contains($sql, 'd.title LIKE ? OR d.doc_number LIKE ?'), 'document_list() tilføjer søgefilter når search er sat');
assert_true(str_contains($sql, 'd.category IN (?,?)'), 'document_list() tilføjer kategori-filter med korrekt antal pladsholdere');
assert_true(str_contains($sql, "d.status IN (?)"), 'document_list() tilføjer status-filter');
assert_true(str_contains($sql, "d.status = 'Godkendt'"), 'document_list() anvender den centrale synlighedsregel for en almindelig bruger');
assert_true(str_contains($sql, 'd.is_template = 0'), 'document_list() udelukker skabeloner når showTemplates er falsk');
assert_true(str_contains($sql, 'ORDER BY d.title DESC'), 'document_list() sorterer efter den angivne kolonne/retning');
assert_equal([1, '%kvalitet%', '%kvalitet%', 'Politik', 'Procedure', 'Godkendt'], $params, 'document_list() binder parametre i forventet rækkefølge: org, søgning x2, kategorier, statusser');

document_list($db, $admin, ['showTemplates' => true]);
$sql = $db->lastStatement->sql;
assert_true(!str_contains($sql, 'is_template = 0'), 'document_list() lader skabeloner blive med når showTemplates er sand');
assert_true(!str_contains($sql, "status = 'Godkendt'"), 'document_list() giver admin fuld adgang uden synligheds-filter');

// document_stats() — ren opslags-SQL, ingen filtre at teste udover organisationsbinding.
$db->rowsToReturn = [['active' => 5, 'pending' => 2, 'expiring' => 1]];
$stats = document_stats($db, 1);
assert_equal([1], $db->lastStatement->boundParams, 'document_stats() binder kun organization_id');
assert_equal(5, $stats['active'] ?? null, 'document_stats() returnerer den hentede række');

// document_list_for_linking() — samme synlighedsregel som document_list(), men uden
// kategori/status/søgning, og med 'documents' som alias (intet join i den forespørgsel).
$db->rowsToReturn = [['id' => 3, 'title' => 'Indkøbspolitik']];
document_list_for_linking($db, $user);
assert_true(str_contains($db->lastStatement->sql, "documents.status = 'Godkendt'"), 'document_list_for_linking() bruger synlighedsregel med documents-alias (intet join)');

// document_validate_link() — skal afvise id 0 uden overhovedet at spørge databasen,
// og ellers slå op med samme synlighedsregel.
assert_equal(null, document_validate_link($db, $user, 0), 'document_validate_link() afviser id 0 uden databaseopslag');
$db->rowsToReturn = [['id' => 3]];
assert_equal(3, document_validate_link($db, $user, 3), 'document_validate_link() returnerer id\'et når dokumentet findes og er synligt');
$db->rowsToReturn = [];
assert_equal(null, document_validate_link($db, $user, 3), 'document_validate_link() returnerer null når intet matcher (ikke synligt/ikke fundet)');

// document_version_find() — bygger den forventede SQL (med join mod documents for
// organisationsscope) og binder [id, organization_id], med et valgfrit ekstra
// 'd.is_template = 1'-fragment når templateOnly er sat (download_template.php).
// stream_file_download() testes IKKE her — den sender HTTP-headers og kalder
// readfile()/exit() direkte, og har derfor ingen ren, DB-fri logik at teste uden en
// rigtig fil og et rigtigt respons, ligesom upload-håndteringen i new_calibration.php
// m.fl. ikke testes her.
$db->rowsToReturn = [['id' => 30, 'document_id' => 2, 'document_status' => 'Godkendt']];
$version = document_version_find($db, 30, 1);
assert_true(str_contains($db->lastStatement->sql, 'v.id = ? AND d.organization_id = ?'), 'document_version_find() filtrerer på version-id og organisation (via dokumentet)');
assert_equal([30, 1], $db->lastStatement->boundParams, 'document_version_find() binder [version_id, organization_id] i den rækkefølge');
assert_equal(30, $version['id'] ?? null, 'document_version_find() returnerer den fundne række');

$db->rowsToReturn = [];
assert_equal(null, document_version_find($db, 999, 1), 'document_version_find() returnerer null når intet findes');

$db->rowsToReturn = [['id' => 31]];
document_version_find($db, 31, 1, ['templateOnly' => true]);
assert_true(str_contains($db->lastStatement->sql, 'd.is_template = 1'), 'document_version_find() tilføjer skabelon-filter når templateOnly er sat');

document_version_find($db, 31, 1);
assert_true(!str_contains($db->lastStatement->sql, 'is_template'), 'document_version_find() uden templateOnly tilføjer intet skabelon-filter');

// --- includes/cases.php: sags-repository (via FakePdo) ------------------------

// case_visibility_sql() / case_is_visible() — sager har ingen "almindelig bruger
// ser mindre"-regel, kun GQAR-begrænsningen.
[$sql, $params] = case_visibility_sql($admin);
assert_equal('', $sql, 'case_visibility_sql() giver admin fuld adgang');

[$sql, $params] = case_visibility_sql($user);
assert_equal('', $sql, 'case_visibility_sql() giver også en almindelig bruger fuld adgang (ingen caseregel for user-rollen)');

[$sql, $params] = case_visibility_sql($gqarWithContract);
assert_equal(" AND c.requires_customer_notification = 1 AND c.contract_reference IN (?)", $sql, 'case_visibility_sql() begrænser gqar til kundenotifikations-sager på egen kontrakt');
assert_equal(['K-1001'], $params, 'case_visibility_sql() binder gqars kontraktreferencer');

$notifiedCase = ['requires_customer_notification' => 1, 'contract_reference' => 'K-1001'];
$internalCase = ['requires_customer_notification' => 0, 'contract_reference' => null];

assert_true(case_is_visible($user, $internalCase), 'en almindelig bruger ser en intern sag (ingen caseregel for user-rollen)');
assert_true(case_is_visible($gqarWithContract, $notifiedCase), 'gqar ser en kundenotifikations-sag på egen kontrakt');
assert_true(!case_is_visible($gqarWithContract, $internalCase), 'gqar ser ikke en intern sag uden kundenotifikation');
assert_true(!case_is_visible($gqarNoContract, $notifiedCase), 'gqar uden den konkrete kontrakt ser ikke sagen, selv med kundenotifikation');

// case_find() — bygger den forventede SQL og binder [id, organization_id].
$db->rowsToReturn = [['id' => 12, 'requires_customer_notification' => 0]];
$case = case_find($db, 12, 1);
assert_true(str_contains($db->lastStatement->sql, 'c.id = ? AND c.organization_id = ?'), 'case_find() filtrerer på id og organisation');
assert_equal([12, 1], $db->lastStatement->boundParams, 'case_find() binder [id, organization_id] i den rækkefølge');
assert_equal(12, $case['id'] ?? null, 'case_find() returnerer den fundne række');

$db->rowsToReturn = [];
assert_equal(null, case_find($db, 999, 1), 'case_find() returnerer null når intet findes');

// case_list() — tjekker filtre, synlighed og udelukkelsen af "Opfølgningspunkt".
$db->rowsToReturn = [['id' => 1], ['id' => 2]];
case_list($db, $gqarWithContract, [
    'types' => ['Afvigelse'],
    'severities' => ['Høj', 'Mellem'],
    'statuses' => ['Registreret'],
    'supplierId' => 9,
    'sortColumn' => 'c.due_date',
    'sortDirection' => 'ASC',
]);
$sql = $db->lastStatement->sql;
$params = $db->lastStatement->boundParams;
assert_true(str_contains($sql, "c.type != 'Opfølgningspunkt'"), 'case_list() udelukker altid Opfølgningspunkt-typen');
assert_true(str_contains($sql, 'c.type IN (?)'), 'case_list() tilføjer type-filter');
assert_true(str_contains($sql, 'c.severity IN (?,?)'), 'case_list() tilføjer sværhedsgrad-filter med korrekt antal pladsholdere');
assert_true(str_contains($sql, 'c.status IN (?)'), 'case_list() tilføjer status-filter');
assert_true(str_contains($sql, 'c.supplier_id = ?'), 'case_list() tilføjer leverandør-filter');
assert_true(str_contains($sql, 'c.requires_customer_notification = 1'), 'case_list() anvender gqar-synlighedsregel');
assert_true(str_contains($sql, 'ORDER BY c.due_date ASC'), 'case_list() sorterer efter den angivne kolonne/retning');
assert_equal([1, 'Afvigelse', 'Høj', 'Mellem', 'Registreret', 9, 'K-1001'], $params, 'case_list() binder parametre i forventet rækkefølge: org, type, sværhedsgrader, status, leverandør, gqar-kontrakt');

case_list($db, $user, []);
$sql = $db->lastStatement->sql;
assert_true(!str_contains($sql, 'requires_customer_notification'), 'case_list() uden filtre/gqar tilføjer intet synligheds-fragment for en almindelig bruger');

// case_stats() — ren opslags-SQL, ingen filtre at teste udover organisationsbinding.
$db->rowsToReturn = [['open_count' => 3]];
$caseStats = case_stats($db, 1);
assert_equal([1], $db->lastStatement->boundParams, 'case_stats() binder kun organization_id');
assert_true(str_contains($db->lastStatement->sql, "type != 'Opfølgningspunkt'"), 'case_stats() udelukker Opfølgningspunkt-typen ligesom case_list()');
assert_equal(3, $caseStats['open_count'] ?? null, 'case_stats() returnerer den hentede række');

// --- includes/audits.php: audit-repository (via FakePdo) ----------------------

// audit_visibility_sql() / audit_is_visible() — samme mønster som sager: ingen
// "almindelig bruger ser mindre"-regel, kun GQAR er begrænset (her: til eksterne
// audits inden for egen kontrakt).
[$sql, $params] = audit_visibility_sql($admin);
assert_equal('', $sql, 'audit_visibility_sql() giver admin fuld adgang');

[$sql, $params] = audit_visibility_sql($gqarWithContract);
assert_equal(" AND a.audit_type = 'Eksternt' AND a.contract_reference IN (?)", $sql, 'audit_visibility_sql() begrænser gqar til eksterne audits på egen kontrakt');
assert_equal(['K-1001'], $params, 'audit_visibility_sql() binder gqars kontraktreferencer');

$externalAudit = ['audit_type' => 'Eksternt', 'contract_reference' => 'K-1001'];
$internalAudit = ['audit_type' => 'Internt', 'contract_reference' => null];

assert_true(audit_is_visible($user, $internalAudit), 'en almindelig bruger ser en intern audit (ingen auditregel for user-rollen)');
assert_true(audit_is_visible($gqarWithContract, $externalAudit), 'gqar ser en ekstern audit på egen kontrakt');
assert_true(!audit_is_visible($gqarWithContract, $internalAudit), 'gqar ser ikke en intern audit');
assert_true(!audit_is_visible($gqarNoContract, $externalAudit), 'gqar uden den konkrete kontrakt ser ikke auditen, selv ekstern');

// audit_find() — bygger den forventede SQL og binder [id, organization_id].
$db->rowsToReturn = [['id' => 5, 'audit_type' => 'Internt']];
$audit = audit_find($db, 5, 1);
assert_true(str_contains($db->lastStatement->sql, 'a.id = ? AND a.organization_id = ?'), 'audit_find() filtrerer på id og organisation');
assert_equal([5, 1], $db->lastStatement->boundParams, 'audit_find() binder [id, organization_id] i den rækkefølge');
assert_equal(5, $audit['id'] ?? null, 'audit_find() returnerer den fundne række');

$db->rowsToReturn = [];
assert_equal(null, audit_find($db, 999, 1), 'audit_find() returnerer null når intet findes');

// audit_list() — filtre, synlighed og sortering.
$db->rowsToReturn = [['id' => 1]];
audit_list($db, $gqarWithContract, [
    'statuses' => ['Planlagt'],
    'types' => ['Eksternt'],
    'sortColumn' => 'a.title',
    'sortDirection' => 'ASC',
]);
$sql = $db->lastStatement->sql;
$params = $db->lastStatement->boundParams;
assert_true(str_contains($sql, 'a.status IN (?)'), 'audit_list() tilføjer status-filter');
assert_true(str_contains($sql, 'a.audit_type IN (?)'), 'audit_list() tilføjer audittype-filter');
assert_true(str_contains($sql, "a.audit_type = 'Eksternt' AND a.contract_reference IN (?)"), 'audit_list() anvender gqar-synlighedsregel');
assert_true(str_contains($sql, 'ORDER BY a.title ASC'), 'audit_list() sorterer efter den angivne kolonne/retning');
assert_equal([1, 'Planlagt', 'Eksternt', 'K-1001'], $params, 'audit_list() binder parametre i forventet rækkefølge: org, status, type, gqar-kontrakt');

audit_list($db, $user, []);
assert_true(!str_contains($db->lastStatement->sql, "audit_type = 'Eksternt'"), 'audit_list() uden filtre/gqar tilføjer intet synligheds-fragment for en almindelig bruger');

// audit_stats() — kører to forespørgsler (audits + cases) og samler dem i ét svar.
$db->statements = []; // nulstil historikken, så vi kun ser de to kald audit_stats() selv laver
$db->rowsQueue = [
    [['planned_this_year' => 4]],
    [['open_findings' => 2]],
];
$auditStats = audit_stats($db, 1);
assert_equal(2, count($db->statements), 'audit_stats() udfører præcis to forespørgsler');
assert_true(str_contains($db->statements[0]->sql, 'FROM audits'), 'audit_stats()s første forespørgsel går mod audits-tabellen');
assert_true(str_contains($db->statements[1]->sql, 'FROM cases'), 'audit_stats()s anden forespørgsel går mod cases-tabellen (fund)');
assert_equal([1], $db->statements[0]->boundParams, 'audit_stats() binder kun organization_id i audits-forespørgslen');
assert_equal([1], $db->statements[1]->boundParams, 'audit_stats() binder kun organization_id i cases-forespørgslen');
assert_equal(4, $auditStats['planned_this_year'] ?? null, 'audit_stats() returnerer planned_this_year fra audits-forespørgslen');
assert_equal(2, $auditStats['open_findings'] ?? null, 'audit_stats() returnerer open_findings fra cases-forespørgslen');
$db->rowsQueue = [];

// --- includes/suppliers.php: leverandør-repository (via FakePdo) --------------

// supplier_find() — bygger den forventede SQL og binder [id, organization_id].
$db->rowsToReturn = [['id' => 4, 'name' => 'Test A/S']];
$supplier = supplier_find($db, 4, 1);
assert_true(str_contains($db->lastStatement->sql, 's.id = ? AND s.organization_id = ?'), 'supplier_find() filtrerer på id og organisation');
assert_equal([4, 1], $db->lastStatement->boundParams, 'supplier_find() binder [id, organization_id] i den rækkefølge');
assert_equal(4, $supplier['id'] ?? null, 'supplier_find() returnerer den fundne række');

$db->rowsToReturn = [];
assert_equal(null, supplier_find($db, 999, 1), 'supplier_find() returnerer null når intet findes');

// supplier_list() — filtre og sortering. Leverandører har ingen GQAR-synlighedsregel,
// så der er ingen synligheds-fragment at tjekke her, i modsætning til de andre moduler.
$db->rowsToReturn = [['id' => 1]];
supplier_list($db, $user, [
    'categories' => ['Rådata'],
    'criticality' => ['Kritisk'],
    'statuses' => ['Godkendt', 'Udfaset'],
    'sortColumn' => 's.next_evaluation_date',
    'sortDirection' => 'DESC',
]);
$sql = $db->lastStatement->sql;
$params = $db->lastStatement->boundParams;
assert_true(str_contains($sql, 's.category IN (?)'), 'supplier_list() tilføjer kategori-filter');
assert_true(str_contains($sql, 's.criticality IN (?)'), 'supplier_list() tilføjer kritikalitets-filter');
assert_true(str_contains($sql, 's.status IN (?,?)'), 'supplier_list() tilføjer status-filter med korrekt antal pladsholdere');
assert_true(str_contains($sql, 'ORDER BY s.next_evaluation_date DESC'), 'supplier_list() sorterer efter den angivne kolonne/retning');
assert_equal([1, 'Rådata', 'Kritisk', 'Godkendt', 'Udfaset'], $params, 'supplier_list() binder parametre i forventet rækkefølge: org, kategori, kritikalitet, status');

supplier_list($db, $user, []);
assert_true(!str_contains($db->lastStatement->sql, 'IN ('), 'supplier_list() uden filtre tilføjer intet filter-fragment');

// supplier_stats() — ren opslags-SQL, ingen filtre at teste udover organisationsbinding.
$db->rowsToReturn = [['total' => 12, 'due_soon' => 3]];
$supplierStats = supplier_stats($db, 1);
assert_equal([1], $db->lastStatement->boundParams, 'supplier_stats() binder kun organization_id');
assert_equal(12, $supplierStats['total'] ?? null, 'supplier_stats() returnerer den hentede række');

// --- can_manage_employee(): admin eller denne medarbejders nærmeste leder ------
// (omdøbt fra can_manage_employee_offboarding() — nu brugt af fratrædelse OG
// kompetencesider, se includes/employees.php)

$managerUser = test_user('user', ['id' => 42]);
$otherUser = test_user('user', ['id' => 99]);
$employeeOfManager = ['manager_user_id' => 42];
$employeeOfSomeoneElse = ['manager_user_id' => 7];

assert_true(can_manage_employee($admin, $employeeOfSomeoneElse), 'admin kan altid tilgå en medarbejder, uanset leder');
assert_true(can_manage_employee($managerUser, $employeeOfManager), 'en bruger kan tilgå sin egen medarbejders data');
assert_true(!can_manage_employee($otherUser, $employeeOfManager), 'en bruger kan ikke tilgå en medarbejder der ikke er deres egen');
assert_true(!can_manage_employee($managerUser, ['manager_user_id' => null]), 'en medarbejder uden leder kan ikke tilgås af en almindelig bruger');

// --- includes/employees.php: medarbejder-repository (via FakePdo) -------------

$db->rowsToReturn = [['id' => 10, 'name' => 'Test Testesen']];
$employee = employee_find($db, 10, 1);
assert_true(str_contains($db->lastStatement->sql, 'id = ? AND organization_id = ?'), 'employee_find() filtrerer på id og organisation');
assert_equal([10, 1], $db->lastStatement->boundParams, 'employee_find() binder [id, organization_id] i den rækkefølge');
assert_equal(10, $employee['id'] ?? null, 'employee_find() returnerer den fundne række');

$db->rowsToReturn = [];
assert_equal(null, employee_find($db, 999, 1), 'employee_find() returnerer null når intet findes');

// --- includes/customers.php: kunde-repository (via FakePdo) -------------------

$db->rowsToReturn = [['id' => 6, 'name' => 'Kunde A/S']];
$customer = customer_find($db, 6, 1);
assert_true(str_contains($db->lastStatement->sql, 'id = ? AND organization_id = ?'), 'customer_find() filtrerer på id og organisation');
assert_equal([6, 1], $db->lastStatement->boundParams, 'customer_find() binder [id, organization_id] i den rækkefølge');
assert_equal(6, $customer['id'] ?? null, 'customer_find() returnerer den fundne række');

$db->rowsToReturn = [];
assert_equal(null, customer_find($db, 999, 1), 'customer_find() returnerer null når intet findes');

$db->rowsToReturn = [['id' => 1]];
customer_list($db, $user, ['sortColumn' => 'contact_person', 'sortDirection' => 'DESC']);
assert_true(str_contains($db->lastStatement->sql, 'ORDER BY contact_person DESC'), 'customer_list() sorterer efter den angivne kolonne/retning');
assert_equal([1], $db->lastStatement->boundParams, 'customer_list() binder kun organization_id (ingen filtre findes i dag)');

customer_list($db, $user, []);
assert_true(str_contains($db->lastStatement->sql, 'ORDER BY name ASC'), 'customer_list() falder tilbage til name/ASC uden filtre');

$db->rowsToReturn = [['total' => 8]];
$customerStats = customer_stats($db, 1);
assert_equal([1], $db->lastStatement->boundParams, 'customer_stats() binder kun organization_id');
assert_equal(8, $customerStats['total'] ?? null, 'customer_stats() returnerer den hentede række');

// --- includes/equipment.php: udstyr-repository (via FakePdo) ------------------

// equipment_find() — bygger den forventede SQL (med ansvarlig-join) og binder
// [id, organization_id]. Samme funktion bruges nu også af new_calibration.php,
// som tidligere havde sit eget, simplere, ikke-joinede opslag.
$db->rowsToReturn = [['id' => 20, 'name' => 'Skydelære', 'responsible_name' => 'Test Testesen']];
$equipment = equipment_find($db, 20, 1);
assert_true(str_contains($db->lastStatement->sql, 'e.id = ? AND e.organization_id = ?'), 'equipment_find() filtrerer på id og organisation');
assert_equal([20, 1], $db->lastStatement->boundParams, 'equipment_find() binder [id, organization_id] i den rækkefølge');
assert_equal(20, $equipment['id'] ?? null, 'equipment_find() returnerer den fundne række');

$db->rowsToReturn = [];
assert_equal(null, equipment_find($db, 999, 1), 'equipment_find() returnerer null når intet findes');

// equipment_list() — kritikalitets-/status-filtre og sortering. Ingen GQAR-
// synlighedsregel her, ligesom leverandører/kunder.
$db->rowsToReturn = [['id' => 1]];
equipment_list($db, $user, [
    'criticality' => ['Kritisk'],
    'statuses' => ['Aktiv', 'Ikke aktiv'],
    'sortColumn' => 'e.next_calibration_date',
    'sortDirection' => 'DESC',
]);
$sql = $db->lastStatement->sql;
$params = $db->lastStatement->boundParams;
assert_true(str_contains($sql, 'e.criticality IN (?)'), 'equipment_list() tilføjer kritikalitets-filter');
assert_true(str_contains($sql, 'e.status IN (?,?)'), 'equipment_list() tilføjer status-filter med korrekt antal pladsholdere');
assert_true(str_contains($sql, 'ORDER BY e.next_calibration_date DESC'), 'equipment_list() sorterer efter den angivne kolonne/retning');
assert_equal([1, 'Kritisk', 'Aktiv', 'Ikke aktiv'], $params, 'equipment_list() binder parametre i forventet rækkefølge: org, kritikalitet, status');

equipment_list($db, $user, []);
assert_true(!str_contains($db->lastStatement->sql, 'e.criticality IN (') && !str_contains($db->lastStatement->sql, 'e.status IN ('), 'equipment_list() uden filtre tilføjer intet filter-fragment');
assert_true(str_contains($db->lastStatement->sql, 'ORDER BY e.name ASC'), 'equipment_list() falder tilbage til e.name/ASC uden filtre');

// equipment_stats() — kører to forespørgsler (total/due_soon + failed_count) og
// samler dem i ét svar, samme mønster som audit_stats().
$db->statements = []; // nulstil historikken, så vi kun ser de to kald equipment_stats() selv laver
$db->rowsQueue = [
    [['total' => 15, 'due_soon' => 3]],
    [['failed_count' => 1]],
];
$equipmentStats = equipment_stats($db, 1);
assert_equal(2, count($db->statements), 'equipment_stats() udfører præcis to forespørgsler');
assert_true(str_contains($db->statements[0]->sql, 'FROM equipment') && !str_contains($db->statements[0]->sql, 'EXISTS'), 'equipment_stats()s første forespørgsel er total/due_soon-tællingen');
assert_true(str_contains($db->statements[1]->sql, 'EXISTS'), 'equipment_stats()s anden forespørgsel er den korrelerede failed_count-tælling');
assert_equal([1], $db->statements[0]->boundParams, 'equipment_stats() binder kun organization_id i første forespørgsel');
assert_equal([1], $db->statements[1]->boundParams, 'equipment_stats() binder kun organization_id i anden forespørgsel');
assert_equal(15, $equipmentStats['total'] ?? null, 'equipment_stats() returnerer total fra første forespørgsel');
assert_equal(3, $equipmentStats['due_soon'] ?? null, 'equipment_stats() returnerer due_soon fra første forespørgsel');
assert_equal(1, $equipmentStats['failed_count'] ?? null, 'equipment_stats() returnerer failed_count fra anden forespørgsel');
$db->rowsQueue = [];

// --- includes/quality_objectives.php: rene periode-/påmindelsesfunktioner -----

// quality_objective_period_step() — DateInterval-spec pr. frekvens.
assert_equal('P7D', quality_objective_period_step('Ugentligt'), 'quality_objective_period_step() giver P7D for Ugentligt');
assert_equal('P1M', quality_objective_period_step('Månedligt'), 'quality_objective_period_step() giver P1M for Månedligt');
assert_equal('P3M', quality_objective_period_step('Kvartalsvist'), 'quality_objective_period_step() giver P3M for Kvartalsvist');
assert_equal('P3M', quality_objective_period_step('ukendt-værdi'), 'quality_objective_period_step() falder tilbage til P3M (Kvartalsvist) for en ukendt værdi');

// quality_objective_period_ends_until() — altid mindst én periode, og rækken stopper ved $until.
assert_equal(
    ['2026-01-08', '2026-01-15'],
    quality_objective_period_ends_until('Ugentligt', '2026-01-01', '2026-01-15'),
    'quality_objective_period_ends_until() (ugentligt) giver periode-slutdatoer frem til og med $until'
);
assert_equal(
    [],
    quality_objective_period_ends_until('Månedligt', '2026-01-01', '2026-01-10'),
    'quality_objective_period_ends_until() returnerer et tomt array når selv den første periode slutter efter $until'
);
assert_equal(
    ['2026-04-01', '2026-07-01'],
    quality_objective_period_ends_until('Kvartalsvist', '2026-01-01', '2026-07-01'),
    'quality_objective_period_ends_until() (kvartalsvist) giver periode-slutdatoer frem til og med $until'
);

// quality_objective_next_due_period() — den første ikke-indberettede periode inden for påmindelsesvinduet.
assert_equal(
    '2026-01-08',
    quality_objective_next_due_period('Ugentligt', '2026-01-01', [], '2026-01-05', 7),
    'quality_objective_next_due_period() finder den første periode inden for påmindelsesvinduet, når intet er indberettet'
);
assert_equal(
    null,
    quality_objective_next_due_period('Ugentligt', '2026-01-01', [], '2026-01-01', 1),
    'quality_objective_next_due_period() returnerer null når næste periode ligger uden for påmindelsesvinduet'
);
assert_equal(
    '2026-01-15',
    quality_objective_next_due_period('Ugentligt', '2026-01-01', ['2026-01-08'], '2026-01-12', 7),
    'quality_objective_next_due_period() springer allerede indberettede perioder over'
);
assert_equal(
    '2026-01-08',
    quality_objective_next_due_period('Ugentligt', '2026-01-01', [], '2026-01-10', 0),
    'quality_objective_next_due_period() finder en allerede overskredet, uindberettet periode (dvs. "for sent")'
);

// --- includes/quality_objectives.php: repository (via FakePdo) ----------------

$db->rowsToReturn = [['id' => 5, 'title' => 'Reducer afvigelser', 'owner_user_id' => 42]];
$objective = quality_objective_find($db, 5, 1);
assert_true(str_contains($db->lastStatement->sql, 'qo.id = ? AND qo.organization_id = ?'), 'quality_objective_find() filtrerer på id og organisation');
assert_equal([5, 1], $db->lastStatement->boundParams, 'quality_objective_find() binder [id, organization_id] i den rækkefølge');
assert_equal(5, $objective['id'] ?? null, 'quality_objective_find() returnerer den fundne række');

$db->rowsToReturn = [];
assert_equal(null, quality_objective_find($db, 999, 1), 'quality_objective_find() returnerer null når intet findes');

$db->rowsToReturn = [['id' => 1]];
quality_objective_list($db, $user, ['statuses' => ['Ny', 'På kurs'], 'sortColumn' => 'qo.title', 'sortDirection' => 'DESC']);
assert_true(str_contains($db->lastStatement->sql, 'qo.status IN (?,?)'), 'quality_objective_list() tilføjer status-filter med korrekt antal pladsholdere');
assert_true(str_contains($db->lastStatement->sql, 'ORDER BY qo.title DESC'), 'quality_objective_list() sorterer efter den angivne kolonne/retning');
assert_equal([1, 'Ny', 'På kurs'], $db->lastStatement->boundParams, 'quality_objective_list() binder parametre i forventet rækkefølge: org, status-filtre');

quality_objective_list($db, $user, []);
assert_true(!str_contains($db->lastStatement->sql, 'qo.status IN ('), 'quality_objective_list() uden filtre tilføjer intet status-filter');
assert_true(str_contains($db->lastStatement->sql, 'ORDER BY qo.title ASC'), 'quality_objective_list() falder tilbage til qo.title/ASC uden filtre');

$db->rowsToReturn = [['active' => 4, 'achieved_ytd' => 2, 'off_track' => 1]];
$qoStats = quality_objective_stats($db, 1);
assert_equal([1], $db->lastStatement->boundParams, 'quality_objective_stats() binder kun organization_id');
assert_equal(4, $qoStats['active'] ?? null, 'quality_objective_stats() returnerer active fra den hentede række');

$db->rowsToReturn = [['period_end' => '2026-01-07', 'value' => 3]];
$measurements = quality_objective_measurement_list($db, 5);
assert_equal([5], $db->lastStatement->boundParams, 'quality_objective_measurement_list() binder objective_id');
assert_equal('2026-01-07', $measurements[0]['period_end'] ?? null, 'quality_objective_measurement_list() returnerer de hentede rækker');

quality_objective_measurement_add($db, 5, '2026-01-07', 3.5, 'note', 42);
assert_true(str_contains($db->lastStatement->sql, 'INSERT INTO quality_objective_measurements'), 'quality_objective_measurement_add() indsætter en ny måling');
assert_equal([5, '2026-01-07', 3.5, 'note', 42], $db->lastStatement->boundParams, 'quality_objective_measurement_add() binder parametrene i forventet rækkefølge');

quality_objective_measurement_add($db, 5, '2026-01-07', 3.5, null, 42);
assert_equal([5, '2026-01-07', 3.5, null, 42], $db->lastStatement->boundParams, 'quality_objective_measurement_add() binder null for en tom note');

$db->rowsToReturn = [['period_end' => '2026-01-07'], ['period_end' => '2026-01-14']];
$periodEnds = quality_objective_all_period_ends($db, 5);
assert_equal([5], $db->lastStatement->boundParams, 'quality_objective_all_period_ends() binder objective_id');
assert_equal(['2026-01-07', '2026-01-14'], $periodEnds, 'quality_objective_all_period_ends() returnerer en flad liste af period_end-datoer (FETCH_COLUMN)');

// --- can_manage_quality_objective(): admin, eller målets egen ejer -------------
// (bruger rollen 'gqar' til ejer-/ikke-ejer-brugerne herunder, ikke 'user' eller
// 'ledelse' — begge er med i permission_roles() og ville udløse et DB-opslag via
// has_permission(), som (ligesom has_permission() for ikke-admin-roller generelt,
// se CLAUDE.md) bevidst ikke er dækket her uden et test-skema. 'gqar' kortslutter
// has_permission() til false uden at røre databasen, så kun ejerskabsgrenen testes.)

$ownerUser = test_user('gqar', ['id' => 77]);
$otherUser2 = test_user('gqar', ['id' => 88]);
$objectiveOwnedBy77 = ['owner_user_id' => 77];

assert_true(can_manage_quality_objective($admin, $objectiveOwnedBy77), 'admin kan altid tilgå et kvalitetsmål');
assert_true(can_manage_quality_objective($ownerUser, $objectiveOwnedBy77), 'målets egen ejer kan tilgå det, uanset rolle');
assert_true(!can_manage_quality_objective($otherUser2, $objectiveOwnedBy77), 'en bruger uden adgang og uden ejerskab kan ikke tilgå et kvalitetsmål');

// --- action_items.php: underaktioner på sager (Fase 1) -------------------------

$db->rowsToReturn = [['id' => 1, 'case_id' => 5, 'status' => 'Åben', 'owner_name' => 'Mette Hansen']];
action_item_list_for_case($db, 5);
assert_true(str_contains($db->lastStatement->sql, 'WHERE ai.case_id = ?'), 'action_item_list_for_case() filtrerer på sagens id');
assert_equal([5], $db->lastStatement->boundParams, 'action_item_list_for_case() binder case_id');

$db->rowsToReturn = [['exists' => 1]];
assert_true(action_item_has_open_for_case($db, 5), 'action_item_has_open_for_case() er sand når en åben underaktion findes');
assert_true(str_contains($db->lastStatement->sql, "status = 'Åben'"), 'action_item_has_open_for_case() tjekker kun åbne underaktioner');

$db->rowsToReturn = [];
assert_true(!action_item_has_open_for_case($db, 5), 'action_item_has_open_for_case() er falsk når ingen rækker findes');

// action_item_add(): første prepare henter næste position (SQL'en beregner selv
// COALESCE(MAX(position), -1) + 1 — fetchColumn()'s returværdi ER den næste position,
// ikke den rå MAX), anden prepare indsætter rækken.
$db->rowsQueue = [[['next_position' => 2]], []];
$db->statements = [];
action_item_add($db, 1, 5, 'Opdatér kvalitetsplan', 42, '2026-12-01', 7);
assert_equal(2, count($db->statements), 'action_item_add() laver præcis to forespørgsler (position + insert)');
assert_true(str_contains($db->statements[1]->sql, 'INSERT INTO action_items'), 'action_item_add() indsætter en ny underaktion');
assert_equal([1, 5, 2, 'Opdatér kvalitetsplan', 42, '2026-12-01', 7], $db->statements[1]->boundParams, 'action_item_add() binder parametrene, med den beregnede næste position');

$db->rowsQueue = [[['next_position' => 0]], []];
action_item_add($db, 1, 5, 'Uden ejer eller frist', null, null, 7);
assert_equal([1, 5, 0, 'Uden ejer eller frist', null, null, 7], $db->lastStatement->boundParams, 'action_item_add() binder null for ejer/frist, position 0 for den første underaktion på en sag');

action_item_set_status($db, 9, 1, true);
assert_true(str_contains($db->lastStatement->sql, 'SET status = ?, completed_at = ?'), 'action_item_set_status() opdaterer status og completed_at');
assert_equal('Afsluttet', $db->lastStatement->boundParams[0] ?? null, 'action_item_set_status(true) sætter status til Afsluttet');
assert_true($db->lastStatement->boundParams[1] !== null, 'action_item_set_status(true) sætter completed_at');

action_item_set_status($db, 9, 1, false);
assert_equal('Åben', $db->lastStatement->boundParams[0] ?? null, 'action_item_set_status(false) genåbner til Åben');
assert_equal(null, $db->lastStatement->boundParams[1], 'action_item_set_status(false) nulstiller completed_at');

$db->rowsToReturn = [['id' => 3, 'title' => 'Opdatér kvalitetsplan', 'due_date' => '2026-11-01', 'case_id' => 5, 'case_title' => 'Forkert materialecertifikat']];
$ownerTasks = action_items_for_owner_tasks($db, 1, 42);
assert_equal([1, 42], $db->lastStatement->boundParams, 'action_items_for_owner_tasks() binder [organization_id, owner_user_id]');
assert_true(str_contains($db->lastStatement->sql, "status = 'Åben' AND ai.due_date IS NOT NULL"), 'action_items_for_owner_tasks() viser kun åbne underaktioner med en frist');
assert_equal('Forkert materialecertifikat', $ownerTasks[0]['case_title'] ?? null, 'action_items_for_owner_tasks() leverer sagens titel til brug i opgave-labelen');

// --- Resultat ------------------------------------------------------------------

echo "\n{$passed} bestået, {$failed} fejlet.\n";
if ($failed > 0) {
    echo "\nFejlede tests:\n";
    foreach ($failures as $f) {
        echo "  - {$f}\n";
    }
    exit(1);
}
exit(0);
