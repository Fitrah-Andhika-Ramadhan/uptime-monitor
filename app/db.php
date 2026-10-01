<?php

function db(): PDO
{
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }

    $dir = dirname(__DIR__) . '/data';
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }

    $path = $dir . '/Xttack.sqlite';
    $fresh = !file_exists($path);

    $pdo = new PDO('sqlite:' . $path);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('PRAGMA busy_timeout = 5000');
    $pdo->exec('PRAGMA foreign_keys = ON');

    $pdo->exec('CREATE TABLE IF NOT EXISTS monitors (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        group_name TEXT NOT NULL DEFAULT "General",
        name TEXT NOT NULL,
        url TEXT NOT NULL,
        interval_min INTEGER NOT NULL DEFAULT 5,
        method TEXT NOT NULL DEFAULT "HEAD",
        expected_code INTEGER NOT NULL DEFAULT 0,
        keyword TEXT NOT NULL DEFAULT "",
        created_at INTEGER NOT NULL
    )');
    $pdo->exec('CREATE TABLE IF NOT EXISTS checks (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        monitor_id INTEGER NOT NULL REFERENCES monitors(id) ON DELETE CASCADE,
        ts INTEGER NOT NULL,
        code INTEGER,
        latency_ms INTEGER,
        ok INTEGER NOT NULL,
        down INTEGER NOT NULL DEFAULT 0,
        err TEXT
    )');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_checks_mon_ts ON checks(monitor_id, ts DESC)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS incidents (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        monitor_id INTEGER NOT NULL REFERENCES monitors(id) ON DELETE CASCADE,
        start_ts INTEGER NOT NULL,
        end_ts INTEGER,
        err TEXT
    )');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_incidents_mon ON incidents(monitor_id, start_ts DESC)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS insights (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        ts INTEGER NOT NULL,
        body TEXT NOT NULL
    )');
    $pdo->exec('CREATE TABLE IF NOT EXISTS sec_scans (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        monitor_id INTEGER NOT NULL,
        ts INTEGER NOT NULL,
        grade TEXT,
        score INTEGER,
        ssl_ok INTEGER,
        ssl_days INTEGER,
        ssl_issuer TEXT,
        https_redirect INTEGER,
        headers_ok TEXT,
        headers_missing TEXT,
        spf INTEGER,
        dmarc INTEGER,
        hsts INTEGER,
        raw TEXT
    )');
    $pdo->exec('CREATE TABLE IF NOT EXISTS attacks (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        ts INTEGER NOT NULL,
        ip TEXT NOT NULL,
        ua TEXT,
        sev TEXT,
        reason TEXT
    )');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_att_ts ON attacks(ts DESC)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_att_ip ON attacks(ip)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS deploy_logs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        ts INTEGER NOT NULL,
        url TEXT NOT NULL,
        name TEXT,
        verdict TEXT NOT NULL,
        score INTEGER,
        items TEXT,
        ai_note TEXT
    )');
    $pdo->exec('CREATE TABLE IF NOT EXISTS deployments (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        ts INTEGER NOT NULL,
        site TEXT NOT NULL,
        branch TEXT,
        commit_sha TEXT,
        message TEXT,
        status TEXT NOT NULL,
        items TEXT,
        ai_note TEXT
    )');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_dep_ts ON deployments(ts DESC)');

    if ($fresh) {
        $pdo->exec('INSERT INTO monitors (group_name, name, url, interval_min, method, created_at) VALUES
            ("Web", "Hostinger", "https://www.hostinger.com", 5, "HEAD", ' . time() . '),
            ("Web", "Cloudflare", "https://www.cloudflare.com", 5, "HEAD", ' . time() . ')');
    }

    return $pdo;
}

