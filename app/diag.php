<?php

function diag_report(): array
{
    $base = defined('APP_BASE') ? APP_BASE : dirname(__DIR__);
    $data = $base . '/data';

    $writable = false;
    $dataErr = '';
    if (is_dir($data)) {
        $probe = $data . '/.write-test-' . bin2hex(random_bytes(4));
        $writable = @file_put_contents($probe, 'ok') !== false;
        @unlink($probe);
        if (!$writable) {
            $dataErr = 'data dir exists but is not writable';
        }
    } else {
        $writable = @mkdir($data, 0775, true);
        if (!$writable) {
            $dataErr = 'data dir missing and could not be created';
        }
    }

    $dbOk = false;
    $dbErr = '';
    try {
        $pdo = new PDO('sqlite:' . $data . '/diag.sqlite');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE IF NOT EXISTS t (id INTEGER)');
        $pdo->exec('DROP TABLE t');
        $dbOk = true;
        @unlink($data . '/diag.sqlite');
    } catch (Throwable $e) {
        $dbErr = $e->getMessage();
    }

    $configPath = $base . '/config.php';
    $hasConfig = is_file($configPath);
    $pwSet = $hasConfig && defined('ADMIN_PASSWORD') && ADMIN_PASSWORD !== 'change-this-password';
    $aiSet = $hasConfig && defined('AI_API_KEY') && AI_API_KEY !== '';
    $cronSet = $hasConfig && defined('CRON_SECRET') && CRON_SECRET !== 'change-this-secret';

    return [
        'php' => PHP_VERSION,
        'sapi' => PHP_SAPI,
        'os' => PHP_OS_FAMILY,
        'base' => $base,
        'layout' => is_file($base . '/public/index.php') ? 'public/' : 'webroot',
        'extensions' => [
            'pdo' => extension_loaded('PDO'),
            'pdo_sqlite' => extension_loaded('pdo_sqlite'),
            'sqlite3' => extension_loaded('sqlite3'),
            'curl' => extension_loaded('curl'),
            'mbstring' => extension_loaded('mbstring'),
            'openssl' => extension_loaded('openssl'),
            'json' => extension_loaded('json'),
        ],
        'data_dir' => $data,
        'data_writable' => $writable,
        'data_error' => $dataErr,
        'db_open_ok' => $dbOk,
        'db_error' => $dbErr,
        'config_present' => $hasConfig,
        'admin_password_set' => $pwSet,
        'ai_key_set' => $aiSet,
        'cron_secret_set' => $cronSet,
        'session_name' => session_name(),
        'session_ok' => true,
        'proc_open' => function_exists('proc_open') && !in_array('proc_open', array_map('trim', explode(',', (string) ini_get('disable_functions'))), true),
        'disable_functions' => ini_get('disable_functions'),
        'error_log' => ini_get('error_log'),
        'time' => date('c'),
    ];
}
