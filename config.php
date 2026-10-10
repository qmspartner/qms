<?php

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
