<?php
require __DIR__ . '/lib.php';

if (!request_admin_ok()) {
    http_response_code(401);
    echo 'Unauthorized. Add ?token=ADMIN_TOKEN.';
    exit;
}

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    http_response_code(400);
    echo 'Missing server id.';
    exit;
}

$pdo = db();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $allowedIps = preg_split('/[\r\n,]+/', (string)($_POST['allowed_ips'] ?? '')) ?: [];
    $allowedIps = array_values(array_unique(array_filter(array_map('normalize_ip', $allowedIps))));
    $owner = trim((string)($_POST['owner'] ?? ''));
    $notes = trim((string)($_POST['notes'] ?? ''));

    $stmt = $pdo->prepare('UPDATE servers SET allowed_ips = ?, owner = ?, notes = ?, updated_at = ? WHERE id = ?');
    $stmt->execute([
        json_encode($allowedIps, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        $owner,
        $notes,
        now_iso(),
        $id,
    ]);

    header('Location: server.php?token=' . urlencode((string)$_GET['token']) . '&id=' . $id);
    exit;
}

$stmt = $pdo->prepare('SELECT * FROM servers WHERE id = ?');
$stmt->execute([$id]);
$server = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$server) {
    http_response_code(404);
    echo 'Server not found.';
    exit;
}

$allowedIps = json_decode($server['allowed_ips'], true) ?: [];
?>
<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>服务器配置</title>
    <style>
        body { margin: 0; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; background: #f6f7f9; color: #17202a; }
        header { background: #101820; color: #fff; padding: 18px 24px; }
        main { padding: 24px; max-width: 860px; margin: 0 auto; }
        form { background: #fff; border: 1px solid #dde2e8; padding: 18px; }
        label { display: block; margin: 14px 0 6px; font-weight: 650; }
        input, textarea { width: 100%; box-sizing: border-box; padding: 9px 10px; border: 1px solid #cfd6df; border-radius: 6px; font: inherit; }
        textarea { min-height: 140px; }
        button { margin-top: 16px; padding: 9px 14px; border: 1px solid #155eef; color: #fff; background: #155eef; border-radius: 6px; cursor: pointer; }
        .muted { color: #687386; }
        a { color: #155eef; text-decoration: none; }
    </style>
</head>
<body>
<header>
    <h1>服务器配置: <?= h($server['hostname']) ?></h1>
</header>
<main>
    <p><a href="index.php?token=<?= h((string)$_GET['token']) ?>">返回后台</a></p>
    <form method="post">
        <p class="muted">Server Key: <?= h($server['server_key']) ?>，公网 IP: <?= h($server['public_ip']) ?></p>

        <label for="allowed_ips">允许登录 IP</label>
        <textarea id="allowed_ips" name="allowed_ips" placeholder="每行一个 IP"><?= h(implode("\n", $allowedIps)) ?></textarea>

        <label for="owner">负责人</label>
        <input id="owner" name="owner" value="<?= h($server['owner']) ?>">

        <label for="notes">备注</label>
        <textarea id="notes" name="notes"><?= h($server['notes']) ?></textarea>

        <button type="submit">保存</button>
    </form>
</main>
</body>
</html>
