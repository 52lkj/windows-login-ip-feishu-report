<?php
require __DIR__ . '/lib.php';

$config = app_config();
require_bearer_token($config['ingest_token'] ?? '');

$payload = read_json_body();
$events = $payload['events'] ?? [];
if (!is_array($events)) {
    json_response(['ok' => false, 'error' => 'events_must_be_array'], 400);
}

$hostname = trim((string)($payload['hostname'] ?? ''));
$serverKey = trim((string)($payload['server_key'] ?? $hostname));
if ($serverKey === '' || $hostname === '') {
    json_response(['ok' => false, 'error' => 'server_key_and_hostname_required'], 400);
}

$publicIp = isset($payload['server_public_ip']) ? normalize_ip((string)$payload['server_public_ip']) : null;
$pdo = db();
$serverId = upsert_server($pdo, $serverKey, $hostname, $publicIp);
$receivedAt = now_iso();
$inserted = 0;
$duplicates = 0;

$stmt = $pdo->prepare('
    INSERT INTO login_events (
        event_key, server_id, server_key, hostname, server_public_ip, username, login_ip,
        channel, method, source, occurred_at, received_at, is_remote, is_success,
        is_anomalous, anomaly_reasons, raw_json
    )
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?, ?)
');

foreach ($events as $event) {
    if (!is_array($event)) {
        continue;
    }

    $event['server_key'] = $serverKey;
    $event['hostname'] = $hostname;
    $event['login_ip'] = normalize_ip((string)($event['login_ip'] ?? 'unknown'));
    $event['username'] = trim((string)($event['username'] ?? 'unknown'));
    $event['channel'] = trim((string)($event['channel'] ?? 'unknown'));
    $event['method'] = trim((string)($event['method'] ?? 'unknown'));
    $event['source'] = trim((string)($event['source'] ?? 'unknown'));
    $event['occurred_at'] = trim((string)($event['occurred_at'] ?? $receivedAt));
    $event['is_remote'] = !in_array($event['login_ip'], ['local', 'unknown'], true);

    $reasons = assess_anomaly($pdo, $serverId, $event);
    $eventKey = event_key($event);

    try {
        $stmt->execute([
            $eventKey,
            $serverId,
            $serverKey,
            $hostname,
            $publicIp,
            $event['username'],
            $event['login_ip'],
            $event['channel'],
            $event['method'],
            $event['source'],
            $event['occurred_at'],
            $receivedAt,
            $event['is_remote'] ? 1 : 0,
            $reasons ? 1 : 0,
            json_encode($reasons, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            json_encode($event, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
        $inserted++;
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') {
            $duplicates++;
            continue;
        }
        throw $e;
    }
}

json_response([
    'ok' => true,
    'inserted' => $inserted,
    'duplicates' => $duplicates,
]);
