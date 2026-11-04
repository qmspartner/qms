<?php

/**
 * Kunde-repository: sjette modul i data-/servicelaget — se includes/documents.php,
 * includes/cases.php, includes/audits.php, includes/suppliers.php og
 * includes/employees.php for de fem første, og CLAUDE.md for den overordnede plan.
 *
 * Som leverandører har kunder ingen GQAR-synlighedsregel (require_permission(),
 * ikke require_permission_allow_gqar()). Duplikeringen her er mindre end i de andre
 * moduler — kun ét sted (edit_customer.php) genskrev customer_find()s forespørgsel
 * ordret — men modulet følger samme mønster som de øvrige for konsistens.
 *
 * De mindre, enkeltstående kunde-forespørgsler (dropdown-/eksistens-tjek i
 * new_case.php, delete_customer.php, og den fulde eksport i
 * export_customers_csv.php) er bevidst IKKE flyttet herind.
 */

require_once __DIR__ . '/functions.php';

/**
 * Henter én kunde ved id, inden for brugerens organisation. Returnerer null hvis ikke
 * fundet.
 */
function customer_find(PDO $db, int $id, int $organizationId): ?array {
    $stmt = $db->prepare('SELECT * FROM customers WHERE id = ? AND organization_id = ?');
    $stmt->execute([$id, $organizationId]);
    $customer = $stmt->fetch();
    return $customer ?: null;
}

/**
 * Kundelisten til kundeoversigten (customers.php): sortering (ingen filtre i dag).
 *
 * $filters-nøgler (alle valgfrie):
 *   sortColumn     (string)  en allerede valideret SQL-kolonne (fx 'name')
 *   sortDirection  (string)  'ASC' eller 'DESC'
 */
function customer_list(PDO $db, array $user, array $filters = []): array {
    $sortColumn = $filters['sortColumn'] ?? 'name';
    $sortDirection = (($filters['sortDirection'] ?? 'ASC') === 'DESC') ? 'DESC' : 'ASC';

    $stmt = $db->prepare("SELECT * FROM customers WHERE organization_id = ? ORDER BY {$sortColumn} {$sortDirection}");
    $stmt->execute([$user['organization_id']]);
    return $stmt->fetchAll();
}

/**
 * Stat-grid-tallet på kundeoversigten (customers.php): samlet antal kunder.
 */
function customer_stats(PDO $db, int $organizationId): array {
    $stmt = $db->prepare('SELECT COUNT(*) AS total FROM customers WHERE organization_id = ?');
    $stmt->execute([$organizationId]);
    return $stmt->fetch();
}
