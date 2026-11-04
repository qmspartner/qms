<?php

/**
 * Udstyr-repository: syvende modul i data-/servicelaget — se includes/documents.php,
 * includes/cases.php, includes/audits.php, includes/suppliers.php,
 * includes/employees.php og includes/customers.php for de seks første, og CLAUDE.md
 * for den overordnede plan.
 *
 * Som leverandører/kunder er der ingen GQAR-synlighedsregel her (require_permission(),
 * ikke require_permission_allow_gqar()).
 *
 * home.php's og contract_overview.php's statistik bruger samme tabel i en anden
 * forespørgselsform (aggregerede tal, andre formål) og er bevidst IKKE flyttet herind,
 * samme afgrænsning som for document_stats()/case_stats()/audit_stats() i de andre
 * moduler.
 */

require_once __DIR__ . '/functions.php';

/**
 * Henter ét udstyr ved id, inden for brugerens organisation, med den ansvarliges
 * navn. Returnerer null hvis ikke fundet. Erstatter det næsten-identiske opslag der
 * var skrevet i new_calibration.php (uden join — samme WHERE, samme organisationsscope).
 */
function equipment_find(PDO $db, int $id, int $organizationId): ?array {
    $stmt = $db->prepare('SELECT e.*, u.name AS responsible_name FROM equipment e JOIN users u ON u.id = e.responsible_id WHERE e.id = ? AND e.organization_id = ?');
    $stmt->execute([$id, $organizationId]);
    $equipment = $stmt->fetch();
    return $equipment ?: null;
}

/**
 * Udstyrslisten til udstyrsoversigten (equipment.php): kritikalitets-/status-filtre,
 * sortering, og den seneste kalibrerings udfald pr. udstyr (korreleret underforespørgsel
 * mod equipment_calibrations, så vi kan vise en "ikke godkendt"-advarsel i listen).
 *
 * $filters-nøgler (alle valgfrie):
 *   criticality, statuses  (string[]) OR'et sammen hver for sig
 *   sortColumn             (string)   en allerede valideret SQL-kolonne (fx 'e.name')
 *   sortDirection           (string)   'ASC' eller 'DESC'
 */
function equipment_list(PDO $db, array $user, array $filters = []): array {
    $sql = '
        SELECT e.*, u.name AS responsible_name, latest.outcome AS latest_outcome
        FROM equipment e
        JOIN users u ON u.id = e.responsible_id
        LEFT JOIN (
            SELECT c1.equipment_id, c1.outcome
            FROM equipment_calibrations c1
            INNER JOIN (
                SELECT equipment_id, MAX(calibration_date) AS max_date
                FROM equipment_calibrations
                GROUP BY equipment_id
            ) c2 ON c2.equipment_id = c1.equipment_id AND c2.max_date = c1.calibration_date
        ) latest ON latest.equipment_id = e.id
        WHERE e.organization_id = ?
    ';
    $params = [$user['organization_id']];

    $criticality = array_values(array_filter($filters['criticality'] ?? []));
    if ($criticality) {
        $sql .= ' AND e.criticality IN (' . implode(',', array_fill(0, count($criticality), '?')) . ')';
        $params = array_merge($params, $criticality);
    }

    $statuses = array_values(array_filter($filters['statuses'] ?? []));
    if ($statuses) {
        $sql .= ' AND e.status IN (' . implode(',', array_fill(0, count($statuses), '?')) . ')';
        $params = array_merge($params, $statuses);
    }

    $sortColumn = $filters['sortColumn'] ?? 'e.name';
    $sortDirection = (($filters['sortDirection'] ?? 'ASC') === 'DESC') ? 'DESC' : 'ASC';
    $sql .= " ORDER BY {$sortColumn} {$sortDirection}";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/**
 * Stat-grid-tallene på udstyrsoversigten (equipment.php): samlet antal, antal der
 * snart skal kalibreres, og antal med en seneste kalibrering der ikke blev godkendt —
 * to forespørgsler, samlet i ét svar, fordi de hører til samme stat-grid (samme
 * mønster som audit_stats()). Organisationens samlede tal, uanset rolle.
 */
function equipment_stats(PDO $db, int $organizationId): array {
    $stmt = $db->prepare("
        SELECT
            COUNT(*) AS total,
            SUM(status = 'Aktiv' AND requires_calibration = 1 AND next_calibration_date IS NOT NULL AND next_calibration_date <= CURDATE() + INTERVAL 60 DAY) AS due_soon
        FROM equipment
        WHERE organization_id = ?
    ");
    $stmt->execute([$organizationId]);
    $stats = $stmt->fetch();

    $stmt = $db->prepare("
        SELECT COUNT(*) AS failed_count
        FROM equipment e
        WHERE e.organization_id = ? AND e.status = 'Aktiv' AND EXISTS (
            SELECT 1 FROM equipment_calibrations c
            WHERE c.equipment_id = e.id AND c.outcome = 'Ikke godkendt'
                AND c.calibration_date = (SELECT MAX(calibration_date) FROM equipment_calibrations c2 WHERE c2.equipment_id = e.id)
        )
    ");
    $stmt->execute([$organizationId]);
    $failedStats = $stmt->fetch();

    return [
        'total' => $stats['total'] ?? 0,
        'due_soon' => $stats['due_soon'] ?? 0,
        'failed_count' => $failedStats['failed_count'] ?? 0,
    ];
}
