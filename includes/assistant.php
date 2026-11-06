<?php

require_once __DIR__ . '/functions.php';
// Bevidst INGEN require af tasks.php her (modsat de fleste andre moduler, kræver
// render_assistant_widget() dens get_my_tasks(), men assistant_today_items() og
// assistant_observations() gør ikke) — tasks.php trækker db.php/config.php med sig,
// og config.php findes ikke i et rent GitHub Actions-checkout (den er gitignored).
// At kræve den her ville ellers stille brud test-suiten i CI, som kun loader de
// rene, DB-frie moduler. render_footer() i functions.php kræver selv tasks.php,
// umiddelbart før den kalder render_assistant_widget().

/**
 * "I dag": kun det mest kritiske udsnit af Mine opgaver — overskredet eller
 * snart forfaldende (urgency 'danger'/'warning'). Resten (godkendelser,
 * kommende revisioner, kvitteringer m.v.) venter til ugeoversigten, for ikke
 * at overvælde den daglige pop-up med ting der endnu ikke haster.
 */
function assistant_today_items(array $tasks): array {
    return array_values(array_filter($tasks, fn ($t) => in_array($t['urgency'], ['danger', 'warning'], true)));
}

/**
 * Mønster-agtige iagttagelser på tværs af organisationens sager — ikke en del
 * af Mine opgaver (som kun ser på DEN enkelte brugers tildelte ting), men en
 * bredere, uge-agtig observation beregnet direkte her. Bevidst IKKE flyttet
 * til includes/cases.php: det er assistent-specifik "er der noget usædvanligt"
 * -logik, ikke en sags-forespørgsel der genbruges andre steder.
 *
 * Sammenligner et glidende 7-dages vindue (i dag og 6 dage tilbage) med et
 * 8-ugers gennemsnit umiddelbart forud for det — et rullende vindue frem for
 * en kalenderuge, så sammenligningen er retfærdig uanset hvilken ugedag det er.
 */
function assistant_observations(PDO $db, int $organizationId): array {
    $today = new DateTime('today');
    $currentStart = (clone $today)->modify('-6 days');
    $currentEnd = (clone $today)->modify('+1 day'); // eksklusiv øvre grænse
    $baselineStart = (clone $currentStart)->modify('-56 days');

    $observations = [];

    // A) Usædvanligt mange sager af samme type i det seneste 7-dages vindue.
    $stmt = $db->prepare('
        SELECT type, COUNT(*) AS antal
        FROM cases
        WHERE organization_id = ? AND created_at >= ? AND created_at < ?
        GROUP BY type
    ');
    $stmt->execute([$organizationId, $currentStart->format('Y-m-d H:i:s'), $currentEnd->format('Y-m-d H:i:s')]);
    $currentByType = [];
    foreach ($stmt->fetchAll() as $row) {
        $currentByType[$row['type']] = (int) $row['antal'];
    }

    if ($currentByType) {
        $stmt = $db->prepare('
            SELECT type, COUNT(*) AS antal
            FROM cases
            WHERE organization_id = ? AND created_at >= ? AND created_at < ?
            GROUP BY type
        ');
        $stmt->execute([$organizationId, $baselineStart->format('Y-m-d H:i:s'), $currentStart->format('Y-m-d H:i:s')]);
        $baselineByType = [];
        foreach ($stmt->fetchAll() as $row) {
            $baselineByType[$row['type']] = (int) $row['antal'];
        }

        $spikes = [];
        foreach ($currentByType as $type => $count) {
            if ($count < 2) {
                continue; // for få til at være en "iagttagelse"
            }
            $avgPerWeek = ($baselineByType[$type] ?? 0) / 8;
            if ($count >= $avgPerWeek * 2) {
                $spikes[] = ['type' => $type, 'count' => $count, 'avg' => $avgPerWeek, 'delta' => $count - $avgPerWeek];
            }
        }
        usort($spikes, fn ($a, $b) => $b['delta'] <=> $a['delta']);
        foreach (array_slice($spikes, 0, 2) as $spike) {
            $observations[] = [
                'icon' => 'ti-trending-up',
                'text' => $spike['avg'] > 0
                    ? t('assistant.observation.volume_spike_with_avg', [
                        'count' => $spike['count'],
                        'type' => type_label($spike['type']),
                        'avg' => number_format($spike['avg'], 1, ',', '.'),
                    ])
                    : t('assistant.observation.volume_spike_new', [
                        'count' => $spike['count'],
                        'type' => type_label($spike['type']),
                    ]),
            ];
        }
    }

    // B) Mange sager rapporteret af samme person i det samme vindue.
    if (count($observations) < 2) {
        $stmt = $db->prepare('
            SELECT c.reported_by, u.name, COUNT(*) AS antal
            FROM cases c
            JOIN users u ON u.id = c.reported_by
            WHERE c.organization_id = ? AND c.created_at >= ? AND c.created_at < ?
            GROUP BY c.reported_by, u.name
            ORDER BY antal DESC
            LIMIT 1
        ');
        $stmt->execute([$organizationId, $currentStart->format('Y-m-d H:i:s'), $currentEnd->format('Y-m-d H:i:s')]);
        $top = $stmt->fetch();
        if ($top && (int) $top['antal'] >= 3) {
            $observations[] = [
                'icon' => 'ti-user',
                'text' => t('assistant.observation.reporter_concentration', [
                    'count' => (int) $top['antal'],
                    'name' => $top['name'],
                ]),
            ];
        }
    }

    return array_slice($observations, 0, 2);
}

function render_assistant_widget(array $user): void {
    $db = get_db();
    $tasks = get_my_tasks($user);
    $todayItems = assistant_today_items($tasks);
    $observations = assistant_observations($db, (int) $user['organization_id']);
    $hasSomething = !empty($todayItems) || !empty($observations);
    ?>
    <div class="qms-assistant" id="qms-assistant" data-has-something="<?= $hasSomething ? '1' : '0' ?>" data-user="<?= (int) $user['id'] ?>">
        <button type="button" class="qms-assistant-avatar" id="qms-assistant-avatar" aria-label="<?= h(t('assistant.open')) ?>" aria-expanded="false">
            <?php if ($hasSomething): ?><span class="qms-assistant-avatar-ring" aria-hidden="true"></span><?php endif; ?>
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M9 12.5l2 2 4.5-5" stroke="#ffffff" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/></svg>
            <?php if ($hasSomething): ?><span class="qms-assistant-avatar-badge" aria-hidden="true"></span><?php endif; ?>
        </button>

        <div class="qms-assistant-panel" id="qms-assistant-panel" style="display:none;">
            <div class="qms-assistant-header">
                <div class="qms-assistant-header-icon">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M9 12.5l2 2 4.5-5" stroke="#ffffff" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round"/></svg>
                </div>
                <div class="qms-assistant-header-text">
                    <p><?= h(t('assistant.title')) ?></p>
                    <p><?= h(t('assistant.greeting', ['name' => $user['name']])) ?></p>
                </div>
                <button type="button" class="qms-assistant-close" id="qms-assistant-close" aria-label="<?= h(t('assistant.close')) ?>">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
                </button>
            </div>

            <div class="qms-assistant-tabs">
                <button type="button" class="qms-assistant-tab qms-assistant-tab-active" data-tab="dag"><?= h(t('assistant.tab_today')) ?></button>
                <button type="button" class="qms-assistant-tab" data-tab="uge"><?= h(t('assistant.tab_week')) ?></button>
            </div>

            <div class="qms-assistant-body" data-pane="dag">
                <?php if (!$todayItems): ?>
                    <p class="qms-assistant-empty"><?= h(t('assistant.today_empty')) ?></p>
                <?php else: ?>
                    <?php foreach ($todayItems as $task): ?>
                        <a class="task-row task-row-<?= h($task['urgency']) ?>" href="<?= h($task['link']) ?>">
                            <span class="task-icon-circle"><i class="ti <?= h($task['icon']) ?>"></i></span>
                            <span class="task-row-text">
                                <span class="task-row-title"><?= h($task['title']) ?></span>
                                <span class="task-row-meta"><?= h($task['label']) ?></span>
                            </span>
                        </a>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <div class="qms-assistant-body" data-pane="uge" style="display:none;">
                <?php if (!$tasks && !$observations): ?>
                    <p class="qms-assistant-empty"><?= h(t('assistant.week_empty')) ?></p>
                <?php else: ?>
                    <?php foreach ($tasks as $task): ?>
                        <a class="task-row task-row-<?= h($task['urgency']) ?>" href="<?= h($task['link']) ?>">
                            <span class="task-icon-circle"><i class="ti <?= h($task['icon']) ?>"></i></span>
                            <span class="task-row-text">
                                <span class="task-row-title"><?= h($task['title']) ?></span>
                                <span class="task-row-meta"><?= h($task['label']) ?></span>
                            </span>
                        </a>
                    <?php endforeach; ?>
                    <?php if ($observations): ?>
                        <p class="qms-assistant-obs-heading"><?= h(t('assistant.observations_heading')) ?></p>
                        <?php foreach ($observations as $obs): ?>
                            <div class="qms-assistant-observation">
                                <i class="ti <?= h($obs['icon']) ?>"></i>
                                <p><?= h($obs['text']) ?></p>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                <?php endif; ?>
            </div>

            <div class="qms-assistant-footer">
                <a href="#" class="qms-assistant-snooze" id="qms-assistant-snooze"><?= h(t('assistant.snooze')) ?></a>
                <a href="home.php" class="qms-assistant-cta">
                    <?= h(t('assistant.open_tasks')) ?>
                    <svg width="11" height="11" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                </a>
            </div>
        </div>
    </div>
    <script>
    (function () {
        var root = document.getElementById('qms-assistant');
        if (!root) return;
        var avatar = document.getElementById('qms-assistant-avatar');
        var panel = document.getElementById('qms-assistant-panel');
        var closeBtn = document.getElementById('qms-assistant-close');
        var snoozeLink = document.getElementById('qms-assistant-snooze');
        var tabs = root.querySelectorAll('.qms-assistant-tab');
        var panes = root.querySelectorAll('.qms-assistant-body');
        var hasSomething = root.dataset.hasSomething === '1';
        var snoozeKey = 'qmsAssistantSnoozedUntil:' + root.dataset.user;

        function isSnoozedToday() {
            try {
                return localStorage.getItem(snoozeKey) === new Date().toISOString().slice(0, 10);
            } catch (e) {
                return false;
            }
        }

        function openPanel() {
            panel.style.display = 'flex';
            avatar.setAttribute('aria-expanded', 'true');
        }
        function closePanel() {
            panel.style.display = 'none';
            avatar.setAttribute('aria-expanded', 'false');
        }

        avatar.addEventListener('click', function () {
            if (panel.style.display === 'none') { openPanel(); } else { closePanel(); }
        });
        closeBtn.addEventListener('click', closePanel);
        document.addEventListener('click', function (e) {
            if (panel.style.display !== 'none' && !root.contains(e.target)) { closePanel(); }
        });

        tabs.forEach(function (tab) {
            tab.addEventListener('click', function () {
                tabs.forEach(function (t) { t.classList.remove('qms-assistant-tab-active'); });
                tab.classList.add('qms-assistant-tab-active');
                panes.forEach(function (p) { p.style.display = p.dataset.pane === tab.dataset.tab ? 'block' : 'none'; });
            });
        });

        if (snoozeLink) {
            snoozeLink.addEventListener('click', function (e) {
                e.preventDefault();
                try { localStorage.setItem(snoozeKey, new Date().toISOString().slice(0, 10)); } catch (err) {}
                closePanel();
            });
        }

        // Dukker automatisk op højst én gang pr. dag pr. browser, kun hvis der
        // rent faktisk er noget at bemærke, og kun hvis ikke allerede fravalgt i dag.
        if (hasSomething && !isSnoozedToday()) {
            openPanel();
        }
    })();
    </script>
    <?php
}
