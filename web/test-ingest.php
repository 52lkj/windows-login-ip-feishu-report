<?php
require __DIR__ . '/lib.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo 'CLI only.';
    exit;
}

$pdo = db();
$serverKey = 'test-server-' . date('YmdHis');
$payload = [
    'server_key' => $serverKey,
    'hostname' => $serverKey,
    'server_public_ip' => '203.0.113.10',
    'events' => [
        [
            'source' => 'self-test',
            'username' => 'root',
            'login_ip' => '198.51.100.23',
            'channel' => 'ssh',
            'method' => 'ssh',
            'occurred_at' => date('c'),
            'is_success' => true,
        ],
    ],
];

$serverId = upsert_server($pdo, $payload['server_key'], $payload['hostname'], $payload['server_public_ip']);
$event = $payload['events'][0];
$event['server_key'] = $payload['server_key'];
$event['hostname'] = $payload['hostname'];
$event['is_remote'] = true;
$reasons = assess_anomaly($pdo, $serverId, $event);

if (!$reasons) {
    fwrite(STDERR, "Expected self-test event to be anomalous.\n");
    exit(1);
}

$stmt = $pdo->prepare('
    INSERT INTO login_events (
        event_key, server_id, server_key, hostname, server_public_ip, username, login_ip,
        channel, method, source, occurred_at, received_at, is_remote, is_success,
        is_anomalous, anomaly_reasons, raw_json
    )
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, 1, ?, ?, ?)
');

$stmt->execute([
    event_key($event),
    $serverId,
    $payload['server_key'],
    $payload['hostname'],
    $payload['server_public_ip'],
    $event['username'],
    $event['login_ip'],
    $event['channel'],
    $event['method'],
    $event['source'],
    $event['occurred_at'],
    now_iso(),
    json_encode($reasons, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    json_encode($event, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
]);

$stmt = $pdo->prepare('SELECT COUNT(*) FROM login_events WHERE server_key = ? AND login_ip = ? AND is_anomalous = 1');
$stmt->execute([$serverKey, $event['login_ip']]);
$count = (int)$stmt->fetchColumn();

if ($count < 1) {
    fwrite(STDERR, "Self-test event was not inserted.\n");
    exit(1);
}

echo json_encode([
    'ok' => true,
    'server_key' => $serverKey,
    'login_ip' => $event['login_ip'],
    'anomaly_reasons' => $reasons,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
