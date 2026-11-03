<?php

/**
 * Sags-repository: ét sted for de forespørgsler mod `cases`, der ellers var genskrevet
 * i cases.php og case.php. Andet modul i data-/servicelaget — se includes/documents.php
 * for det første og CLAUDE.md for den overordnede plan (modul for modul, ikke ét stort
 * ryk).
 *
 * I modsætning til dokumenter har sager ingen "almindelig bruger ser mindre"-regel —
 * den eneste indskrænkning er GQAR: en GQAR må kun se sager der kræver kundenotifikation
 * (requires_customer_notification = 1), og kun inden for egen kontrakt.
 *
 * home.php's og contract_overview.php's statistik bruger samme grundregel, men i en
 * anden forespørgselsform (aggregerede SUM()'er på tværs af andre tabeller/formål) og
 * er bevidst IKKE flyttet herind — samme afgrænsning som for document_stats()/home.php
 * i dokument-modulet.
 */

require_once __DIR__ . '/functions.php';

/**
 * Hvilke sager må $user se, som SQL-fragment + bind-parametre (samme kontrakt som
 * document_visibility_sql() i includes/functions.php). Tom streng/ingen parametre for
 * fuld adgang (alle ikke-GQAR-roller ser alle sager i deres organisation).
 */
function case_visibility_sql(array $user, string $alias = 'c'): array {
    if (is_gqar($user)) {
        [$clause, $params] = contract_in_clause("{$alias}.contract_reference", $user['gqar_contract_references']);
        return [" AND {$alias}.requires_customer_notification = 1 AND {$clause}", $params];
    }
    return ['', []];
}

/**
 * Samme regel som case_visibility_sql(), men for én allerede hentet sag.
 */
function case_is_visible(array $user, array $case): bool {
    if (is_gqar($user)) {
        return (bool) ($case['requires_customer_notification'] ?? false)
            && user_has_contract($user, $case['contract_reference'] ?? null);
    }
    return true;
}

/**
 * Henter én sag ved id, inden for brugerens organisation, med de tilknyttede navne
 * case.php viser (rapportør, ansvarlig, linket dokument/audit/leverandør/kunde/
 * kilde-optegnelse). Returnerer null hvis ikke fundet. Tjekker IKKE case_is_visible()
 * selv — kaldestedet skal selv kalde den efter, så "findes ikke" (404) og "må ikke ses"
 * (403) forbliver to forskellige svar, ligesom document_find()/document_is_visible().
 */
function case_find(PDO $db, int $id, int $organizationId): ?array {
    $stmt = $db->prepare('
        SELECT c.*, r.name AS reported_by_name, a.name AS assigned_to_name, d.title AS linked_document_title, d.doc_number AS linked_document_number,
               au.title AS linked_audit_title, sup.name AS linked_supplier_name, cust.name AS customer_name, sr.title AS source_record_title, sr.type AS source_record_type
        FROM cases c
        JOIN users r ON r.id = c.reported_by
        LEFT JOIN users a ON a.id = c.assigned_to
        LEFT JOIN documents d ON d.id = c.linked_document_id
        LEFT JOIN audits au ON au.id = c.audit_id
        LEFT JOIN suppliers sup ON sup.id = c.supplier_id
        LEFT JOIN customers cust ON cust.id = c.customer_id
        LEFT JOIN records sr ON sr.id = c.source_record_id
        WHERE c.id = ? AND c.organization_id = ?
    ');
    $stmt->execute([$id, $organizationId]);
    $case = $stmt->fetch();
    return $case ?: null;
}

/**
 * Sagslisten til sagsoversigten (cases.php): type-/sværhedsgrad-/status-filtre,
 * leverandør-filter, og den centrale GQAR-synlighedsregel. Udelukker altid
 * "Opfølgningspunkt" (en intern sags-undertype, der ikke vises på selve sagslisten).
 *
 * $filters-nøgler (alle valgfrie):
 *   types, severities, statuses  (string[]) OR'et sammen hver for sig
 *   supplierId                   (int)      begræns til én leverandørs sager
 *   sortColumn                   (string)   en allerede valideret SQL-kolonne (fx 'c.id')
 *   sortDirection                 (string)   'ASC' eller 'DESC'
 */
function case_list(PDO $db, array $user, array $filters = []): array {
    $sql = "
        SELECT c.*, r.name AS reported_by_name, a.name AS assigned_to_name
        FROM cases c
        JOIN users r ON r.id = c.reported_by
        LEFT JOIN users a ON a.id = c.assigned_to
        WHERE c.organization_id = ? AND c.type != 'Opfølgningspunkt'
    ";
    $params = [$user['organization_id']];

    $types = array_values(array_filter($filters['types'] ?? []));
    if ($types) {
        $sql .= ' AND c.type IN (' . implode(',', array_fill(0, count($types), '?')) . ')';
        $params = array_merge($params, $types);
    }

    $severities = array_values(array_filter($filters['severities'] ?? []));
    if ($severities) {
        $sql .= ' AND c.severity IN (' . implode(',', array_fill(0, count($severities), '?')) . ')';
        $params = array_merge($params, $severities);
    }

    $statuses = array_values(array_filter($filters['statuses'] ?? []));
    if ($statuses) {
        $sql .= ' AND c.status IN (' . implode(',', array_fill(0, count($statuses), '?')) . ')';
        $params = array_merge($params, $statuses);
    }

    $supplierId = (int) ($filters['supplierId'] ?? 0);
    if ($supplierId) {
        $sql .= ' AND c.supplier_id = ?';
        $params[] = $supplierId;
    }

    [$visibilityClause, $visibilityParams] = case_visibility_sql($user);
    $sql .= $visibilityClause;
    $params = array_merge($params, $visibilityParams);

    $sortColumn = $filters['sortColumn'] ?? 'c.id';
    $sortDirection = (($filters['sortDirection'] ?? 'DESC') === 'ASC') ? 'ASC' : 'DESC';
    $sql .= " ORDER BY {$sortColumn} {$sortDirection}";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/**
 * Stat-grid-tallene på sagsoversigten (cases.php) — organisationens samlede tal, uanset
 * rolle, med samme "Opfølgningspunkt"-udelukkelse som case_list(). Ligesom
 * document_stats() er dette ikke en synligheds-beslutning; det er kaldestedets ansvar
 * at afgøre hvilke af tallene der rent faktisk vises til hvem.
 */
function case_stats(PDO $db, int $organizationId): array {
    $stmt = $db->prepare("
        SELECT
            SUM(status != 'Lukket') AS open_count,
            SUM(type = 'Afvigelse') AS deviation_count,
            SUM(type = 'Forslag') AS suggestion_count,
            SUM(due_date IS NOT NULL AND due_date < CURDATE() AND status != 'Lukket') AS overdue_count,
            SUM(requires_customer_notification = 1 AND status != 'Lukket') AS notification_count,
            SUM(type = 'Kundeklage' AND status != 'Lukket') AS complaint_count,
            SUM(type = 'Mistænkt forfalsket del' AND status != 'Lukket') AS counterfeit_count,
            SUM(type = 'Leverandør reklamation' AND status != 'Lukket') AS supplier_complaint_count,
            SUM(status = 'Lukket' AND corrective_action IS NOT NULL AND corrective_action != '' AND effectiveness_result IS NULL AND effectiveness_review_due IS NULL) AS effectiveness_pending_date_count,
            SUM(status = 'Lukket' AND corrective_action IS NOT NULL AND corrective_action != '' AND effectiveness_result IS NULL AND effectiveness_review_due IS NOT NULL AND effectiveness_review_due <= CURDATE() + INTERVAL " . EFFECTIVENESS_DUE_SOON_DAYS . " DAY) AS effectiveness_due_count
        FROM cases
        WHERE organization_id = ? AND type != 'Opfølgningspunkt'
    ");
    $stmt->execute([$organizationId]);
    return $stmt->fetch();
}
