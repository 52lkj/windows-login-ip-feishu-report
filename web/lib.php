<?php

function app_config(): array
{
    static $config = null;
    if ($config !== null) {
        return $config;
    }

    $path = __DIR__ . '/config.php';
    if (!is_file($path)) {
        $path = __DIR__ . '/config.example.php';
    }

    $config = require $path;
    date_default_timezone_set($config['timezone'] ?? 'Asia/Shanghai');
    return $config;
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }

    $config = app_config();
    $dbPath = $config['db_path'];
    $dir = dirname($dbPath);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    $pdo = new PDO('sqlite:' . $dbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('PRAGMA foreign_keys = ON');
    migrate($pdo);
    return $pdo;
}

function migrate(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS servers (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            server_key TEXT NOT NULL UNIQUE,
            hostname TEXT NOT NULL,
            public_ip TEXT,
            allowed_ips TEXT NOT NULL DEFAULT '[]',
            owner TEXT,
            notes TEXT,
            deleted_at TEXT,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL
        )
    ");

    ensure_column($pdo, 'servers', 'deleted_at', 'TEXT');

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS login_events (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            event_key TEXT NOT NULL UNIQUE,
            server_id INTEGER,
            server_key TEXT NOT NULL,
            hostname TEXT NOT NULL,
            server_public_ip TEXT,
            username TEXT NOT NULL,
            login_ip TEXT NOT NULL,
            channel TEXT NOT NULL,
            method TEXT NOT NULL,
            source TEXT NOT NULL,
            occurred_at TEXT NOT NULL,
            received_at TEXT NOT NULL,
            is_remote INTEGER NOT NULL,
            is_success INTEGER NOT NULL DEFAULT 1,
            is_anomalous INTEGER NOT NULL DEFAULT 0,
            anomaly_reasons TEXT NOT NULL DEFAULT '[]',
            raw_json TEXT NOT NULL,
            FOREIGN KEY(server_id) REFERENCES servers(id)
        )
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS ip_enrichment (
            ip TEXT PRIMARY KEY,
            vt_last_checked_at TEXT,
            vt_malicious INTEGER,
            vt_suspicious INTEGER,
            vt_harmless INTEGER,
            vt_undetected INTEGER,
            vt_reputation INTEGER,
            vt_country TEXT,
            vt_as_owner TEXT,
            vt_raw_json TEXT,
            risk_level TEXT NOT NULL DEFAULT 'unknown',
            updated_at TEXT NOT NULL
        )
    ");

    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_login_events_received_at ON login_events(received_at)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_login_events_ip ON login_events(login_ip)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_login_events_server ON login_events(server_key)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_login_events_anomaly ON login_events(is_anomalous)');
}

function ensure_column(PDO $pdo, string $table, string $column, string $definition): void
{
    $stmt = $pdo->query("PRAGMA table_info($table)");
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $info) {
        if (($info['name'] ?? '') === $column) {
            return;
        }
    }

    $pdo->exec("ALTER TABLE $table ADD COLUMN $column $definition");
}

function json_response(array $data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function read_json_body(): array
{
    $body = file_get_contents('php://input');
    $data = json_decode($body, true);
    if (!is_array($data)) {
        json_response(['ok' => false, 'error' => 'invalid_json'], 400);
    }

    return $data;
}

function require_bearer_token(string $expected): void
{
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if ($header === '' && function_exists('apache_request_headers')) {
        $headers = apache_request_headers();
        $header = $headers['Authorization'] ?? $headers['authorization'] ?? '';
    }

    $token = '';
    if (preg_match('/^Bearer\s+(.+)$/i', $header, $matches)) {
        $token = trim($matches[1]);
    } elseif (isset($_GET['token'])) {
        $token = (string)$_GET['token'];
    }

    if ($expected === '' || !hash_equals($expected, $token)) {
        json_response(['ok' => false, 'error' => 'unauthorized'], 401);
    }
}

function request_admin_ok(): bool
{
    $config = app_config();
    $expected = $config['admin_token'] ?? '';
    $token = $_GET['token'] ?? '';
    return $expected !== '' && hash_equals($expected, (string)$token);
}

function h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function now_iso(): string
{
    return date('c');
}

function normalize_ip(string $ip): string
{
    $ip = trim(strtolower($ip));
    if (strpos($ip, '::ffff:') === 0) {
        $ip = substr($ip, 7);
    }
    return $ip;
}

function event_key(array $event): string
{
    return hash('sha256', implode('|', [
        $event['server_key'] ?? '',
        $event['hostname'] ?? '',
        $event['username'] ?? '',
        $event['login_ip'] ?? '',
        $event['occurred_at'] ?? '',
        $event['source'] ?? '',
    ]));
}

function upsert_server(PDO $pdo, string $serverKey, string $hostname, ?string $publicIp): int
{
    $now = now_iso();
    $stmt = $pdo->prepare('SELECT id FROM servers WHERE server_key = ?');
    $stmt->execute([$serverKey]);
    $id = $stmt->fetchColumn();

    if ($id) {
        $stmt = $pdo->prepare('UPDATE servers SET hostname = ?, public_ip = ?, deleted_at = NULL, updated_at = ? WHERE id = ?');
        $stmt->execute([$hostname, $publicIp, $now, $id]);
        return (int)$id;
    }

    $stmt = $pdo->prepare('
        INSERT INTO servers (server_key, hostname, public_ip, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?)
    ');
    $stmt->execute([$serverKey, $hostname, $publicIp, $now, $now]);
    return (int)$pdo->lastInsertId();
}

function server_allowed_ips(PDO $pdo, int $serverId): array
{
    $stmt = $pdo->prepare('SELECT allowed_ips FROM servers WHERE id = ?');
    $stmt->execute([$serverId]);
    $value = $stmt->fetchColumn();
    $ips = json_decode((string)$value, true);
    return is_array($ips) ? array_values(array_filter(array_map('normalize_ip', $ips))) : [];
}

function assess_anomaly(PDO $pdo, int $serverId, array $event): array
{
    $reasons = [];
    $ip = normalize_ip((string)$event['login_ip']);
    $username = strtolower((string)$event['username']);

    if (($event['is_remote'] ?? true) && !in_array($ip, ['local', 'unknown'], true)) {
        $allowed = server_allowed_ips($pdo, $serverId);
        if ($allowed && !in_array($ip, $allowed, true)) {
            $reasons[] = 'not_in_server_allowed_ips';
        }

        $stmt = $pdo->prepare('SELECT COUNT(*) FROM login_events WHERE server_id = ? AND login_ip = ?');
        $stmt->execute([$serverId, $ip]);
        if ((int)$stmt->fetchColumn() === 0) {
            $reasons[] = 'first_seen_on_server';
        }
    }

    if ($username === 'root' || $username === 'administrator') {
        $reasons[] = 'privileged_user';
    }

    $hour = (int)date('G', strtotime((string)$event['occurred_at']));
    if ($hour < 7 || $hour > 23) {
        $reasons[] = 'off_hours';
    }

    return array_values(array_unique($reasons));
}

function risk_level_from_vt(array $stats, int $reputation): string
{
    $malicious = (int)($stats['malicious'] ?? 0);
    $suspicious = (int)($stats['suspicious'] ?? 0);

    if ($malicious >= 3 || $reputation < -20) {
        return 'high';
    }
    if ($malicious > 0 || $suspicious > 0 || $reputation < 0) {
        return 'medium';
    }
    return 'low';
}

function vt_fetch_ip_with_status(string $ip): array
{
    $config = app_config();
    $apiKey = trim((string)($config['virustotal_api_key'] ?? ''));
    if ($apiKey === '') {
        return [
            'ok' => false,
            'message' => '没有配置 VirusTotal API key，请在 web/config.php 设置 virustotal_api_key。',
        ];
    }

    if (!in_array('https', stream_get_wrappers(), true)) {
        return [
            'ok' => false,
            'message' => '当前 PHP 没有启用 HTTPS stream wrapper，请启用 openssl 扩展后重启 PHP 服务。',
        ];
    }

    $url = 'https://www.virustotal.com/api/v3/ip_addresses/' . rawurlencode($ip);
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'header' => "x-apikey: {$apiKey}\r\nAccept: application/json\r\n",
            'timeout' => 20,
            'ignore_errors' => true,
        ],
    ]);

    $body = @file_get_contents($url, false, $context);
    $statusLine = $http_response_header[0] ?? '';
    if ($body === false) {
        $error = error_get_last();
        $errorMessage = vt_human_error_message((string)($error['message'] ?? ''));
        return [
            'ok' => false,
            'message' => '请求 VirusTotal 失败：' . $errorMessage,
            'status_line' => $statusLine,
        ];
    }

    if (!preg_match('/\s2\d\d\s/', $statusLine)) {
        $data = json_decode($body, true);
        $vtMessage = $data['error']['message'] ?? $data['error']['code'] ?? trim($body);
        if ($vtMessage === '') {
            $vtMessage = 'VirusTotal 返回了非成功状态。';
        }

        return [
            'ok' => false,
            'message' => trim($statusLine . ' ' . $vtMessage),
            'status_line' => $statusLine,
        ];
    }

    $data = json_decode($body, true);
    if (!is_array($data)) {
        return [
            'ok' => false,
            'message' => 'VirusTotal 返回的数据不是有效 JSON。',
            'status_line' => $statusLine,
        ];
    }

    return [
        'ok' => true,
        'message' => 'VirusTotal 查询成功。',
        'status_line' => $statusLine,
        'data' => $data,
    ];
}

function vt_human_error_message(string $message): string
{
    if ($message === '') {
        return '网络连接失败或请求超时。';
    }

    if (stripos($message, 'access permissions') !== false || strpos($message, '访问权限不允许') !== false) {
        return '当前 PHP 进程无法连接外网，常见原因是 Windows 防火墙、安全软件或运行环境限制了出站 HTTPS 连接。';
    }

    if (stripos($message, 'timed out') !== false || strpos($message, '超时') !== false) {
        return '连接 VirusTotal 超时，请检查本机网络是否能访问 www.virustotal.com。';
    }

    if (stripos($message, 'getaddrinfo') !== false || stripos($message, 'php_network_getaddresses') !== false) {
        return 'DNS 解析失败，请检查本机 DNS 或网络连接。';
    }

    return $message;
}

function vt_fetch_ip(string $ip): ?array
{
    $result = vt_fetch_ip_with_status($ip);
    return $result['ok'] ? $result['data'] : null;
}

function vt_enrich_ip_with_status(PDO $pdo, string $ip): array
{
    $ip = normalize_ip($ip);
    if ($ip === '' || $ip === 'local' || $ip === 'unknown') {
        return [
            'ok' => false,
            'message' => '这个 IP 不能查询 VirusTotal。',
        ];
    }

    $fetch = vt_fetch_ip_with_status($ip);
    if (!$fetch['ok']) {
        return $fetch;
    }

    $data = $fetch['data'];
    $attrs = $data['data']['attributes'] ?? [];
    $stats = $attrs['last_analysis_stats'] ?? [];
    $reputation = (int)($attrs['reputation'] ?? 0);
    $riskLevel = risk_level_from_vt($stats, $reputation);
    $now = now_iso();

    $stmt = $pdo->prepare('
        INSERT INTO ip_enrichment (
            ip, vt_last_checked_at, vt_malicious, vt_suspicious, vt_harmless, vt_undetected,
            vt_reputation, vt_country, vt_as_owner, vt_raw_json, risk_level, updated_at
        )
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON CONFLICT(ip) DO UPDATE SET
            vt_last_checked_at = excluded.vt_last_checked_at,
            vt_malicious = excluded.vt_malicious,
            vt_suspicious = excluded.vt_suspicious,
            vt_harmless = excluded.vt_harmless,
            vt_undetected = excluded.vt_undetected,
            vt_reputation = excluded.vt_reputation,
            vt_country = excluded.vt_country,
            vt_as_owner = excluded.vt_as_owner,
            vt_raw_json = excluded.vt_raw_json,
            risk_level = excluded.risk_level,
            updated_at = excluded.updated_at
    ');

    $stmt->execute([
        $ip,
        $now,
        (int)($stats['malicious'] ?? 0),
        (int)($stats['suspicious'] ?? 0),
        (int)($stats['harmless'] ?? 0),
        (int)($stats['undetected'] ?? 0),
        $reputation,
        $attrs['country'] ?? null,
        $attrs['as_owner'] ?? null,
        json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        $riskLevel,
        $now,
    ]);

    return [
        'ok' => true,
        'message' => sprintf(
            'VirusTotal 刷新成功：风险等级 %s，恶意 %d，可疑 %d。',
            $riskLevel,
            (int)($stats['malicious'] ?? 0),
            (int)($stats['suspicious'] ?? 0)
        ),
        'checked_at' => $now,
        'risk_level' => $riskLevel,
    ];
}

function vt_enrich_ip(PDO $pdo, string $ip): bool
{
    $result = vt_enrich_ip_with_status($pdo, $ip);
    return (bool)$result['ok'];
}
