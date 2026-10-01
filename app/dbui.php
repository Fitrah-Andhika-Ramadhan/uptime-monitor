<?php

const DBUI_EDITABLE = ['monitors', 'incidents', 'attacks', 'deployments', 'insights'];
const DBUI_READONLY = ['checks', 'sec_scans', 'deploy_logs', 'sqlite_master'];

function dbui_table_ok(string $t): bool
{
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        foreach (db()->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'") as $r) {
            $cache[] = $r['name'];
        }
    }
    return in_array($t, array_merge(DBUI_EDITABLE, DBUI_READONLY), true) && in_array($t, $cache, true);
}

function dbui_tables(): array
{
    $out = [];
    foreach (dbui_editable_names() as $t) {
        $n = (int) db()->query('SELECT COUNT(*) FROM ' . dbui_qid($t))->fetchColumn();
        $out[] = ['name' => $t, 'rows' => $n, 'editable' => true];
    }
    foreach (dbui_readonly_names() as $t) {
        try {
            $n = (int) db()->query('SELECT COUNT(*) FROM ' . dbui_qid($t))->fetchColumn();
        } catch (Throwable $e) {
            continue;
        }
        $out[] = ['name' => $t, 'rows' => $n, 'editable' => false];
    }
    return $out;
}

function dbui_editable_names(): array
{
    $names = [];
    foreach (db()->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name") as $r) {
        $names[] = $r['name'];
    }
    return $names;
}

function dbui_readonly_names(): array
{
    return array_diff(DBUI_READONLY, ['sqlite_master']);
}

function dbui_qid(string $t): string
{
    return '"' . str_replace('"', '', $t) . '"';
}

function dbui_columns(string $t): array
{
    $cols = [];
    foreach (db()->query('PRAGMA table_info(' . dbui_qid($t) . ')') as $r) {
        $cols[] = ['name' => $r['name'], 'type' => $r['type'], 'pk' => (bool) $r['pk']];
    }
    return $cols;
}

function dbui_rows(string $t, int $page = 1, int $per = 50, string $q = ''): array
{
    $off = ($page - 1) * $per;
    $sql = 'SELECT * FROM ' . dbui_qid($t);
    $params = [];
    if ($q !== '') {
        $cols = dbui_columns($t);
        $whr = [];
        foreach ($cols as $i => $c) {
            $whr[] = 'CAST(' . dbui_qid($c['name']) . ' AS TEXT) LIKE ?';
            $params[] = '%' . $q . '%';
        }
        $sql .= ' WHERE ' . implode(' OR ', $whr);
    }
    $sql .= ' LIMIT ' . $per . ' OFFSET ' . $off;
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st->fetchAll();
}

function dbui_insert(string $t, array $data): int
{
    if (!in_array($t, DBUI_EDITABLE, true) || !dbui_table_ok($t)) {
        throw new RuntimeException('table not editable');
    }
    $cols = dbui_columns($t);
    $names = array_column($cols, 'name');
    $data = array_filter($data, fn ($k) => in_array($k, $names, true), ARRAY_FILTER_USE_KEY);

    if (!$data) {
        throw new RuntimeException('no valid columns');
    }

    $ph = implode(', ', array_map(fn ($c) => ':' . str_replace('"', '', $c), array_keys($data)));
    $set = implode(', ', array_map(fn ($c) => dbui_qid($c) . ' = :' . str_replace('"', '', $c), array_keys($data)));

    if (isset($data['ts']) && ($data['ts'] === '' || $data['ts'] === null)) {
        $data['ts'] = time();
    }

    $st = db()->prepare('INSERT INTO ' . dbui_qid($t) . ' (' . implode(', ', array_map(fn ($c) => dbui_qid((string) $c), array_keys($data))) . ") VALUES ($ph)");
    foreach ($data as $k => $v) {
        $st->bindValue(':' . str_replace('"', '', (string) $k), dbui_coerce($v));
    }
    $st->execute();
    return (int) db()->lastInsertId();
}

function dbui_coerce($v)
{
    if (is_string($v)) {
        $t = trim($v);
        if ($t !== '' && preg_match('/^-?\d+$/', $t)) {
            return (int) $t;
        }
    }
    return $v;
}

function dbui_update(string $t, int $id, array $data): void
{
    if (!in_array($t, DBUI_EDITABLE, true) || !dbui_table_ok($t)) {
        throw new RuntimeException('table not editable');
    }
    $cols = dbui_columns($t);
    $names = array_column($cols, 'name');
    $pk = 'id';
    $set = [];
    $params = [];
    foreach ($data as $k => $v) {
        if (in_array($k, $names, true) && $k !== $pk) {
            $set[] = dbui_qid($k) . ' = :' . str_replace('"', '', $k);
            $params[':' . str_replace('"', '', $k)] = dbui_coerce($v);
        }
    }
    if (!$set) {
        throw new RuntimeException('nothing to update');
    }
    $params[':pk'] = $id;
    $st = db()->prepare('UPDATE ' . dbui_qid($t) . ' SET ' . implode(', ', $set) . ' WHERE ' . dbui_qid($pk) . ' = :pk');
    $st->execute($params);
}

function dbui_delete(string $t, int $id): void
{
    if (!in_array($t, DBUI_EDITABLE, true) || !dbui_table_ok($t)) {
        throw new RuntimeException('table not editable');
    }
    $st = db()->prepare('DELETE FROM ' . dbui_qid($t) . ' WHERE id = ?');
    $st->execute([$id]);
}

function dbui_sql_readonly(string $sql): array
{
    $sql = trim(preg_replace('/^\s*--.*$/m', '', $sql));
    $head = strtolower(preg_replace('/^\s*\(?([\w]+).*$/s', '$1', (string) $sql));
    if (!in_array($head, ['select', 'pragma', 'explain', 'with'], true)) {
        throw new RuntimeException('read-only SQL required (SELECT/PRAGMA/EXPLAIN/WITH)');
    }
    $st = db()->prepare($sql);
    $st->execute();
    $rows = $st->fetchAll();
    return array_slice($rows, 0, 200);
}
