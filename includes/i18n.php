<?php

define('AVAILABLE_LANGUAGES', ['da' => 'Dansk', 'en' => 'English']);

function load_language(): void {
    $lang = null;

    if (isset($_SESSION['lang']) && isset(AVAILABLE_LANGUAGES[$_SESSION['lang']])) {
        $lang = $_SESSION['lang'];
    } else {
        $user = function_exists('current_user') ? current_user() : null;
        if ($user && !empty($user['language']) && isset(AVAILABLE_LANGUAGES[$user['language']])) {
            $lang = $user['language'];
        }
    }

    if (!$lang) {
        $lang = 'da';
    }

    $_SESSION['lang'] = $lang;
    $GLOBALS['__lang'] = $lang;

    $file = __DIR__ . '/lang/' . $lang . '.php';
    $GLOBALS['__translations'] = is_file($file) ? require $file : [];
}

function t(string $key, array $replace = []): string {
    $translations = $GLOBALS['__translations'] ?? [];
    $value = $translations[$key] ?? $key;
    foreach ($replace as $k => $v) {
        $value = str_replace('{' . $k . '}', (string) $v, $value);
    }
    return $value;
}

function current_language(): string {
    return $GLOBALS['__lang'] ?? 'da';
}
