<?php
require __DIR__ . '/lib.php';

if (!request_admin_ok()) {
    http_response_code(401);
    echo 'Unauthorized. Add ?token=ADMIN_TOKEN.';
    exit;
}

$config = app_config();
$checks = [];

$checks[] = [
    'name' => 'config.php',
    'ok' => is_file(__DIR__ . '/config.php'),
    'detail' => is_file(__DIR__ . '/config.php') ? 'loaded' : 'using config.example.php',
];

$checks[] = [
    'name' => 'PDO SQLite',
    'ok' => extension_loaded('pdo_sqlite'),
    'detail' => extension_loaded('pdo_sqlite') ? 'available' : 'missing',
];

$dbPath = $config['db_path'] ?? '';
$dbDir = $dbPath ? dirname($dbPath) : '';
$checks[] = [
    'name' => 'database directory',
    'ok' => $dbDir !== '' && (is_dir($dbDir) || is_writable(dirname($dbDir))),
    'detail' => $dbDir,
];

$checks[] = [
    'name' => 'ingest token',
    'ok' => ($config['ingest_token'] ?? '') !== '' && ($config['ingest_token'] ?? '') !== 'change-this-ingest-token',
    'detail' => 'configured',
];

$checks[] = [
    'name' => 'admin token',
    'ok' => ($config['admin_token'] ?? '') !== '' && ($config['admin_token'] ?? '') !== 'change-this-admin-token',
    'detail' => 'configured',
];

$checks[] = [
    'name' => 'VirusTotal API key',
    'ok' => ($config['virustotal_api_key'] ?? '') !== '',
    'detail' => ($config['virustotal_api_key'] ?? '') !== '' ? 'configured' : 'not configured',
];

$dbOk = false;
try {
    $pdo = db();
    $pdo->query('SELECT COUNT(*) FROM servers')->fetchColumn();
    $dbOk = true;
} catch (Throwable $e) {
    $checks[] = [
        'name' => 'database migration',
        'ok' => false,
        'detail' => $e->getMessage(),
    ];
}

if ($dbOk) {
    $checks[] = [
        'name' => 'database migration',
        'ok' => true,
        'detail' => 'ok',
    ];
}

$allOk = !in_array(false, array_column($checks, 'ok'), true);
?>
<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>后台健康检查</title>
    <style>
        body { margin: 0; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; background: #f6f7f9; color: #17202a; }
        header { background: #101820; color: #fff; padding: 18px 24px; }
        main { padding: 24px; max-width: 900px; margin: 0 auto; }
        table { width: 100%; border-collapse: collapse; background: #fff; border: 1px solid #dde2e8; }
        th, td { padding: 10px 12px; border-bottom: 1px solid #eef1f4; text-align: left; font-size: 14px; }
        th { background: #eef2f6; }
        .tag { display: inline-block; padding: 2px 8px; border-radius: 999px; font-size: 12px; }
        .ok { background: #e8f7ee; color: #146c3e; }
        .bad { background: #ffe8e8; color: #9b1c1c; }
        .muted { color: #687386; }
        a { color: #155eef; text-decoration: none; }
    </style>
</head>
<body>
<header>
    <h1>后台健康检查</h1>
</header>
<main>
    <p>
        总体状态:
        <span class="tag <?= $allOk ? 'ok' : 'bad' ?>"><?= $allOk ? '正常' : '需要处理' ?></span>
    </p>
    <p><a href="index.php?token=<?= h((string)$_GET['token']) ?>">返回后台</a></p>
    <table>
        <thead>
        <tr>
            <th>项目</th>
            <th>状态</th>
            <th>详情</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($checks as $check): ?>
            <tr>
                <td><?= h($check['name']) ?></td>
                <td><span class="tag <?= $check['ok'] ? 'ok' : 'bad' ?>"><?= $check['ok'] ? 'OK' : 'FAIL' ?></span></td>
                <td class="muted"><?= h($check['detail']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</main>
</body>
</html>
