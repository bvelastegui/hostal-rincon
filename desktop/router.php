<?php

declare(strict_types=1);

$token = getenv('HOSTAL_DESKTOP_TOKEN');
if (! $token || ! hash_equals($token, $_SERVER['HTTP_X_HOSTAL_DESKTOP'] ?? '')) {
    http_response_code(403);
    exit;
}

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path === '/desktop-ready') {
    header('X-Hostal-Ready: '.$token);
    http_response_code(204);
    exit;
}

$public = realpath($_SERVER['DOCUMENT_ROOT']);
if ($path && preg_match('~^/(?:build/[a-zA-Z0-9_./-]+|favicon\.(?:svg|ico|png)|apple-touch-icon\.png|robots\.txt)$~D', $path)) {
    $file = realpath($public.$path);
    if ($file && str_starts_with($file, $public.DIRECTORY_SEPARATOR) && is_file($file) && ! str_ends_with(strtolower($file), '.php')) {
        return false;
    }
}

require $public.'/index.php';
