<?php

/**
 * Underaktions-repository ("handlingsplaner med underaktioner", Fase 1: Sager).
 *
 * Et "action item" er én sporbar delhandling (ejer, deadline, status) under en sag — et
 * lag ovenpå det eksisterende fritekstfelt cases.corrective_action, som IKKE ændres eller
 * fjernes: fritekst er fortsat opsummeringen, underaktionerne er det der kan tildeles,
 * forfalde og afsluttes enkeltvis, og som derfor kan surfaces i "Mine opgaver".
 *
 * action_items.case_id er i dag altid sat (Fase 1 har kun denne ene forælder-type). En
 * senere fase (Risici, Ledelsens evaluering) tilføjer flere nullable forælder-kolonner
 * efter samme mønster, og disse funktioner får da en matchende $riskId/$reviewVersionId-
 * variant — ikke ét generisk parent_type/parent_id, for at bevare rigtige FK-constraints
 * (se migrations/013_underaktioner.sql).
 */

require_once __DIR__ . '/functions.php';

/**
 * Alle underaktioner for én sag, nyeste-åbne-først derefter position, med ejerens navn.
 */
function action_item_list_for_case(PDO $db, int $caseId): array {
    $stmt = $db->prepare('
        SELECT ai.*, u.name AS owner_name
        FROM action_items ai
        LEFT JOIN users u ON u.id = ai.owner_user_id
        WHERE ai.case_id = ?
        ORDER BY (ai.status = \'Afsluttet\'), ai.position, ai.id
    ');
    $stmt->execute([$caseId]);
    return $stmt->fetchAll();
}

/**
 * Bruges til at blokere lukning af sagen (case.php) — ren eksistens-tjek, ingen rækker
 * hentes unødigt.
 */
function action_item_has_open_for_case(PDO $db, int $caseId): bool {
    $stmt = $db->prepare("SELECT 1 FROM action_items WHERE case_id = ? AND status = 'Åben' LIMIT 1");
    $stmt->execute([$caseId]);
    return (bool) $stmt->fetchColumn();
}

function action_item_add(PDO $db, int $organizationId, int $caseId, string $title, ?int $ownerUserId, ?string $dueDate, int $createdBy): void {
    $stmt = $db->prepare('SELECT COALESCE(MAX(position), -1) + 1 FROM action_items WHERE case_id = ?');
    $stmt->execute([$caseId]);
    $nextPosition = (int) $stmt->fetchColumn();

    $stmt = $db->prepare('
        INSERT INTO action_items (organization_id, case_id, position, title, owner_user_id, due_date, created_by)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ');
    $stmt->execute([$organizationId, $caseId, $nextPosition, $title, $ownerUserId ?: null, $dueDate ?: null, $createdBy]);
}

function action_item_set_status(PDO $db, int $id, int $organizationId, bool $closed): void {
    $stmt = $db->prepare('
        UPDATE action_items
        SET status = ?, completed_at = ?
        WHERE id = ? AND organization_id = ?
    ');
    $stmt->execute([$closed ? 'Afsluttet' : 'Åben', $closed ? date('Y-m-d H:i:s') : null, $id, $organizationId]);
}

function action_item_delete(PDO $db, int $id, int $organizationId): void {
    $stmt = $db->prepare('DELETE FROM action_items WHERE id = ? AND organization_id = ?');
    $stmt->execute([$id, $organizationId]);
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
