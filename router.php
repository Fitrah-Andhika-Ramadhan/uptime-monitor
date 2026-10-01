<?php

$docroot = is_file(__DIR__ . '/public/index.php') ? __DIR__ . '/public' : __DIR__;
$path = rawurldecode(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
$file = $docroot . str_replace(['\\', '..'], '', $path);

if ($path === '/' || $path === '/index.html') {
    header('Content-Type: text/html; charset=utf-8');
    readfile($docroot . '/index.html');
    return true;
}

if ($file !== $docroot && is_file($file)) {
    return false;
}

require $docroot . '/index.php';
