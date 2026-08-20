<?php
require __DIR__ . '/lib.php';

if (!request_admin_ok()) {
    http_response_code(401);
    echo 'Unauthorized. Add ?token=ADMIN_TOKEN.';
    exit;
}

$pdo = db();
$adminToken = (string)$_GET['token'];
$showDeleted = isset($_GET['show_deleted']) && $_GET['show_deleted'] === '1';
$where = $showDeleted ? '1 = 1' : 's.deleted_at IS NULL';

$servers = $pdo->query("
    SELECT s.*,
        COUNT(e.id) AS login_count,
        SUM(CASE WHEN e.is_anomalous = 1 THEN 1 ELSE 0 END) AS anomaly_count,
        MAX(e.occurred_at) AS last_login_at
    FROM servers s
    LEFT JOIN login_events e ON e.server_id = s.id
    WHERE $where
    GROUP BY s.id
    ORDER BY s.deleted_at IS NOT NULL ASC, last_login_at DESC, s.updated_at DESC
")->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>服务器管理</title>
    <style>
        body { margin: 0; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; background: #f6f7f9; color: #17202a; }
        header { background: #101820; color: #fff; padding: 18px 24px; }
        main { padding: 24px; max-width: 1280px; margin: 0 auto; }
        h1 { margin: 0; font-size: 22px; }
        table { width: 100%; border-collapse: collapse; background: #fff; border: 1px solid #dde2e8; }
        th, td { padding: 10px 12px; border-bottom: 1px solid #eef1f4; text-align: left; font-size: 14px; vertical-align: top; }
        th { background: #eef2f6; font-weight: 650; }
        .toolbar { display: flex; gap: 10px; align-items: center; justify-content: space-between; margin: 0 0 14px; }
        .button { display: inline-block; padding: 8px 12px; border-radius: 6px; background: #155eef; color: #fff; text-decoration: none; }
        .ghost { background: #eef2f6; color: #17202a; }
        .tag { display: inline-block; padding: 2px 8px; border-radius: 999px; font-size: 12px; background: #edf2ff; color: #2446a6; }
        .bad { background: #ffe8e8; color: #9b1c1c; }
        .low { background: #e8f7ee; color: #146c3e; }
        .muted { color: #687386; }
        a { color: #155eef; text-decoration: none; }
    </style>
</head>
<body>
<header>
    <h1>服务器管理</h1>
</header>
<main>
    <div class="toolbar">
        <div>
            <a class="button" href="server.php?token=<?= h($adminToken) ?>">新增服务器</a>
            <a class="button ghost" href="index.php?token=<?= h($adminToken) ?>">返回后台</a>
        </div>
        <div>
            <?php if ($showDeleted): ?>
                <a href="servers.php?token=<?= h($adminToken) ?>">隐藏已删除</a>
            <?php else: ?>
                <a href="servers.php?token=<?= h($adminToken) ?>&show_deleted=1">显示已删除</a>
            <?php endif; ?>
        </div>
    </div>

    <table>
        <thead>
        <tr>
            <th>服务器</th>
            <th>公网 IP</th>
            <th>负责人</th>
            <th>登录次数</th>
            <th>异常</th>
            <th>最后登录</th>
            <th>状态</th>
            <th>操作</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($servers as $server): ?>
            <tr>
                <td><?= h($server['hostname']) ?><br><span class="muted"><?= h($server['server_key']) ?></span></td>
                <td><?= h($server['public_ip']) ?></td>
                <td><?= h($server['owner']) ?></td>
                <td><?= (int)$server['login_count'] ?></td>
                <td><span class="tag <?= ((int)$server['anomaly_count'] > 0) ? 'bad' : 'low' ?>"><?= (int)$server['anomaly_count'] ?></span></td>
                <td><?= h($server['last_login_at']) ?></td>
                <td>
                    <?php if ($server['deleted_at']): ?>
                        <span class="tag bad">已删除</span><br><span class="muted"><?= h($server['deleted_at']) ?></span>
                    <?php else: ?>
                        <span class="tag low">启用</span>
                    <?php endif; ?>
                </td>
                <td><a href="server.php?token=<?= h($adminToken) ?>&id=<?= (int)$server['id'] ?>">编辑</a></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</main>
</body>
</html>
