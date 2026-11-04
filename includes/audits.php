<?php

/**
 * Audit-repository: tredje modul i data-/servicelaget — se includes/documents.php
 * for det første og includes/cases.php for det andet, og CLAUDE.md for den
 * overordnede plan (modul for modul, ikke ét stort ryk).
 *
 * Samme mønster som sager: ingen "almindelig bruger ser mindre"-regel, kun GQAR er
 * begrænset — til EKSTERNE audits (audit_type = 'Eksternt'), inden for egen kontrakt.
 *
 * home.php's og contract_overview.php's statistik bruger samme grundregel i en anden
 * forespørgselsform (aggregerede tal, andre formål) og er bevidst IKKE flyttet herind,
 * samme afgrænsning som for document_stats()/case_stats() i de andre moduler.
 */

require_once __DIR__ . '/functions.php';

/**
 * Hvilke audits må $user se, som SQL-fragment + bind-parametre (samme kontrakt som
 * document_visibility_sql()/case_visibility_sql()). Tom streng/ingen parametre for
 * fuld adgang.
 */
function audit_visibility_sql(array $user, string $alias = 'a'): array {
    if (is_gqar($user)) {
        [$clause, $params] = contract_in_clause("{$alias}.contract_reference", $user['gqar_contract_references']);
        return [" AND {$alias}.audit_type = 'Eksternt' AND {$clause}", $params];
    }
    return ['', []];
}

/**
 * Samme regel som audit_visibility_sql(), men for én allerede hentet audit.
 */
function audit_is_visible(array $user, array $audit): bool {
    if (is_gqar($user)) {
        return ($audit['audit_type'] ?? null) === 'Eksternt'
            && user_has_contract($user, $audit['contract_reference'] ?? null);
    }
    return true;
}

/**
 * Henter én audit ved id, inden for brugerens organisation, med auditor-navn og et
 * evt. linket dokuments titel/nummer. Returnerer null hvis ikke fundet. Tjekker IKKE
 * audit_is_visible() selv — samme 404-vs-403-skel som document_find()/case_find().
 */
function audit_find(PDO $db, int $id, int $organizationId): ?array {
    $stmt = $db->prepare('
        SELECT a.*, u.name AS auditor_name, d.title AS linked_document_title, d.doc_number AS linked_document_number
        FROM audits a
        JOIN users u ON u.id = a.auditor_id
        LEFT JOIN documents d ON d.id = a.linked_document_id
        WHERE a.id = ? AND a.organization_id = ?
    ');
    $stmt->execute([$id, $organizationId]);
    $audit = $stmt->fetch();
    return $audit ?: null;
}

/**
 * Auditlisten til auditoversigten (audits.php): status-/audittype-filtre og den
 * centrale GQAR-synlighedsregel.
 *
 * $filters-nøgler (alle valgfrie):
 *   statuses, types  (string[]) OR'et sammen hver for sig
 *   sortColumn       (string)   en allerede valideret SQL-kolonne (fx 'a.planned_date')
 *   sortDirection    (string)   'ASC' eller 'DESC'
 */
function audit_list(PDO $db, array $user, array $filters = []): array {
    $sql = '
        SELECT a.*, u.name AS auditor_name
        FROM audits a
        JOIN users u ON u.id = a.auditor_id
        WHERE a.organization_id = ?
    ';
    $params = [$user['organization_id']];

    $statuses = array_values(array_filter($filters['statuses'] ?? []));
    if ($statuses) {
        $sql .= ' AND a.status IN (' . implode(',', array_fill(0, count($statuses), '?')) . ')';
        $params = array_merge($params, $statuses);
    }

    $types = array_values(array_filter($filters['types'] ?? []));
    if ($types) {
        $sql .= ' AND a.audit_type IN (' . implode(',', array_fill(0, count($types), '?')) . ')';
        $params = array_merge($params, $types);
    }

    [$visibilityClause, $visibilityParams] = audit_visibility_sql($user);
    $sql .= $visibilityClause;
    $params = array_merge($params, $visibilityParams);

    $sortColumn = $filters['sortColumn'] ?? 'a.planned_date';
    $sortDirection = (($filters['sortDirection'] ?? 'DESC') === 'ASC') ? 'ASC' : 'DESC';
    $sql .= " ORDER BY {$sortColumn} {$sortDirection}";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/**
 * Stat-grid-tallene på auditoversigten (audits.php): antal planlagte audits i år
 * (audits-tabellen) og antal åbne fund fra audits (cases-tabellen, hvor audit_id er
 * sat og status ikke er 'Lukket') — to forespørgsler mod to tabeller, samlet i ét
 * svar fordi de hører til samme stat-grid. Organisationens samlede tal, uanset
 * rolle, ligesom document_stats()/case_stats().
 */
function audit_stats(PDO $db, int $organizationId): array {
    $stmt = $db->prepare("SELECT SUM(YEAR(planned_date) = YEAR(CURDATE())) AS planned_this_year FROM audits WHERE organization_id = ?");
    $stmt->execute([$organizationId]);
    $plannedStats = $stmt->fetch();

    $stmt = $db->prepare("SELECT COUNT(*) AS open_findings FROM cases WHERE organization_id = ? AND audit_id IS NOT NULL AND status != 'Lukket'");
    $stmt->execute([$organizationId]);
    $findingStats = $stmt->fetch();

    return [
        'planned_this_year' => $plannedStats['planned_this_year'] ?? 0,
        'open_findings' => $findingStats['open_findings'] ?? 0,
    ];
}
