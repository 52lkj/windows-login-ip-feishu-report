<?php
require __DIR__ . '/lib.php';

if (!request_admin_ok()) {
    http_response_code(401);
    echo 'Unauthorized. Add ?token=ADMIN_TOKEN.';
    exit;
}

$ip = normalize_ip((string)($_GET['ip'] ?? ''));
if ($ip === '') {
    http_response_code(400);
    echo 'Missing ip.';
    exit;
}

$pdo = db();
if (isset($_POST['refresh_vt'])) {
    $result = vt_enrich_ip_with_status($pdo, $ip);
    $params = [
        'token' => (string)$_GET['token'],
        'ip' => $ip,
        'vt_status' => $result['ok'] ? 'ok' : 'failed',
        'vt_message' => (string)($result['message'] ?? ''),
    ];
    header('Location: ip.php?' . http_build_query($params));
    exit;
}

$stmt = $pdo->prepare('SELECT * FROM ip_enrichment WHERE ip = ?');
$stmt->execute([$ip]);
$enrichment = $stmt->fetch(PDO::FETCH_ASSOC);

$stmt = $pdo->prepare('
    SELECT * FROM login_events
    WHERE login_ip = ?
    ORDER BY occurred_at DESC, id DESC
    LIMIT 100
');
$stmt->execute([$ip]);
$events = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>IP 详情</title>
    <style>
        body { margin: 0; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; background: #f6f7f9; color: #17202a; }
        header { background: #101820; color: #fff; padding: 18px 24px; }
        main { padding: 24px; max-width: 1100px; margin: 0 auto; }
        table { width: 100%; border-collapse: collapse; background: #fff; border: 1px solid #dde2e8; }
        th, td { padding: 10px 12px; border-bottom: 1px solid #eef1f4; text-align: left; font-size: 14px; }
        th { background: #eef2f6; }
        .panel { background: #fff; border: 1px solid #dde2e8; padding: 16px; margin-bottom: 18px; }
        .tag { display: inline-block; padding: 2px 8px; border-radius: 999px; font-size: 12px; background: #edf2ff; color: #2446a6; }
        .bad { background: #ffe8e8; color: #9b1c1c; }
        .mid { background: #fff4d6; color: #7a4b00; }
        .low { background: #e8f7ee; color: #146c3e; }
        .notice { border: 1px solid #b7d7bd; background: #edf8ef; color: #14532d; padding: 10px 12px; margin-bottom: 14px; }
        .notice.failed { border-color: #f2b8b5; background: #fff0ef; color: #8a1c14; }
        .muted { color: #687386; }
        button { padding: 8px 12px; border: 1px solid #155eef; color: #fff; background: #155eef; border-radius: 6px; cursor: pointer; }
        a { color: #155eef; text-decoration: none; }
    </style>
</head>
<body>
<header>
    <h1>IP 详情: <?= h($ip) ?></h1>
</header>
<main>
    <p><a href="index.php?token=<?= h((string)$_GET['token']) ?>">返回后台</a></p>
    <div class="panel">
        <h2>VirusTotal</h2>
        <?php if (isset($_GET['vt_status'])): ?>
            <?php $noticeClass = (string)$_GET['vt_status'] === 'ok' ? '' : ' failed'; ?>
            <div class="notice<?= h($noticeClass) ?>">
                <?= h((string)($_GET['vt_message'] ?? 'VirusTotal 刷新完成。')) ?>
                <?php if ((string)$_GET['vt_status'] !== 'ok'): ?>
                    <br><a href="health.php?token=<?= h((string)$_GET['token']) ?>&check_vt=1">查看健康检查</a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
        <?php if ($enrichment): ?>
            <?php $risk = $enrichment['risk_level']; $riskClass = $risk === 'high' ? 'bad' : ($risk === 'medium' ? 'mid' : 'low'); ?>
            <p>风险等级: <span class="tag <?= h($riskClass) ?>"><?= h($risk) ?></span></p>
            <p>恶意: <?= (int)$enrichment['vt_malicious'] ?>，可疑: <?= (int)$enrichment['vt_suspicious'] ?>，无害: <?= (int)$enrichment['vt_harmless'] ?>，未检出: <?= (int)$enrichment['vt_undetected'] ?></p>
            <p>国家/ASN: <?= h(trim(($enrichment['vt_country'] ?? '') . ' ' . ($enrichment['vt_as_owner'] ?? ''))) ?></p>
            <p class="muted">最后查询: <?= h($enrichment['vt_last_checked_at']) ?></p>
        <?php else: ?>
            <p class="muted">还没有 VirusTotal 缓存结果。</p>
        <?php endif; ?>
        <form method="post">
            <button type="submit" name="refresh_vt" value="1">刷新 VirusTotal</button>
        </form>
    </div>

    <h2>登录记录</h2>
    <table>
        <thead>
        <tr>
            <th>时间</th>
            <th>服务器</th>
            <th>用户</th>
            <th>异常原因</th>
            <th>来源</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($events as $event): ?>
            <tr>
                <td><?= h($event['occurred_at']) ?></td>
                <td><?= h($event['hostname']) ?></td>
                <td><?= h($event['username']) ?></td>
                <td><?= h(implode(', ', json_decode($event['anomaly_reasons'], true) ?: [])) ?></td>
                <td><?= h($event['source']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</main>
</body>
</html>
