<?php

/**
 * Dokument-repository: ét sted for de forespørgsler mod `documents`, der ellers var
 * genskrevet i flere scriptfiler (index.php, document.php, new_audit.php, new_case.php).
 * Dette er det første skridt i at flytte forretnings-/dataadgangslogik ud af
 * scriptfilerne og ind i et rigtigt data-/servicelag, modul for modul — "dokumenter" er
 * det første modul. Ren SQL-indkapsling her; en side (fx document.php) beslutter selv
 * hvad den vil gøre med resultatet (404 vs. 403, rendering osv.).
 *
 * `download.php`s forespørgsel (document_versions JOIN documents) er bevidst IKKE
 * flyttet herind endnu — den har en anden form (version-centreret, ikke
 * dokument-centreret) og hører naturligt hjemme i et kommende "versions"-modul.
 * home.php's dashboard-statistik er også bevidst urørt (se CLAUDE.md/document_stats()
 * nedenfor) — den beregner et andet sæt tal på en anden måde pr. rolle.
 */

require_once __DIR__ . '/functions.php';

/**
 * Henter ét dokument ved id, inden for brugerens organisation. Returnerer null hvis
 * dokumentet ikke findes (eller hører til en anden organisation). Tjekker IKKE
 * document_is_visible() selv — kaldestedet skal selv kalde den efterfølgende, fordi
 * et "findes ikke" (404) og et "må ikke ses" (403) er to forskellige svar til brugeren.
 */
function document_find(PDO $db, int $id, int $organizationId): ?array {
    $stmt = $db->prepare('SELECT d.*, u.name AS owner_name FROM documents d JOIN users u ON u.id = d.owner_id WHERE d.id = ? AND d.organization_id = ?');
    $stmt->execute([$id, $organizationId]);
    $doc = $stmt->fetch();
    return $doc ?: null;
}

/**
 * Dokumentlisten til dokumentoversigten (index.php): søgning, kategori-/status-filtre,
 * skabelon-til/fra, og den centrale synlighedsregel (document_visibility_sql()).
 *
 * $filters-nøgler (alle valgfrie):
 *   search        (string)  fritekst mod titel/dok-nr.
 *   categories    (string[]) kategori-værdier, OR'et sammen
 *   statuses      (string[]) status-værdier, OR'et sammen
 *   showTemplates (bool)    vis skabeloner med (ellers udelukket)
 *   sortColumn    (string)  en allerede valideret SQL-kolonne (fx 'd.title') —
 *                           kaldestedet er ansvarlig for whitelisting mod brugerinput,
 *                           denne funktion sætter den rå streng direkte ind i SQL'en
 *   sortDirection (string)  'ASC' eller 'DESC'
 */
function document_list(PDO $db, array $user, array $filters = []): array {
    $sql = "SELECT d.*, u.name AS owner_name,
        NOT EXISTS (SELECT 1 FROM document_versions v WHERE v.document_id = d.id AND v.stored_filename IS NOT NULL) AS no_file
        FROM documents d JOIN users u ON u.id = d.owner_id WHERE d.organization_id = ?";
    $params = [$user['organization_id']];

    $search = trim($filters['search'] ?? '');
    if ($search !== '') {
        $sql .= ' AND (d.title LIKE ? OR d.doc_number LIKE ?)';
        $params[] = "%{$search}%";
        $params[] = "%{$search}%";
    }

    $categories = array_values(array_filter($filters['categories'] ?? []));
    if ($categories) {
        $sql .= ' AND d.category IN (' . implode(',', array_fill(0, count($categories), '?')) . ')';
        $params = array_merge($params, $categories);
    }

    $statuses = array_values(array_filter($filters['statuses'] ?? []));
    if ($statuses) {
        $sql .= ' AND d.status IN (' . implode(',', array_fill(0, count($statuses), '?')) . ')';
        $params = array_merge($params, $statuses);
    }

    [$visibilityClause, $visibilityParams] = document_visibility_sql($user);
    $sql .= $visibilityClause;
    $params = array_merge($params, $visibilityParams);

    if (empty($filters['showTemplates'])) {
        $sql .= ' AND d.is_template = 0';
    }

    $sortColumn = $filters['sortColumn'] ?? 'd.doc_number';
    $sortDirection = (($filters['sortDirection'] ?? 'ASC') === 'DESC') ? 'DESC' : 'ASC';
    $sql .= " ORDER BY {$sortColumn} {$sortDirection}";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/**
 * Stat-grid-tallene på dokumentoversigten (index.php): antal godkendte, afventende og
 * snart udløbende dokumenter for hele organisationen, uanset rolle. Hvilke af disse tal
 * der rent faktisk VISES til hvilken rolle er en UI-beslutning, truffet af kaldestedet
 * (se $canSeeWorkflowStats i index.php) — ikke noget denne funktion afgør.
 */
function document_stats(PDO $db, int $organizationId): array {
    $stmt = $db->prepare("
        SELECT
            SUM(status = 'Godkendt') AS active,
            SUM(status = 'Til godkendelse') AS pending,
            SUM(next_review_date IS NOT NULL AND next_review_date <= CURDATE() + INTERVAL 60 DAY) AS expiring
        FROM documents
        WHERE organization_id = ?
    ");
    $stmt->execute([$organizationId]);
    return $stmt->fetch();
}

/**
 * Dokumenter der kan linkes til fra et andet modul (en ny audit eller sag) — begrænset
 * til dem brugeren overhovedet må se, via samme centrale synlighedsregel som listen og
 * dokumentsiden.
 */
function document_list_for_linking(PDO $db, array $user): array {
    [$visibilityClause, $visibilityParams] = document_visibility_sql($user, 'documents');
    $sql = 'SELECT id, title, doc_number FROM documents WHERE organization_id = ?' . $visibilityClause . ' ORDER BY title';
    $params = array_merge([$user['organization_id']], $visibilityParams);
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/**
 * Validerer at $documentId findes, hører til brugerens organisation, OG er synligt for
 * brugeren (samme regel som document_list_for_linking()) — brugt når et link til et
 * dokument gemmes fra en formular (ny audit/sag). Returnerer id'et hvis gyldigt, ellers
 * null — kaldestedet skal behandle null som "intet link gemt", ikke som en fejl der
 * stopper hele submittet (et ugyldigt/usynligt dokument-id må ikke kunne vælges, men
 * skal heller ikke vælte resten af formularen).
 */
function document_validate_link(PDO $db, array $user, int $documentId): ?int {
    if (!$documentId) {
        return null;
    }
    [$visibilityClause, $visibilityParams] = document_visibility_sql($user, 'documents');
    $sql = 'SELECT id FROM documents WHERE id = ? AND organization_id = ?' . $visibilityClause;
    $params = array_merge([$documentId, $user['organization_id']], $visibilityParams);
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetch() ? $documentId : null;
}
