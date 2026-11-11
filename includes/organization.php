<?php

require_once __DIR__ . '/functions.php';

/**
 * Organisationsdiagrammet (Indstillinger → Organisation): viser lederskabsstrukturen
 * blandt SYSTEMBRUGERE (users.manager_user_id), med antal medarbejdere (employees,
 * uden systemlogin) der rapporterer direkte til hver leder. Bevidst kun systembrugere —
 * en medarbejder uden login kan i dag ikke selv stå som leder for andre medarbejdere
 * (employees.manager_user_id peger på users.id, ikke på en anden employees-række), se
 * CLAUDE.md. Træbygning og lag-udregning herunder er rene, DB-fri funktioner og testes
 * uden database; kun de to små opslagsfunktioner nederst rører PDO.
 */

/**
 * Bygger et (muligt) skov af træer fra en flad liste af brugere. En bruger uden
 * `manager_user_id`, eller hvis leder ikke findes i listen (inaktiv/slettet), bliver
 * selv en rod. $employeeCounts er en simpel [user_id => antal]-opslagstabel.
 *
 * @param array<int, array{id:int,name:string,title:?string,role:string}> $users
 * @param array<int, int> $employeeCounts
 * @return array<int, array> rod-knuder, hver med 'children' (rekursivt samme form)
 */
function organization_build_tree(array $users, array $employeeCounts): array {
    $nodes = [];
    foreach ($users as $u) {
        $nodes[(int) $u['id']] = [
            'id' => (int) $u['id'],
            'name' => $u['name'],
            'title' => $u['title'] ?? null,
            'role' => $u['role'],
            'employee_count' => $employeeCounts[(int) $u['id']] ?? 0,
            'children' => [],
        ];
    }

    $roots = [];
    foreach ($users as $u) {
        $id = (int) $u['id'];
        $managerId = (int) ($u['manager_user_id'] ?? 0);
        if ($managerId && isset($nodes[$managerId]) && $managerId !== $id) {
            $nodes[$managerId]['children'][] = &$nodes[$id];
        } else {
            $roots[] = &$nodes[$id];
        }
    }
    unset($nodes);

    return $roots;
}

/**
 * Beregner bredden (i px) af et undertræ: bladets kortbredde, eller summen af
 * børnenes undertræ-bredder plus mellemrum mellem dem.
 */
function organization_chart_subtree_width(array $node, int $cardWidth, int $hGap): int {
    if (!$node['children']) {
        return $cardWidth;
    }
    $total = 0;
    foreach ($node['children'] as $child) {
        $total += organization_chart_subtree_width($child, $cardWidth, $hGap);
    }
    $total += $hGap * (count($node['children']) - 1);
    return max($total, $cardWidth);
}

/**
 * Placerer én knude (og rekursivt dens børn): y af dybden, x centreret over børnenes
 * samlede bredde (eller $leftX direkte for et blad). Ændrer $node in-place.
 */
function organization_chart_place_node(array &$node, int $leftX, int $depth, int $cardWidth, int $cardHeight, int $hGap, int $vGap): void {
    $node['y'] = $depth * ($cardHeight + $vGap);
    $node['w'] = $cardWidth;
    $node['h'] = $cardHeight;

    if (!$node['children']) {
        $node['x'] = $leftX;
        return;
    }

    $currentX = $leftX;
    foreach ($node['children'] as &$child) {
        $childWidth = organization_chart_subtree_width($child, $cardWidth, $hGap);
        organization_chart_place_node($child, $currentX, $depth + 1, $cardWidth, $cardHeight, $hGap, $vGap);
        $currentX += $childWidth + $hGap;
    }
    unset($child);

    $firstCenter = $node['children'][0]['x'] + $cardWidth / 2;
    $lastCenter = end($node['children'])['x'] + $cardWidth / 2;
    $node['x'] = (int) round(($firstCenter + $lastCenter) / 2 - $cardWidth / 2);
}

/**
 * Lægger en hel skov (flere rødder side om side) ud og returnerer en flad knude-liste
 * plus kant-liste (for at tegne forbindelseslinjer) og diagrammets samlede mål.
 *
 * @param array $roots fra organization_build_tree()
 * @param array{cardWidth?:int,cardHeight?:int,hGap?:int,vGap?:int,rootGap?:int} $opts
 * @return array{nodes:array,edges:array<int,array{parent:int,children:int[]}>,width:int,height:int}
 */
function organization_chart_layout(array $roots, array $opts = []): array {
    $cardWidth = $opts['cardWidth'] ?? 240;
    $cardHeight = $opts['cardHeight'] ?? 112;
    $hGap = $opts['hGap'] ?? 32;
    $vGap = $opts['vGap'] ?? 96;
    $rootGap = $opts['rootGap'] ?? 80;

    $leftX = 0;
    $maxDepth = 0;
    foreach ($roots as &$root) {
        $rootWidth = organization_chart_subtree_width($root, $cardWidth, $hGap);
        organization_chart_place_node($root, $leftX, 0, $cardWidth, $cardHeight, $hGap, $vGap);
        $leftX += $rootWidth + $rootGap;
    }
    unset($root);

    $nodes = [];
    $edges = [];
    $flatten = function (array $node) use (&$flatten, &$nodes, &$edges, &$maxDepth) {
        $nodes[] = [
            'id' => $node['id'],
            'name' => $node['name'],
            'title' => $node['title'],
            'role' => $node['role'],
            'employee_count' => $node['employee_count'],
            'x' => $node['x'],
            'y' => $node['y'],
            'w' => $node['w'],
            'h' => $node['h'],
        ];
        if ($node['children']) {
            $edges[] = [
                'parent' => $node['id'],
                'children' => array_map(fn ($c) => $c['id'], $node['children']),
            ];
            foreach ($node['children'] as $child) {
                $flatten($child);
            }
        }
    };
    foreach ($roots as $root) {
        $flatten($root);
    }

    $width = $leftX > 0 ? $leftX - $rootGap : 0;
    $height = $nodes ? max(array_column($nodes, 'y')) + $cardHeight : 0;

    return ['nodes' => $nodes, 'edges' => $edges, 'width' => $width, 'height' => $height];
}

/**
 * Aktive systembrugere i organisationen med deres leder, til diagrammet.
 */
function organization_users(PDO $db, int $organizationId): array {
    $stmt = $db->prepare('
        SELECT id, name, title, role, manager_user_id
        FROM users
        WHERE organization_id = ? AND active = 1
        ORDER BY name
    ');
    $stmt->execute([$organizationId]);
    return $stmt->fetchAll();
}

/**
 * Antal aktive medarbejdere (uden systemlogin) der rapporterer direkte til hver
 * systembruger, som [user_id => antal].
 */
function organization_employee_counts(PDO $db, int $organizationId): array {
    $stmt = $db->prepare('
        SELECT manager_user_id, COUNT(*) AS antal
        FROM employees
        WHERE organization_id = ? AND active = 1 AND manager_user_id IS NOT NULL
        GROUP BY manager_user_id
    ');
    $stmt->execute([$organizationId]);
    $counts = [];
    foreach ($stmt->fetchAll() as $row) {
        $counts[(int) $row['manager_user_id']] = (int) $row['antal'];
    }
    return $counts;
}

/**
 * Farve/badge-udseende pr. systemrolle til diagrammet — ren visningslogik, ikke en
 * rettighedsregel (dem står is_gqar()/has_permission() m.fl. for).
 */
function organization_role_style(string $role): array {
    return match ($role) {
        'admin' => ['dot' => '#6b4ca6', 'text' => '#6b4ca6', 'bg' => '#f1ecf8'],
        'approver' => ['dot' => '#1d7a5f', 'text' => '#1d7a5f', 'bg' => '#e7f3ee'],
        'editor' => ['dot' => '#1c5f8a', 'text' => '#1c5f8a', 'bg' => '#e9f0f6'],
        'ledelse' => ['dot' => '#1c5f8a', 'text' => '#1c5f8a', 'bg' => '#e9f0f6'],
        'gqar' => ['dot' => '#9b6b1f', 'text' => '#9b6b1f', 'bg' => '#f6efe3'],
        default => ['dot' => '#878671', 'text' => '#878671', 'bg' => '#f1efe6'],
    };
}
