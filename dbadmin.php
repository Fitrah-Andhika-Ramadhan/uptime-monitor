<?php
require_once __DIR__ . '/app/auth.php';
auth_boot();
require_auth();

// Define custom Adminer class to bypass login
function adminer_object() {
    class AdminerSoftware extends Adminer {
        function name() {
            return 'Database Admin';
        }
        function credentials() {
            return ['sqlite', '', ''];
        }
        function database() {
            return __DIR__ . '/data/monitors.sqlite';
        }
        function login($login, $password) {
            return true;
        }
    }
    return new AdminerSoftware;
}

$_GET['sqlite'] = '';
$_GET['username'] = '';
$_GET['db'] = __DIR__ . '/data/monitors.sqlite';

require __DIR__ . '/app/adminer.php';
