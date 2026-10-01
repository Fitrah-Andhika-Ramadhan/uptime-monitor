<?php

function tty_root(): string
{
    return dirname(__DIR__);
}

function tty_run(string $line): array
{
    $line = trim($line);
    if ($line === '') {
        return ['out' => '', 'code' => 0];
    }
    if (preg_match('/[;&|><`$(){}\[\]!*?~\\\\\n\r]/', $line) && !defined('ENABLE_RAW_SHELL')) {
        return ['out' => 'blocked: shell metacharacters are not allowed (safe mode)', 'code' => 126];
    }
    if (defined('ENABLE_RAW_SHELL') && ENABLE_RAW_SHELL) {
        return tty_exec($line, tty_root());
    }

    $parts = preg_split('/\s+/', $line, 2);
    $cmd = strtolower($parts[0]);
    $rest = $parts[1] ?? '';

    switch ($cmd) {
        case 'help':
            return ['out' => tty_help(), 'code' => 0];
        case 'pwd':
            return ['out' => tty_root(), 'code' => 0];
        case 'whoami':
            return ['out' => getenv('USERNAME') ?: (getenv('USER') ?: 'www-data'), 'code' => 0];
        case 'date':
            return ['out' => date('D M j H:i:s T Y'), 'code' => 0];
        case 'echo':
            return ['out' => $rest, 'code' => 0];
        case 'env':
            $keys = ['PATH', 'HOME', 'USERNAME', 'COMPUTERNAME', 'OS', 'PROCESSOR_ARCHITECTURE', 'TEMP'];
            $out = [];
            foreach ($keys as $k) {
                if (($v = getenv($k)) !== false) {
                    $out[] = "$k=$v";
                }
            }
            return ['out' => implode("\n", $out), 'code' => 0];
        case 'ls':
        case 'dir':
            $dir = trim($rest) !== '' ? trim($rest) : '';
            $target = realpath($dir === '' ? tty_root() : (tty_root() . '/' . $dir));
            $root = realpath(tty_root());
            if ($target === false || strpos($target, $root) !== 0) {
                return ['out' => 'ls: path outside project root', 'code' => 1];
            }
            $items = @scandir($target);
            if ($items === false) {
                return ['out' => 'ls: cannot open directory', 'code' => 1];
            }
            $out = [];
            foreach ($items as $it) {
                if ($it === '.') continue;
                $p = $target . DIRECTORY_SEPARATOR . $it;
                $out[] = (is_dir($p) ? 'd  ' : '-  ') . str_pad(number_format(filesize($p)) . 'B', 12, ' ', STR_PAD_LEFT) . '  ' . $it;
            }
            return ['out' => implode("\n", $out), 'code' => 0];
        case 'cat':
        case 'tail':
            $args = preg_split('/\s+/', trim($rest));
            $file = $args[0] ?? '';
            $n = (int) ($args[1] ?? 20);
            $target = realpath(tty_root() . '/' . $file);
            $root = realpath(tty_root());
            if ($file === '' || $target === false || strpos($target, $root) !== 0 || !is_file($target)) {
                return ['out' => "$cmd: no such file in project", 'code' => 1];
            }
            if (filesize($target) > 262144) {
                return ['out' => "$cmd: file too large (max 256KB)", 'code' => 1];
            }
            $data = (string) file_get_contents($target);
            if ($cmd === 'tail') {
                $lines = preg_split('/\R/', $data);
                $data = implode("\n", array_slice($lines, -max(1, min($n, 200))));
            }
            return ['out' => $data, 'code' => 0];
        case 'grep':
            $args = preg_split('/\s+/', trim($rest), 2);
            $needle = $args[0] ?? '';
            $file = $args[1] ?? '';
            if ($needle === '' || $file === '') {
                return ['out' => 'usage: grep <pattern> <file>', 'code' => 1];
            }
            $target = realpath(tty_root() . '/' . $file);
            $root = realpath(tty_root());
            if ($target === false || strpos($target, $root) !== 0 || !is_file($target)) {
                return ['out' => 'grep: no such file in project', 'code' => 1];
            }
            $hits = [];
            foreach (preg_split('/\R/', (string) file_get_contents($target)) as $i => $l) {
                if (@preg_match('/' . str_replace('/', '\/', $needle) . '/i', $l) && $l !== '') {
                    $hits[] = ($i + 1) . ':' . $l;
                }
                if (count($hits) >= 100) break;
            }
            return ['out' => implode("\n", $hits), 'code' => $hits ? 0 : 1];
        case 'php':
            $args = trim($rest);
            if (preg_match('/^(-v|-m|-i|--version|--modules|--ini)$/', $args)) {
                return tty_bin(PHP_BINARY . ' ' . $args);
            }
            if ($args === 'cron' || $args === 'check') {
                require_once tty_root() . '/app/checker.php';
                check_all_due();
                return ['out' => 'cron: uptime checks executed at ' . date('c'), 'code' => 0];
            }
            return ['out' => 'usage: php <-v|-m|-i|cron>', 'code' => 1];
        case 'df':
            $root = tty_os() === 'WIN' ? (getenv('SystemDrive') ?: 'C:') . '\\' : '/';
            $free = @disk_free_space($root);
            $total = @disk_total_space($root);
            if ($free === false || $total === false) {
                return ['out' => 'df: unavailable', 'code' => 1];
            }
            $pct = $total > 0 ? round(($total - $free) / $total * 100, 1) : 0;
            return ['out' => sprintf("Filesystem  Size  Used  Avail  Use%%\n%s  %s  %s  %s  %s%%", $root, tty_bytes($total), tty_bytes($total - $free), tty_bytes($free), $pct), 'code' => 0];
        case 'uptime':
            $load = function_exists('sys_getloadavg') ? @sys_getloadavg() : null;
            $up = tty_uptime_seconds();
            return ['out' => 'up ' . tty_dhms($up) . ($load ? '  load: ' . implode(' ', array_map(fn ($v) => round($v, 2), $load)) : '') . '  (php ' . PHP_VERSION . ')', 'code' => 0];
        case 'free':
            return ['out' => tty_mem(), 'code' => 0];
        case 'tasklist':
        case 'ps':
            return tty_bin(tty_os() === 'WIN' ? 'tasklist' : 'ps aux --sort=-%mem');
        case 'ping':
            $host = tty_host($rest);
            if ($host === '') {
                return ['out' => 'ping: invalid host', 'code' => 1];
            }
            return tty_bin(tty_os() === 'WIN' ? "ping -n 2 $host" : "ping -c 2 $host");
        case 'dns':
        case 'nslookup':
        case 'dig':
            $host = tty_host($rest);
            if ($host === '') {
                return ['out' => 'dns: invalid host', 'code' => 1];
            }
            return ['out' => tty_dns($host), 'code' => 0];
        case 'curl':
            $url = trim($rest);
            if (!filter_var($url, FILTER_VALIDATE_URL) || !preg_match('~^https?://~i', $url)) {
                return ['out' => 'curl: invalid http(s) url', 'code' => 1];
            }
            return ['out' => tty_curl($url), 'code' => 0];
        case 'mon':
        case 'monitors':
            require_once tty_root() . '/app/checker.php';
            $out = [];
            foreach (monitors_payload() as $m) {
                $out[] = str_pad($m['name'], 24) . str_pad($m['status'], 10) . str_pad(($m['latency_ms'] ?? '-') . 'ms', 9) . ($m['uptime_24h'] >= 0 ? $m['uptime_24h'] . '%' : 'n/a');
            }
            return ['out' => $out ? implode("\n", $out) : 'no monitors', 'code' => 0];
        case 'check':
            require_once tty_root() . '/app/checker.php';
            $id = (int) $rest;
            $st = db()->prepare('SELECT * FROM monitors WHERE id = ? OR name LIKE ? LIMIT 1');
            $st->execute([$id, '%' . trim($rest) . '%']);
            $m = $st->fetch();
            if (!$m) {
                return ['out' => 'check: monitor not found', 'code' => 1];
            }
            $r = run_check($m, time());
            return ['out' => "HTTP {$r['code']} - {$r['latency']} ms - " . ($r['down'] ? 'DOWN' : 'ok') . ($r['err'] ? ' (' . $r['err'] . ')' : ''), 'code' => 0];
        case 'cron':
            require_once tty_root() . '/app/checker.php';
            check_all_due();
            return ['out' => 'cron executed: ' . date('c'), 'code' => 0];
        case 'sql':
            require_once tty_root() . '/app/dbui.php';
            try {
                $rows = dbui_sql_readonly($rest);
            } catch (Throwable $e) {
                return ['out' => 'sql: ' . $e->getMessage(), 'code' => 1];
            }
            if (!$rows) {
                return ['out' => '(0 rows)', 'code' => 0];
            }
            $cols = array_keys($rows[0]);
            $out = [implode("\t", $cols)];
            foreach ($rows as $r) {
                $out[] = implode("\t", array_map(fn ($v) => (string) $v, array_values($r)));
            }
            return ['out' => implode("\n", $out), 'code' => 0];
        case 'git':
            if (!is_dir(tty_root() . '/.git')) {
                return ['out' => 'git: not a git repository', 'code' => 1];
            }
            if (!preg_match('/^(status|log|branch)(\s+-{1,2}[\w=.\/-]+)*$/', trim($rest) ?: 'status')) {
                return ['out' => 'git: only status/log/branch allowed', 'code' => 1];
            }
            return tty_bin('git ' . (trim($rest) ?: 'status'), tty_root());
        case 'attacks':
            require_once tty_root() . '/app/sec.php';
            $rows = attack_list(15);
            $out = [];
            foreach ($rows as $r) {
                $out[] = date('H:i:s', (int) $r['ts']) . '  ' . str_pad($r['ip'], 16) . str_pad($r['sev'], 8) . $r['reason'];
            }
            return ['out' => $out ? implode("\n", $out) : 'no attacks logged', 'code' => 0];
        case 'bans':
            require_once tty_root() . '/app/sec.php';
            $b = ban_list();
            return ['out' => $b ? implode("\n", array_map(fn ($ip, $ts) => $ip . '  ' . date('c', (int) $ts), array_keys($b), $b)) : 'no banned ips', 'code' => 0];
        case 'unban':
            require_once tty_root() . '/app/sec.php';
            $ip = trim($rest);
            return ['out' => filter_var($ip, FILTER_VALIDATE_IP) ? (unban_ip($ip) ? "unbanned $ip" : 'ip not banned') : 'invalid ip', 'code' => 0];
        case 'monitors-add':
            return ['out' => 'use the dashboard UI or the API to add monitors', 'code' => 1];
        default:
            return ['out' => "command not found: $cmd (type 'help')", 'code' => 127];
    }
}

function tty_os(): string
{
    return stripos(PHP_OS_FAMILY, 'Windows') !== false ? 'WIN' : 'UNIX';
}

function tty_host(string $s): string
{
    $s = trim($s);
    return preg_match('/^[a-z0-9]([a-z0-9.\-]{0,252}[a-z0-9])?$/i', $s) ? $s : '';
}

function tty_bytes(float $b): string
{
    $u = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = 0;
    while ($b >= 1024 && $i < count($u) - 1) {
        $b /= 1024;
        $i++;
    }
    return round($b, 1) . $u[$i];
}

function tty_dhms(int $s): string
{
    $d = intdiv($s, 86400);
    $h = intdiv($s % 86400, 3600);
    $m = intdiv($s % 3600, 60);
    return ($d ? $d . 'd ' : '') . $h . 'h ' . $m . 'm';
}

function tty_uptime_seconds(): int
{
    if (tty_os() === 'WIN') {
        $out = tty_bin('powershell -NoProfile -Command "(Get-CimInstance Win32_OperatingSystem).LastBootUpTime.ToString(\'o\')"');
        $ts = strtotime(trim($out['out']));
        return $ts ? max(1, time() - $ts) : 0;
    }
    if (is_readable('/proc/uptime')) {
        return (int) (float) explode(' ', (string) file_get_contents('/proc/uptime'))[0];
    }
    return 0;
}

function tty_mem(): string
{
    if (tty_os() !== 'WIN') {
        $raw = @file_get_contents('/proc/meminfo') ?: '';
        if ($raw !== '') {
            $get = function ($k) use ($raw) {
                preg_match('/^' . $k . ':\s+(\d+)/m', $raw, $m);
                return isset($m[1]) ? (int) $m[1] * 1024 : 0;
            };
            $tot = $get('MemTotal');
            $avail = $get('MemAvailable');
            return 'total ' . tty_bytes($tot) . '  used ' . tty_bytes($tot - $avail) . '  free ' . tty_bytes($avail);
        }
    }
    $out = tty_bin('powershell -NoProfile -Command "$os=Get-CimInstance Win32_OperatingSystem; \'{0:N0} KB free / {1:N0} KB total\' -f ($os.FreePhysicalMemory),($os.TotalVisibleMemorySize)"');
    return trim($out['out']) ?: 'mem: unavailable';
}

function tty_bin(string $cmdline, ?string $cwd = null): array
{
    $desc = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = proc_open($cmdline, $desc, $pipes, $cwd ?? tty_root());
    if (!is_resource($proc)) {
        return ['out' => 'exec failed', 'code' => 127];
    }
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($proc);
    $text = trim((string) $out . "\n" . (string) $err);
    return ['out' => mb_substr($text, 0, 20000), 'code' => $code];
}

function tty_exec(string $cmdline, ?string $cwd = null): array
{
    return tty_bin($cmdline, $cwd);
}

function tty_dns(string $host): string
{
    $out = [];
    foreach (['A', 'AAAA', 'MX', 'TXT', 'NS'] as $t) {
        $const = 'DNS_' . $t;
        $recs = @dns_get_record($host, constant($const)) ?: [];
        foreach ($recs as $r) {
            $val = $r['ip'] ?? $r['ipv6'] ?? $r['target'] ?? $r['txt'] ?? '';
            $out[] = str_pad($t, 6) . ($val !== '' ? $val : json_encode($r));
        }
    }
    return $out ? implode("\n", $out) : 'no records';
}

function tty_curl(string $url): string
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_NOBODY => false,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; XttackTTY/1.0)',
    ]);
    $body = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $time = round((float) curl_getinfo($ch, CURLINFO_TOTAL_TIME) * 1000);
    $err = curl_error($ch);
    curl_close($ch);
    $head = explode("\r\n\r\n", $body)[0] ?? '';
    $lines = array_slice(preg_split('/\R/', $head), 0, 8);
    $preview = mb_substr(preg_replace('/\s+/', ' ', strip_tags(explode("\r\n\r\n", $body)[1] ?? '')), 0, 200);
    return "HTTP $code in {$time}ms" . ($err ? " ($err)" : '') . "\n" . implode("\n", $lines) . "\n\n" . $preview;
}

function tty_help(): string
{
    return implode("\n", [
        'Real commands (executed on the server, safe mode):',
        '  ls|dir [path]        list files (project root limited)',
        '  cat <file>           print file',
        '  tail <file> [n]      last n lines',
        '  grep <pat> <file>    search in file',
        '  pwd, whoami, date, env, echo <text>',
        '  php -v|-m|-i         PHP binary details',
        '  php cron             run uptime checks now',
        '  df, uptime, free, ps|tasklist',
        '  ping <host>          real ping (2 packets)',
        '  dns <host>           A/AAAA/MX/TXT/NS records',
        '  curl <url>           real HTTP request + timing',
        '  mon|monitors         live monitor table',
        '  check <id|name>      run a real check now',
        '  cron                 run due checks now',
        '  sql <SELECT...>      read-only SQL',
        '  attacks / bans / unban <ip>',
        '  git status|log|branch',
        '  clear, help, exit',
    ]);
}
