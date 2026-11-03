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
require_once __DIR__ . '/../includes/functions.php';

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
