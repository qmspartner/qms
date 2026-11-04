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
