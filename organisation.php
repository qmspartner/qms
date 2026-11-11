<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/organization.php';

$user = require_role(['admin']);
$db = get_db();

$users = organization_users($db, $user['organization_id']);
$employeeCounts = organization_employee_counts($db, $user['organization_id']);
$roots = organization_build_tree($users, $employeeCounts);
$chart = organization_chart_layout($roots);

$nodesById = [];
foreach ($chart['nodes'] as $node) {
    $nodesById[$node['id']] = $node;
}

$rolesPresent = array_values(array_unique(array_map(fn ($u) => $u['role'], $users)));

$currentScript = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));

/**
 * Tegner forbindelseslinjerne for ét niveau: en lodret stub fra lederens underkant,
 * eventuelt en vandret linje der spænder over børnenes centre, og en lille pil ned i
 * hvert barn. Samme "en-til-mange"-opbygning som i det godkendte mock-up.
 */
function render_organization_edge(array $parent, array $children, int &$arrowCounter): void {
    $parentBottomX = $parent['x'] + $parent['w'] / 2;
    $parentBottomY = $parent['y'] + $parent['h'];
    $childTopY = $children[0]['y'];
    $midY = (int) round(($parentBottomY + $childTopY) / 2);
    $stubLen = max($midY - $parentBottomY, 1);

    echo '<svg width="8" height="' . $stubLen . '" viewBox="0 0 8 ' . $stubLen . '" preserveAspectRatio="none" style="position:absolute; left:' . ($parentBottomX - 4) . 'px; top:' . $parentBottomY . 'px; width:8px; height:' . $stubLen . 'px; overflow:visible; fill:none; stroke:#c7c4b8; stroke-width:2;"><path d="M 4 0 L 4 ' . $stubLen . '"></path></svg>';

    $centers = array_map(fn ($c) => $c['x'] + $c['w'] / 2, $children);
    if (count($children) > 1) {
        $left = (int) min($centers);
        $right = (int) max($centers);
        $len = $right - $left;
        echo '<svg width="' . $len . '" height="8" viewBox="0 0 ' . $len . ' 8" preserveAspectRatio="none" style="position:absolute; left:' . $left . 'px; top:' . ($midY - 4) . 'px; width:' . $len . 'px; height:8px; overflow:visible; fill:none; stroke:#c7c4b8; stroke-width:2;"><path d="M 0 4 L ' . $len . ' 4"></path></svg>';
    }

    foreach ($children as $child) {
        $cx = $child['x'] + $child['w'] / 2;
        $segLen = max($childTopY - $midY, 1);
        $arrowCounter++;
        $markerId = 'orgchart-arrow-' . $arrowCounter;
        echo '<svg width="8" height="' . $segLen . '" viewBox="0 0 8 ' . $segLen . '" preserveAspectRatio="none" style="position:absolute; left:' . ($cx - 4) . 'px; top:' . $midY . 'px; width:8px; height:' . $segLen . 'px; overflow:visible; fill:none; stroke:#c7c4b8; stroke-width:2; stroke-linecap:round; stroke-linejoin:round;">'
            . '<defs><marker id="' . $markerId . '" orient="auto" markerWidth="5" markerHeight="5" refX="2" refY="2" overflow="visible"><path d="M0 0 L4 2 L0 4 Z" fill="#c7c4b8" stroke="none"/></marker></defs>'
            . '<path d="M 4 0 L 4 ' . max($segLen - 4, 0) . '" marker-end="url(#' . $markerId . ')"></path></svg>';
    }
}

render_header(t('organization.title'));
?>
<div class="page-heading"><h1><?= h(t('organization.title')) ?></h1></div>
<p class="muted" style="margin:-8px 0 20px; max-width:720px;"><?= h(t('organization.description')) ?></p>

<div class="tabs">
    <a href="organisation.php" class="tab-link <?= $currentScript === 'organisation.php' ? 'tab-link-active' : '' ?>"><?= h(t('organization.tab.chart')) ?></a>
    <a href="competence.php" class="tab-link <?= $currentScript === 'competence.php' ? 'tab-link-active' : '' ?>"><?= h(t('nav.competence')) ?></a>
    <a href="users.php" class="tab-link <?= $currentScript === 'users.php' ? 'tab-link-active' : '' ?>"><?= h(t('nav.users')) ?></a>
</div>

<?php if (!$chart['nodes']): ?>
    <div class="card"><?= h(t('organization.empty')) ?></div>
<?php else: ?>

<div class="orgchart-legend">
    <?php foreach ($rolesPresent as $role): $style = organization_role_style($role); ?>
        <span class="orgchart-legend-item"><span class="orgchart-legend-dot" style="background:<?= h($style['dot']) ?>;"></span><?= h(role_label($role)) ?></span>
    <?php endforeach; ?>
    <span class="orgchart-legend-count"><?= h(t('organization.user_count', ['count' => count($users)])) ?></span>
</div>

<div class="orgchart-scroll">
    <div class="orgchart-host" style="width:<?= (int) $chart['width'] ?>px; height:<?= (int) $chart['height'] ?>px;">
        <?php
        $arrowCounter = 0;
        foreach ($chart['edges'] as $edge) {
            $parent = $nodesById[$edge['parent']];
            $children = array_map(fn ($id) => $nodesById[$id], $edge['children']);
            render_organization_edge($parent, $children, $arrowCounter);
        }
        foreach ($chart['nodes'] as $node):
            $style = organization_role_style($node['role']);
            $nameParts = explode(' ', trim($node['name']));
            $initials = mb_strtoupper(mb_substr($nameParts[0], 0, 1) . mb_substr(end($nameParts), 0, 1));
        ?>
            <div class="orgchart-card" style="left:<?= (int) $node['x'] ?>px; top:<?= (int) $node['y'] ?>px; width:<?= (int) $node['w'] ?>px; height:<?= (int) $node['h'] ?>px;">
                <span class="orgchart-avatar" style="background:<?= h($style['dot']) ?>;"><?= h($initials) ?></span>
                <span class="orgchart-card-text">
                    <b class="orgchart-name"><?= h($node['name']) ?></b><br>
                    <?php if ($node['title']): ?><span class="orgchart-title"><?= h($node['title']) ?></span><br><?php endif; ?>
                    <span class="orgchart-role" style="color:<?= h($style['text']) ?>;"><?= h(role_label($node['role'])) ?></span><br>
                    <span class="orgchart-count-badge" style="color:<?= h($style['text']) ?>; background:<?= h($style['bg']) ?>;"><?= h(t('organization.employee_count', ['count' => $node['employee_count']])) ?></span>
                </span>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<p class="muted" style="max-width:720px; margin-top:16px;"><?= h(t('organization.note')) ?></p>

<?php endif; ?>
<?php render_footer(); ?>
