# PRD — Xttack (AI Uptime & Cyber Monitor)

> Dokumen ini adalah **handoff spec** untuk melanjutkan pengembangan dengan AI/engineer lain.
> Versi: 1.0 · Status: production-ish (deploy Hostinger) · Bahasa UI: **English** kecuali Terminal (Indonesia).

---

## 1. Ringkasan Produk

**Xttack** adalah aplikasi web (PHP + SQLite, tanpa build step) yang menggabungkan:

1. **Uptime & latency monitoring** multi-domain (cron + realtime SSE).
2. **Security hardening scan** (SSL, security headers, SPF/DMARC, blacklist DNSBL nyata).
3. **Attack detection** (honeypot, scanner UA, SQLi/XSS payload, autoban IP) — middleware internal.
4. **Deploy gate** (pre-flight checklist + verdict AI) & **riwayat Penempatan/Deployments** via webhook GitHub.
5. **AI assistant** (chat streaming SSE + insight otomatis + catatan gate).
6. **Terminal real** (eksekusi perintah nyata di server, safe-mode allowlist).
7. **Database CRUD** (browse/insert/update/delete tabel + SQL read-only console).

Target hosting: **Hostinger Business Web Hosting** (Apache/LiteSpeed, PHP 8.x, SQLite).

### Tujuan
- Satu dashboard untuk memantau semua domain pengguna + gerbang deploy sebelum rilis.
- Deteksi dini gangguan (uptime, SSL, blacklist) dan serangan (probe/scanner/bruteforce).
- Asisten AI yang menjawab berbasis data monitoring nyata (bukan halusinasi).

### Non-Goals
- Bukan web hosting/CDN (tidak menyimpan aset situs lain).
- Bukan full SIEM/WAF network-level (hanya tingkat aplikasi + DNSBL).
- Bukan multi-tenant SaaS (single admin).

---

## 2. Persona & Skenario

| Persona | Skenario utama |
|---|---|
| Pemilik beberapa domain (Hostinger) | Pantau uptime, cek SSL/blacklist, gate sebelum deploy |
| DevOps tunggal | Webhook GitHub → checklist otomatis → verdict, lihat riwayat Penempatan |
| Operator keamanan | Lihat attacker IP/UA + alasan, unban, baca hasil hardening |
| Power user | Terminal: `mon`, `check`, `cron`, `sql`, `curl`, `dns`, `attacks` |

---

## 3. Status Fitur

| # | Fitur | Status | Catatan |
|---|---|---|---|
| F1 | Monitor uptime/latency HEAD/GET + keyword + expected code | ✅ | cek per interval (min 5 menit) via cron |
| F2 | Riwayat checks (60 terakhir) & incidents (up/down transitions) | ✅ | status: up/degraded/down/pending |
| F3 | Uptime 24h & 7d per monitor | ✅ | `uptime_pct()` |
| F4 | Realtime dashboard via SSE | ✅ | tick 10 detik; fallback polling 15 detik |
| F5 | Security scan: SSL (leaf), headers, SPF/DMARC, DNSBL | ✅ | auto-rotasi tiap `SCAN_TTL_H` (6 jam) |
| F6 | Grade A–F + skor /90 | ✅ | blacklist → −40 poin |
| F7 | Attack detection: honeypot, scanner UA, payload, autoban 24 jam | ✅ | file `data/banned.json` |
| F8 | Deploy gate manual + AI note | ✅ | `POST action=gate` |
| F9 | Penempatan via webhook GitHub | ✅ | `POST action=deploy-hook&secret=` |
| F10 | Chat AI streaming (SSE) + history file per-session | ✅ | provider OpenAI-compatible |
| F11 | Insight otomatis (cached `INSIGHT_TTL_MIN`) | ✅ | dipakai juga saat DOWN |
| F12 | Terminal real safe-mode | ✅ | `proc_open`/native; allowlist |
| F13 | Database CRUD + SQL read-only console | ✅ | tabel writable dibatasi |
| F14 | Diagnostik (`?action=diag`) | ✅ | cek ekstensi/izin/config |
| F15 | Error JSON global (bukan blank 500) | ✅ | `set_exception_handler` |
| F16 | Auto-scan rotasi | ✅ | 1 monitor/rotasi, tiap ≥6 jam |
| — | E-mail/Slack/WA notifikasi | ❌ | roadmap |
| — | Grafana-style time-series charts | ❌ | roadmap |
| — | Multi-user & RBAC | ❌ | roadmap |

---

## 4. Arsitektur

```
E:\uptime-monitor  (branch main — layout klasik)
├─ public/                 # docroot (index.html, index.php, assets/)
│   ├─ index.php           # API front controller + SSE
│   ├─ index.html          # SPA shell
│   └─ assets/{css,js}
├─ app/
│   ├─ db.php              # PDO sqlite + schema + seed
│   ├─ checker.php         # uptime checks, status, incidents, auto-scan rotation
│   ├─ ai.php              # AI context, chat stream, insight
│   ├─ sec.php             # scan hardening, DNSBL, attack log/ban, middleware
│   ├─ deploy.php          # gate checks, verdict, AI note, deployments
│   ├─ dbui.php            # generic CRUD + read-only SQL
│   ├─ tty.php             # terminal executor (safe-mode allowlist)
│   ├─ auth.php            # session, CSRF, throttle, JSON helpers
│   └─ diag.php            # environment diagnostics
├─ cron/check.php          # cron entrypoint (uptime + rotasi scan)
├─ data/                   # Xttack.sqlite, banned.json, chat_*.json  (web-blocked)
├─ config.php              # RAHASIA (gitignored)
├─ config.example.php
└─ router.php              # dev server router (dual layout aware)
```

**Branch `hostinger-root`** (untuk deploy langsung ke `public_html`): `public/*` di-flatten ke root; `app/`, `data/`, `cron/`, `config.php` ikut berada di dalam `public_html` dan **dilindungi `.htaccess`**.

### Layout detection
`public/index.php` / `index.php`:
```php
$base = dirname(__DIR__);
if (!is_file($base.'/app/db.php') && is_file(__DIR__.'/app/db.php')) $base = __DIR__;
define('APP_BASE', $base);
```
Semua `app/*.php` memakai `dirname(__DIR__)` (selalu = root project di kedua layout).

---

## 5. Data Model (SQLite)

`db()` di `app/db.php` — auto-create schema + seed 2 monitor contoh (Hostinger, Cloudflare) hanya saat DB baru.

| Tabel | Kolom | Keterangan |
|---|---|---|
| `monitors` | id, group_name, name, url, interval_min, method(HEAD/GET), expected_code, keyword, created_at | target monitoring |
| `checks` | id, monitor_id, ts, code, latency_ms, ok, down, err | riwayat cek; index `(monitor_id, ts DESC)` |
| `incidents` | id, monitor_id, start_ts, end_ts, err | periode DOWN |
| `insights` | id, ts, body | cache insight AI |
| `sec_scans` | id, monitor_id, ts, grade, score, ssl_ok, ssl_days, ssl_issuer, https_redirect, headers_ok(JSON), headers_missing, spf, dmarc, hsts, raw(JSON: ip, blacklist, code, blacklisted) | hasil hardening |
| `attacks` | id, ts, ip, ua, sev(low/medium/high), reason | event serangan |
| `deploy_logs` | id, ts, url, name, verdict, score, items(JSON), ai_note | hasil gate manual |
| `deployments` | id, ts, site, branch, commit_sha, message, status(passed/warn/blocked/checking), items(JSON), ai_note | riwayat push GitHub |

Retensi: `checks` >45 hari dipangkas acak 5% saat cron. `attacks` & `deployments` tanpa pruning (roadmap).

---

## 6. API Reference

Front controller: `GET|POST /index.php?action=<name>`

**Umum:** respons JSON `{ok:true,...}`; error `{ok:false,error:"..."}` + status code. POST wajib header `X-Requested-With: fetch` + `X-CSRF: <token>` untuk endpoint terproteksi.

| Action | Method | Auth | Payload/Query | Respons |
|---|---|---|---|---|
| `me` | GET | – | – | auth, csrf, site, ai(configured?) |
| `login` | POST | – | `{password}` | `{csrf}` · throttle 6/5mnt |
| `logout` | POST | ✔ | – | ok |
| `live` | GET | – | – | **SSE** `event: tick` tiap 10 dtk ×8 (monitors, att, sec, ts) |
| `monitors` | GET | – | – | daftar monitor + status/uptime/bar/spark |
| `history` | GET | – | `id` | 200 checks + 15 incidents |
| `monitor-create` | POST | ✔ | name,url,group_name,interval_min,method,expected_code,keyword | id (langsung cek pertama) |
| `monitor-update` | POST | ✔ | id + field | ok |
| `monitor-delete` | POST | ✔ | id | ok |
| `instant-check` | POST | ✔ | id | result + status_after |
| `security` | GET | ✔ | – | map monitor→scan terakhir |
| `security-scan` | POST | ✔ | id | hasil scan (SSL/headers/DNS/blacklist) · throttle 15/jam |
| `attacks` | GET | ✔ | – | rows(60) + stats + bans |
| `attacks-clear` | POST | ✔ | – | hapus log |
| `unban` | POST | ✔ | ip | ok |
| `deployments` | GET | ✔ | – | rows(40) |
| `deploy-hook` | POST | secret | GitHub push payload | id + verdict |
| `gate` | POST | ✔ | url,name? | verdict, score, items, ai_note · throttle 20/jam |
| `gate-logs` | GET | ✔ | – | 10 gate terakhir |
| `insight` | POST | ✔ | – | text (cached/new) · throttle 20/jam |
| `chat` | POST | ✔ | `{message}` | **SSE** `data:{c}` … `event: done` · throttle 15/5mnt |
| `db-tables` | GET | ✔ | – | tables + columns |
| `db-rows` | GET | ✔ | table,page,q | rows(50) |
| `db-insert` / `db-update` / `db-delete` | POST | ✔ | table,data / id | ok |
| `db-exec` | POST | ✔ | sql (SELECT/PRAGMA/EXPLAIN/WITH) | rows(200) |
| `tty` | POST | ✔ | `{cmd}` | `{result:{out,code}}` |
| `diag` | GET | – | – | lingkungan & diagnosa |

**Tabel writable (CRUD):** monitors, incidents, attacks, deployments, insights.
**Read-only:** checks, sec_scans, deploy_logs.

---

## 7. Realtime (SSE)

- Endpoint `?action=live`: satu koneksi, 8 tick × 10 dtk lalu ditutup; browser `EventSource` auto-reconnect.
- Payload: `{monitors, att(stats), sec(map scan), ts}` → client (`onLive`) me-render ulang view aktif (Dashboard/Statistics/Security).
- Dev lokal single-thread: jalankan `PHP_CLI_SERVER_WORKERS=8 php -S ... router.php` agar SSE tidak memblokir request lain.
- Di produksi (LiteSpeed/Apache) tidak perlu konfigurasi khusus.

---

## 8. Security Model

1. **Auth**: satu admin, `ADMIN_PASSWORD` (plain constant; roadmap → hash).
2. **Session**: `fitcyber_sess`, cookie HttpOnly, SameSite=Lax, Secure bila HTTPS.
3. **CSRF**: token di `$_SESSION['csrf']`, wajib header `X-CSRF` untuk POST terproteksi.
4. **Rate limit**: session-based (`throttle()`): login 6/300s, chat 15/300s, scan 15/3600s, gate 20/3600s.
5. **Middleware `sec_middleware()`** (setiap request):
   - Honeypot path → 403 + log (`wp-login.php`, `/.env`, `/phpmyadmin`, dll).
   - Scanner UA → 403 + log (sqlmap, nikto, nuclei, …).
   - Payload regex SQLi/XSS (`union select`, `<script`, `../`) → 400 + log.
   - IP banned (`data/banned.json`, TTL 24 jam) → 403.
6. **Autoban**: ≥4 event `medium|high` dalam 10 menit → ban 24 jam (loopback dikecualikan, aman untuk dev).
7. **Isolasi file**: `.htaccess` memblokir `config.php`, `app/`, `data/`, `cron/`, `*.sqlite`, `*.db`.
8. **Terminal safe-mode**: blokir metachar (`; & | > < \` $ ( ) { } [ ] ! * ? ~ \\`), path traversal dibatasi `tty_root()`; allowlist perintah. `ENABLE_RAW_SHELL=true` = shell bebas (**RCE**, hanya untuk server sendiri).

---

## 9. Integrasi AI

- Konfigurasi: `AI_BASE_URL`, `AI_API_KEY`, `AI_MODEL` (OpenAI-compatible `POST /chat/completions`).
- Provider teruji: OpenCode Zen (`https://opencode.ai/zen/v1`), model `space-bunny-free`. Groq/OpenAI/OpenRouter kompatibel.
- **Konteks**: `ai_context()` menyuntik status semua monitor, uptime, insiden aktif, insiden selesai.
- **Chat**: streaming SSE; parser hanya menerima `delta.content` (mengabaikan `reasoning_content`); history 2 pesan terakhir + file `data/chat_<sid>.json` (TTL 2 jam).
- **Insight/gate note**: request non-stream; hasil disimpan (`insights`, `deploy_logs`, `deployments`).
- Kegagalan provider (funds/free-tier) kini ditampilkan sebagai pesan error yang jelas.
- Prompt: Bahasa mengikuti user; default English; ringkas.

---

## 10. Terminal (REAL)

`app/tty.php → tty_run($line)`:

| Perintah | Implementasi |
|---|---|
| `ls/dir [path]`, `cat`, `tail`, `grep` | PHP native, dibatasi root project, maks 256 KB |
| `pwd, whoami, date, env, echo` | native |
| `php -v/-m/-i`, `php cron` | `php` = `PHP_BINARY`; `cron` = `check_all_due()` |
| `df`, `free`, `uptime` | native/`powershell` (Windows) & `/proc` (Linux) |
| `ps/tasklist`, `ping`, `git status|log|branch` | `proc_open` (bisa diblokir shared hosting) |
| `dns <host>` | `dns_get_record` A/AAAA/MX/TXT/NS |
| `curl <url>` | cURL + timing + preview |
| `mon`, `check <id|name>`, `cron` | reuse checker |
| `sql <SELECT...>` | `dbui_sql_readonly` |
| `attacks`, `bans`, `unban <ip>` | reuse sec |
| `help`, `clear`, `exit` | client/server |

Bila `proc_open` disabled → perintah OS balas `exec failed` (ditangani, bukan crash).

---

## 11. Deployment (Hostinger)

### Layout A — klasik (`main`)
`public/*` → `public_html/`; `config.php`, `app/`, `data/`, `cron/` di root akun (di atas `public_html`) + docroot diarahkan ke `<repo>/public` (butuh subdomain/docroot custom).

### Layout B — root (`hostinger-root`) ← **dipakai pengguna**
1. hPanel → Advanced → GIT → repo `Fitrah-Andhika-Ramadhan/uptime-monitor`, branch `hostinger-root`, dir `public_html` → Deploy.
2. Buat `config.php` di `public_html` (dari `config.example.php`).
3. `data/` permission 755 (775 bila gagal tulis).
4. Cron tiap 5 menit: `/usr/local/bin/php /home/USERNAME/public_html/cron/check.php`.
5. Webhook GitHub: `https://DOMAIN/index.php?action=deploy-hook&secret=CRON_SECRET` (json, event push).
6. Verifikasi: `/index.php?action=diag` → login.

Alternatif: zip `E:\xttack-hostinger.zip` (layout klasik).

---

## 12. Konfigurasi (`config.php`)

| Konstanta | Default | Fungsi |
|---|---|---|
| `SITE_NAME` | Xttack | nama tampil |
| `ADMIN_PASSWORD` | change-this-password | login admin |
| `AI_BASE_URL` / `AI_API_KEY` / `AI_MODEL` | OpenCode Zen / (kosong) / space-bunny-free | provider AI |
| `CHECK_TIMEOUT` | 8 | timeout cURL (detik) |
| `DEGRADED_MS` | 2000 | ambang latensi lambat |
| `INSIGHT_TTL_MIN` | 30 | cache insight |
| `SCAN_TTL_H` | 6 | rotasi auto-scan |
| `CHAT_RATE_LIMIT` | 15 | pesan per 5 menit |
| `CRON_SECRET` | change-this-secret | proteksi cron URL & webhook |
| `ENABLE_RAW_SHELL` | false | izinkan shell bebas (BERISIKO) |

---

## 13. Non-Functional

- PHP ≥ 7.4 (target 8.1+), ekstensi: `pdo_sqlite`, `curl`, `mbstring`, `openssl`, `json`.
- Tanpa dependency eksternal / build step; deploy = copy file.
- HTTP request monitoring: HEAD default (ringan), GET untuk keyword.
- Poll/S tick: 10 dtk (SSE) — hemat resource dibanding polling per-klien.
- Cron: 5 menit (uptime) + 1 scan terotasi per pemanggilan (≥6 jam/monitor).
- UI: dark cyber theme (neon hijau, monospace, scanline), responsif; `prefers-reduced-motion` dihormati.

---

## 14. Known Issues / Tech Debt

1. **CSS**: sisa kelas layout lama (`mon-card`, `bar-row`, `grid`) masih ada (dead code).
2. **Password plaintext** di config (idealnya `password_hash`).
3. **Chat kualitas**: model gratis `space-bunny-free` kadang bertele-tele/typo ringan.
4. **DNSBL**: SURBL/Spamhaus bisa menolak query dari resolver publik → hasil `-1` (n/a) — sudah dibedakan dari `1` (listed).
5. **Cloudflare-proxied domain**: IP hasil resolve = IP CDN → reputasi IP kurang relevan (isi tetap dicek per-domain).
6. **`proc_open`** sering disabled di shared hosting → sebagian perintah Terminal tidak tersedia (by design, ditangani).
7. **SSE lokal** butuh `PHP_CLI_SERVER_WORKERS`; tanpa itu request paralel memblokir.
8. **Autoban** hanya mengandalkan data request ke app (bukan access log server).
9. **Webhook** belum verifikasi signature HMAC GitHub (baru shared secret di URL) — roadmap.
10. **Insight/`sec_scans` growth** tanpa pruning.

---

## 15. Roadmap

- Notifikasi (email/Slack/WhatsApp/Telegram) saat DOWN, blacklisted, atau gate `blocked`.
- HMAC `X-Hub-Signature-256` untuk webhook.
- Halaman detail monitor (chart time-series latency, kalender uptime).
- Multi-user + RBAC, audit log, 2FA.
- Hash password + dukungan `.env`.
- Backup/restore DB dari UI; export CSV.
- WAF-lite: aturan blokir IP manual (sudah ada unban; tambah add-ban & whitelist).
- Integrasi status page publik yang bisa di-embed.
- Migrasi skema versi (`user_version`) untuk upgrade mulus.

---

## 16. Konvensi Kontribusi (PENTING untuk AI berikutnya)

- **Bahasa**: semua UI/teks kode **English**; kecuali blok Terminal (Indonesia) dan pesan AI mengikuti bahasa user.
- **Komentar kode**: hindari; kode harus self-explanatory.
- **PHP style**: fungsi prosedural di `app/*.php` (tanpa namespace), PDO prepared statements, `json_encode` untuk API.
- **Frontend**: vanilla JS (`public/assets/js/app.js`), tanpa framework; render HTML via template literal + `esc()`.
- **Naming aksi API**: kebab-case (`monitor-create`, `db-rows`).
- **Commit**: Conventional Commits (`feat|fix|chore(scope): ...`).
- **Rahasia**: `config.php`, `data/*` **tidak boleh** masuk Git (lihat `.gitignore`).
- **Dua branch wajib sinkron**: setiap perubahan `public/index.php` harus dicerminkan ke `index.php` pada branch `hostinger-root` (konten identik, beda lokasi); `.htaccess` hanya ada di `hostinger-root`.
- Setelah mengubah `public/assets/*`, hard refresh browser (cache 7 hari) atau bump query string.

### Perintah dev
```powershell
# jalankan dev server (multi-worker agar SSE lancar)
Stop-Process -Name php -ErrorAction SilentlyContinue
$env:PHP_CLI_SERVER_WORKERS=8
php -S 127.0.0.1:8099 -t public router.php     # layout klasik
# login default lokal: admin123  (ubah di config.php)

# lint
foreach ($f in Get-ChildItem -Recurse app,public,cron -Include *.php) { php -l $f.FullName }
node --check public\assets\js\app.js

# cron manual
php cron\check.php
```

### Uji cepat end-to-end
```powershell
# login + ambil CSRF, lalu:
curl.exe -s "http://127.0.0.1:8099/index.php?action=diag"
curl.exe -s -b ck.txt "http://127.0.0.1:8099/index.php?action=monitors"
curl.exe -s -N -m 12 -b ck.txt "http://127.0.0.1:8099/index.php?action=live"   # SSE
```

---

## 17. Status Environment & Handoff

| Item | Nilai |
|---|---|
| Repo | https://github.com/Fitrah-Andhika-Ramadhan/uptime-monitor |
| Branch produksi | `hostinger-root` (flatten, tanpa subdomain) |
| Branch dev/layout klasik | `main` |
| Domain deploy | `https://xattackor.meet-rc3id.id` |
| Lokal | `E:\uptime-monitor` (login `admin123`) |
| Paket zip | `E:\xttack-hostinger.zip` (layout klasik) |
| Server lokal | `http://127.0.0.1:8099` |

### Yang belum tervalidasi di produksi (perlu dicek AI berikutnya)
1. `GET /index.php?action=diag` di server — pastikan `data_writable`, `pdo_sqlite`, `config_present` benar.
2. Login sukses di server (pernah 500 sebelum diag/JSON-handler ditambahkan).
3. Cron Hostinger benar-benar menulis `checks` (tunggu 5–10 menit, cek `?action=monitors`).
4. Webhook GitHub terpasang & `deployments` bertambah saat push.
5. Blacklist DNSBL dari IP server (resolver Hostinger) memberi hasil valid (bukan `-1` semua).
6. `data/chat_*.json` & `banned.json` tumbuh wajar (izin tulis).
