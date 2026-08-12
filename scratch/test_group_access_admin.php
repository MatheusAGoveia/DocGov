<?php
// Teste de integração das equipes. As fixtures são removidas ao final.
require __DIR__ . '/../config/db.php';
require __DIR__ . '/../services/PermissionService.php';

function groupAssert(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}

function groupInsert(PDO $pdo, string $sql, array $params): int {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (int)$stmt->fetchColumn();
}

function callGroupAction(int $adminId, array $params): array {
    $command = escapeshellarg(PHP_BINARY)
        . ' ' . escapeshellarg(__DIR__ . '/request_admin_action.php')
        . ' ' . escapeshellarg((string)$adminId)
        . ' admin '
        . escapeshellarg(base64_encode(json_encode($params)));
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) throw new RuntimeException('Falha ao executar ação administrativa.');
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);
    preg_match('/HTTP_STATUS:(\d+)/', $stderr, $match);
    return ['status' => isset($match[1]) ? (int)$match[1] : 0, 'stdout' => $stdout, 'stderr' => $stderr];
}

$suffix = bin2hex(random_bytes(5));
$adminId = 0;
$activeUserId = 0;
$inactiveUserId = 0;
$groupId = 0;

try {
    $adminId = groupInsert($pdo, "INSERT INTO users (name, username, email, role, active) VALUES (?, ?, ?, 'admin', TRUE) RETURNING id", ['Admin Equipes', "group.admin.{$suffix}", "group.admin.{$suffix}@example.invalid"]);
    $activeUserId = groupInsert($pdo, "INSERT INTO users (name, username, email, role, active) VALUES (?, ?, ?, 'reader', TRUE) RETURNING id", ['Membro Ativo', "group.active.{$suffix}", "group.active.{$suffix}@example.invalid"]);
    $inactiveUserId = groupInsert($pdo, "INSERT INTO users (name, username, email, role, active) VALUES (?, ?, ?, 'reader', FALSE) RETURNING id", ['Membro Inativo', "group.inactive.{$suffix}", "group.inactive.{$suffix}@example.invalid"]);
    $categoryId = groupInsert($pdo, "INSERT INTO categories (name, slug, description, active) VALUES (?, ?, '', TRUE) RETURNING id", ["Categoria Equipe {$suffix}", "categoria-equipe-{$suffix}"]);

    $teamName = "Equipe Integração {$suffix}";
    $withoutCsrf = callGroupAction($adminId, ['group_action' => 'create_group', 'name' => $teamName, 'active' => '1']);
    groupAssert($withoutCsrf['status'] === 419, 'Criação de equipe sem CSRF não foi bloqueada.');
    $checkGroup = $pdo->prepare('SELECT id FROM groups WHERE name = ?');
    $checkGroup->execute([$teamName]);
    groupAssert(!$checkGroup->fetchColumn(), 'Equipe foi criada sem token CSRF.');

    $created = callGroupAction($adminId, ['group_action' => 'create_group', 'csrf_token' => str_repeat('a', 64), 'name' => $teamName, 'active' => '1']);
    groupAssert($created['status'] === 302, 'Criação válida de equipe falhou: status ' . $created['status'] . ' / ' . $created['stderr']);
    $checkGroup->execute([$teamName]);
    $groupId = (int)$checkGroup->fetchColumn();
    groupAssert($groupId > 0, 'Equipe criada não foi persistida.');

    callGroupAction($adminId, ['group_action' => 'add_user', 'csrf_token' => str_repeat('a', 64), 'group_id' => $groupId, 'user_id' => $inactiveUserId]);
    $inactiveMembership = $pdo->prepare('SELECT COUNT(*) FROM user_groups WHERE group_id = ? AND user_id = ?');
    $inactiveMembership->execute([$groupId, $inactiveUserId]);
    groupAssert((int)$inactiveMembership->fetchColumn() === 0, 'Usuário inativo foi incluído na equipe.');

    $add = callGroupAction($adminId, ['group_action' => 'add_user', 'csrf_token' => str_repeat('a', 64), 'group_id' => $groupId, 'user_id' => $activeUserId]);
    groupAssert($add['status'] === 302, 'Usuário ativo não foi incluído na equipe.');
    $service = new PermissionService($pdo);
    $service->saveResourcePermission('category', $categoryId, null, $groupId, 'view', $adminId);
    groupAssert($service->canView($activeUserId, 'category', $categoryId), 'Regra da equipe não alcançou o membro.');

    callGroupAction($adminId, ['group_action' => 'toggle_status', 'csrf_token' => str_repeat('a', 64), 'group_id' => $groupId]);
    groupAssert(!$service->canView($activeUserId, 'category', $categoryId), 'Equipe inativa continuou concedendo acesso.');
    callGroupAction($adminId, ['group_action' => 'toggle_status', 'csrf_token' => str_repeat('a', 64), 'group_id' => $groupId]);
    groupAssert($service->canView($activeUserId, 'category', $categoryId), 'Reativar equipe não restaurou o acesso preservado.');

    callGroupAction($adminId, ['group_action' => 'remove_user', 'csrf_token' => str_repeat('a', 64), 'group_id' => $groupId, 'user_id' => $activeUserId]);
    groupAssert(!$service->canView($activeUserId, 'category', $categoryId), 'Membro removido continuou com acesso da equipe.');
    callGroupAction($adminId, ['group_action' => 'add_user', 'csrf_token' => str_repeat('a', 64), 'group_id' => $groupId, 'user_id' => $activeUserId]);

    callGroupAction($adminId, ['group_action' => 'delete_group', 'csrf_token' => str_repeat('a', 64), 'group_id' => $groupId]);
    groupAssert(!(bool)$pdo->query("SELECT 1 FROM groups WHERE id = {$groupId}")->fetchColumn(), 'Equipe excluída ainda existe.');
    $audit = $pdo->prepare("SELECT COUNT(*) FROM permission_audit WHERE user_id = ? AND principal_type = 'TEAM' AND principal_id = ? AND action = 'PERMISSION_REMOVED'");
    $audit->execute([$adminId, $groupId]);
    groupAssert((int)$audit->fetchColumn() >= 1, 'Exclusão da equipe não auditou a remoção das regras.');

    echo "[OK] Equipes: CSRF, membros ativos, ativação, desativação, herança, remoção e auditoria.\n";
} finally {
    if ($groupId > 0) {
        $pdo->prepare('DELETE FROM groups WHERE id = ?')->execute([$groupId]);
        $pdo->prepare("DELETE FROM permission_audit WHERE principal_type = 'TEAM' AND principal_id = ?")->execute([$groupId]);
        $pdo->prepare("DELETE FROM usage_audit_events WHERE metadata->>'team_id' = ?")->execute([(string)$groupId]);
    }
    if (isset($categoryId) && $categoryId > 0) $pdo->prepare('DELETE FROM categories WHERE id = ?')->execute([$categoryId]);
    foreach ([$inactiveUserId, $activeUserId, $adminId] as $userId) {
        if ($userId > 0) $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$userId]);
    }
}
