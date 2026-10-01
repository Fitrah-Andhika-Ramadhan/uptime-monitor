<?php

$base = dirname(__DIR__);
if (!is_file($base . '/app/db.php') && is_file(__DIR__ . '/app/db.php')) {
    $base = __DIR__;
}
define('APP_BASE', $base);

require_once $base . '/config.php';
require_once $base . '/app/db.php';
require_once $base . '/app/checker.php';
require_once $base . '/app/ai.php';
require_once $base . '/app/sec.php';
require_once $base . '/app/deploy.php';
require_once $base . '/app/dbui.php';
require_once $base . '/app/tty.php';
require_once $base . '/app/auth.php';

auth_boot();
sec_middleware();

$action = isset($_GET['action']) ?     preg_replace('/[^a-z0-9_-]/', '', (string) $_GET['action']) : '';
$method = $_SERVER['REQUEST_METHOD'];
$in = [];

if ($action === 'deploy-hook') {
    $secret = isset($_GET['secret']) ? (string) $_GET['secret'] : '';
    if (!hash_equals((string) CRON_SECRET, $secret)) {
        fail('secret tidak valid', 403);
    }
    $ev = $_SERVER['HTTP_X_GITHUB_EVENT'] ?? 'push';
    if ($ev !== 'push') {
        jout(['ok' => true, 'ignored' => $ev], 200);
    }
    $in = json_decode((string) file_get_contents('php://input'), true) ?: [];
    $repo = (string) ($in['repository']['full_name'] ?? '');
    $site = $repo !== '' ? str_replace(['--', '/'], '-', $repo) : ((string) ($in['site'] ?? 'unknown'));
    $branch = isset($in['ref']) ? substr((string) $in['ref'], strlen('refs/heads/')) : (string) ($in['branch'] ?? 'main');
    $sha = (string) ($in['head_commit']['id'] ?? ($in['sha'] ?? ''));
    $msg = (string) ($in['head_commit']['message'] ?? ($in['message'] ?? ''));
    $site = mb_substr($site, 0, 120);
    $depId = record_deployment($site, $branch, $sha ?: null, mb_substr($msg, 0, 160), 'checking');

    $mon = db()->prepare('SELECT * FROM monitors');
    $mon->execute();
    $m = null;
    $hostSite = explode('-', $site)[0];
    foreach ($mon->fetchAll() as $row) {
        $h = strtolower(preg_replace('/^www\./i', '', (string) (parse_url((string) $row['url'], PHP_URL_HOST) ?: '')));
        if ($h !== '' && (stripos($site, $h) !== false || stripos($h, $hostSite) !== false)) {
            $m = $row;
            break;
        }
    }
    if ($m === null) {
        $m = ['id' => 0, 'url' => 'https://' . $hostSite . '.id', 'method' => 'HEAD', 'expected_code' => 0, 'keyword' => '', 'interval_min' => 5];
    }
    $items = gate_checks((string) $m['url']);
    $v = gate_verdict($items);
    $aiNote = gate_ai_note($items);
    update_deployment($depId, $v['verdict'], $items, $aiNote);
    gate_log_add((string) $m['url'], $site, $v['verdict'], $v['score'], $items, $aiNote);
    jout(['ok' => true, 'id' => $depId, 'verdict' => $v['verdict']]);
}

if ($method === 'POST') {
    if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'fetch') {
        fail('Invalid request.', 400);
    }
    $in = json_decode((string) file_get_contents('php://input'), true) ?: [];
}

switch ($action) {

    case 'me':
        jout([
            'ok' => true,
            'auth' => is_auth(),
            'csrf' => is_auth() ? $_SESSION['csrf'] : null,
            'site' => SITE_NAME,
            'ai' => ai_ready(),
        ], 200);

    case 'login':
        if ($method !== 'POST') {
            fail('POST method required.', 405);
        }
        throttle('login', 6, 300);
        $pw = (string) ($in['password'] ?? '');
        if (!hash_equals(ADMIN_PASSWORD, $pw) || ADMIN_PASSWORD === 'ganti-password-ini') {
            fail(ADMIN_PASSWORD === 'ganti-password-ini'
                ? 'Admin password has not been changed. Edit config.php first.'
                : 'Wrong password.', 401);
        }
        login();
        session_regenerate_id(true);
        jout(['ok' => true, 'csrf' => $_SESSION['csrf']]);

    case 'logout':
        check_csrf();
        require_auth();
        logout();
        jout(['ok' => true]);

    case 'monitors':
        $rows = [];
        $mon = db()->query('SELECT * FROM monitors ORDER BY group_name, id')->fetchAll();

        foreach ($mon as $m) {
            $hist = latest_check((int) $m['id']);
            $row = $hist ? $hist[0] : null;
            $spark = array_map(static fn ($r) => ['t' => (int) $r['ts'], 'l' => (int) $r['latency_ms']], $hist);

            $rows[] = [
                'id' => (int) $m['id'],
                'group_name' => $m['group_name'],
                'name' => $m['name'],
                'url' => $m['url'],
                'interval_min' => (int) $m['interval_min'],
                'method' => $m['method'],
                'expected_code' => (int) $m['expected_code'],
                'keyword' => $m['keyword'],
                'status' => monitor_status($m),
                'uptime_24h' => uptime_pct((int) $m['id'], 1),
                'uptime_7d' => uptime_pct((int) $m['id'], 7),
                'latency_ms' => $row ? (int) $row['latency_ms'] : null,
                'code' => $row ? (int) $row['code'] : null,
                'err' => $row ? $row['err'] : null,
                'checked_at' => $row ? (int) $row['ts'] : null,
                'bar' => array_map(static fn ($r) => [
                    't' => (int) $r['ts'],
                    'ok' => (int) $r['ok'],
                    'down' => (int) $r['down'],
                    'code' => $r['code'] === null ? null : (int) $r['code'],
                    'l' => (int) $r['latency_ms'],
                ], $hist),
                'spark' => $spark,
            ];
        }
        jout(['ok' => true, 'monitors' => $rows], 200);

    case 'history':
        $id = (int) ($_GET['id'] ?? 0);
        $q = db()->prepare('SELECT * FROM checks WHERE monitor_id = ? ORDER BY ts DESC LIMIT 200');
        $q->execute([$id]);
        $checks = array_map(static fn ($r) => [
            'ts' => (int) $r['ts'],
            'ok' => (int) $r['ok'],
            'down' => (int) $r['down'],
            'code' => $r['code'] === null ? null : (int) $r['code'],
            'latency' => (int) $r['latency_ms'],
            'err' => $r['err'],
        ], $q->fetchAll());

        $q = db()->prepare('SELECT * FROM incidents WHERE monitor_id = ? ORDER BY start_ts DESC LIMIT 15');
        $q->execute([$id]);
        $incidents = array_map(static fn ($r) => [
            'start_ts' => (int) $r['start_ts'],
            'end_ts' => $r['end_ts'] === null ? null : (int) $r['end_ts'],
            'err' => $r['err'],
        ], $q->fetchAll());

        jout(['ok' => true, 'checks' => $checks, 'incidents' => $incidents], 200);

    case 'group-create':
    case 'group-delete':
    case 'monitor-create':
    case 'monitor-update':
    case 'monitor-delete':
    case 'instant-check':
    case 'insight':
    case 'chat':
    case 'security-scan':
        check_csrf();
        require_auth();
        break;

    case 'security':
        require_auth();
        jout(['ok' => true, 'scans' => latest_sec_scans()], 200);

    case 'live':
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        session_write_close();
        header('Content-Type: text/event-stream; charset=utf-8');
        header('Cache-Control: no-cache');
        header('X-Accel-Buffering: no');
        echo ':' . str_pad('', 2048) . "\n\n";
        flush();
        $tick = 0;
        $key = 't' . time();
        while ($tick < 8 && !connection_aborted()) {
            if (session_status() === PHP_SESSION_NONE) {
                session_start();
            }
            $att = attack_stats();
            session_write_close();
            echo 'event: tick' . "\n";
            echo 'data: ' . json_encode([
                'monitors' => monitors_payload(),
                'att' => $att,
                'sec' => latest_sec_scans(),
                'ts' => time(),
            ], JSON_UNESCAPED_SLASHES) . "\n\n";
            flush();
            sleep(10);
            $tick++;
        }
        exit;

    case 'deployments':
        require_auth();
        jout(['ok' => true, 'rows' => deployment_list(40)], 200);

    case 'attacks':
        require_auth();
        jout(['ok' => true, 'rows' => attack_list(60), 'stats' => attack_stats(), 'bans' => ban_list()], 200);

    case 'attacks-clear':
        check_csrf();
        require_auth();
        clear_attacks();
        jout(['ok' => true], 200);

    case 'unban':
        check_csrf();
        require_auth();
        $ip = trim((string) ($in['ip'] ?? ''));
        jout(['ok' => unban_ip($ip)], 200);

    case 'gate-logs':
        require_auth();
        jout(['ok' => true, 'rows' => gate_logs(10)], 200);

    case 'db-tables':
        require_auth();
        jout(['ok' => true, 'tables' => dbui_tables(), 'columns' => array_combine(array_map(fn ($t) => $t['name'], dbui_tables()), array_map(fn ($t) => dbui_columns($t['name']), dbui_tables()))], 200);

    case 'db-rows':
        require_auth();
        $t = (string) ($_GET['table'] ?? '');
        if (!dbui_table_ok($t)) {
            fail('Unknown table', 404);
        }
        jout(['ok' => true, 'rows' => dbui_rows($t, max(1, (int) ($_GET['page'] ?? 1)), 50, (string) ($_GET['q'] ?? ''))], 200);

    case 'db-insert':
        check_csrf();
        require_auth();
        $t = (string) ($in['table'] ?? '');
        $data = (array) ($in['data'] ?? []);
        unset($data['id']);
        dbui_insert($t, $data);
        jout(['ok' => true], 200);

    case 'db-update':
        check_csrf();
        require_auth();
        $t = (string) ($in['table'] ?? '');
        $id = (int) ($in['id'] ?? 0);
        dbui_update($t, $id, (array) ($in['data'] ?? []));
        jout(['ok' => true], 200);

    case 'db-delete':
        check_csrf();
        require_auth();
        $t = (string) ($in['table'] ?? '');
        $id = (int) ($in['id'] ?? 0);
        dbui_delete($t, $id);
        jout(['ok' => true], 200);

    case 'db-exec':
        check_csrf();
        require_auth();
        $sql = (string) ($in['sql'] ?? '');
        try {
            $rows = dbui_sql_readonly($sql);
        } catch (Throwable $e) {
            fail($e->getMessage(), 400);
        }
        jout(['ok' => true, 'rows' => $rows], 200);

    case 'tty':
        check_csrf();
        require_auth();
        $cmd = (string) ($in['cmd'] ?? '');
        if (mb_strlen($cmd) > 500) {
            fail('command too long', 400);
        }
        jout(['ok' => true, 'result' => tty_run($cmd)], 200);

    case 'gate':
        check_csrf();
        require_auth();
        break;

    default:
        fail('Unknown action: ' . $action, 404);
}

if ($method !== 'POST') {
    fail('Method not supported.', 405);
}

switch ($action) {

    case 'monitor-create':
    case 'monitor-update':
        $name = trim((string) ($in['name'] ?? ''));
        $url = filter_var(trim((string) ($in['url'] ?? '')), FILTER_VALIDATE_URL);
        $group = trim((string) ($in['group_name'] ?? '')) ?: 'General';
        $interval = max(5, (int) ($in['interval_min'] ?? 5));
        $mth = strtoupper(trim((string) ($in['method'] ?? 'HEAD'))) === 'GET' ? 'GET' : 'HEAD';
        $expected = (int) ($in['expected_code'] ?? 0);
        $keyword = trim((string) ($in['keyword'] ?? ''));

        if ($name === '' || mb_strlen($name) > 200) {
            fail('Monitor name is required (max 200 chars).');
        }
        if (!$url) {
            fail('Invalid URL, must start with http:// or https://');
        }
        if (mb_strlen($keyword) > 300) {
            fail('Keyword is too long.');
        }

        if ($action === 'monitor-create') {
            throttle('create', 20, 3600);
            db()->prepare('INSERT INTO monitors (group_name, name, url, interval_min, method, expected_code, keyword, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([$group, $name, $url, $interval, $mth, $expected, $keyword, time()]);
            $id = (int) db()->lastInsertId();
            $q = db()->prepare('SELECT * FROM monitors WHERE id = ?');
            $q->execute([$id]);
            run_check($q->fetch(), time());
            jout(['ok' => true, 'id' => $id]);
        }

        $id = (int) ($in['id'] ?? 0);
        $m = db()->prepare('SELECT id FROM monitors WHERE id = ?');
        $m->execute([$id]);
        if (!$m->fetch()) {
            fail('Monitor not found.', 404);
        }
        db()->prepare('UPDATE monitors SET group_name = ?, name = ?, url = ?, interval_min = ?, method = ?, expected_code = ?, keyword = ? WHERE id = ?')
            ->execute([$group, $name, $url, $interval, $mth, $expected, $keyword, $id]);
        jout(['ok' => true]);

    case 'monitor-delete':
        $id = (int) ($in['id'] ?? 0);
        if ($id <= 0) {
            fail('Invalid id.');
        }
        db()->prepare('DELETE FROM incidents WHERE monitor_id = ?')->execute([$id]);
        db()->prepare('DELETE FROM checks WHERE monitor_id = ?')->execute([$id]);
        db()->prepare('DELETE FROM monitors WHERE id = ?')->execute([$id]);
        jout(['ok' => true]);

    case 'instant-check':
        $id = (int) ($in['id'] ?? 0);
        $m = db()->prepare('SELECT * FROM monitors WHERE id = ?');
        $m->execute([$id]);
        $mon = $m->fetch();
        if (!$mon) {
            fail('Monitor not found.', 404);
        }
        $r = run_check($mon, time());
        jout(['ok' => true, 'result' => $r, 'status_after' => monitor_status($mon)]);

    case 'security-scan':
        throttle('scan', 15, 3600);
        $id = (int) ($in['id'] ?? 0);
        $m = db()->prepare('SELECT * FROM monitors WHERE id = ?');
        $m->execute([$id]);
        $mon = $m->fetch();
        if (!$mon) {
            fail('Monitor not found.', 404);
        }
        try {
            $r = scan_security($mon);
        } catch (Throwable $e) {
            fail('Scan gagal: ' . $e->getMessage(), 500);
        }
        jout(['ok' => true, 'scan' => $r]);

    case 'gate':
        throttle('gate', 20, 3600);
        $url = filter_var(trim((string) ($in['url'] ?? '')), FILTER_VALIDATE_URL);
        if (!$url) {
            fail('URL tidak valid.');
        }
        $items = gate_checks($url);
        $v = gate_verdict($items);
        $ai = gate_ai_note($items);
        gate_log_add($url, (string) ($in['name'] ?? ''), $v['verdict'], $v['score'], $items, $ai);
        jout(['ok' => true, 'verdict' => $v['verdict'], 'score' => $v['score'], 'items' => $items, 'ai_note' => $ai]);

    case 'insight':
        throttle('insight', 20, 3600);
        if (!ai_ready()) {
            fail('Konfigurasi AI belum lengkap. Buka config.php dan isi AI_API_KEY.', 400);
        }
        $cached = ai_cached_insight(INSIGHT_TTL_MIN);
        if ($cached !== null) {
            jout(['ok' => true, 'cached' => true, 'text' => $cached]);
        }
        jout(['ok' => true, 'cached' => false, 'text' => generate_insight()]);

    case 'chat':
        throttle('chat', CHAT_RATE_LIMIT, 300);
        $msg = trim((string) ($in['message'] ?? ''));
        if ($msg === '') {
            fail('Empty message.');
        }
        if (mb_strlen($msg) > 1000) {
            $msg = mb_substr($msg, 0, 1000);
        }
        if (!ai_ready()) {
            fail('Konfigurasi AI belum lengkap. Buka config.php dan isi AI_API_KEY.', 400);
        }

        $hist = $_SESSION['chat'] ?? [];
        $clean = [];
        foreach ($hist as $c) {
            if (is_array($c) && ((time() - (int) $c['ts']) < 7200) && in_array($c['role'], ['user', 'assistant'], true)) {
                $clean[] = $c;
            }
        }
        $sid = session_id();
        $histFile = APP_BASE . '/data/chat_' . preg_replace('/[^a-zA-Z0-9]/', '', $sid) . '.json';
        $hist = $_SESSION['chat'] ?? [];
        if (!file_exists($histFile) && $hist) {
            file_put_contents($histFile, json_encode($hist), LOCK_EX);
        }

        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        session_write_close();
        header('Content-Type: text/event-stream; charset=utf-8');
        header('Cache-Control: no-cache');
        header('X-Accel-Buffering: no');

        $prev = [];
        if (file_exists($histFile)) {
            $prev = json_decode((string) file_get_contents($histFile), true) ?: [];
        }
        $prev = array_values(array_filter($prev, function ($c) {
            return is_array($c) && ((time() - (int) $c['ts']) < 7200) && in_array($c['role'], ['user', 'assistant'], true);
        }));
        $prev[] = ['role' => 'user', 'content' => $msg, 'ts' => time()];

        $out = '';
        try {
            chat_stream(array_slice($prev, -3, 2), $msg, function ($chunk) use (&$out) {
                $out .= $chunk;
                echo 'data: ' . json_encode(['c' => $chunk], JSON_UNESCAPED_UNICODE) . "\n\n";
                flush();
            });
            $prev[] = ['role' => 'assistant', 'content' => $out, 'ts' => time()];
            file_put_contents($histFile, json_encode($prev), LOCK_EX);
        } catch (Throwable $e) {
            echo 'data: ' . json_encode(['e' => $e->getMessage()], JSON_UNESCAPED_UNICODE) . "\n\n";
            flush();
        }
        echo 'event: done' . "\n" . 'data: {}' . "\n\n";
        exit;
}

