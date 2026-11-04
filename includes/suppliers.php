<?php

/**
 * Leverandør-repository: fjerde modul i data-/servicelaget — se includes/documents.php,
 * includes/cases.php og includes/audits.php for de tre første, og CLAUDE.md for den
 * overordnede plan (modul for modul, ikke ét stort ryk).
 *
 * I modsætning til de tre andre moduler har leverandører ingen GQAR-synlighedsregel —
 * `suppliers.php`/`supplier_detail.php` bruger `require_permission()`, ikke
 * `require_permission_allow_gqar()`, så GQAR har slet ikke adgang til dette modul.
 * Der er derfor ingen `supplier_visibility_sql()`/`supplier_is_visible()`-modpart til
 * dem i de andre moduler.
 *
 * De øvrige, mindre leverandør-forespørgsler (den simple dropdown-liste og
 * eksistens-tjek i new_case.php, delete_supplier.php, includes/functions.php) er
 * bevidst IKKE flyttet herind — de forekommer hver kun ét sted, så der er ingen
 * duplikering at rydde op på, kun kompleksitet at tilføje.
 */

require_once __DIR__ . '/functions.php';

/**
 * Henter én leverandør ved id, inden for brugerens organisation, med ejerens navn.
 * Returnerer null hvis ikke fundet.
 */
function supplier_find(PDO $db, int $id, int $organizationId): ?array {
    $stmt = $db->prepare('SELECT s.*, u.name AS owner_name FROM suppliers s JOIN users u ON u.id = s.owner_id WHERE s.id = ? AND s.organization_id = ?');
    $stmt->execute([$id, $organizationId]);
    $supplier = $stmt->fetch();
    return $supplier ?: null;
}

/**
 * Leverandørlisten til leverandøroversigten (suppliers.php): kategori-/kritikalitets-/
 * status-filtre og sortering.
 *
 * $filters-nøgler (alle valgfrie):
 *   categories, criticality, statuses  (string[]) OR'et sammen hver for sig
 *   sortColumn                         (string)   en allerede valideret SQL-kolonne (fx 's.name')
 *   sortDirection                      (string)   'ASC' eller 'DESC'
 */
function supplier_list(PDO $db, array $user, array $filters = []): array {
    $sql = 'SELECT s.* FROM suppliers s WHERE s.organization_id = ?';
    $params = [$user['organization_id']];

    $categories = array_values(array_filter($filters['categories'] ?? []));
    if ($categories) {
        $sql .= ' AND s.category IN (' . implode(',', array_fill(0, count($categories), '?')) . ')';
        $params = array_merge($params, $categories);
    }

    $criticality = array_values(array_filter($filters['criticality'] ?? []));
    if ($criticality) {
        $sql .= ' AND s.criticality IN (' . implode(',', array_fill(0, count($criticality), '?')) . ')';
        $params = array_merge($params, $criticality);
    }

    $statuses = array_values(array_filter($filters['statuses'] ?? []));
    if ($statuses) {
        $sql .= ' AND s.status IN (' . implode(',', array_fill(0, count($statuses), '?')) . ')';
        $params = array_merge($params, $statuses);
    }

    $sortColumn = $filters['sortColumn'] ?? 's.name';
    $sortDirection = (($filters['sortDirection'] ?? 'ASC') === 'DESC') ? 'DESC' : 'ASC';
    $sql .= " ORDER BY {$sortColumn} {$sortDirection}";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/**
 * Stat-grid-tallene på leverandøroversigten (suppliers.php): samlet antal og antal
 * der snart skal revurderes. Organisationens samlede tal, uanset rolle, ligesom
 * document_stats()/case_stats()/audit_stats().
 */
function supplier_stats(PDO $db, int $organizationId): array {
    $stmt = $db->prepare("
        SELECT
            COUNT(*) AS total,
            SUM(status = 'Godkendt' AND next_evaluation_date IS NOT NULL AND next_evaluation_date <= CURDATE() + INTERVAL 60 DAY) AS due_soon
        FROM suppliers WHERE organization_id = ?
    ");
    $stmt->execute([$organizationId]);
    return $stmt->fetch();
}
