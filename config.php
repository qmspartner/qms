<?php

// Fejl i produktion må aldrig vises til brugeren (kan lække filstier, SQL og lignende).
// De logges i stedet til webhotellets normale PHP-fejllog.
ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

// Tving HTTPS: hvis forespørgslen ikke allerede er krypteret, send videre til https-udgaven
// af samme adresse. Tjekker både den almindelige indikator og X-Forwarded-Proto, som nogle
// webhoteller/proxyer bruger, når krypteringen "afsluttes" før den når PHP.
$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
if (!$isHttps && php_sapi_name() !== 'cli') {
    $redirectUrl = 'https://' . ($_SERVER['HTTP_HOST'] ?? '') . ($_SERVER['REQUEST_URI'] ?? '');
    header('Location: ' . $redirectUrl, true, 301);
    exit;
}

// Udfyld disse med oplysningerne fra dit webhotels kontrolpanel (cPanel -> MySQL databases).
define('DB_HOST', 'localhost');
define('DB_NAME', 'qmspartner_DB1');
define('DB_USER', 'qmspartner_admin');
define('DB_PASS', 'Aqua1165QMS');

// Mappe hvor uploadede dokumenter gemmes (skal have skriverettigheder, fx chmod 755/775).
define('UPLOAD_DIR', __DIR__ . '/uploads/');

// Tilladte filtyper for upload.
define('ALLOWED_EXTENSIONS', ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'jpg', 'jpeg', 'png']);

// Maks. filstørrelse i bytes (10 MB).
define('MAX_FILE_SIZE', 10 * 1024 * 1024);
define('MAIL_FROM', 'noreply@qmspartner.dk'); define('SITE_URL', 'https://qmspartner.dk/qms');
date_default_timezone_set('Europe/Copenhagen');

// Antal dage før udløb en kvalifikation/kompetence regnes som "udløber snart" (samme
// tærskel som udstyrs-kalibrering, leverandørevaluering og optegnelsers revisionsdato).
define('COMPETENCE_DUE_SOON_DAYS', 60);

// Hærdet session-cookie: kun HTTPS, ikke tilgængelig for JavaScript, og ikke sendt med ved
// tredjeparts-navigation (reducerer både session-hijacking og CSRF-angrebsfladen).
ini_set('session.use_strict_mode', '1');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => true,
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();
