<?php

/**
 * Medarbejder-repository: femte modul i data-/servicelaget — se includes/documents.php,
 * includes/cases.php, includes/audits.php og includes/suppliers.php for de fire
 * første, og CLAUDE.md for den overordnede plan (modul for modul, ikke ét stort ryk).
 *
 * Bemærk navnet: ved at kigge på "kompetencer" fandt vi, at den egentlige duplikering
 * ikke lå i selve kvalifikations-forespørgslerne (kompetencematricen, "Mine
 * opgaver"-panelet og dashboard-statistikken henter hver sit specifikke udsnit — ikke
 * den samme forespørgsel igen og igen — og er derfor bevidst IKKE flyttet herind), men
 * i ÉT medarbejder-opslag og i adgangsreglen "admin eller denne medarbejders nærmeste
 * leder", som var skrevet tre gange under to forskellige (og ét direkte misvisende)
 * navne. Se can_manage_employee() i includes/functions.php — den blev ikke flyttet
 * herind, fordi den bruges af både kompetence- og fratrædelsessider og derfor hører
 * til det mere generelle rettighedslag, samme sted som is_gqar() m.fl.
 */

require_once __DIR__ . '/functions.php';

/**
 * Henter én medarbejder ved id, inden for brugerens organisation. Returnerer null hvis
 * ikke fundet. Erstatter det identiske opslag der var skrevet i employee_log.php,
 * employee_qualification.php og edit_employee.php.
 */
function employee_find(PDO $db, int $id, int $organizationId): ?array {
    $stmt = $db->prepare('SELECT * FROM employees WHERE id = ? AND organization_id = ?');
    $stmt->execute([$id, $organizationId]);
    $employee = $stmt->fetch();
    return $employee ?: null;
}
