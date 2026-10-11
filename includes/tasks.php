<?php
require_once __DIR__ . '/db.php';

/**
 * Samler den indloggede brugers opgaver på tværs af dokumenter og sager.
 * Returnerer en liste sorteret efter hastegrad: overskredne frister først,
 * derefter godkendelser, tildelte sager, og til sidst kommende revisioner.
 */
function get_my_tasks(array $user): array {
    $db = get_db();
    $orgId = $user['organization_id'];
    $userId = $user['id'];
    $tasks = [];

    $stmt = $db->prepare('
        SELECT d.id, d.title, d.doc_number
        FROM document_versions v
        JOIN documents d ON d.id = v.document_id
        WHERE v.assigned_approver_id = ? AND v.status = \'Til godkendelse\' AND d.organization_id = ?
    ');
    $stmt->execute([$userId, $orgId]);
    foreach ($stmt->fetchAll() as $doc) {
        $tasks[] = [
            'urgency' => 'accent',
            'icon' => 'ti-file-check',
            'label' => t('tasks.doc_pending'),
            'title' => $doc['doc_number'] . ' — ' . $doc['title'],
            'link' => 'document.php?id=' . $doc['id'],
            'sort' => 1,
        ];
    }

    $stmt = $db->prepare("
        SELECT id, title, due_date
        FROM cases
        WHERE assigned_to = ? AND organization_id = ? AND status != 'Lukket'
    ");
    $stmt->execute([$userId, $orgId]);
    foreach ($stmt->fetchAll() as $case) {
        $overdue = $case['due_date'] && $case['due_date'] < date('Y-m-d');
        $tasks[] = [
            'urgency' => $overdue ? 'danger' : 'warning',
            'icon' => $overdue ? 'ti-alert-triangle' : 'ti-user-check',
            'label' => $overdue ? t('tasks.case_overdue') : t('tasks.case_assigned'),
            'title' => $case['title'],
            'link' => 'case.php?id=' . $case['id'],
            'sort' => $overdue ? 0 : 2,
        ];
    }

    $stmt = $db->prepare("
        SELECT id, title, effectiveness_review_due, assigned_to, reported_by
        FROM cases
        WHERE organization_id = ? AND status = 'Lukket' AND corrective_action IS NOT NULL AND corrective_action != ''
            AND effectiveness_result IS NULL AND effectiveness_review_due IS NOT NULL
            AND effectiveness_review_due <= CURDATE() + INTERVAL " . EFFECTIVENESS_DUE_SOON_DAYS . " DAY
            AND COALESCE(assigned_to, reported_by) = ?
    ");
    $stmt->execute([$orgId, $userId]);
    foreach ($stmt->fetchAll() as $case) {
        $overdue = $case['effectiveness_review_due'] < date('Y-m-d');
        $tasks[] = [
            'urgency' => $overdue ? 'danger' : 'warning',
            'icon' => $overdue ? 'ti-alert-triangle' : 'ti-calendar-event',
            'label' => $overdue ? t('tasks.effectiveness_overdue') : t('tasks.effectiveness_due'),
            'title' => $case['title'],
            'link' => 'case.php?id=' . $case['id'],
            'sort' => $overdue ? 0 : 2,
        ];
    }

    $stmt = $db->prepare("
        SELECT id, title, doc_number
        FROM documents
        WHERE owner_id = ? AND organization_id = ? AND status = 'Godkendt'
            AND next_review_date IS NOT NULL AND next_review_date <= CURDATE() + INTERVAL 60 DAY
    ");
    $stmt->execute([$userId, $orgId]);
    foreach ($stmt->fetchAll() as $doc) {
        $tasks[] = [
            'urgency' => 'neutral',
            'icon' => 'ti-calendar-event',
            'label' => t('tasks.doc_review'),
            'title' => $doc['doc_number'] . ' — ' . $doc['title'],
            'link' => 'document.php?id=' . $doc['id'],
            'sort' => 3,
        ];
    }

    usort($tasks, fn($a, $b) => $a['sort'] <=> $b['sort']);

    return $tasks;
}

function render_task_panel(array $tasks): void {
    ?>
    <div class="task-panel">
        <div class="task-panel-header">
            <p class="task-panel-title"><?= h(t('tasks.title')) ?></p>
            <?php if ($tasks): ?>
                <span class="task-count-badge"><?= count($tasks) ?></span>
            <?php endif; ?>
        </div>
        <p class="muted" style="margin:0 0 14px;"><?= h(t('tasks.subtitle')) ?></p>

        <?php if (!$tasks): ?>
            <p class="muted"><?= h(t('tasks.empty')) ?></p>
        <?php else: ?>
            <?php foreach ($tasks as $i => $task): ?>
                <a class="task-row task-row-<?= h($task['urgency']) ?><?= $i === array_key_last($tasks) ? ' task-row-last' : '' ?>" href="<?= h($task['link']) ?>">
                    <span class="task-icon-circle">
                        <i class="ti <?= h($task['icon']) ?>"></i>
                    </span>
                    <span class="task-row-text">
                        <span class="task-row-title"><?= h($task['title']) ?></span>
                        <span class="task-row-meta"><?= h($task['label']) ?></span>
                    </span>
                </a>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
    <?php
}
