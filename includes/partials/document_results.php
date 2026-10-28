<?php
/**
 * Delt fragment: aktive filter-chips + dokumenttabel for dokumentoversigten (index.php).
 *
 * Bruges to steder:
 *  - index.php's normale sidevisning (inkluderes direkte i siden)
 *  - index.php's AJAX-svar ved levende søgning (?partial=1), hvor DETTE fragment
 *    er hele svaret, så markup'en garanteret er identisk begge steder.
 *
 * Forventer at følgende variabler allerede er sat af den inkluderende fil:
 * $activeChips, $sortCol, $sortDir, $categories, $selectedCategories,
 * $statuses, $selectedStatuses, $documents
 */
?>
<?php render_active_filters($activeChips, clear_all_filters_href(['q', 'category', 'status'])); ?>

<table class="doc-table">
    <thead>
    <tr>
        <th><?php render_th_controls(t('documents.table.document'), 'title', $sortCol, $sortDir === 'DESC' ? 'desc' : 'asc'); ?></th>
        <th><?php render_th_controls(t('documents.table.id'), 'doc_number', $sortCol, $sortDir === 'DESC' ? 'desc' : 'asc'); ?></th>
        <th><?php render_th_controls(t('documents.table.category'), 'category', $sortCol, $sortDir === 'DESC' ? 'desc' : 'asc', 'category', $categories, $selectedCategories, 'category_label', t('documents.table.category')); ?></th>
        <th><?php render_th_controls(t('documents.table.owner'), 'owner_name', $sortCol, $sortDir === 'DESC' ? 'desc' : 'asc'); ?></th>
        <th><?php render_th_controls(t('documents.table.status'), 'status', $sortCol, $sortDir === 'DESC' ? 'desc' : 'asc', 'status', $statuses, $selectedStatuses, 'status_label', t('documents.table.status')); ?></th>
        <th><?php render_th_controls(t('documents.table.reviewed'), 'next_review_date', $sortCol, $sortDir === 'DESC' ? 'desc' : 'asc'); ?></th>
    </tr>
    </thead>
    <tbody>
    <?php foreach ($documents as $doc): ?>
        <tr onclick="window.location='document.php?id=<?= (int)$doc['id'] ?>'">
            <td><?= h($doc['title']) ?><?php if ($doc['is_template']): ?> <span class="template-badge"><?= h(t('doc.template_badge')) ?></span><?php endif; ?></td>
            <td><?= h($doc['doc_number']) ?></td>
            <td><?= h(category_label($doc['category'])) ?></td>
            <td><?= h($doc['owner_name']) ?></td>
            <td><span class="<?= status_badge_class($doc['status']) ?>"><?= h(status_label($doc['status'])) ?></span></td>
            <td><?= format_date($doc['next_review_date']) ?></td>
        </tr>
    <?php endforeach; ?>
    <?php if (!$documents): ?>
        <tr><td colspan="6" class="muted"><?= h(t('documents.empty')) ?></td></tr>
    <?php endif; ?>
    </tbody>
</table>
