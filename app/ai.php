<?php

function ai_ready(): bool
{
    return AI_API_KEY !== '' && AI_BASE_URL !== '' && AI_MODEL !== '';
}

function ai_settings(): array
{
    return ['base' => rtrim(AI_BASE_URL, '/'), 'key' => AI_API_KEY, 'model' => AI_MODEL];
}

function ago_str(int $ts): string
{
    $d = time() - $ts;
    if ($d < 60) return $d . ' detik lalu';
    if ($d < 3600) return floor($d / 60) . ' menit lalu';
    if ($d < 86400) return floor($d / 3600) . ' jam lalu';
    return floor($d / 86400) . ' hari lalu';
}

function ai_context(): string
{
    $lines = ['STATUS SAAT INI:'];
    foreach (db()->query('SELECT * FROM monitors ORDER BY group_name, id') as $m) {
        $status = monitor_status($m);
        $u24 = uptime_pct((int) $m['id'], 1);
        $u7 = uptime_pct((int) $m['id'], 7);
        $c = latest_check((int) $m['id']);
        $row = $c ? $c[0] : null;
        $lines[] = sprintf('- [%s] %s (%s) | uptime 24j %s%% | 7h %s%% | latensi %s | kode %s | dicek %s',
            strtoupper($status),
            $m['name'],
            $m['url'],
            $u24 < 0 ? 'n/a' : (string) $u24,
            $u7 < 0 ? 'n/a' : (string) $u7,
            $row ? ((int) $row['latency_ms'] . ' ms') : 'n/a',
            $row ? ('HTTP ' . (int) $row['code']) : '-',
            $row ? ago_str((int) $row['ts']) : 'no data yet'
        );
    }
    foreach (open_incidents() as $i) {
        $lines[] = sprintf('INCIDENT_AKTIF: %s sejak %s (%s)', $i['monitor_name'], ago_str((int) $i['start_ts']), $i['err']);
    }
    foreach (recent_incidents(5) as $i) {
        if ($i['end_ts']) {
            $lines[] = sprintf('INCIDENT_SELESAI: %s, durasi %d menit (%s)', $i['monitor_name'], (int) round(((int) $i['end_ts'] - (int) $i['start_ts']) / 60), $i['err']);
        }
    }
    return implode("\n", $lines);
}

const AI_SYSTEM = <<<'TXT'
Kamu adalah asisten monitoring di dalam dashboard uptime bernama %BRIEF%.
Respond in the user's language; default to concise, professional ENGLISH when they write English, and Indonesian when they use Indonesian, gunakan Markdown sederhana (bold, bullet) bila membantu.
Data monitor pengguna dikirim di akhir pesan user setiap kali chat. Rujuk data itu, jangan berhalusinasi angka.
Jika ada insiden aktif, jelaskan akar masalah yang mungkin, level keparahan, dan langkah mitigasi konkret.
Jika data cukup, tambahkan rekomendasi preventif singkat (mis. timeout, CDN, retry).
Jawaban maksimal sekitar 250 kata kecuali diminta rinci.
Jawab langsung dan padat. Jangan mengulang seluruh data mentah; sebut hanya angka yang relevan.
TXT;

function chat_stream(array $history, string $userMsg, callable $sink): void
{
    $s = ai_settings();
    $messages = [['role' => 'system', 'content' => str_replace('%BRIEF%', SITE_NAME, AI_SYSTEM)]];
    foreach (array_slice($history, -2) as $m) {
        $messages[] = ['role' => $m['role'], 'content' => (string) $m['content']];
    }

    $messages[] = ['role' => 'user', 'content' => $userMsg . "\n\nDATA DASHBOARD SAAT INI:\n" . ai_context()];

    $buffer = '';
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $s['base'] . '/chat/completions',
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $s['key'],
            'Content-Type: application/json',
            'Accept: text/event-stream',
        ],
        CURLOPT_POSTFIELDS => json_encode([
            'model' => $s['model'],
            'stream' => true,
            'temperature' => 0.4,
            'max_tokens' => 700,
            'messages' => $messages,
        ]),
        CURLOPT_TIMEOUT => 60,
        CURLOPT_WRITEFUNCTION => function ($ch, $data) use ($sink, &$got, &$rawTail, &$buffer) {
            $len = strlen($data);
            $rawTail = substr(($rawTail ?? '') . $data, -2000);
            $buffer .= $data;
            while (($pos = strpos($buffer, "\n")) !== false) {
                $line = trim(substr($buffer, 0, $pos));
                $buffer = substr($buffer, $pos + 1);
                if (strpos($line, 'data:') !== 0) {
                    continue;
                }

                $payload = trim(substr($line, 5));
                if ($payload === '' || $payload === '[DONE]') {
                    continue;
                }
                $j = json_decode($payload, true);
                $delta = $j['choices'][0]['delta']['content'] ?? '';
                if ($delta !== '') {
                    $got = true;
                    $sink($delta);
                }
            }
            return $len;
        },
    ]);

    $ok = curl_exec($ch);
    if (!$got && (curl_error($ch) !== '')) {
        curl_close($ch);
        throw new RuntimeException('AI stream gagal: ' . curl_error($ch));
    }
    curl_close($ch);
    if (!$got) {
        $e = json_decode((string) $rawTail, true);
        $msg = $e['error']['message'] ?? $e['message'] ?? 'respons AI kosong';
        throw new RuntimeException('AI gateway: ' . $msg);
    }
}

function ai_cached_insight(int $ttlMin): ?string
{
    $row = db()->query('SELECT ts, body FROM insights ORDER BY ts DESC LIMIT 1')->fetch();
    if (!$row || (time() - (int) $row['ts']) > $ttlMin * 60) {
        return null;
    }
    return $row['body'];
}

function generate_insight(): string
{
    $s = ai_settings();
    $prompt = 'Buat ringkasan kesehatan jaringan situs web pemilik dashboard ini dalam Bahasa Indonesia, format Markdown (' .
        'paragraf pembuka 1-2 kalimat, poin per domain, lalu "Langkah selanjutnya" berisi 3 poin aksi singkat). ' .
        'Gunakan HANYA data berikut. Maksimal 180 kata.' . "\n\n" . ai_context();

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $s['base'] . '/chat/completions',
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $s['key'],
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS => json_encode([
            'model' => $s['model'],
            'temperature' => 0.3,
            'max_tokens' => 500,
            'messages' => [
                ['role' => 'system', 'content' => str_replace('%BRIEF%', SITE_NAME, AI_SYSTEM)],
                ['role' => 'user', 'content' => $prompt],
            ],
        ]),
        CURLOPT_TIMEOUT => 55,
    ]);

    $res = curl_exec($ch);
    $errno = curl_errno($ch);
    curl_close($ch);

    if ($errno || !$res) {
        throw new RuntimeException('Permintaan AI gagal (' . $errno . ')');
    }

    $j = json_decode($res, true);
    $text = $j['choices'][0]['message']['content'] ?? '';
    if ($text === '') {
        throw new RuntimeException('Respons AI kosong');
    }

    db()->prepare('INSERT INTO insights (ts, body) VALUES (?, ?)')->execute([time(), $text]);
    return $text;
}

function maybe_analyze(array $m, int $now): void
{
    $last = db()->query('SELECT ts FROM insights ORDER BY ts DESC LIMIT 1')->fetch();
    if ($last && ($now - (int) $last['ts']) < (INSIGHT_TTL_MIN * 60)) {
        return;
    }
    generate_insight();
}

