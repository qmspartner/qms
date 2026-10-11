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

// Konventionen bag Skabelonbiblioteket: en dokumentkategori hvis danske label
// starter med "Skabelon" (fx "Skabelon - FAI", "Skabelon - PFMEA") behandles som
// et skabelonbibliotek-emne, ikke et almindeligt styret dokument. Admin opretter
// kategorierne selv under Lister.
const TEMPLATE_CATEGORY_PREFIX = 'Skabelon';
const FAI_TEMPLATE_CATEGORY = 'Skabelon - FAI';

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
        WHERE d.organization_id = ? AND d.category = ? AND d.status = 'Godkendt'
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

function severity_class(?string $severity): string {
    return match ($severity) {
        'Høj' => 'badge badge-danger',
        'Mellem' => 'badge badge-warning',
        'Lav' => 'badge badge-success',
        default => 'badge badge-neutral',
    };
}

function render_header(string $title): void {
    $user = current_user();
    ?>
    <!DOCTYPE html>
    <html lang="<?= h(current_language()) ?>">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title><?= h($title) ?> - <?= h(t('brand.name')) ?></title>
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@latest/dist/tabler-icons.min.css">
        <link rel="stylesheet" href="css/style.css?v=36">
    </head>
    <body>
    <div class="sticky-header" id="site-header">
    <?php if (is_impersonating()): ?>
        <div class="impersonation-banner">
            <?= h(t('impersonation.banner', ['name' => $user['name'], 'role' => role_label($user['role'])])) ?>
            <a href="stop_impersonation.php"><?= h(t('impersonation.exit')) ?></a>
        </div>
    <?php endif; ?>
    <header class="topbar">
        <a href="<?= $user ? 'home.php' : 'login.php' ?>" class="brand">
            <img src="css/logo.png?v=2" alt="QMSPartner - Quality Management System" class="brand-logo">
        </a>
        <nav>
            <?php if ($user): ?>
                <span class="user-tag"><?= h($user['name']) ?> (<?= h(role_label($user['role'])) ?>)</span>
                <?php if (is_gqar($user)): ?>
                    <a href="index.php"><?= h(t('nav.documents')) ?></a>
                    <a href="cases.php"><?= h(t('nav.cases')) ?></a>
                    <a href="audits.php"><?= h(t('nav.audits')) ?></a>
                    <a href="contract_overview.php"><?= h(t('nav.contract_overview')) ?></a>
                <?php else: ?>
                    <?php if (has_permission($user, 'documents')): ?><a href="index.php"><?= h(t('nav.documents')) ?></a><?php endif; ?>
                    <?php if (has_permission($user, 'cases')): ?><a href="cases.php"><?= h(t('nav.cases')) ?></a><?php endif; ?>
                    <?php
                        $showAudits = has_permission($user, 'audits');
                        $showRecords = has_permission($user, 'records');
                        $showEquipment = has_permission($user, 'equipment');
                        $showRisks = has_permission($user, 'risks');
                        $showSuppliers = has_permission($user, 'suppliers');
                    ?>
                    <?php if ($showAudits || $showRecords || $showEquipment || $showRisks || $showSuppliers): ?>
                        <details class="nav-dropdown">
                            <summary><?= h(t('nav.operations')) ?> <i class="ti ti-chevron-down"></i></summary>
                            <div class="nav-dropdown-menu">
                                <?php if ($showAudits): ?><a href="audits.php"><?= h(t('nav.audits')) ?></a><?php endif; ?>
                                <?php if ($showRecords): ?><a href="records.php"><?= h(t('nav.records')) ?></a><?php endif; ?>
                                <?php if ($showEquipment): ?><a href="equipment.php"><?= h(t('nav.equipment')) ?></a><?php endif; ?>
                                <?php if ($showRisks): ?><a href="risks.php"><?= h(t('nav.risks')) ?></a><?php endif; ?>
                                <?php if ($showSuppliers): ?><a href="suppliers.php"><?= h(t('nav.suppliers')) ?></a><?php endif; ?>
                            </div>
                        </details>
                    <?php endif; ?>
                    <details class="nav-dropdown">
                        <summary><?= h(t('nav.overview')) ?> <i class="ti ti-chevron-down"></i></summary>
                        <div class="nav-dropdown-menu">
                            <a href="contract_overview.php"><?= h(t('nav.contract_overview')) ?></a>
                            <a href="reports.php"><?= h(t('nav.reports')) ?></a>
                        </div>
                    </details>
                    <?php $showCustomers = has_permission($user, 'customers'); $showTemplates = has_permission($user, 'templates'); $isAdmin = $user['role'] === 'admin'; ?>
                    <?php if ($showCustomers || $showTemplates || $isAdmin): ?>
                        <details class="nav-dropdown">
                            <summary><?= h(t('nav.settings')) ?> <i class="ti ti-chevron-down"></i></summary>
                            <div class="nav-dropdown-menu">
                                <?php if ($showCustomers): ?><a href="customers.php"><?= h(t('nav.customers')) ?></a><?php endif; ?>
                                <?php if ($showTemplates): ?><a href="templates.php"><?= h(t('nav.templates')) ?></a><?php endif; ?>
                                <?php if ($isAdmin): ?>
                                    <a href="users.php"><?= h(t('nav.users')) ?></a>
                                    <a href="competence.php"><?= h(t('nav.competence')) ?></a>
                                    <a href="lists.php"><?= h(t('nav.lists')) ?></a>
                                    <a href="permissions.php"><?= h(t('nav.permissions')) ?></a>
                                    <a href="system_settings.php"><?= h(t('nav.system_settings')) ?></a>
                                <?php endif; ?>
                            </div>
                        </details>
                    <?php endif; ?>
                <?php endif; ?>
                <a href="help.php"><?= h(t('nav.help')) ?></a>
                <a href="logout.php"><?= h(t('nav.logout')) ?></a>
            <?php endif; ?>
            <span class="lang-switch">
                <?php foreach (AVAILABLE_LANGUAGES as $code => $label): ?>
                    <a href="lang.php?lang=<?= h($code) ?>" class="<?= current_language() === $code ? 'lang-active' : '' ?>" aria-label="<?= h($label) ?>" title="<?= h($label) ?>" style="display:inline-flex; align-items:center; padding:2px;"><?= language_flag_svg($code) ?></a>
                <?php endforeach; ?>
            </span>
        </nav>
    </header>
    </div>
    <script>
    (function () {
        // Topmenuen (og evt. "Se som anden bruger"-banneret) er sticky, så den altid
        // er synlig. Andre elementer (tabelheadere, hjælpesidens indholdsfortegnelse)
        // skal placere sig under den i stedet for under den, så den reelle højde måles
        // her og stilles til rådighed som en CSS-variabel.
        var header = document.getElementById('site-header');
        if (!header) return;
        function setHeaderHeight() {
            document.documentElement.style.setProperty('--header-h', header.offsetHeight + 'px');
        }
        setHeaderHeight();
        window.addEventListener('resize', setHeaderHeight);
    })();
    </script>
    <main class="container">
    <?php
}

function render_footer(): void {
    ?>
    </main>
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
