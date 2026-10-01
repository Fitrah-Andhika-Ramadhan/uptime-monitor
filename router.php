<?php

$path = rawurldecode(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
$file = __DIR__ . '/public' . str_replace(['\\', '..'], '', $path);

if ($path === '/' || $path === '/index.html') {
    header('Content-Type: text/html; charset=utf-8');
    readfile(__DIR__ . '/public/index.html');
    return true;
}

if ($file !== __DIR__ . '/public' && is_file($file)) {
    return false;
}

require __DIR__ . '/public/index.php';

