<?php

function blacklist_checks(string $host, string $ip): array
{
    $list = [
        'dbl_dblspamhaus' => 'Spamhaus DBL',
        'dbl_surbl' => 'SURBL',
        'dbl_zen' => 'Spamhaus Zen (ip)',
    ];
    $res = [];
    foreach ($list as $k => $label) {
        $query = '';
        if (($k === 'dbl_dblspamhaus' || $k === 'dbl_surbl')) {
            $query = strtolower($host ?? '') . '.' . ($k === 'dbl_dblspamhaus' ? 'dbl.spamhaus.org' : 'multi.uribl.com');
        } else {
            $ipOctets = '';
            if ($ip && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                $ipOctets = implode('.', array_reverse(explode('.', $ip))) . '.';
            }
            if ($ipOctets === '') {
                $res[$k] = -1;
                continue;
            }
            $query = $ipOctets . 'zen.spamhaus.org';
        }
        $rec = @dns_get_record($query, DNS_A);
        if (!$rec || !count($rec)) {
            $res[$k] = 0;
            continue;
        }
        $ipr = (string) ($rec[0]['ip'] ?? '');
        if (strpos($ipr, '127.0.0.') !== 0 || $ipr === '127.0.0.1' || strpos($ipr, '127.255.') === 0) {
            $res[$k] = -1; // Resolver di-blokir atau IP salah, anggap tidak masuk blacklist
            continue;
        }
        $last = (int) substr($ipr, strrpos($ipr, '.') + 1);
        $res[$k] = ($last > 1 && $last <= 11) ? 1 : -1;
    }
    return $res;
}

function scan_security(array $m, bool $save = true): array
{
    $url = (string) ($m['url'] ?? '');
    $host = parse_url($url, PHP_URL_HOST) ?: '';
    $host = preg_replace('/^www\./i', '', $host);
    $https = stripos($url, 'https://') === 0;

    $ch = curl_init($url);
    $headers = [];
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => 'GET',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$headers) {
            $l = trim($line);
            if ($l !== '') {
                $headers[] = $l;
            }
            return strlen($line);
        },
        CURLOPT_HEADER => true,
        CURLOPT_CERTINFO => true,
        CURLOPT_NOBODY => false,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_CONNECTTIMEOUT => CHECK_TIMEOUT,
        CURLOPT_TIMEOUT => CHECK_TIMEOUT,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; FitCyberSecScan/1.0)',
    ]);
    $body = curl_exec($ch);
    $cert = curl_getinfo($ch, CURLINFO_CERTINFO);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    $hmap = [];
    foreach ($headers as $h) {
        if (($p = strpos($h, ':')) !== false) {
            $hmap[strtolower(trim(substr($h, 0, $p)))] = trim(substr($h, $p + 1));
        }
    }

    $have = function (...$names) use ($hmap) {
        foreach ($names as $n) {
            if (isset($hmap[$n]) && $hmap[$n] !== '') {
                return true;
            }
        }
        return false;
    };

    $sslOk = 0;
    $sslDays = null;
    $sslIssuer = '';
    
    // Fallback using stream_socket_client because Hostinger's cURL often drops CURLINFO_CERTINFO
    $ctx = stream_context_create(['ssl' => ['capture_peer_cert' => true, 'verify_peer' => false, 'verify_peer_name' => false]]);
    $client = @stream_socket_client('ssl://' . $host . ':443', $errNo, $errStr, 5, STREAM_CLIENT_CONNECT, $ctx);
    if ($client) {
        $params = stream_context_get_params($client);
        if (isset($params['options']['ssl']['peer_certificate'])) {
            $parsed = openssl_x509_parse($params['options']['ssl']['peer_certificate']);
            if ($parsed && isset($parsed['validTo_time_t'])) {
                $exp = (int) $parsed['validTo_time_t'];
                if ($exp > time()) {
                    $sslOk = 1;
                    $sslDays = (int) ceil(($exp - time()) / 86400);
                    $sslIssuer = $parsed['issuer']['O'] ?? ($parsed['issuer']['CN'] ?? '');
                }
            }
        }
        fclose($client);
    }

    $hsts = $have('strict-transport-security') ? 1 : 0;
    $xfo = $have('x-frame-options', 'content-security-policy') ? 1 : 0;
    $xcto = $have('x-content-type-options') ? 1 : 0;
    $ref = $have('referrer-policy') ? 1 : 0;
    $csp = $have('content-security-policy') ? 1 : 0;

    $spf = 0;
    $dmarc = 0;
    if ($host !== '') {
        $recs = @dns_get_record($host, DNS_TXT) ?: [];
        foreach ($recs as $r) {
            if (stripos((string) ($r['txt'] ?? ''), 'v=spf1') === 0) {
                $spf = 1;
            }
        }
        $recs2 = @dns_get_record('_dmarc.' . $host, DNS_TXT) ?: [];
        foreach ($recs2 as $r) {
            if (stripos((string) ($r['txt'] ?? ''), 'v=dmarc1') === 0) {
                $dmarc = 1;
            }
        }
    }

    $score = 0;
    $score += $sslOk ? 30 : 0;
    if ($sslOk && $sslDays !== null && $sslDays > 14) {
        $score += 5;
    }
    $score += $hsts ? 10 : 0;
    $score += $xfo ? 10 : 0;
    $score += $xcto ? 10 : 0;
    $score += $ref ? 10 : 0;
    $score += $csp ? 10 : 0;
    $score += $spf ? 5 : 0;
    $score += $dmarc ? 5 : 0;

    $ip = '';
    $arecs = @dns_get_record($host, DNS_A) ?: [];
    if ($arecs) {
        $ip = (string) ($arecs[0]['ip'] ?? '');
    }
    $bl = blacklist_checks($host, $ip);
    $blacklisted = (($bl['dbl_dblspamhaus'] ?? 0) === 1) || (($bl['dbl_surbl'] ?? 0) === 1) || (($bl['dbl_zen'] ?? 0) === 1);
    if ($blacklisted) {
        $score = max(0, $score - 40);
    }

    $grade = $score >= 90 ? 'A' : ($score >= 75 ? 'B' : ($score >= 60 ? 'C' : ($score >= 40 ? 'D' : 'F')));
    $score = min($score, 90);

    $headersFound = ['hsts' => $hsts, 'xfo' => $xfo, 'xcto' => $xcto, 'ref' => $ref, 'csp' => $csp];
    $raw = json_encode(['ip' => $ip, 'blacklist' => $bl, 'code' => $code, 'blacklisted' => $blacklisted ? 1 : 0]);

    if ($save) {
        db()->prepare('INSERT INTO sec_scans (monitor_id, ts, grade, score, ssl_ok, ssl_days, ssl_issuer, https_redirect, headers_ok, headers_missing, spf, dmarc, hsts, raw) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([
                (int) ($m['id'] ?? 0), time(), $grade, $score, $sslOk, $sslDays, $sslIssuer, $https ? 1 : 0,
                json_encode($headersFound), '', $spf, $dmarc, $hsts, $raw,
            ]);
    }

    return [
        'grade' => $grade,
        'score' => $score,
        'ssl_ok' => $sslOk,
        'ssl_days' => $sslDays,
        'ssl_issuer' => $sslIssuer,
        'https_redirect' => $https,
        'headers' => $headersFound,
        'spf' => $spf,
        'dmarc' => $dmarc,
        'hsts' => $hsts,
        'ip' => $ip,
        'blacklist' => $bl,
        'blacklisted' => $blacklisted ? 1 : 0,
    ];
}

function client_ip(): string
{
    $remote = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
    if (filter_var($remote, FILTER_VALIDATE_IP)) {
        return $remote;
    }
    foreach ([
        $_SERVER['HTTP_CF_CONNECTING_IP'] ?? '',
        $_SERVER['HTTP_X_REAL_IP'] ?? '',
        $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '',
    ] as $c) {
        $c = trim(explode(',', (string) $c)[0]);
        if (filter_var($c, FILTER_VALIDATE_IP)) {
            return $c;
        }
    }
    return 'unknown';
}

function ua(): string
{
    return substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? '-'), 0, 200);
}

function client_is_local(): bool
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    return in_array($ip, ['127.0.0.1', '::1'], true) || filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_RES_RANGE) === false;
}

function log_attack(string $reason, string $sev = 'low', bool $respectBan = true): void
{
    static $done = false;
    if ($done && $respectBan) {
        return;
    }
    if ($respectBan) {
        $done = true;
    }

    $ip = client_ip();
    db()->prepare('INSERT INTO attacks (ts, ip, ua, sev, reason) VALUES (?, ?, ?, ?, ?)')
        ->execute([time(), $ip, ua(), $sev, mb_substr($reason, 0, 300)]);

    if ($respectBan && !in_array($ip, ['127.0.0.1', '::1'], true)) {
        $q = db()->prepare('SELECT COUNT(*) FROM attacks WHERE ip = ? AND ts > ? AND sev IN (?, ?)');
        $q->execute([$ip, time() - 600, 'medium', 'high']);
        if ((int) $q->fetchColumn() >= 4) {
            ban_ip($ip, 'too many attack attempts within 10 minutes');
        }
    }
}

function ban_path(): string
{
    return dirname(__DIR__) . '/data/banned.json';
}

function is_banned(string $ip): bool
{
    $list = json_decode((string) @file_get_contents(ban_path()), true) ?: [];
    $ts = (int) ($list[$ip] ?? 0);
    if (!$ts) {
        return false;
    }
    if ($ts && (time() - $ts) > 86400) {
        unset($list[$ip]);
        file_put_contents(ban_path(), json_encode($list), LOCK_EX);
        return false;
    }
    return true;
}

function ban_ip(string $ip, string $why): void
{
    $list = json_decode((string) @file_get_contents(ban_path()), true) ?: [];
    $list[$ip] = time();
    file_put_contents(ban_path(), json_encode($list), LOCK_EX);
    log_attack("BANNED: $ip ($why)", 'high', false);
}

function unban_ip(string $ip): bool
{
    $list = json_decode((string) @file_get_contents(ban_path()), true) ?: [];
    if (isset($list[$ip])) {
        unset($list[$ip]);
        file_put_contents(ban_path(), json_encode($list), LOCK_EX);
        return true;
    }
    return false;
}

function ban_list(): array
{
    $list = json_decode((string) @file_get_contents(ban_path()), true) ?: [];
    return $list;
}

function attack_list(int $limit = 60): array
{
    $q = db()->prepare('SELECT ts, ip, ua, sev, reason FROM attacks ORDER BY ts DESC, id DESC LIMIT ?');
    $q->bindParam(1, $limit, PDO::PARAM_INT);
    $q->execute();
    return $q->fetchAll();
}

function attack_stats(): array
{
    $out = ['h24' => 0, 'd7' => 0, 'uniq24' => 0, 'high24' => 0, 'banned' => count(ban_list())];
    $q = db()->query("SELECT ts, ip, sev FROM attacks");
    $ips24 = [];
    foreach ($q->fetchAll() as $r) {
        $age = time() - (int) $r['ts'];
        if ($age < 86400) {
            $out['h24']++;
            $ips24[$r['ip']] = 1;
            if ($r['sev'] === 'high') {
                $out['high24']++;
            }
        }
        if ($age < 7 * 86400) {
            $out['d7']++;
        }
    }
    $out['uniq24'] = count($ips24);
    return $out;
}

function clear_attacks(): void
{
    db()->exec('DELETE FROM attacks');
}

function sec_middleware(): void
{
    $ip = client_ip();
    $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');

    if ($ip !== 'unknown' && is_banned($ip)) {
        http_response_code(403);
        echo '<h1 style="color:#e11d48">Your IP may be mistakenly banned (temporary).</h1>';
        exit;
    }
    if ($ip === 'unknown') {
        log_attack('ENUM PROBE: unclear/spoofed headers', 'low', false);
    }

    $path = strtolower(parse_url($uri, PHP_URL_PATH) ?? $uri);
    if (preg_match('~^(wp-login\.php|wp-admin|wp-includes|wp-content|xmlrpc\.php|setup-config|phpmyadmin|pma/|adminer|\.env|\.git|wp-config\.php|backup|dump)/?$~i', ltrim($path, '/'))
        || preg_match('~^(wp-login|wp-admin|xmlrpc|setup-config|adminer|config\.php)~', ltrim($path, '/'))) {
        log_attack('HONEYPOT: mencoba ' . $uri, 'high');
        http_response_code(403);
        echo '<h1 style="color:#e11d48">403 Forbidden</h1>';
        exit;
    }

    $ua = ua();
    if (preg_match('~(sqlmap|nikto|masscan|zgrab|dirbuster|gobuster|wfuzz|nuclei|exploit|hydra|metasploit|acunetix|nessus)~i', $ua)) {
        log_attack("SCANNER SIGNATURE: $ua", 'high');
        http_response_code(403);
        echo '<h1 style="color:#e11d48">Go away scanner.</h1>';
        exit;
    }

    $low = strtolower(rawurldecode($uri));
    if (preg_match('~(union[\s+/*]*select|insert[\s+/*]*into|drop[\s+/*]*table|<script|onerror\s*=|javascript:|eval\s*\(|system\s*\(|base64_decode|\.\.(/|%2f)+|%00)~', $low)) {
        log_attack('SUSPICIOUS PAYLOAD: ' . mb_substr($low, 0, 200), 'medium');
        http_response_code(400);
        echo '<h1 style="color:#e11d48">Bad Request</h1>';
        exit;
    }
}

function latest_sec_scans(): array
{
    $rows = [];
    $monitors = db()->query('SELECT id FROM monitors')->fetchAll();
    foreach ($monitors as $m) {
        $q = db()->prepare('SELECT * FROM sec_scans WHERE monitor_id = ? ORDER BY ts DESC LIMIT 1');
        $q->execute([(int) $m['id']]);
        $r = $q->fetch();
        if ($r) {
            $raw = json_decode((string) $r['raw'], true) ?: [];
            $rows[(int) $m['id']] = [
                'ts' => (int) $r['ts'],
                'grade' => $r['grade'],
                'score' => (int) $r['score'],
                'ssl_ok' => (int) $r['ssl_ok'],
                'ssl_days' => $r['ssl_days'] === null ? null : (int) $r['ssl_days'],
                'ssl_issuer' => $r['ssl_issuer'],
                'https_redirect' => (int) $r['https_redirect'],
                'headers' => json_decode((string) $r['headers_ok'], true) ?: [],
                'spf' => (int) $r['spf'],
                'dmarc' => (int) $r['dmarc'],
                'hsts' => (int) $r['hsts'],
                'ip' => $raw['ip'] ?? null,
                'blacklist' => $raw['blacklist'] ?? [],
                'blacklisted' => (int) ($raw['blacklisted'] ?? 0),
            ];
        }
    }
    return $rows;
}


