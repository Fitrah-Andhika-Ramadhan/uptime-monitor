<?php

function gate_checks(string $url): array
{
    $host = parse_url($url, PHP_URL_HOST) ?: '';
    $items = [];

    $m = ['id' => 0, 'url' => $url, 'method' => 'HEAD', 'expected_code' => 0, 'keyword' => '', 'interval_min' => 5];
    $r = run_check($m, time());
    $items[] = [
        'key' => 'health',
        'label' => 'Kesehatan endpoint',
        'pass' => !($r['down'] ?? true),
        'warn' => ($r['ok'] ?? false) && ($r['latency'] ?? 999) > DEGRADED_MS,
        'detail' => "HTTP {$r['code']}, {$r['latency']} ms" . ($r['err'] ? " - {$r['err']}" : ''),
    ];

    $items[] = [
        'key' => 'https',
        'label' => 'HTTPS aktif',
        'pass' => stripos($url, 'https://') === 0,
        'warn' => false,
        'detail' => stripos($url, 'https://') === 0 ? 'HTTPS' : 'URL masih http://',
    ];

    if ($host !== '') {
        $spf = 0;
        $dmarc = 0;
        foreach (@dns_get_record($host, DNS_TXT) ?: [] as $rec) {
            if (stripos((string) ($rec['txt'] ?? ''), 'v=spf1') === 0) {
                $spf = 1;
            }
        }
        foreach (@dns_get_record('_dmarc.' . $host, DNS_TXT) ?: [] as $rec) {
            if (stripos((string) ($rec['txt'] ?? ''), 'v=dmarc1') === 0) {
                $dmarc = 1;
            }
        }
        $items[] = [
            'key' => 'dns',
            'label' => 'DNS resolve + SPF/DMARC',
            'pass' => $spf && $dmarc && checkdnsrr($host, 'A'),
            'warn' => ($spf xor $dmarc),
            'detail' => 'SPF ' . ($spf ? 'OK' : 'tidak ada') . ' / DMARC ' . ($dmarc ? 'OK' : 'tidak ada'),
        ];
    }

    $headers = [];
    $hdrs = sec_headers_only($url);
    $need = ['strict-transport-security', 'x-frame-options', 'x-content-type-options', 'referrer-policy', 'content-security-policy'];
    $missing = [];
    foreach ($need as $n) {
        if (!isset($hdrs[$n]) || $hdrs[$n] === '') {
            $missing[] = $n;
        }
    }
    $items[] = [
        'key' => 'headers',
        'label' => 'Security headers',
        'pass' => count($missing) === 0,
        'warn' => count($missing) <= 2 && count($missing) > 0,
        'detail' => count($missing) ? 'kurang: ' . implode(', ', $missing) : 'lengkap',
    ];

    return $items;
}

function sec_headers_only(string $url): array
{
    $ch = curl_init($url);
    $hdrs = [];
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_NOBODY => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_CONNECTTIMEOUT => CHECK_TIMEOUT,
        CURLOPT_TIMEOUT => CHECK_TIMEOUT,
        CURLOPT_HEADERFUNCTION => function ($c, $line) use (&$hdrs) {
            if (stripos($line, 'HTTP/') !== 0 && ($p = strpos($line, ':')) !== false) {
                $hdrs[strtolower(trim(substr($line, 0, $p)))] = trim(substr($line, $p + 1));
            }
            return strlen($line);
        },
        CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; XttackGate/1.0)',
    ]);
    curl_exec($ch);
    curl_close($ch);
    return $hdrs;
}

function gate_verdict(array $items): array
{
    $fail = 0;
    $warn = 0;
    foreach ($items as $i) {
        if ($i['pass']) {
            continue;
        }
        if (!empty($i['warn'])) {
            $warn++;
        } else {
            $fail++;
        }
    }
    if ($fail === 0 && $warn === 0) {
        return ['verdict' => 'passed', 'score' => 100];
    }
    if ($fail === 0) {
        return ['verdict' => 'warn', 'score' => max(60, 100 - $warn * 15)];
    }
    return ['verdict' => 'blocked', 'score' => max(10, 100 - $fail * 30 - $warn * 10)];
}

function gate_ai_note(array $items): string
{
    if (!ai_ready()) {
        return '';
    }
    $summary = '';
    foreach ($items as $i) {
        $summary .= '- ' . $i['label'] . ': ' . ($i['pass'] ? 'OK' : (!empty($i['warn']) ? 'WARN' : 'GAGAL')) . ' (' . $i['detail'] . ")\n";
    }

    $s = ai_settings();
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $s['base'] . '/chat/completions',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $s['key'], 'Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode([
            'model' => $s['model'],
            'temperature' => 0.2,
            'max_tokens' => 250,
            'messages' => [
                ['role' => 'system', 'content' => 'Kamu reviewer deployment. Berdasar checklist, tulis verdict ringkas Bahasa Indonesia (maks 60 kata): layak tindakan lanjut atau perbaiki dulu beserta alasannya. Tanpa format Markdown berat.'],
                ['role' => 'user', 'content' => "CHECKLIST PENEMPATAN:\n" . $summary],
            ],
        ]),
        CURLOPT_TIMEOUT => 50,
    ]);
    $res = curl_exec($ch);
    $errno = curl_errno($ch);
    curl_close($ch);
    if ($errno || !$res) {
        return '';
    }
    $j = json_decode($res, true);
    $text = $j['choices'][0]['message']['content'] ?? '';
    if ($text === '') {
        $text = $j['error']['message'] ?? '';
    }
    return trim((string) $text);
}

function record_deployment(string $site, ?string $branch, ?string $sha, ?string $message, string $status, array $items = [], string $aiNote = ''): int
{
    db()->prepare('INSERT INTO deployments (ts, site, branch, commit_sha, message, status, items, ai_note) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([time(), $site, $branch, $sha, $message, $status, json_encode($items), $aiNote]);
    return (int) db()->lastInsertId();
}

function update_deployment(int $id, string $status, array $items = [], string $aiNote = ''): void
{
    db()->prepare('UPDATE deployments SET status = ?, items = ?, ai_note = ? WHERE id = ?')
        ->execute([$status, json_encode($items), $aiNote, $id]);
}

function deployment_list(int $limit = 40): array
{
    $q = db()->prepare('SELECT * FROM deployments ORDER BY ts DESC, id DESC LIMIT ?');
    $q->bindParam(1, $limit, PDO::PARAM_INT);
    $q->execute();
    $rows = $q->fetchAll();
    return array_map(static function ($r) {
        return [
            'id' => (int) $r['id'],
            'ts' => (int) $r['ts'],
            'site' => $r['site'],
            'branch' => $r['branch'],
            'sha' => $r['commit_sha'] ? substr($r['commit_sha'], 0, 7) : null,
            'message' => $r['message'],
            'status' => $r['status'],
            'ai_note' => $r['ai_note'],
        ];
    }, $rows);
}

function gate_logs(int $limit = 10): array
{
    $q = db()->prepare('SELECT * FROM deploy_logs ORDER BY ts DESC LIMIT ?');
    $q->bindParam(1, $limit, PDO::PARAM_INT);
    $q->execute();
    return array_map(static fn ($r) => [
        'ts' => (int) $r['ts'],
        'url' => $r['url'],
        'name' => $r['name'],
        'verdict' => $r['verdict'],
        'score' => (int) $r['score'],
        'items' => json_decode((string) $r['items'], true) ?: [],
        'ai_note' => (string) $r['ai_note'],
    ], $q->fetchAll());
}

function gate_log_add(string $url, ?string $name, string $verdict, int $score, array $items, string $aiNote): void
{
    db()->prepare('INSERT INTO deploy_logs (ts, url, name, verdict, score, items, ai_note) VALUES (?, ?, ?, ?, ?, ?, ?)')
        ->execute([time(), $url, $name, $verdict, $score, json_encode($items), $aiNote]);
}

