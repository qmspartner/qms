<?php

require_once __DIR__ . '/i18n.php';
load_language();

function h(?string $value): string {
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

// CSRF-beskyttelse: ét token pr. session, kontrolleret på hvert POST-kald.
function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string {
    return '<input type="hidden" name="csrf_token" value="' . h(csrf_token()) . '">';
}

function verify_csrf(): void {
    $token = $_POST['csrf_token'] ?? '';
    if (!is_string($token) || $token === '' || empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
        http_response_code(403);
        echo function_exists('t') ? t('error.csrf') : 'Siden er forældet, eller sessionen er udløbet. Genindlæs siden og forsøg igen.';
        exit;
    }
}

const DATE_FORMAT_OPTIONS = ['d-m-Y', 'Y-m-d'];

function get_organization_date_format(): string {
    static $cache = [];
    $user = current_user();
    $orgId = $user['organization_id'] ?? 0;
    if (!isset($cache[$orgId])) {
        $fmt = 'd-m-Y';
        if ($orgId) {
            $db = get_db();
            $stmt = $db->prepare('SELECT date_format FROM organizations WHERE id = ?');
            $stmt->execute([$orgId]);
            $stored = $stmt->fetchColumn();
            if (in_array($stored, DATE_FORMAT_OPTIONS, true)) {
                $fmt = $stored;
            }
        }
        $cache[$orgId] = $fmt;
    }
    return $cache[$orgId];
}

function organization_delivers_software(): bool {
    static $cache = [];
    $user = current_user();
    $orgId = $user['organization_id'] ?? 0;
    if (!isset($cache[$orgId])) {
        $enabled = false;
        if ($orgId) {
            $db = get_db();
            $stmt = $db->prepare('SELECT aqap2210_enabled FROM organizations WHERE id = ?');
            $stmt->execute([$orgId]);
            $enabled = (bool) $stmt->fetchColumn();
        }
        $cache[$orgId] = $enabled;
    }
    return $cache[$orgId];
}

// Skabelonbiblioteket: et dokument er en skabelon, hvis documents.is_template = 1
// - uanset hvilken almindelig dokumentkategori (fx "Procedure") det ligger i.
// FAI_CATEGORY er den rigtige dokumentkategori, FAI-skabelonen (og alle andre
// FAI-dokumenter) ligger i.
const FAI_CATEGORY = 'FAI';

// Den nyeste godkendte skabelon (is_template = 1) i en given dokumentkategori,
// eller null hvis der ikke findes en. Bruges både til "opret nyt dokument"-nudgen
// og til FAI-skabelonboksen i kontraktoverblikket.
function get_latest_template_document(string $category): ?array {
    $user = current_user();
    $orgId = $user['organization_id'] ?? 0;
    if (!$orgId) {
        return null;
    }
    $db = get_db();
    $stmt = $db->prepare("
        SELECT d.*, v.version_number, v.approved_at, v.original_approval_date, v.stored_filename, v.original_filename
        FROM documents d
        LEFT JOIN document_versions v ON v.id = d.current_version_id
        WHERE d.organization_id = ? AND d.category = ? AND d.is_template = 1 AND d.status = 'Godkendt'
        ORDER BY d.id DESC
        LIMIT 1
    ");
    $stmt->execute([$orgId, $category]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function language_flag_svg(string $code): string {
    if ($code === 'da') {
        return '<svg width="18" height="13" viewBox="0 0 18 13" aria-hidden="true"><rect width="18" height="13" fill="#c8102e"/><rect x="6" y="0" width="3" height="13" fill="#fff"/><rect x="0" y="5" width="18" height="3" fill="#fff"/></svg>';
    }
    if ($code === 'en') {
        return '<svg width="18" height="13" viewBox="0 0 18 13" aria-hidden="true"><rect width="18" height="13" fill="#00247d"/><path d="M0 0L18 13M18 0L0 13" stroke="#fff" stroke-width="2.4"/><path d="M0 0L18 13M18 0L0 13" stroke="#cf142b" stroke-width="1.2"/><rect x="7.2" y="0" width="3.6" height="13" fill="#fff"/><rect x="0" y="4.6" width="18" height="3.8" fill="#fff"/><rect x="7.8" y="0" width="2.4" height="13" fill="#cf142b"/><rect x="0" y="5.2" width="18" height="2.6" fill="#cf142b"/></svg>';
    }
    return '';
}

function organization_has_fai(): bool {
    static $cache = [];
    $user = current_user();
    $orgId = $user['organization_id'] ?? 0;
    if (!isset($cache[$orgId])) {
        $enabled = false;
        if ($orgId) {
            $db = get_db();
            $stmt = $db->prepare('SELECT fai_enabled FROM organizations WHERE id = ?');
            $stmt->execute([$orgId]);
            $enabled = (bool) $stmt->fetchColumn();
        }
        $cache[$orgId] = $enabled;
    }
    return $cache[$orgId];
}

function date_format_example(?string $fmt = null): string {
    return date($fmt ?? get_organization_date_format(), strtotime('2026-03-07'));
}

function format_date(?string $date): string {
    if (!$date) {
        return '—';
    }
    $timestamp = strtotime($date);
    if (!$timestamp) {
        return '—';
    }
    return date(get_organization_date_format(), $timestamp);
}

// Parser en fritekst-dato ud fra organisationens valgte datoformat (til fx CSV-import).
// Returnerer en ISO-dato (ÅÅÅÅ-MM-DD) til databasen, eller null hvis værdien ikke matcher formatet.
function parse_date_by_org_format(string $value): ?string {
    $fmt = get_organization_date_format();
    $d = DateTime::createFromFormat($fmt, $value);
    if (!$d || $d->format($fmt) !== $value) {
        return null;
    }
    return $d->format('Y-m-d');
}

function greeting_for_time(): string {
    $hour = (int) date('G');
    return match (true) {
        $hour < 10 => t('home.greeting_morning'),
        $hour < 18 => t('home.greeting_day'),
        default => t('home.greeting_evening'),
    };
}

function format_long_date(): string {
    $weekdaysDa = ['søndag', 'mandag', 'tirsdag', 'onsdag', 'torsdag', 'fredag', 'lørdag'];
    $monthsDa = ['', 'januar', 'februar', 'marts', 'april', 'maj', 'juni', 'juli', 'august', 'september', 'oktober', 'november', 'december'];
    $weekdaysEn = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
    $monthsEn = ['', 'January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];

    $w = (int) date('w');
    $d = (int) date('j');
    $m = (int) date('n');

    if (current_language() === 'en') {
        return $weekdaysEn[$w] . ', ' . $monthsEn[$m] . ' ' . $d;
    }
    return $weekdaysDa[$w] . ' den ' . $d . '. ' . $monthsDa[$m];
}

function status_badge_class(string $status): string {
    return match ($status) {
        'Godkendt' => 'badge badge-success',
        'Til godkendelse' => 'badge badge-warning',
        'Afvist', 'Udgået' => 'badge badge-danger',
        default => 'badge badge-neutral',
    };
}

function status_label(string $status): string {
    return t('status.' . $status);
}

function case_status_label(string $status): string {
    return t('case_status.' . $status);
}

function severity_label(?string $severity): string {
    return $severity ? t('severity.' . $severity) : '—';
}

function type_label(string $type): string {
    return t('type.' . $type);
}

function category_label(string $category): string {
    return list_value_label('document_category', $category);
}

function role_label(string $role): string {
    return t('role.' . $role);
}

function query_merge(array $overrides): string {
    $params = $_GET;
    foreach ($overrides as $k => $v) {
        if ($v === null) {
            unset($params[$k]);
        } else {
            $params[$k] = $v;
        }
    }
    return http_build_query($params);
}

function sort_header_link(string $column, string $currentSort, string $currentDir): array {
    $active = $currentSort === $column;
    $nextDir = ($active && $currentDir === 'asc') ? 'desc' : 'asc';
    $icon = $active ? ($currentDir === 'asc' ? 'ti-arrow-up' : 'ti-arrow-down') : 'ti-arrows-sort';
    $href = basename($_SERVER['PHP_SELF']) . '?' . query_merge(['sort' => $column, 'dir' => $nextDir]);
    return ['href' => $href, 'icon' => $icon, 'active' => $active];
}

function remove_array_filter_href(string $param, array $current, string $value): string {
    $remaining = array_values(array_diff($current, [$value]));
    return basename($_SERVER['PHP_SELF']) . '?' . query_merge([$param => $remaining ?: null]);
}

function clear_filter_href(string $param): string {
    return basename($_SERVER['PHP_SELF']) . '?' . query_merge([$param => null]);
}

function clear_all_filters_href(array $params): string {
    $overrides = [];
    foreach ($params as $p) {
        $overrides[$p] = null;
    }
    return basename($_SERVER['PHP_SELF']) . '?' . query_merge($overrides);
}

function render_active_filters(array $chips, string $clearAllHref): void {
    if (!$chips) {
        return;
    }
    ?>
    <div class="active-filters">
        <?php foreach ($chips as $chip): ?>
            <a class="filter-chip" href="<?= h($chip['href']) ?>"><?= h($chip['label']) ?> <i class="ti ti-x" aria-hidden="true"></i></a>
        <?php endforeach; ?>
        <a class="filter-clear-all" href="<?= h($clearAllHref) ?>"><?= h(t('filter.clear_all')) ?></a>
    </div>
    <?php
}

function hidden_inputs_for_query(array $exclude = []): void {
    foreach ($_GET as $key => $value) {
        if (in_array($key, $exclude, true)) {
            continue;
        }
        if (is_array($value)) {
            foreach ($value as $v) {
                echo '<input type="hidden" name="' . h($key) . '[]" value="' . h($v) . '">';
            }
        } else {
            echo '<input type="hidden" name="' . h($key) . '" value="' . h($value) . '">';
        }
    }
}

function render_th_controls(
    string $label,
    string $column,
    string $currentSort,
    string $currentDir,
    ?string $filterParam = null,
    array $filterOptions = [],
    array $filterSelected = [],
    ?callable $filterLabelFn = null,
    string $filterTitle = ''
): void {
    $s = sort_header_link($column, $currentSort, $currentDir);
    $hasFilter = $filterParam !== null;
    $filterActive = $hasFilter && $filterSelected;
    ?>
    <div class="th-inner">
        <a href="<?= h($s['href']) ?>" class="th-label <?= $s['active'] ? 'th-active' : '' ?>" title="<?= h($label) ?>"><?= h($label) ?></a>
        <span class="th-icons">
            <a href="<?= h($s['href']) ?>" class="<?= $s['active'] ? 'th-active' : '' ?>"><i class="ti <?= h($s['icon']) ?>" aria-hidden="true"></i></a>
            <?php if ($hasFilter): ?>
                <span class="th-divider"></span>
                <details class="col-filter">
                    <summary><i class="ti <?= $filterActive ? 'ti-filter-filled filter-active' : 'ti-filter' ?>" aria-hidden="true"></i></summary>
                    <div class="filter-dropdown">
                        <div class="filter-dropdown-title"><?= h($filterTitle) ?></div>
                        <form method="get">
                            <?php hidden_inputs_for_query([$filterParam]); ?>
                            <div class="filter-dropdown-options">
                                <?php foreach ($filterOptions as $opt): ?>
                                    <label><input type="checkbox" name="<?= h($filterParam) ?>[]" value="<?= h($opt) ?>" <?= in_array($opt, $filterSelected, true) ? 'checked' : '' ?>> <?= h($filterLabelFn($opt)) ?></label>
                                <?php endforeach; ?>
                            </div>
                            <div class="filter-dropdown-actions">
                                <?php if ($filterActive): ?>
                                    <a href="<?= h(clear_filter_href($filterParam)) ?>" class="filter-clear-link"><?= h(t('filter.clear')) ?></a>
                                <?php endif; ?>
                                <button type="submit"><?= h(t('filter.apply')) ?></button>
                            </div>
                        </form>
                    </div>
                </details>
            <?php endif; ?>
        </span>
    </div>
    <?php
}

function get_list_items_cached(string $listType, int $organizationId): array {
    static $cache = [];
    $key = $listType . ':' . $organizationId;
    if (!isset($cache[$key])) {
        $db = get_db();
        $stmt = $db->prepare('SELECT * FROM list_items WHERE list_type = ? AND organization_id = ? ORDER BY sort_order, id');
        $stmt->execute([$listType, $organizationId]);
        $cache[$key] = $stmt->fetchAll();
    }
    return $cache[$key];
}

function list_active_values(string $listType): array {
    $user = current_user();
    $orgId = $user['organization_id'] ?? 0;
    $items = get_list_items_cached($listType, $orgId);
    return array_values(array_column(array_filter($items, fn($i) => (int) $i['active'] === 1), 'label_da'));
}

function list_value_label(string $listType, string $valueDa): string {
    $user = current_user();
    $orgId = $user['organization_id'] ?? 0;
    $items = get_list_items_cached($listType, $orgId);
    foreach ($items as $item) {
        if ($item['label_da'] === $valueDa) {
            return (current_language() === 'en' && $item['label_en']) ? $item['label_en'] : $item['label_da'];
        }
    }
    return $valueDa;
}

function help_tip(string $textKey, array $vars = []): void {
    echo '<details class="help-tip"><summary aria-label="' . h(t('help.tip_aria')) . '">?</summary><div class="help-tip-content">' . h(t($textKey, $vars)) . '</div></details>';
}

function document_category_prefix(string $category): ?string {
    $user = current_user();
    $orgId = $user['organization_id'] ?? 0;
    $items = get_list_items_cached('document_category', $orgId);
    foreach ($items as $item) {
        if ($item['label_da'] === $category) {
            return $item['prefix'] ?: null;
        }
    }
    return null;
}

function document_category_prefixes_map(): array {
    $user = current_user();
    $orgId = $user['organization_id'] ?? 0;
    $items = get_list_items_cached('document_category', $orgId);
    $map = [];
    foreach ($items as $item) {
        if ($item['active'] && $item['prefix']) {
            $map[$item['label_da']] = $item['prefix'];
        }
    }
    return $map;
}

function list_type_label(string $listType): string {
    return t('lists.type.' . $listType);
}

// Til brug ved oprettelse af nye dokumenter: for hver dokumentkategori med
// mindst én godkendt skabelon (is_template = 1), dens titel/version/download-id
// - bruges til at vise og foreslå den rette skabelon, når man opretter et nyt
// dokument i samme kategori.
function document_category_template_info_map(): array {
    $user = current_user();
    $orgId = $user['organization_id'] ?? 0;
    if (!$orgId) {
        return [];
    }
    $db = get_db();
    $stmt = $db->prepare("
        SELECT d.category, d.title, v.version_number, d.current_version_id
        FROM documents d
        LEFT JOIN document_versions v ON v.id = d.current_version_id
        WHERE d.organization_id = ? AND d.is_template = 1 AND d.status = 'Godkendt'
        ORDER BY d.category, d.id DESC
    ");
    $stmt->execute([$orgId]);
    $map = [];
    foreach ($stmt->fetchAll() as $row) {
        if (!isset($map[$row['category']])) {
            $map[$row['category']] = [
                'title' => $row['title'],
                'version' => $row['version_number'],
                'versionId' => (int) $row['current_version_id'],
            ];
        }
    }
    return $map;
}

function permission_modules(): array {
    return ['documents', 'cases', 'audits', 'records', 'equipment', 'risks', 'suppliers', 'customers', 'users', 'lists', 'templates'];
}

function permission_module_label(string $module): string {
    return t('nav.' . $module);
}

function permission_roles(): array {
    // Administrator holdes udenfor (altid fuld adgang), GQAR styres separat af kontraktafgrænsningen.
    return ['editor', 'approver', 'user'];
}

function has_permission(array $user, string $module, string $action = 'view'): bool {
    if ($user['role'] === 'admin') {
        return true;
    }
    if (!in_array($user['role'], permission_roles(), true)) {
        // GQAR (og evt. fremtidige specialroller) styres af deres egen, separate logik.
        return false;
    }
    static $cache = [];
    $key = $user['organization_id'] . ':' . $user['role'];
    if (!isset($cache[$key])) {
        $db = get_db();
        $stmt = $db->prepare('SELECT * FROM role_permissions WHERE organization_id = ? AND role = ?');
        $stmt->execute([$user['organization_id'], $user['role']]);
        $rows = [];
        foreach ($stmt->fetchAll() as $row) {
            $rows[$row['module']] = $row;
        }
        $cache[$key] = $rows;
    }
    $row = $cache[$key][$module] ?? null;
    if (!$row) {
        return false;
    }
    $col = 'can_' . $action;
    return (bool) ($row[$col] ?? false);
}

function seed_default_permissions(int $organizationId): void {
    $db = get_db();
    $adminOnlyModules = ['users', 'lists'];
    $stmt = $db->prepare('
        INSERT INTO role_permissions (organization_id, role, module, can_view, can_create, can_edit, can_delete)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ');
    foreach (permission_roles() as $role) {
        foreach (permission_modules() as $module) {
            if (in_array($module, $adminOnlyModules, true)) {
                $view = $create = $edit = $delete = 0;
            } else {
                $view = 1;
                $edit = ($role === 'editor') ? 1 : 0;
                // Sager kan registreres af alle roller, ikke kun Redaktør — opret-reglen er bredere end rediger-reglen for netop dette modul.
                $create = ($module === 'cases') ? 1 : $edit;
                $delete = 0;
            }
            $stmt->execute([$organizationId, $role, $module, $view, $create, $edit, $delete]);
        }
    }
}

function seed_default_lists(int $organizationId): void {
    $db = get_db();
    $defaults = [
        'document_category' => [
            ['Procedure', 'Procedure'],
            ['Arbejdsinstruktion', 'Work instruction'],
            ['Formular', 'Form'],
            ['Politik', 'Policy'],
            ['Leverance-kvalitetsplan', 'Deliverable quality plan'],
        ],
        'record_type' => [
            ['Kompetencematrix', 'Competence matrix'],
            ['Kalibreringsbevis', 'Calibration certificate'],
            ['Mødereferat', 'Meeting minutes'],
            ['Træningsbevis', 'Training certificate'],
            ['Ledelsens evaluering', 'Management review'],
        ],
        'risk_category' => [
            ['Strategisk', 'Strategic'],
            ['Operationel', 'Operational'],
            ['Leverandør', 'Supplier'],
            ['IT', 'IT'],
            ['Andet', 'Other'],
        ],
        'supplier_category' => [
            ['Vare', 'Goods'],
            ['Service', 'Service'],
            ['Underleverandør af produktion', 'Production subcontractor'],
            ['Rådgivning', 'Consulting'],
            ['Andet', 'Other'],
        ],
    ];

    $stmt = $db->prepare('INSERT INTO list_items (organization_id, list_type, label_da, label_en, sort_order) VALUES (?, ?, ?, ?, ?)');
    foreach ($defaults as $listType => $items) {
        foreach ($items as $i => $pair) {
            $stmt->execute([$organizationId, $listType, $pair[0], $pair[1], $i + 1]);
        }
    }
}

function can_edit_documents(array $user): bool {
    return has_permission($user, 'documents', 'edit');
}

function can_approve_documents(array $user): bool {
    return in_array($user['role'], ['admin', 'approver'], true);
}

function can_manage_cases(array $user): bool {
    return has_permission($user, 'cases', 'edit');
}

function can_create_cases(array $user): bool {
    return has_permission($user, 'cases', 'create');
}

function can_manage_records(array $user): bool {
    return has_permission($user, 'records', 'edit');
}

function can_manage_audits(array $user): bool {
    return has_permission($user, 'audits', 'edit');
}

function can_manage_equipment(array $user): bool {
    return has_permission($user, 'equipment', 'edit');
}

function mismatch_class(?int $systemCount, ?int $manualCount): string {
    if ($manualCount === null) {
        return 'badge badge-neutral';
    }
    return $systemCount === $manualCount ? 'badge badge-success' : 'badge badge-danger';
}

function mismatch_label(?int $systemCount, ?int $manualCount): string {
    if ($manualCount === null) {
        return '—';
    }
    return $systemCount === $manualCount ? t('mgmtreview.match') : t('mgmtreview.mismatch');
}

function can_manage_risks(array $user): bool {
    return has_permission($user, 'risks', 'edit');
}

function get_entity_contracts(string $table, string $fkColumn, int $entityId): array {
    $db = get_db();
    $stmt = $db->prepare("SELECT contract_reference FROM {$table} WHERE {$fkColumn} = ? ORDER BY contract_reference");
    $stmt->execute([$entityId]);
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

function save_entity_contracts(string $table, string $fkColumn, int $entityId, array $contractRefs): void {
    $db = get_db();
    $stmt = $db->prepare("DELETE FROM {$table} WHERE {$fkColumn} = ?");
    $stmt->execute([$entityId]);
    $contractRefs = array_values(array_unique(array_filter($contractRefs, fn($c) => $c !== '')));
    if ($contractRefs) {
        $stmt = $db->prepare("INSERT INTO {$table} ({$fkColumn}, contract_reference) VALUES (?, ?)");
        foreach ($contractRefs as $ref) {
            $stmt->execute([$entityId, $ref]);
        }
    }
}

function user_has_contract(array $user, ?string $contractRef): bool {
    if ($contractRef === null || $contractRef === '') {
        return false;
    }
    return in_array($contractRef, $user['gqar_contract_references'] ?? [], true);
}

// Bygger "AND col IN (?, ?, ...)" ud fra brugerens kontraktliste, eller en umulig
// betingelse hvis listen er tom (så en GQAR uden kontrakter ikke ser noget som helst).
function contract_in_clause(string $column, array $contractRefs): array {
    if (!$contractRefs) {
        return ["1 = 0", []];
    }
    $placeholders = implode(',', array_fill(0, count($contractRefs), '?'));
    return ["{$column} IN ({$placeholders})", $contractRefs];
}

function count_open_counterfeit_cases(int $supplierId): int {
    $db = get_db();
    $stmt = $db->prepare("SELECT COUNT(*) FROM cases WHERE supplier_id = ? AND type = 'Mistænkt forfalsket del' AND status != 'Lukket'");
    $stmt->execute([$supplierId]);
    return (int) $stmt->fetchColumn();
}

function is_gqar(array $user): bool {
    return $user['role'] === 'gqar';
}

function deny_gqar(array $user): void {
    if (is_gqar($user)) {
        http_response_code(403);
        echo t('error.forbidden');
        exit;
    }
}

function can_manage_customers(array $user): bool {
    return has_permission($user, 'customers', 'edit');
}

function can_manage_suppliers(array $user): bool {
    return has_permission($user, 'suppliers', 'edit');
}

function supplier_category_options(): array {
    return list_active_values('supplier_category');
}

function supplier_category_label(string $category): string {
    return list_value_label('supplier_category', $category);
}

function supplier_status_label(string $status): string {
    return t('supplier_status.' . $status);
}

function supplier_status_class(string $status): string {
    return match ($status) {
        'Godkendt' => 'badge badge-success',
        'Under vurdering' => 'badge badge-warning',
        'Afvist', 'Udfaset' => 'badge badge-danger',
        default => 'badge badge-neutral',
    };
}

function risk_score(int $probability, int $consequence): int {
    return $probability * $consequence;
}

function risk_band(int $score): string {
    return match (true) {
        $score >= 15 => 'Kritisk',
        $score >= 10 => 'Høj',
        $score >= 5 => 'Mellem',
        default => 'Lav',
    };
}

function risk_band_label(string $band): string {
    return t('risk_band.' . $band);
}

function risk_band_class(string $band): string {
    return match ($band) {
        'Kritisk' => 'badge badge-danger',
        'Høj' => 'badge badge-risk-high',
        'Mellem' => 'badge badge-warning',
        default => 'badge badge-success',
    };
}

function risk_category_label(string $category): string {
    return list_value_label('risk_category', $category);
}

function risk_status_label(string $status): string {
    return t('risk_status.' . $status);
}

function risk_status_class(string $status): string {
    return match ($status) {
        'Lukket' => 'badge badge-success',
        'Under behandling' => 'badge badge-warning',
        default => 'badge badge-neutral',
    };
}

function equipment_criticality_label(string $value): string {
    return t('equipment_criticality.' . $value);
}

function equipment_criticality_class(string $value): string {
    return $value === 'Kritisk' ? 'badge badge-danger' : 'badge badge-neutral';
}

function equipment_status_label(string $value): string {
    return t('equipment_status.' . $value);
}

function equipment_status_class(string $value): string {
    return $value === 'Aktiv' ? 'badge badge-success' : 'badge badge-neutral';
}

function audit_type_label(string $value): string {
    return t('audit_type.' . $value);
}

function equipment_outcome_label(string $value): string {
    return t('equipment_outcome.' . $value);
}

function equipment_outcome_class(string $value): string {
    return match ($value) {
        'Ikke godkendt' => 'badge badge-danger',
        'Justeret' => 'badge badge-warning',
        default => 'badge badge-success',
    };
}

function audit_status_label(string $status): string {
    return t('audit_status.' . $status);
}

// Kompetence- og uddannelsesmodul: status udregnes altid ud fra datoerne i
// employee_qualifications i stedet for at blive gemt, så den aldrig kan gå i utakt.
function qualification_status(array $row): string {
    if (empty($row['obtained_date'])) {
        return 'planned';
    }
    if (empty($row['expiry_date'])) {
        return 'valid';
    }
    $today = new DateTimeImmutable('today');
    $expiry = new DateTimeImmutable($row['expiry_date']);
    if ($expiry < $today) {
        return 'expired';
    }
    if ($expiry <= $today->modify('+' . COMPETENCE_DUE_SOON_DAYS . ' days')) {
        return 'expiring';
    }
    return 'valid';
}

function qualification_status_label(string $status): string {
    return t('competence.status.' . $status);
}

function qualification_status_class(string $status): string {
    return match ($status) {
        'valid' => 'badge badge-success',
        'expiring' => 'badge badge-warning',
        'expired' => 'badge badge-danger',
        default => 'badge badge-neutral',
    };
}

// Kompakt farvemarkør til kompetencematricen, som en fuld badge ikke skalerer godt i
// (for lidt plads når der kommer mange kvalifikationer som kolonner).
function qualification_status_dot_class(string $status): string {
    return match ($status) {
        'valid' => 'matrix-dot matrix-dot-success',
        'expiring' => 'matrix-dot matrix-dot-warning',
        'expired' => 'matrix-dot matrix-dot-danger',
        default => 'matrix-dot matrix-dot-neutral',
    };
}

function can_manage_competence(array $user): bool {
    return $user['role'] === 'admin';
}

function audit_status_class(string $status): string {
    return match ($status) {
        'Gennemført' => 'badge badge-success',
        'Aflyst' => 'badge badge-danger',
        default => 'badge badge-neutral',
    };
}

function record_type_label(string $type): string {
    return list_value_label('record_type', $type);
}

/**
 * Optegnelsestyper der er flyttet til deres eget funktionelle modul (Møder, Udstyr, ...)
 * og derfor ikke længere skal kunne oprettes eller vises via den generiske Optegnelser-side.
 */
function record_type_relocated(): array {
    return ['Mødereferat', 'Ledelsens evaluering'];
}

function record_type_group(string $type): string {
    return match ($type) {
        'Kompetencematrix', 'Træningsbevis' => 'records.group.competence',
        'Kalibreringsbevis' => 'records.group.equipment',
        default => 'records.group.other',
    };
}

/**
 * Bygger den grupperede, søgbare navigation i venstre side af Optegnelser.
 * Grupperne er faste i koden (se record_type_group) — en type uden en kendt
 * gruppe lander automatisk i "Andet", så den ikke forsvinder fra oversigten.
 */
function record_type_nav_groups(array $selectedTypes): array {
    $allTypes = array_values(array_diff(list_active_values('record_type'), record_type_relocated()));

    $byGroup = [];
    foreach ($allTypes as $type) {
        $groupKey = record_type_group($type);
        $byGroup[$groupKey][] = $type;
    }

    // Fast, meningsfuld rækkefølge på grupperne; "Andet" ligger altid sidst.
    $groupOrder = ['records.group.competence', 'records.group.equipment', 'records.group.other'];

    $groups = [];
    foreach ($groupOrder as $groupKey) {
        if (empty($byGroup[$groupKey])) {
            continue;
        }
        $items = [];
        foreach ($byGroup[$groupKey] as $type) {
            $items[] = [
                'value' => $type,
                'label' => record_type_label($type),
                'active' => in_array($type, $selectedTypes, true),
                'href' => basename($_SERVER['PHP_SELF']) . '?' . query_merge(['type' => [$type]]),
            ];
        }
        $groups[] = ['label' => t($groupKey), 'items' => $items];
    }

    return $groups;
}

function record_type_defaults(string $type): array {
    return match ($type) {
        'Kompetencematrix' => ['retention' => 3, 'months' => 3],
        'Kalibreringsbevis' => ['retention' => 5, 'months' => 12],
        'Mødereferat' => ['retention' => 5, 'months' => 0],
        'Træningsbevis' => ['retention' => 5, 'months' => 12],
        default => ['retention' => 5, 'months' => 0],
    };
}

function case_status_class(string $status): string {
    return match ($status) {
        'Lukket' => 'badge badge-success',
        'Handling igangsat' => 'badge badge-warning',
        default => 'badge badge-neutral',
    };
}

// Effektivitetstjek af korrigerende handlinger (ISO 9001 pkt. 10.2): status udregnes
// altid ud fra sagens felter i stedet for at blive gemt separat, så den aldrig kan
// gå i utakt (samme princip som qualification_status() for kompetencematricen).
// 'none'         - sagen er ikke lukket, eller der er ingen korrigerende handling at tjekke
// 'pending_date' - lukket med korrigerende handling, men sagsbehandler har ikke sat en tjek-dato endnu
// 'planned'      - tjek-dato sat, ligger længere ude end due-soon-vinduet
// 'due_soon'     - tjek-dato nærmer sig
// 'overdue'      - tjek-dato er overskredet uden registreret resultat
// 'effective'    - tjekket er udført og handlingen var effektiv
function effectiveness_status(array $case): string {
    if ($case['status'] !== 'Lukket' || empty($case['corrective_action'])) {
        return 'none';
    }
    if (($case['effectiveness_result'] ?? null) === 'Effektiv') {
        return 'effective';
    }
    if (empty($case['effectiveness_review_due'])) {
        return 'pending_date';
    }
    $today = new DateTimeImmutable('today');
    $due = new DateTimeImmutable($case['effectiveness_review_due']);
    if ($due < $today) {
        return 'overdue';
    }
    if ($due <= $today->modify('+' . EFFECTIVENESS_DUE_SOON_DAYS . ' days')) {
        return 'due_soon';
    }
    return 'planned';
}

function effectiveness_status_label(string $status): string {
    return t('effectiveness.status.' . $status);
}

function effectiveness_status_class(string $status): string {
    return match ($status) {
        'effective' => 'badge badge-success',
        'due_soon' => 'badge badge-warning',
        'overdue' => 'badge badge-danger',
        default => 'badge badge-neutral',
    };
}

function severity_class(?string $severity): string {
    return match ($severity) {
        'Høj' => 'badge badge-danger',
        'Mellem' => 'badge badge-warning',
        'Lav' => 'badge badge-success',
        default => 'badge badge-neutral',
    };
}

// Simple, faste streg-ikoner til sidebar-navigationen. Rene SVG-strenge (ikke
// brugerdata), så de outputtes direkte uden h().
function nav_icon(string $key): string {
    $icons = [
        'documents' => '<path d="M14 2H7a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><path d="M9 13h6M9 17h6"/>',
        'cases' => '<path d="M10.3 3.9 1.9 18a2 2 0 0 0 1.7 3h16.8a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0Z"/><path d="M12 9v4"/><path d="M12 17h.01"/>',
        'records' => '<rect x="2.5" y="4" width="19" height="5" rx="1"/><path d="M4 9v9a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9"/><path d="M10 13h4"/>',
        'meetings' => '<rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/>',
        'audits' => '<rect x="6" y="3" width="12" height="18" rx="2"/><path d="M9 3v2a1 1 0 0 0 1 1h4a1 1 0 0 0 1-1V3"/><path d="m9 13 2 2 4-4"/>',
        'equipment' => '<path d="M14.7 6.3a4 4 0 0 1-5.4 5.4L4 17l3 3 5.3-5.3a4 4 0 0 1 5.4-5.4l-2.7 2.7-2-2Z"/>',
        'risks' => '<path d="M12 2 3 6v6c0 5 4 8.5 9 10 5-1.5 9-5 9-10V6l-9-4Z"/><path d="M12 8v4"/><path d="M12 16h.01"/>',
        'suppliers' => '<rect x="1" y="9" width="13" height="8" rx="1"/><path d="M14 11h4l4 3v3h-2"/><circle cx="6" cy="19" r="1.6"/><circle cx="17" cy="19" r="1.6"/>',
        'reports' => '<path d="M4 20V10M12 20V4M20 20v-7"/>',
        'contract' => '<path d="M14 2H7a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><path d="m8.5 15 2 2 4-4.5"/>',
        'customers' => '<path d="M3 21V9l9-5 9 5v12"/><path d="M9 21v-6h6v6"/>',
        'templates' => '<rect x="4" y="3" width="13" height="13" rx="1.5"/><path d="M20 8v11a2 2 0 0 1-2 2H7"/>',
        'users' => '<circle cx="12" cy="8" r="3.5"/><path d="M5 20c0-3.5 3-6 7-6s7 2.5 7 6"/>',
        'competence' => '<path d="M12 3 2 8l10 5 10-5-10-5Z"/><path d="M6 10.5V15c0 1.5 3 3 6 3s6-1.5 6-3v-4.5"/>',
        'lists' => '<path d="M8 6h13M8 12h13M8 18h13"/><circle cx="3.5" cy="6" r="1.3"/><circle cx="3.5" cy="12" r="1.3"/><circle cx="3.5" cy="18" r="1.3"/>',
        'permissions' => '<path d="M12 2 4 5v6c0 5 3.5 8.5 8 10 4.5-1.5 8-5 8-10V5l-8-3Z"/><circle cx="12" cy="11" r="2"/><path d="M12 13v3"/>',
        'system_settings' => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09a1.65 1.65 0 0 0-1-1.51 1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09a1.65 1.65 0 0 0 1.51-1 1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1Z"/>',
        'help' => '<circle cx="12" cy="12" r="9.5"/><path d="M9.5 9.2a2.5 2.5 0 1 1 3.9 2.4c-.8.5-1.4.9-1.4 2"/><path d="M12 17h.01"/>',
        'logout' => '<path d="M9 21H6a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h3"/><path d="M15 16.5 20 12l-5-4.5"/><path d="M20 12H9"/>',
    ];
    $path = $icons[$key] ?? '';
    return '<svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $path . '</svg>';
}

function render_header(string $title): void {
    $user = current_user();
    $currentScript = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    $isCurrent = fn (string $script): string => $currentScript === $script ? ' sidebar-link-active' : '';
    ?>
    <!DOCTYPE html>
    <html lang="<?= h(current_language()) ?>">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title><?= h($title) ?> - <?= h(t('brand.name')) ?></title>
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@latest/dist/tabler-icons.min.css">
        <link rel="stylesheet" href="css/style.css?v=46">
    </head>
    <body>
    <?php if (is_impersonating()): ?>
        <div class="impersonation-banner">
            <?= h(t('impersonation.banner', ['name' => $user['name'], 'role' => role_label($user['role'])])) ?>
            <a href="stop_impersonation.php"><?= h(t('impersonation.exit')) ?></a>
        </div>
    <?php endif; ?>
    <?php if (!$user): ?>
    <div class="guest-topbar">
        <a href="login.php" class="guest-brand">
            <img src="css/logo.png?v=2" alt="QMSPartner - Quality Management System" class="guest-brand-logo">
        </a>
        <span class="lang-switch">
            <?php foreach (AVAILABLE_LANGUAGES as $code => $label): ?>
                <a href="lang.php?lang=<?= h($code) ?>" class="<?= current_language() === $code ? 'lang-active' : '' ?>" aria-label="<?= h($label) ?>" title="<?= h($label) ?>" style="display:inline-flex; align-items:center; padding:2px;"><?= language_flag_svg($code) ?></a>
            <?php endforeach; ?>
        </span>
    </div>
    <?php endif; ?>
    <div class="app-shell">
    <?php if ($user): ?>
    <aside class="sidebar">
        <a href="home.php" class="sidebar-brand">
            <img src="css/logo.png?v=2" alt="QMSPartner - Quality Management System" class="sidebar-brand-logo">
        </a>
        <nav class="sidebar-nav">
            <?php if (is_gqar($user)): ?>
                <a href="index.php" class="sidebar-link<?= $isCurrent('index.php') ?>"><?= nav_icon('documents') ?><span><?= h(t('nav.documents')) ?></span></a>
                <a href="cases.php" class="sidebar-link<?= $isCurrent('cases.php') ?>"><?= nav_icon('cases') ?><span><?= h(t('nav.cases')) ?></span></a>
                <a href="audits.php" class="sidebar-link<?= $isCurrent('audits.php') ?>"><?= nav_icon('audits') ?><span><?= h(t('nav.audits')) ?></span></a>
                <a href="contract_overview.php" class="sidebar-link<?= $isCurrent('contract_overview.php') ?>"><?= nav_icon('contract') ?><span><?= h(t('nav.contract_overview')) ?></span></a>
            <?php else: ?>
                <?php if (has_permission($user, 'documents')): ?><a href="index.php" class="sidebar-link<?= $isCurrent('index.php') ?>"><?= nav_icon('documents') ?><span><?= h(t('nav.documents')) ?></span></a><?php endif; ?>
                <?php if (has_permission($user, 'cases')): ?><a href="cases.php" class="sidebar-link<?= $isCurrent('cases.php') ?>"><?= nav_icon('cases') ?><span><?= h(t('nav.cases')) ?></span></a><?php endif; ?>
                <?php
                    $showAudits = has_permission($user, 'audits');
                    $showRecords = has_permission($user, 'records');
                    $showEquipment = has_permission($user, 'equipment');
                    $showRisks = has_permission($user, 'risks');
                    $showSuppliers = has_permission($user, 'suppliers');
                ?>
                <?php if ($showAudits || $showRecords || $showEquipment || $showRisks || $showSuppliers): ?>
                    <p class="sidebar-group-label"><?= h(t('nav.operations')) ?></p>
                    <?php if ($showAudits): ?><a href="audits.php" class="sidebar-link<?= $isCurrent('audits.php') ?>"><?= nav_icon('audits') ?><span><?= h(t('nav.audits')) ?></span></a><?php endif; ?>
                    <?php if ($showRecords): ?><a href="meetings.php" class="sidebar-link<?= $isCurrent('meetings.php') ?>"><?= nav_icon('meetings') ?><span><?= h(t('nav.meetings')) ?></span></a><?php endif; ?>
                    <?php if ($showEquipment): ?><a href="equipment.php" class="sidebar-link<?= $isCurrent('equipment.php') ?>"><?= nav_icon('equipment') ?><span><?= h(t('nav.equipment')) ?></span></a><?php endif; ?>
                    <?php if ($showRisks): ?><a href="risks.php" class="sidebar-link<?= $isCurrent('risks.php') ?>"><?= nav_icon('risks') ?><span><?= h(t('nav.risks')) ?></span></a><?php endif; ?>
                    <?php if ($showSuppliers): ?><a href="suppliers.php" class="sidebar-link<?= $isCurrent('suppliers.php') ?>"><?= nav_icon('suppliers') ?><span><?= h(t('nav.suppliers')) ?></span></a><?php endif; ?>
                <?php endif; ?>
                <p class="sidebar-group-label"><?= h(t('nav.overview')) ?></p>
                <a href="contract_overview.php" class="sidebar-link<?= $isCurrent('contract_overview.php') ?>"><?= nav_icon('contract') ?><span><?= h(t('nav.contract_overview')) ?></span></a>
                <a href="reports.php" class="sidebar-link<?= $isCurrent('reports.php') ?>"><?= nav_icon('reports') ?><span><?= h(t('nav.reports')) ?></span></a>
            <?php endif; ?>
        </nav>
    </aside>
    <?php endif; ?>
    <div class="app-main">
    <?php if ($user): ?>
    <div class="content-topbar">
        <?php if (!is_gqar($user)): ?>
            <?php $showCustomers = has_permission($user, 'customers'); $showTemplates = has_permission($user, 'templates'); $isAdmin = $user['role'] === 'admin'; ?>
            <?php if ($showCustomers || $showTemplates || $isAdmin): ?>
                <details class="nav-dropdown">
                    <summary class="content-topbar-item"><?= nav_icon('system_settings') ?><span><?= h(t('nav.settings')) ?></span> <i class="ti ti-chevron-down"></i></summary>
                    <div class="nav-dropdown-menu">
                        <?php if ($showCustomers): ?><a href="customers.php"><?= nav_icon('customers') ?><span><?= h(t('nav.customers')) ?></span></a><?php endif; ?>
                        <?php if ($showTemplates): ?><a href="templates.php"><?= nav_icon('templates') ?><span><?= h(t('nav.templates')) ?></span></a><?php endif; ?>
                        <?php if ($isAdmin): ?>
                            <a href="users.php"><?= nav_icon('users') ?><span><?= h(t('nav.users')) ?></span></a>
                            <a href="competence.php"><?= nav_icon('competence') ?><span><?= h(t('nav.competence')) ?></span></a>
                            <a href="lists.php"><?= nav_icon('lists') ?><span><?= h(t('nav.lists')) ?></span></a>
                            <a href="permissions.php"><?= nav_icon('permissions') ?><span><?= h(t('nav.permissions')) ?></span></a>
                            <a href="system_settings.php"><?= nav_icon('system_settings') ?><span><?= h(t('nav.system_settings')) ?></span></a>
                        <?php endif; ?>
                    </div>
                </details>
            <?php endif; ?>
        <?php endif; ?>
        <a href="help.php" class="content-topbar-item"><?= nav_icon('help') ?><span><?= h(t('nav.help')) ?></span></a>
        <span class="content-topbar-divider"></span>
        <span class="user-tag"><?= h($user['name']) ?> (<?= h(role_label($user['role'])) ?>)</span>
        <span class="lang-switch">
            <?php foreach (AVAILABLE_LANGUAGES as $code => $label): ?>
                <a href="lang.php?lang=<?= h($code) ?>" class="<?= current_language() === $code ? 'lang-active' : '' ?>" aria-label="<?= h($label) ?>" title="<?= h($label) ?>" style="display:inline-flex; align-items:center; padding:2px;"><?= language_flag_svg($code) ?></a>
            <?php endforeach; ?>
        </span>
        <a href="logout.php" class="content-topbar-item content-topbar-logout"><?= nav_icon('logout') ?><span><?= h(t('nav.logout')) ?></span></a>
    </div>
    <?php endif; ?>
    <main class="container">
    <?php
}

function render_footer(): void {
    ?>
    </main>
    </div>
    </div>
    <script>
    document.addEventListener('click', function (e) {
        document.querySelectorAll('.nav-dropdown[open], .help-tip[open]').forEach(function (el) {
            if (!el.contains(e.target)) {
                el.removeAttribute('open');
            }
        });
    });

    // Bevar indtastede felter i opret-formularer, hvis siden vises igen pga. en valideringsfejl.
    // Opt-in pr. formular via attributten data-preserve="unikt-navn" — ingen effekt på andre formularer.
    document.querySelectorAll('form[data-preserve]').forEach(function (form) {
        var key = 'formdraft:' + form.dataset.preserve;
        var hadError = !!document.querySelector('.alert-danger');
        if (hadError) {
            try {
                var saved = JSON.parse(sessionStorage.getItem(key) || 'null');
                if (saved) {
                    Array.prototype.forEach.call(form.elements, function (el) {
                        if (!el.name || el.type === 'file' || el.type === 'submit' || el.type === 'hidden') return;
                        var val = saved[el.name];
                        if (val === undefined) return;
                        if (el.type === 'checkbox') {
                            el.checked = !!val;
                        } else if (el.tagName === 'SELECT' && el.multiple) {
                            Array.prototype.forEach.call(el.options, function (opt) {
                                opt.selected = val.indexOf(opt.value) !== -1;
                            });
                        } else {
                            el.value = val;
                        }
                    });
                    form.querySelectorAll('select, input[type=checkbox]').forEach(function (el) {
                        el.dispatchEvent(new Event('change'));
                    });
                }
            } catch (e) {}
        } else {
            try { sessionStorage.removeItem(key); } catch (e) {}
        }

        form.addEventListener('submit', function () {
            var data = {};
            Array.prototype.forEach.call(form.elements, function (el) {
                if (!el.name || el.type === 'file' || el.type === 'submit' || el.type === 'hidden') return;
                if (el.type === 'checkbox') {
                    data[el.name] = el.checked;
                } else if (el.tagName === 'SELECT' && el.multiple) {
                    data[el.name] = Array.prototype.map.call(el.selectedOptions, function (o) { return o.value; });
                } else {
                    data[el.name] = el.value;
                }
            });
            try { sessionStorage.setItem(key, JSON.stringify(data)); } catch (e) {}
        });
    });
    </script>
    </body>
    </html>
    <?php
}
