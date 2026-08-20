<?php
require __DIR__ . '/lib.php';

$config = app_config();

if (PHP_SAPI !== 'cli') {
    require_bearer_token($config['admin_token'] ?? '');
}

$pdo = db();
$cutoff = date('c', time() - 86400);
$stmt = $pdo->prepare("
    SELECT DISTINCT e.login_ip
    FROM login_events e
    LEFT JOIN ip_enrichment i ON i.ip = e.login_ip
    WHERE e.is_anomalous = 1
      AND e.is_remote = 1
      AND e.login_ip NOT IN ('local', 'unknown')
      AND (i.vt_last_checked_at IS NULL OR i.vt_last_checked_at < ?)
    ORDER BY e.received_at DESC
    LIMIT 20
");
$stmt->execute([$cutoff]);

$checked = 0;
foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $ip) {
    if (vt_enrich_ip($pdo, $ip)) {
        $checked++;
    }
}

$result = ['ok' => true, 'checked' => $checked];
if (PHP_SAPI === 'cli') {
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit;
}

json_response($result);
