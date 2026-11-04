<?php
/**
 * Dagligt cron-job: sender e-mail-påmindelser til ejere af kvalitetsmål, når en
 * måleperiode nærmer sig (eller er overskredet) ifølge deres eget
 * reminder_lead_days. Ingen session, ingen login - køres fra kommandolinjen
 * (cPanel Cron Job), IKKE via browseren. Se CLAUDE.md for opsætning af selve
 * cron-jobbet på webhotellet.
 *
 * Dedup: en e-mail sendes kun én gang pr. periode pr. mål -
 * quality_objectives.last_reminder_sent_period_end gemmer den sidste periode,
 * der er sendt påmindelse for, og jobbet springer over hvis den allerede matcher.
 *
 * Kør manuelt for test: php send_quality_objective_reminders.php
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('Dette script må kun køres fra kommandolinjen (cron), ikke via browseren.');
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/notifications.php';
require_once __DIR__ . '/includes/quality_objectives.php';

$db = get_db();

$stmt = $db->prepare("
    SELECT qo.*, u.name AS owner_name, u.email AS owner_email
    FROM quality_objectives qo
    JOIN users u ON u.id = qo.owner_user_id
    WHERE qo.status NOT IN ('Opnået', 'Opgivet')
");
$stmt->execute();
$objectives = $stmt->fetchAll();

$sent = 0;
$checked = 0;

foreach ($objectives as $objective) {
    $checked++;
    $measuredPeriodEnds = quality_objective_all_period_ends($db, (int) $objective['id']);
    $nextDue = quality_objective_next_due_period(
        $objective['measurement_frequency'],
        $objective['start_date'],
        $measuredPeriodEnds,
        date('Y-m-d'),
        (int) $objective['reminder_lead_days']
    );

    if ($nextDue === null) {
        continue;
    }

    // Allerede sendt påmindelse for netop denne periode - spring over, så
    // ejeren ikke får en ny e-mail hver nat indtil målingen bliver indberettet.
    if ($objective['last_reminder_sent_period_end'] === $nextDue) {
        continue;
    }

    $overdue = $nextDue < date('Y-m-d');
    notify_quality_objective_reminder(
        ['name' => $objective['owner_name'], 'email' => $objective['owner_email']],
        (int) $objective['id'],
        $objective['title'],
        $nextDue,
        $overdue
    );

    $update = $db->prepare('UPDATE quality_objectives SET last_reminder_sent_period_end = ? WHERE id = ?');
    $update->execute([$nextDue, $objective['id']]);
    $sent++;
}

echo "Kvalitetsmål-påmindelser: {$checked} mål tjekket, {$sent} påmindelse(r) sendt.\n";
