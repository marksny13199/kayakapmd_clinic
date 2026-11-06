<?php

$prefix = '/kayakapmd_clinic';
$publicPath = realpath(__DIR__ . '/public');

if ($publicPath === false) {
    http_response_code(500);
    exit('Laravel public directory not found.');
}

$requestUri = $_SERVER['REQUEST_URI'] ?? '/';
$uri = parse_url($requestUri, PHP_URL_PATH);

if (!is_string($uri) || $uri === '') {
    $uri = '/';
}

// Remove the application prefix if present.
if ($uri === $prefix) {
    $uri = '/';
} elseif (strpos($uri, $prefix . '/') === 0) {
    $uri = substr($uri, strlen($prefix));
}

// Decode and validate the path.
$path = rawurldecode($uri);

if (
    strpos($path, "\0") !== false ||
    preg_match('#(?:^|/)\.\.(?:/|$)#', $path)
) {
    http_response_code(400);
    exit('Bad Request');
}

// Resolve the requested public file.
$relativePath = ltrim($path, '/');
$file = $publicPath;

if ($relativePath !== '') {
    $file .= DIRECTORY_SEPARATOR
        . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
}

// Serve an existing file only from inside public/.
if ($relativePath !== '' && is_file($file)) {
    $realFile = realpath($file);
    $publicPrefix = rtrim($publicPath, DIRECTORY_SEPARATOR)
        . DIRECTORY_SEPARATOR;

    if (
        $realFile !== false &&
        strpos($realFile, $publicPrefix) === 0
    ) {
        $mime = strtolower(pathinfo($realFile, PATHINFO_EXTENSION)) === 'css'
            ? 'text/css; charset=UTF-8'
            : (function_exists('mime_content_type')
                ? mime_content_type($realFile)
                : false);

        header('Content-Type: ' . ($mime ?: 'application/octet-stream'));
        header('Content-Length: ' . filesize($realFile));
        header('X-Content-Type-Options: nosniff');

        readfile($realFile);
        exit;
    }
}

// Forward application requests to Laravel.
$query = parse_url($requestUri, PHP_URL_QUERY);

$_SERVER['REQUEST_URI'] = $uri
    . ($query !== null && $query !== '' ? '?' . $query : '');

$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['PHP_SELF'] = '/index.php';
$_SERVER['QUERY_STRING'] = $query ?? '';

require $publicPath . '/index.php';
