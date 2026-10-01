# Xttack - AI Uptime & Security Monitor

Monitor uptime & latensi + **security hardening** + **gerbang deploy (staging-check)** + **deteksi serangan** dengan asisten AI. Cocok untuk **Hostinger Business Web Hosting** (PHP 8 + SQLite, tanpa dependency build).

## Fitur

| Modul | Fungsi |
|---|---|
| Dashboard | daftar website ala hPanel (grup, search, chip status) + sparkline latensi |
| Statistik | tabel uptime 24j/7h + latensi avg/max per situs |
| Keamanan | grade A-F per situs (SSL, HSTS/XFO/CSP/nosniff/referrer, SPF/DMARC) |
| **Deteksi Serangan** | honeypot (.env/wp-admin/phpmyadmin dll), deteksi scanner UA (sqlmap/nikto/...), SQLi/XSS payload, bruteforce login, auto-ban 24 jam, tombol unban |
| **Penempatan** | riwayat deploy per commit via webhook GitHub; tiap push dicer gate pre-flight |
| **Gerbang Deploy** | pre-flight checklist manual: endpoint, HTTPS, DNS/SPF/DMARC, security headers + verdict AI (LAYAK/PERHATIAN/BLOKIR) |
| **Terminal** | TTY virtual ala Linux: `help`, `ls`, `status`, `check`, `add`, `sec`, `attacks`, `gate`, `deplog`, `ai <q>` dll (tanpa shell asli) |
| AI | chat streaming (space-bunny-free via opencode gateway) + insight otomatis |
| Cron | cek uptime berkala via cron hPanel |

## Workflow "check dulu sebelum deploy"

1. Sambungkan GitHub: hPanel repo > Settings > Webhooks > tambahkan
   `https://DOMAIN_ANDA/index.php?action=deploy-hook&secret=CRON_SECRET` (Content type: application/json, event: push)
2. Setiap push: Xttack mencatat baris Penempatan, menjalankan checklist (HTTP 200?, HTTPS, SPF/DMARC, headers), dan AI menilai.
3. Verdict **passed** = aman deploy; **blocked** = perbaiki item gagal dulu (lihat kolom catatan AI).
4. Bisa juga manual: menu Tingkat lanjut > Gerbang Deploy, input URL staging.

## Struktur server

```
public_html/      <- isi public/ (docroot)
config.php, app/, data/, cron/  -> root akun hosting (1 level di atas public_html)
```

## Deploy ke Hostinger

1. Upload isi `public/` ke `public_html/`; `config.php`, `app/`, `data/`, `cron/` ke root akun.
2. Edit `config.php`: `ADMIN_PASSWORD`, `AI_API_KEY`, `AI_MODEL`, `CRON_SECRET` (dipakai cron & webhook).
3. Cron hPanel tiap 5 menit: `/usr/local/bin/php /home/USERNAME/cron/check.php`
4. Login, tambah monitor situs Anda.
5. (Opsional) deteksi serangan bekerja otomatis via middleware + rewrite di `public/.htaccess`.

## Realtime & Terminal

- **Realtime**: halaman Dashboard, Statistics, dan Security diperbarui lewat **SSE** (`?action=live`, tick 10 detik) — monitor, serangan, dan hasil scan terbaru tanpa refresh. Ada fallback polling otomatis bila SSE terputus.
- **Auto-scan Security**: cron memutar scan hardening tiap `SCAN_TTL_H` jam (default 6) per monitor: SSL, security headers, SPF/DMARC, plus **blacklist nyata** (Spamhaus DBL, SURBL, SORBS, Spamhaus Zen) dengan IP server hasil resolve DNS.
- **Terminal (REAL, bukan simulasi)**: perintah dieksekusi di server dengan *safe mode* — allowlist perintah (`ls, cat, tail, grep, php -v/-m/-i, php cron, df, uptime, free, ps/tasklist, ping, dns, curl, mon, check, cron, sql, attacks, bans, unban, git status/log/branch`) dan penolakan karakter shell (`;&|><`$()[]!*?~\`). Untuk shell bebas penuh (berisiko RCE), ubah `ENABLE_RAW_SHELL` menjadi `true` di config.php — hanya untuk server milik Anda sendiri.

## Catatan keamanan

- Data serangan: tabel `attacks` + `data/banned.json` (auto-ban 24 jam; bisa adjust di halaman Keamanan atau terminal `unban <ip>`).
- Password admin + CSRF (cookie HttpOnly) melindungi endpoint tulis.
- Terminal dashboard TIDAK mengeksekusi shell asli - hanya perintah virtual aman.



## Database CRUD
- Dashboard > Database: browse row (SELECT like filter), insert, edit, delete untuk tabel writable; read-only untuk log (checks, sec_scans, deploy_logs). 
- SQL console read-only: SELECT/PRAGMA/EXPLAIN/WITH.