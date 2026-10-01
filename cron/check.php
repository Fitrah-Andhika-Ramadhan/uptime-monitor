<?php

if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'litespeed') {
    $secret = isset($_GET['secret']) ? (string) $_GET['secret'] : '';
    if (!defined('CRON_SECRET') || $secret === '' || !hash_equals(CRON_SECRET, $secret)) {
        http_response_code(403);
        exit('forbidden');
    }
}

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/app/db.php';
require_once dirname(__DIR__) . '/app/checker.php';
require_once dirname(__DIR__) . '/app/ai.php';

check_all_due();

if (PHP_SAPI === 'cli') {
    echo 'check selesai ' . date('c') . PHP_EOL;
}

