<?php

function jout(array $data, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function fail(string $msg, int $code = 400): void
{
    jout(['ok' => false, 'error' => $msg], $code);
}

function auth_boot(): void
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
        || ($_SERVER['SERVER_PORT'] ?? 0) == 443;

    session_set_cookie_params([
        'lifetime' => 0,
        'path' => dirname($_SERVER['SCRIPT_NAME'] ?? '/'),
        'domain' => '',
        'secure' => $https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_name('fitcyber_sess');
    session_start();

    if (!isset($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
}

function login(): void
{
    $_SESSION['uid'] = 1;
}

function logout(): void
{
    unset($_SESSION['uid'], $_SESSION['chat'], $_SESSION['rl']);
}

function current_user_id(): int
{
    return isset($_SESSION['uid']) ? (int) $_SESSION['uid'] : 0;
}

function is_auth(): bool
{
    return current_user_id() > 0;
}

function check_csrf(): void
{
    $sent = $_SERVER['HTTP_X_CSRF'] ?? '';
    if (!is_string($sent) || $sent === '' || !hash_equals($_SESSION['csrf'], $sent)) {
        fail('CSRF token tidak valid.', 403);
    }
}

function require_auth(): void
{
    if (!is_auth()) {
        fail('Login required first.', 401);
    }
}

function throttle(string $key, int $max, int $window): void
{
    $now = time();
    $_SESSION['rl'] = ($_SESSION['rl'] ?? []);
    $hits = [];
    foreach (($_SESSION['rl'][$key] ?? []) as $ts) {
        if (($now - $ts) < $window) {
            $hits[] = $ts;
        }
    }
    $hits[] = $now;
    $_SESSION['rl'][$key] = $hits;

    if (count($hits) > $max) {
        fail('Too many requests, slow down.', 429);
    }
}

