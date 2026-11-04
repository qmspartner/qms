<?php

/**
 * Kvalitetsmål-repository (ISO 9001 §6.2 kvalitetsmål / §9.1.2 kundetilfredshed som
 * målbar, tidsserie-baseret data i stedet for fritekst i ledelsens evaluering).
 *
 * Modulet har sin egen rolle, "Ledelse" (role.ledelse) — kun den og Administrator kan
 * se/redigere kvalitetsmål, via det almindelige tilladelses-modul 'quality_objectives'
 * (require_permission(), ingen GQAR-adgang, samme som udstyr/leverandører/kunder).
 *
 * De rene periode-/påmindelsesfunktioner nederst (quality_objective_period_ends_until(),
 * quality_objective_next_due_period()) tager ingen PDO og er brugt begge steder en
 * kommende måling skal opdages: i includes/tasks.php (live, ved hvert sidevisning) og i
 * send_quality_objective_reminders.php (et dagligt cron-job, der sender selve
 * e-mailen — se migrations/README.md og CLAUDE.md for hvordan det sættes op på
 * webhotellet). quality_objective_next_due_period() tager selv højde for
 * påmindelsesfristen (den leder kun efter perioder op til $asOf + reminder_lead_days),
 * så "returnerer en periode" OG "perioden er inden for påmindelsesvinduet" er samme ting.
 */

require_once __DIR__ . '/functions.php';

function quality_objective_find(PDO $db, int $id, int $organizationId): ?array {
    $stmt = $db->prepare('
        SELECT qo.*, u.name AS owner_name, u.email AS owner_email
        FROM quality_objectives qo
        JOIN users u ON u.id = qo.owner_user_id
        WHERE qo.id = ? AND qo.organization_id = ?
    ');
    $stmt->execute([$id, $organizationId]);
    $objective = $stmt->fetch();
    return $objective ?: null;
}

/**
 * Kvalitetsmål-listen til oversigten (quality_objectives.php): status-filter og
 * sortering. Ingen GQAR-synlighedsregel (modulet bruger require_permission(), ikke
 * require_permission_allow_gqar()).
 *
 * $filters-nøgler (alle valgfrie):
 *   statuses      (string[]) status-værdier, OR'et sammen
 *   sortColumn    (string)   en allerede valideret SQL-kolonne (fx 'qo.title')
 *   sortDirection (string)   'ASC' eller 'DESC'
 */
function quality_objective_list(PDO $db, array $user, array $filters = []): array {
    $sql = 'SELECT qo.*, u.name AS owner_name FROM quality_objectives qo JOIN users u ON u.id = qo.owner_user_id WHERE qo.organization_id = ?';
    $params = [$user['organization_id']];

    $statuses = array_values(array_filter($filters['statuses'] ?? []));
    if ($statuses) {
        $sql .= ' AND qo.status IN (' . implode(',', array_fill(0, count($statuses), '?')) . ')';
        $params = array_merge($params, $statuses);
    }

    $sortColumn = $filters['sortColumn'] ?? 'qo.title';
    $sortDirection = (($filters['sortDirection'] ?? 'ASC') === 'DESC') ? 'DESC' : 'ASC';
    $sql .= " ORDER BY {$sortColumn} {$sortDirection}";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/**
 * Stat-grid-tallene på kvalitetsmål-oversigten: aktive mål, opnået i år, og ikke på
 * kurs — organisationens samlede tal, uanset rolle (kun Ledelse/admin ser siden
 * overhovedet, men funktionen følger samme "ingen rolle-filtrering her" princip som
 * de andre moduler).
 */
function quality_objective_stats(PDO $db, int $organizationId): array {
    $stmt = $db->prepare("
        SELECT
            SUM(status IN ('Ny', 'På kurs', 'Ikke på kurs')) AS active,
            SUM(status = 'Opnået' AND YEAR(updated_at) = YEAR(CURDATE())) AS achieved_ytd,
            SUM(status = 'Ikke på kurs') AS off_track
        FROM quality_objectives
        WHERE organization_id = ?
    ");
    $stmt->execute([$organizationId]);
    return $stmt->fetch();
}

/**
 * Målingslog for ét kvalitetsmål, nyeste periode først — bruges på detaljesiden.
 */
function quality_objective_measurement_list(PDO $db, int $objectiveId): array {
    $stmt = $db->prepare('
        SELECT m.*, u.name AS recorded_by_name
        FROM quality_objective_measurements m
        JOIN users u ON u.id = m.recorded_by
        WHERE m.objective_id = ?
        ORDER BY m.period_end DESC
    ');
    $stmt->execute([$objectiveId]);
    return $stmt->fetchAll();
}

/**
 * Registrerer en ny måling for en given periode. UNIQUE-indekset på
 * (objective_id, period_end) forhindrer at samme periode indberettes to gange —
 * kaldestedet bør tjekke det selv (quality_objective_detail.php) og vise en pæn
 * fejl i stedet for at lade en PDOException boble op.
 */
function quality_objective_measurement_add(PDO $db, int $objectiveId, string $periodEnd, float $value, ?string $note, int $recordedBy): void {
    $stmt = $db->prepare('
        INSERT INTO quality_objective_measurements (objective_id, period_end, value, note, recorded_by)
        VALUES (?, ?, ?, ?, ?)
    ');
    $stmt->execute([$objectiveId, $periodEnd, $value, $note ?: null, $recordedBy]);
}

/**
 * Alle periode-afslutningsdatoer for et mål, fra måledatoen og frem — bruges til at
 * vide hvilke perioder der allerede findes (så vi kan tilbyde dem i en dropdown på
 * detaljesiden, fx).
 */
function quality_objective_all_period_ends(PDO $db, int $objectiveId): array {
    $stmt = $db->prepare('SELECT period_end FROM quality_objective_measurements WHERE objective_id = ?');
    $stmt->execute([$objectiveId]);
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

// --- Rene periode-/påmindelsesfunktioner (ingen PDO, ingen database) ----------------

/**
 * Hvor stort et skridt frem er "én periode" for en given målefrekvens, som et
 * DateInterval-spec-streng (bruges af DateInterval).
 */
function quality_objective_period_step(string $frequency): string {
    return match ($frequency) {
        'Ugentligt' => 'P7D',
        'Månedligt' => 'P1M',
        default => 'P3M', // Kvartalsvist
    };
}

/**
 * Alle periode-afslutningsdatoer (Y-m-d) fra den FØRSTE periode efter $startDate og
 * frem til og med $until. Returnerer et TOMT array, hvis selv den første periode
 * slutter efter $until — det er bevidst: quality_objective_next_due_period() bruger
 * netop det til at afgøre at ingen periode er inden for påmindelsesvinduet endnu.
 * (quality_objective_detail.php's brug af [0]-indekset håndterer selv et tomt array
 * via ??.) $guard begrænser løkken (maks. ~38 år ugentligt), så en fejlagtig/meget
 * gammel $startDate ikke kan give en uendelig eller urimelig stor løkke.
 */
function quality_objective_period_ends_until(string $frequency, string $startDate, string $until): array {
    $step = quality_objective_period_step($frequency);
    $cursor = (new DateTimeImmutable($startDate))->add(new DateInterval($step));
    $untilDate = new DateTimeImmutable($until);
    $ends = [];
    $guard = 0;
    while ($cursor <= $untilDate && $guard < 2000) {
        $ends[] = $cursor->format('Y-m-d');
        $cursor = $cursor->add(new DateInterval($step));
        $guard++;
    }
    return $ends;
}

/**
 * Den NÆSTE periode, der endnu ikke er indberettet en måling for, inden for
 * $reminderLeadDays dage af $asOf (dækker både "lige ved at forfalde" og allerede
 * overskredne, uindberettede perioder). Returnerer null hvis der ikke er nogen periode
 * der kræver opmærksomhed endnu (dvs. næste periode ligger længere ude end
 * påmindelses-vinduet).
 *
 * $measuredPeriodEnds er listen af allerede indberettede period_end-datoer (Y-m-d),
 * fx fra quality_objective_all_period_ends().
 */
function quality_objective_next_due_period(string $frequency, string $startDate, array $measuredPeriodEnds, string $asOf, int $reminderLeadDays): ?string {
    $horizon = (new DateTimeImmutable($asOf))->add(new DateInterval('P' . max(0, $reminderLeadDays) . 'D'))->format('Y-m-d');
    $candidates = quality_objective_period_ends_until($frequency, $startDate, $horizon);
    $measured = array_flip($measuredPeriodEnds);
    foreach ($candidates as $periodEnd) {
        if (!isset($measured[$periodEnd])) {
            return $periodEnd;
        }
    }
    return null;
}
