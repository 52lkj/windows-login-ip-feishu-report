<?php
require __DIR__ . '/lib.php';

if (!request_admin_ok()) {
    http_response_code(401);
    echo 'Unauthorized. Add ?token=ADMIN_TOKEN.';
    exit;
}

$pdo = db();
$adminToken = (string)$_GET['token'];
$id = (int)($_GET['id'] ?? 0);
$errors = [];
$server = [
    'id' => 0,
    'server_key' => '',
    'hostname' => '',
    'public_ip' => '',
    'allowed_ips' => '[]',
    'owner' => '',
    'notes' => '',
    'deleted_at' => null,
];

if ($id > 0) {
    $stmt = $pdo->prepare('SELECT * FROM servers WHERE id = ?');
    $stmt->execute([$id]);
    $server = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$server) {
        http_response_code(404);
        echo 'Server not found.';
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? 'save');

    if ($action === 'delete' && $id > 0) {
        $now = now_iso();
        $stmt = $pdo->prepare('UPDATE servers SET deleted_at = ?, updated_at = ? WHERE id = ?');
        $stmt->execute([$now, $now, $id]);
        header('Location: servers.php?token=' . urlencode($adminToken));
        exit;
    }

    if ($action === 'restore' && $id > 0) {
        $stmt = $pdo->prepare('UPDATE servers SET deleted_at = NULL, updated_at = ? WHERE id = ?');
        $stmt->execute([now_iso(), $id]);
        header('Location: server.php?token=' . urlencode($adminToken) . '&id=' . $id);
        exit;
    }

    $serverKey = trim((string)($_POST['server_key'] ?? ''));
    $hostname = trim((string)($_POST['hostname'] ?? ''));
    $publicIp = normalize_ip((string)($_POST['public_ip'] ?? ''));
    $allowedIps = preg_split('/[\r\n,]+/', (string)($_POST['allowed_ips'] ?? '')) ?: [];
    $allowedIps = array_values(array_unique(array_filter(array_map('normalize_ip', $allowedIps))));
    $owner = trim((string)($_POST['owner'] ?? ''));
    $notes = trim((string)($_POST['notes'] ?? ''));

    if ($serverKey === '') {
        $errors[] = 'Server Key is required.';
    }
    if ($hostname === '') {
        $errors[] = 'Hostname is required.';
    }

    if (!$errors) {
        $now = now_iso();
        $allowedIpsJson = json_encode($allowedIps, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        try {
            if ($id > 0) {
                $stmt = $pdo->prepare('
                    UPDATE servers
                    SET server_key = ?, hostname = ?, public_ip = ?, allowed_ips = ?, owner = ?, notes = ?, updated_at = ?
                    WHERE id = ?
                ');
                $stmt->execute([$serverKey, $hostname, $publicIp ?: null, $allowedIpsJson, $owner, $notes, $now, $id]);
            } else {
                $stmt = $pdo->prepare('
                    INSERT INTO servers (server_key, hostname, public_ip, allowed_ips, owner, notes, created_at, updated_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ');
                $stmt->execute([$serverKey, $hostname, $publicIp ?: null, $allowedIpsJson, $owner, $notes, $now, $now]);
                $id = (int)$pdo->lastInsertId();
            }

            header('Location: server.php?token=' . urlencode($adminToken) . '&id=' . $id);
            exit;
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                $errors[] = 'Server Key already exists.';
            } else {
                throw $e;
            }
        }
    }

    $server = array_merge($server, [
        'server_key' => $serverKey,
        'hostname' => $hostname,
        'public_ip' => $publicIp,
        'allowed_ips' => json_encode($allowedIps, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'owner' => $owner,
        'notes' => $notes,
    ]);
}

$allowedIps = json_decode($server['allowed_ips'], true) ?: [];
$isNew = $id <= 0;
?>
<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $isNew ? '新增服务器' : '服务器配置' ?></title>
    <style>
        body { margin: 0; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; background: #f6f7f9; color: #17202a; }
        header { background: #101820; color: #fff; padding: 18px 24px; }
        main { padding: 24px; max-width: 860px; margin: 0 auto; }
        form { background: #fff; border: 1px solid #dde2e8; padding: 18px; }
        label { display: block; margin: 14px 0 6px; font-weight: 650; }
        input, textarea { width: 100%; box-sizing: border-box; padding: 9px 10px; border: 1px solid #cfd6df; border-radius: 6px; font: inherit; }
        textarea { min-height: 140px; }
        button { margin-top: 16px; padding: 9px 14px; border: 1px solid #155eef; color: #fff; background: #155eef; border-radius: 6px; cursor: pointer; }
        .danger-button { background: #9b1c1c; border-color: #9b1c1c; }
        .secondary-button { background: #eef2f6; color: #17202a; border-color: #cfd6df; }
        .errors { background: #ffe8e8; color: #9b1c1c; border: 1px solid #ffc9c9; padding: 10px 12px; margin-bottom: 14px; }
        .muted { color: #687386; }
        a { color: #155eef; text-decoration: none; }
    </style>
</head>
<body>
<header>
    <h1><?= $isNew ? '新增服务器' : '服务器配置: ' . h($server['hostname']) ?></h1>
</header>
<main>
    <p><a href="servers.php?token=<?= h($adminToken) ?>">返回服务器管理</a></p>
    <?php if ($errors): ?>
        <div class="errors"><?= h(implode(' ', $errors)) ?></div>
    <?php endif; ?>
    <form method="post">
        <?php if ($server['deleted_at']): ?>
            <p class="muted">当前服务器已删除，历史登录记录仍然保留。点击恢复可重新启用。</p>
        <?php endif; ?>

        <label for="server_key">Server Key</label>
        <input id="server_key" name="server_key" value="<?= h($server['server_key']) ?>" placeholder="stable-server-id">

        <label for="hostname">主机名</label>
        <input id="hostname" name="hostname" value="<?= h($server['hostname']) ?>" placeholder="server-hostname">

        <label for="public_ip">公网 IP</label>
        <input id="public_ip" name="public_ip" value="<?= h($server['public_ip']) ?>" placeholder="203.0.113.10">

        <label for="allowed_ips">允许登录 IP</label>
        <textarea id="allowed_ips" name="allowed_ips" placeholder="每行一个 IP"><?= h(implode("\n", $allowedIps)) ?></textarea>

        <label for="owner">负责人</label>
        <input id="owner" name="owner" value="<?= h($server['owner']) ?>">

        <label for="notes">备注</label>
        <textarea id="notes" name="notes"><?= h($server['notes']) ?></textarea>

        <button type="submit" name="action" value="save">保存</button>
        <?php if (!$isNew && !$server['deleted_at']): ?>
            <button class="danger-button" type="submit" name="action" value="delete" onclick="return confirm('确定删除这台服务器吗？历史登录记录会保留。')">删除</button>
        <?php endif; ?>
        <?php if (!$isNew && $server['deleted_at']): ?>
            <button class="secondary-button" type="submit" name="action" value="restore">恢复</button>
        <?php endif; ?>
    </form>
</main>
</body>
</html>
