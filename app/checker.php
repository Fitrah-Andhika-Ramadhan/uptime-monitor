<?php

function hue($seed)
{
    $h = 0;
    foreach (str_split($seed) as $c) {
        $h = ($h * 31 + ord($c)) % 360;
    }
    return $h;
}

function check_all_due(): void
{
    $now = time();
    foreach (db()->query('SELECT * FROM monitors ORDER BY id') as $m) {
        $last = db()->prepare('SELECT MAX(ts) FROM checks WHERE monitor_id = ?');
        $last->execute([$m['id']]);
        $lastTs = (int) $last->fetchColumn();
        if ($lastTs && ($now - $lastTs) < $m['interval_min'] * 60 - 30) {
            continue;
        }
        run_check($m, $now);
    }
    if (random_int(1, 100) <= 5) {
        db()->exec('DELETE FROM checks WHERE ts < ' . ($now - 45 * 86400));
    }
    auto_scan_rotation();
}

function auto_scan_rotation(): void
{
    require_once dirname(__DIR__) . '/app/sec.php';
    $ttl = (time()) - (defined('SCAN_TTL_H') ? SCAN_TTL_H : 6) * 3600;
    $q = db()->query('SELECT m.id,
            (SELECT MAX(ts) FROM sec_scans s WHERE s.monitor_id = m.id) AS last_ts
        FROM monitors m ORDER BY (last_ts IS NULL) DESC, last_ts ASC LIMIT 1');
    $row = $q->fetch();
    if (!$row) {
        return;
    }
    if ($row['last_ts'] !== null && (int) $row['last_ts'] > $ttl) {
        return;
    }
    $st = db()->prepare('SELECT * FROM monitors WHERE id = ?');
    $st->execute([(int) $row['id']]);
    $m = $st->fetch();
    if ($m) {
        try {
            scan_security($m);
        } catch (Throwable $e) {
        }
    }
}

function run_check(array $m, int $now): array
{
    $ch = curl_init($m['url']);
    $isHead = strtoupper($m['method'] ?? 'HEAD') === 'HEAD';
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $isHead ? 'HEAD' : 'GET',
        CURLOPT_NOBODY => $isHead,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_CONNECTTIMEOUT => CHECK_TIMEOUT,
        CURLOPT_TIMEOUT => CHECK_TIMEOUT,
        CURLOPT_ENCODING => '',
        CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; XttackMonitor/1.0)',
    ]);
    $body = curl_exec($ch);
    $errno = curl_errno($ch);
    $errmsg = curl_error($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $latency = (int) round((float) curl_getinfo($ch, CURLINFO_TOTAL_TIME) * 1000);
    curl_close($ch);

    $ok = true;
    $down = false;
    $err = null;

    if ($errno) {
        $ok = false;
        $down = true;
        $err = 'cURL #' . $errno . ': ' . $errmsg;
    } else {
        $expected = (int) ($m['expected_code'] ?? 0);
        if ($expected > 0) {
            $ok = $code === $expected;
            if (!$ok) {
                $err = 'HTTP ' . $code . ' (diharapkan ' . $expected . ')';
            }
        } else {
            $ok = $code >= 200 && $code < 400 && $code !== 0;
            if (!$ok) {
                $err = 'HTTP ' . $code;
            }
        }
        $kw = trim((string) ($m['keyword'] ?? ''));
        if ($kw !== '' && is_string($body) && $body !== '') {
            $ok = $ok && stripos($body, $kw) !== false;
            if (!($ok)) {
                $err = 'kata kunci "' . $kw . '" tidak ditemukan';
            }
        }
        if ($code >= 500) {
            $down = true;
        }
    }

    $mid = (int) ($m['id'] ?? 0);
    if ($mid > 0) {
        db()->prepare('INSERT INTO checks (monitor_id, ts, code, latency_ms, ok, down, err) VALUES (?, ?, ?, ?, ?, ?, ?)')
            ->execute([$mid, $now, $code, $latency, $ok ? 1 : 0, $down ? 1 : 0, $err]);

        $open = db()->prepare('SELECT id, start_ts FROM incidents WHERE monitor_id = ? AND end_ts IS NULL ORDER BY start_ts DESC LIMIT 1');
        $open->execute([$mid]);
        $inc = $open->fetch();

        if ($down && !$inc) {
            db()->prepare('INSERT INTO incidents (monitor_id, start_ts, err) VALUES (?, ?, ?)')
                ->execute([$mid, $now, $err]);
        } elseif (!$down && $inc) {
            $dur = $now - (int) $inc['start_ts'];
            db()->prepare('UPDATE incidents SET end_ts = ?, err = ? WHERE id = ?')
                ->execute([$now, 'auto-recovered after ' . $dur .'s', $inc['id']]);
        }

        if ($down && ai_ready()) {
            try {
                maybe_analyze($m, $now);
            } catch (Throwable $e) {
            }
        }
    }

    return compact('code', 'latency', 'ok', 'down', 'err');
}

function latest_check(int $id)
{
    $q = db()->prepare('SELECT * FROM checks WHERE monitor_id = ? ORDER BY ts DESC LIMIT 60');
    $q->execute([$id]);
    return $q->fetchAll();
}

function uptime_pct(int $id, int $days): float
{
    $q = db()->prepare('SELECT
            SUM(CASE WHEN ok = 1 THEN 1 ELSE 0 END) * 100.0 / COUNT(*) AS up
        FROM checks WHERE monitor_id = ? AND ts >= ?');
    $q->execute([$id, time() - $days * 86400]);
    $v = $q->fetchColumn();
    return $v === null ? -1.0 : round((float) $v, 2);
}

function monitor_status(array $m): string
{
    $c = latest_check((int) $m['id']);
    if (!$c) {
        return 'unknown';
    }
    $recent = array_slice($c, 0, 5);
    $fails = count(array_filter($recent, fn ($r) => (int) $r['ok'] === 0));
    $lastRecent = $recent[0];
    if ((int) $lastRecent['down'] === 1 && $fails >= 3) {
        return 'down';
    }
    $avg = array_sum(array_map(fn ($r) => (int) $r['latency_ms'], $recent)) / max(count($recent), 1);
    if ($avg > DEGRADED_MS) {
        return 'degraded';
    }
    return 'up';
}

function open_incidents(): array
{
    return db()->query('SELECT i.*, m.name AS monitor_name FROM incidents i
        JOIN monitors m ON m.id = i.monitor_id
        WHERE i.end_ts IS NULL ORDER BY i.start_ts DESC')->fetchAll();
}

function recent_incidents(int $limit = 8): array
{
    $q = db()->prepare('SELECT i.*, m.name AS monitor_name FROM incidents i
        JOIN monitors m ON m.id = i.monitor_id ORDER BY i.start_ts DESC LIMIT ?');
    $q->execute([$limit]);
    return $q->fetchAll();
}

function monitors_payload(): array
{
    $rows = [];
    foreach (db()->query('SELECT * FROM monitors ORDER BY group_name, id') as $m) {
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
    return $rows;
}

