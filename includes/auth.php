<?php
require_once __DIR__ . '/db.php';

function current_user(): ?array {
    return $_SESSION['user'] ?? null;
}

function require_login(): array {
    $user = current_user();
    if (!$user) {
        header('Location: login.php');
        exit;
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verify_csrf();
    }
    if (is_impersonating() && $_SERVER['REQUEST_METHOD'] === 'POST') {
        http_response_code(403);
        echo function_exists('t') ? t('impersonation.readonly') : 'Handlinger er deaktiveret, mens du ser systemet som en anden bruger.';
        exit;
    }
    return $user;
}

function require_role(array $roles): array {
    $user = require_login();
    if (!in_array($user['role'], $roles, true)) {
        http_response_code(403);
        echo function_exists('t') ? t('error.forbidden') : 'Du har ikke adgang til denne side.';
        exit;
    }
    return $user;
}

function require_permission(string $module, string $action = 'view'): array {
    $user = require_login();
    if (!has_permission($user, $module, $action)) {
        http_response_code(403);
        echo function_exists('t') ? t('error.forbidden') : 'Du har ikke adgang til denne side.';
        exit;
    }
    return $user;
}

function require_permission_allow_gqar(string $module, string $action = 'view'): array {
    $user = require_login();
    if (!is_gqar($user) && !has_permission($user, $module, $action)) {
        http_response_code(403);
        echo function_exists('t') ? t('error.forbidden') : 'Du har ikke adgang til denne side.';
        exit;
    }
    return $user;
}

const LOGIN_MAX_ATTEMPTS = 5;
const LOGIN_LOCKOUT_MINUTES = 15;

// Simpel brute-force-bremse: tæller forgæves login pr. e-mail/IP inden for et tidsvindue.
function is_login_locked(string $email, string $ip): bool {
    $db = get_db();
    $stmt = $db->prepare(
        'SELECT COUNT(*) FROM login_attempts WHERE (email = ? OR ip_address = ?) AND attempted_at > (NOW() - INTERVAL ' . LOGIN_LOCKOUT_MINUTES . ' MINUTE)'
    );
    $stmt->execute([$email, $ip]);
    return (int) $stmt->fetchColumn() >= LOGIN_MAX_ATTEMPTS;
}

function record_failed_login(string $email, string $ip): void {
    $db = get_db();
    $stmt = $db->prepare('INSERT INTO login_attempts (email, ip_address) VALUES (?, ?)');
    $stmt->execute([$email, $ip]);
    // Let housekeeping, så tabellen ikke vokser uendeligt.
    $db->exec('DELETE FROM login_attempts WHERE attempted_at < (NOW() - INTERVAL 1 DAY)');
}

function clear_login_attempts(string $email, string $ip): void {
    $db = get_db();
    $stmt = $db->prepare('DELETE FROM login_attempts WHERE email = ? OR ip_address = ?');
    $stmt->execute([$email, $ip]);
}

function attempt_login(string $email, string $password): bool {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    if (is_login_locked($email, $ip)) {
        return false;
    }

    $db = get_db();
    $stmt = $db->prepare('
        SELECT u.*, o.name AS organization_name
        FROM users u
        JOIN organizations o ON o.id = u.organization_id
        WHERE u.email = ? AND u.active = 1
    ');
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if ($user && password_verify($password, $user['password_hash'])) {
        clear_login_attempts($email, $ip);
        $_SESSION['user'] = build_session_user($user);
        if (!empty($user['language'])) {
            $_SESSION['lang'] = $user['language'];
        }
        return true;
    }

    record_failed_login($email, $ip);
    return false;
}

function build_session_user(array $user): array {
    $orgName = $user['organization_name'] ?? null;
    if ($orgName === null) {
        $db = get_db();
        $stmt = $db->prepare('SELECT name FROM organizations WHERE id = ?');
        $stmt->execute([$user['organization_id']]);
        $orgName = $stmt->fetch()['name'] ?? '';
    }
    return [
        'id' => $user['id'],
        'organization_id' => $user['organization_id'],
        'organization_name' => $orgName,
        'name' => $user['name'],
        'email' => $user['email'],
        'role' => $user['role'],
        'language' => $user['language'] ?? 'da',
        'gqar_contract_references' => $user['role'] === 'gqar' ? get_entity_contracts('gqar_contracts', 'user_id', $user['id']) : [],
    ];
}

function is_impersonating(): bool {
    return isset($_SESSION['original_admin']);
}

function log_action(int $documentId, ?int $versionId, string $action, string $details = ''): void {
    $user = current_user();
    $db = get_db();
    $stmt = $db->prepare(
        'INSERT INTO audit_log (organization_id, document_id, version_id, user_id, action, details) VALUES (?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([$user['organization_id'] ?? 0, $documentId, $versionId, $user['id'] ?? 0, $action, $details]);
}

function log_customer_action(int $customerId, string $action, string $details = ''): void {
    $user = current_user();
    $db = get_db();
    $stmt = $db->prepare(
        'INSERT INTO customer_log (organization_id, customer_id, user_id, action, details) VALUES (?, ?, ?, ?, ?)'
    );
    $stmt->execute([$user['organization_id'] ?? 0, $customerId, $user['id'] ?? 0, $action, $details]);
}

function log_supplier_action(int $supplierId, string $action, string $details = ''): void {
    $user = current_user();
    $db = get_db();
    $stmt = $db->prepare(
        'INSERT INTO supplier_log (organization_id, supplier_id, user_id, action, details) VALUES (?, ?, ?, ?, ?)'
    );
    $stmt->execute([$user['organization_id'] ?? 0, $supplierId, $user['id'] ?? 0, $action, $details]);
}

function log_risk_action(int $riskId, string $action, string $details = ''): void {
    $user = current_user();
    $db = get_db();
    $stmt = $db->prepare(
        'INSERT INTO risk_log (organization_id, risk_id, user_id, action, details) VALUES (?, ?, ?, ?, ?)'
    );
    $stmt->execute([$user['organization_id'] ?? 0, $riskId, $user['id'] ?? 0, $action, $details]);
}

function log_equipment_action(int $equipmentId, string $action, string $details = ''): void {
    $user = current_user();
    $db = get_db();
    $stmt = $db->prepare(
        'INSERT INTO equipment_log (organization_id, equipment_id, user_id, action, details) VALUES (?, ?, ?, ?, ?)'
    );
    $stmt->execute([$user['organization_id'] ?? 0, $equipmentId, $user['id'] ?? 0, $action, $details]);
}

function log_audit_action(int $auditId, string $action, string $details = ''): void {
    $user = current_user();
    $db = get_db();
    $stmt = $db->prepare(
        'INSERT INTO audit_trail_log (organization_id, audit_id, user_id, action, details) VALUES (?, ?, ?, ?, ?)'
    );
    $stmt->execute([$user['organization_id'] ?? 0, $auditId, $user['id'] ?? 0, $action, $details]);
}

function log_record_action(int $recordId, string $action, string $details = ''): void {
    $user = current_user();
    $db = get_db();
    $stmt = $db->prepare(
        'INSERT INTO record_log (organization_id, record_id, user_id, action, details) VALUES (?, ?, ?, ?, ?)'
    );
    $stmt->execute([$user['organization_id'] ?? 0, $recordId, $user['id'] ?? 0, $action, $details]);
}

function log_employee_action(int $employeeId, string $action, string $details = ''): void {
    $user = current_user();
    $db = get_db();
    $stmt = $db->prepare(
        'INSERT INTO employee_log (organization_id, employee_id, user_id, action, details) VALUES (?, ?, ?, ?, ?)'
    );
    $stmt->execute([$user['organization_id'] ?? 0, $employeeId, $user['id'] ?? 0, $action, $details]);
}

function log_case_action(int $caseId, string $action, string $details = ''): void {
    $user = current_user();
    $db = get_db();
    $stmt = $db->prepare(
        'INSERT INTO case_log (organization_id, case_id, user_id, action, details) VALUES (?, ?, ?, ?, ?)'
    );
    $stmt->execute([$user['organization_id'] ?? 0, $caseId, $user['id'] ?? 0, $action, $details]);
}
