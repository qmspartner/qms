<?php

/**
 * Underaktions-repository ("handlingsplaner med underaktioner", Fase 1: Sager).
 *
 * Et "action item" er én sporbar delhandling (ejer, deadline, status, bevis) under en sag.
 * Fra og med denne udvidelse ERSTATTER underaktionerne fritekstfelterne
 * cases.corrective_action/preventive_action i selve redigeringsformularen (begge kolonner
 * bevares uændret i databasen, de bruges blot ikke længere) — hver underaktion hører til
 * én af de to kategorier (action_items.category), og kan have et vedhæftet bevis for at
 * den faktisk er udført (action_item_evidence, ét bilag ad gangen, et nyt upload erstatter
 * det forrige).
 *
 * For de sagstyper der kræver lukningsdokumentation ($requiresClosureDocumentation i
 * case.php: Afvigelse, Audit fund, Kundeklage, Leverandør reklamation) kan en underaktion
 * kun afsluttes ('Afsluttet') hvis den har et vedhæftet bevis — ELLER markeres 'N/A' med en
 * påkrævet, logget begrundelse (samme mønster som fratrædelsens påkrævede, loggede
 * begrundelse, se can_manage_employee()/offboard_employee.php). For de øvrige sagstyper
 * (Forslag, Observation, Mistænkt forfalsket del) er intet af dette håndhævet — status kan
 * sættes til 'Afsluttet' uden bevis, og sagen kan lukkes uden underaktioner overhovedet.
 * Selve håndhævelsen (hvornår bevis/N/A kræves) ligger bevidst i case.php, ikke her — dette
 * modul er en ren, forælder-agnostisk repository-funktion-samling.
 *
 * action_items.case_id er i dag altid sat (Fase 1 har kun denne ene forælder-type). En
 * senere fase (Risici, Ledelsens evaluering) tilføjer flere nullable forælder-kolonner
 * efter samme mønster, og disse funktioner får da en matchende $riskId/$reviewVersionId-
 * variant — ikke ét generisk parent_type/parent_id, for at bevare rigtige FK-constraints
 * (se migrations/013_underaktioner.sql og migrations/014_underaktioner_kategori_bevis.sql).
 */

require_once __DIR__ . '/functions.php';

/**
 * Alle underaktioner for én sag, nyeste-åbne-først derefter position, med ejerens navn.
 * $category er valgfri — uden den returneres begge kategorier samlet (bruges af
 * action_item_has_open_for_case() og "Mine opgaver"); case.php kalder den altid med en
 * kategori for selve visningen, da Korrigerende/Forebyggende nu er to adskilte lister.
 */
function action_item_list_for_case(PDO $db, int $caseId, ?string $category = null): array {
    $sql = '
        SELECT ai.*, u.name AS owner_name
        FROM action_items ai
        LEFT JOIN users u ON u.id = ai.owner_user_id
        WHERE ai.case_id = ?
    ';
    $params = [$caseId];
    if ($category !== null) {
        $sql .= ' AND ai.category = ?';
        $params[] = $category;
    }
    $sql .= ' ORDER BY (ai.status = \'Afsluttet\' OR ai.status = \'N/A\'), ai.position, ai.id';

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/**
 * Bruges til at blokere lukning af sagen (case.php) — ren eksistens-tjek, ingen rækker
 * hentes unødigt. 'N/A' tæller som resolveret, ligesom 'Afsluttet' — kun 'Åben' blokerer.
 */
function action_item_has_open_for_case(PDO $db, int $caseId): bool {
    $stmt = $db->prepare("SELECT 1 FROM action_items WHERE case_id = ? AND status = 'Åben' LIMIT 1");
    $stmt->execute([$caseId]);
    return (bool) $stmt->fetchColumn();
}

/**
 * Antal underaktioner pr. kategori for en sag — bruges af case.php til at håndhæve, at en
 * sag der kræver lukningsdokumentation skal have MINDST ÉN underaktion i hver kategori
 * (svarer til den tidligere "korrigerende kræver forebyggende"-regel, nu anvendt på
 * eksistensen af underaktioner i stedet for fritekst).
 */
function action_item_category_counts_for_case(PDO $db, int $caseId): array {
    $stmt = $db->prepare('SELECT category, COUNT(*) AS antal FROM action_items WHERE case_id = ? GROUP BY category');
    $stmt->execute([$caseId]);
    $counts = ['Korrigerende' => 0, 'Forebyggende' => 0];
    foreach ($stmt->fetchAll() as $row) {
        $counts[$row['category']] = (int) $row['antal'];
    }
    return $counts;
}

function action_item_add(PDO $db, int $organizationId, int $caseId, string $category, string $title, ?int $ownerUserId, ?string $dueDate, int $createdBy): void {
    $stmt = $db->prepare('SELECT COALESCE(MAX(position), -1) + 1 FROM action_items WHERE case_id = ?');
    $stmt->execute([$caseId]);
    $nextPosition = (int) $stmt->fetchColumn();

    $stmt = $db->prepare('
        INSERT INTO action_items (organization_id, case_id, category, position, title, owner_user_id, due_date, created_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ');
    $stmt->execute([$organizationId, $caseId, $category, $nextPosition, $title, $ownerUserId ?: null, $dueDate ?: null, $createdBy]);
}

/**
 * Markér en underaktion afsluttet. Kalderen (case.php) har allerede afgjort, om bevis er
 * påkrævet for denne sagstype, og i givet fald tjekket at det findes — denne funktion gør
 * blot opdateringen og rydder et evt. tidligere N/A-mærke op.
 */
function action_item_close(PDO $db, int $id, int $organizationId): void {
    $stmt = $db->prepare("
        UPDATE action_items
        SET status = 'Afsluttet', completed_at = ?, na_reason = NULL, na_logged_by = NULL, na_logged_at = NULL
        WHERE id = ? AND organization_id = ?
    ");
    $stmt->execute([date('Y-m-d H:i:s'), $id, $organizationId]);
}

function action_item_reopen(PDO $db, int $id, int $organizationId): void {
    $stmt = $db->prepare("
        UPDATE action_items
        SET status = 'Åben', completed_at = NULL, na_reason = NULL, na_logged_by = NULL, na_logged_at = NULL
        WHERE id = ? AND organization_id = ?
    ");
    $stmt->execute([$id, $organizationId]);
}

/**
 * Markér en underaktion 'N/A' (ikke relevant) med en påkrævet, logget begrundelse — det
 * alternativ til bevis, som blokerende sagstyper også accepterer ved lukning.
 */
function action_item_mark_na(PDO $db, int $id, int $organizationId, string $reason, int $loggedBy): void {
    $stmt = $db->prepare("
        UPDATE action_items
        SET status = 'N/A', completed_at = NULL, na_reason = ?, na_logged_by = ?, na_logged_at = ?
        WHERE id = ? AND organization_id = ?
    ");
    $stmt->execute([$reason, $loggedBy, date('Y-m-d H:i:s'), $id, $organizationId]);
}

function action_item_delete(PDO $db, int $id, int $organizationId): void {
    $stmt = $db->prepare('DELETE FROM action_items WHERE id = ? AND organization_id = ?');
    $stmt->execute([$id, $organizationId]);
}

/**
 * Seneste bevis for én underaktion, eller false hvis intet er uploadet endnu.
 */
function action_item_evidence_find_for_item(PDO $db, int $actionItemId): array|false {
    $stmt = $db->prepare('
        SELECT e.*, u.name AS uploaded_by_name
        FROM action_item_evidence e
        JOIN users u ON u.id = e.uploaded_by
        WHERE e.action_item_id = ?
        ORDER BY e.uploaded_at DESC
        LIMIT 1
    ');
    $stmt->execute([$actionItemId]);
    return $stmt->fetch();
}

function action_item_evidence_add(PDO $db, int $organizationId, int $actionItemId, string $storedFilename, string $originalFilename, int $uploadedBy): void {
    $stmt = $db->prepare('
        INSERT INTO action_item_evidence (organization_id, action_item_id, stored_filename, original_filename, uploaded_by)
        VALUES (?, ?, ?, ?, ?)
    ');
    $stmt->execute([$organizationId, $actionItemId, $storedFilename, $originalFilename, $uploadedBy]);
}

/**
 * Sletter ALLE tidligere bevis-rækker for en underaktion (i dag højst én) og returnerer
 * deres lagrede filnavne, så kalderen (case.php) kan slette selve filerne fra disk — denne
 * funktion rører ikke filsystemet selv, i tråd med at andre moduler heller ikke gør det
 * (se new_case.php's egen move_uploaded_file()-håndtering uden for cases.php).
 */
function action_item_evidence_delete_for_item(PDO $db, int $actionItemId, int $organizationId): array {
    $stmt = $db->prepare('SELECT stored_filename FROM action_item_evidence WHERE action_item_id = ? AND organization_id = ?');
    $stmt->execute([$actionItemId, $organizationId]);
    $filenames = array_column($stmt->fetchAll(), 'stored_filename');

    $stmt = $db->prepare('DELETE FROM action_item_evidence WHERE action_item_id = ? AND organization_id = ?');
    $stmt->execute([$actionItemId, $organizationId]);

    return $filenames;
}

/**
 * Åbne underaktioner med deadline, til "Mine opgaver" (includes/tasks.php) — ejerens
 * egne, på tværs af alle sager i organisationen. Underaktioner uden deadline eller uden
 * ejer surfaces bevidst ikke her (intet at forfalde mod / ingen at vise den for).
 */
function action_items_for_owner_tasks(PDO $db, int $organizationId, int $ownerUserId): array {
    $stmt = $db->prepare("
        SELECT ai.id, ai.title, ai.due_date, c.id AS case_id, c.title AS case_title
        FROM action_items ai
        JOIN cases c ON c.id = ai.case_id
        WHERE ai.organization_id = ? AND ai.owner_user_id = ? AND ai.status = 'Åben' AND ai.due_date IS NOT NULL
    ");
    $stmt->execute([$organizationId, $ownerUserId]);
    return $stmt->fetchAll();
}
