<?php
require __DIR__ . '/lib.php';

if (!request_admin_ok()) {
    http_response_code(401);
    echo 'Unauthorized. Add ?token=ADMIN_TOKEN.';
    exit;
}

$pdo = db();
$events = $pdo->query("
    SELECT e.*, s.allowed_ips, i.risk_level, i.vt_malicious, i.vt_suspicious, i.vt_country, i.vt_as_owner, i.vt_last_checked_at
    FROM login_events e
    LEFT JOIN servers s ON s.id = e.server_id
    LEFT JOIN ip_enrichment i ON i.ip = e.login_ip
    ORDER BY e.occurred_at DESC, e.id DESC
    LIMIT 200
")->fetchAll(PDO::FETCH_ASSOC);

$servers = $pdo->query("
    SELECT s.*,
        COUNT(e.id) AS login_count,
        SUM(CASE WHEN e.is_anomalous = 1 THEN 1 ELSE 0 END) AS anomaly_count,
        MAX(e.occurred_at) AS last_login_at
    FROM servers s
    LEFT JOIN login_events e ON e.server_id = s.id
    WHERE s.deleted_at IS NULL
    GROUP BY s.id
    ORDER BY last_login_at DESC
")->fetchAll(PDO::FETCH_ASSOC);

$adminToken = $_GET['token'];
?>
<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>登录监控后台</title>
    <style>
        body { margin: 0; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; background: #f6f7f9; color: #17202a; }
        header { background: #101820; color: #fff; padding: 18px 24px; }
        main { padding: 24px; max-width: 1280px; margin: 0 auto; }
        h1 { margin: 0; font-size: 22px; }
        h2 { margin: 26px 0 12px; font-size: 18px; }
        table { width: 100%; border-collapse: collapse; background: #fff; border: 1px solid #dde2e8; }
        th, td { padding: 10px 12px; border-bottom: 1px solid #eef1f4; text-align: left; font-size: 14px; vertical-align: top; }
        th { background: #eef2f6; font-weight: 650; }
        .tag { display: inline-block; padding: 2px 8px; border-radius: 999px; font-size: 12px; background: #edf2ff; color: #2446a6; }
        .bad { background: #ffe8e8; color: #9b1c1c; }
        .mid { background: #fff4d6; color: #7a4b00; }
        .low { background: #e8f7ee; color: #146c3e; }
        .muted { color: #687386; }
        a { color: #155eef; text-decoration: none; }
        code { background: #eef1f4; padding: 2px 5px; border-radius: 4px; }
    </style>
</head>
<body>
<header>
    <h1>登录监控后台</h1>
</header>
<main>
    <p>
        <a href="servers.php?token=<?= h($adminToken) ?>">服务器管理</a>
        ·
        <a href="health.php?token=<?= h($adminToken) ?>">后台健康检查</a>
    </p>
    <h2>服务器</h2>
    <table>
        <thead>
        <tr>
            <th>服务器</th>
            <th>公网 IP</th>
            <th>登录次数</th>
            <th>异常</th>
            <th>最后登录</th>
            <th>白名单</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($servers as $server): ?>
            <tr>
                <td>
                    <a href="server.php?token=<?= h($adminToken) ?>&id=<?= (int)$server['id'] ?>"><?= h($server['hostname']) ?></a>
                    <br><span class="muted"><?= h($server['server_key']) ?></span>
                </td>
                <td><?= h($server['public_ip']) ?></td>
                <td><?= (int)$server['login_count'] ?></td>
                <td><span class="tag <?= ((int)$server['anomaly_count'] > 0) ? 'bad' : 'low' ?>"><?= (int)$server['anomaly_count'] ?></span></td>
                <td><?= h($server['last_login_at']) ?></td>
                <td><code><?= h($server['allowed_ips']) ?></code></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <h2>最近登录</h2>
    <table>
        <thead>
        <tr>
            <th>时间</th>
            <th>服务器</th>
            <th>用户</th>
            <th>登录 IP</th>
            <th>异常</th>
            <th>VirusTotal</th>
            <th>来源</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($events as $event): ?>
            <?php
            $risk = $event['risk_level'] ?: 'unknown';
            $riskClass = $risk === 'high' ? 'bad' : ($risk === 'medium' ? 'mid' : ($risk === 'low' ? 'low' : ''));
            $reasons = json_decode($event['anomaly_reasons'], true) ?: [];
            ?>
            <tr>
                <td><?= h($event['occurred_at']) ?></td>
                <td><?= h($event['hostname']) ?></td>
                <td><?= h($event['username']) ?></td>
                <td>
                    <?= h($event['login_ip']) ?>
                    <?php if ($event['login_ip'] !== 'local' && $event['login_ip'] !== 'unknown'): ?>
                        <br><a href="ip.php?token=<?= h($adminToken) ?>&ip=<?= urlencode($event['login_ip']) ?>">查看 IP</a>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ((int)$event['is_anomalous'] === 1): ?>
                        <span class="tag bad">异常</span><br>
                        <span class="muted"><?= h(implode(', ', $reasons)) ?></span>
                    <?php else: ?>
                        <span class="tag low">正常</span>
                    <?php endif; ?>
                </td>
                <td>
                    <span class="tag <?= h($riskClass) ?>"><?= h($risk) ?></span><br>
                    <span class="muted">M: <?= (int)$event['vt_malicious'] ?> S: <?= (int)$event['vt_suspicious'] ?></span><br>
                    <span class="muted"><?= h(trim(($event['vt_country'] ?? '') . ' ' . ($event['vt_as_owner'] ?? ''))) ?></span>
                </td>
                <td><?= h($event['source']) ?><br><span class="muted"><?= h($event['method']) ?></span></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</main>
</body>
</html>
