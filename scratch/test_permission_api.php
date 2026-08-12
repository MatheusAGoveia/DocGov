<?php
// Teste de integração HTTP/JSON do fluxo de administração local.
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../services/PermissionService.php';

function apiAssert(bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function insertApiFixture(PDO $pdo, string $sql, array $params): int {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (int)$stmt->fetchColumn();
}

function callPermissionEndpoint(string $endpoint, string $method, int $userId, string $role, array $params, bool $withCsrf = true): array {
    if ($withCsrf && in_array(strtoupper($method), ['POST', 'DELETE'], true)) {
        $params['csrf_token'] = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    }
    $command = escapeshellarg(PHP_BINARY)
        . ' ' . escapeshellarg(__DIR__ . '/request_permission_api.php')
        . ' ' . escapeshellarg($endpoint)
        . ' ' . escapeshellarg($method)
        . ' ' . escapeshellarg((string)$userId)
        . ' ' . escapeshellarg($role)
        . ' ' . escapeshellarg(base64_encode(json_encode($params)));

    $process = proc_open($command, [
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('Não foi possível executar o endpoint em subprocesso.');
    }
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);

    preg_match('/HTTP_STATUS:(\d+)/', $stderr, $statusMatch);
    $status = isset($statusMatch[1]) ? (int)$statusMatch[1] : 0;
    $payload = json_decode($stdout, true);
    return ['status' => $status, 'payload' => $payload, 'stderr' => $stderr, 'stdout' => $stdout];
}

$suffix = bin2hex(random_bytes(5));
$fixtureIds = ['users' => [], 'groups' => [], 'categories' => [], 'subcategories' => [], 'subjects' => [], 'documents' => []];

try {
    $globalAdminId = insertApiFixture($pdo, "INSERT INTO users (name, username, email, role, active) VALUES (?, ?, ?, 'admin', TRUE) RETURNING id", ['API Global Admin', "api.global.{$suffix}", "api.global.{$suffix}@example.invalid"]);
    $localAdminId = insertApiFixture($pdo, "INSERT INTO users (name, username, email, role, active) VALUES (?, ?, ?, 'reader', TRUE) RETURNING id", ['Leonidas API', "api.leonidas.{$suffix}", "api.leonidas.{$suffix}@example.invalid"]);
    $targetUserId = insertApiFixture($pdo, "INSERT INTO users (name, username, email, role, active) VALUES (?, ?, ?, 'reader', TRUE) RETURNING id", ['Samuel API', "api.samuel.{$suffix}", "api.samuel.{$suffix}@example.invalid"]);
    $fixtureIds['users'] = [$globalAdminId, $localAdminId, $targetUserId];

    $groupId = insertApiFixture($pdo, "INSERT INTO groups (name, description, active) VALUES (?, '', TRUE) RETURNING id", ["Equipe API {$suffix}"]);
    $fixtureIds['groups'][] = $groupId;

    $categoryId = insertApiFixture($pdo, "INSERT INTO categories (name, slug, description, active) VALUES (?, ?, '', TRUE) RETURNING id", ['Infraestrutura API', "api-infra-{$suffix}"]);
    $fixtureIds['categories'][] = $categoryId;
    $networksId = insertApiFixture($pdo, "INSERT INTO subcategories (category_id, name, slug, description, active) VALUES (?, ?, ?, '', TRUE) RETURNING id", [$categoryId, 'Redes API', "api-redes-{$suffix}"]);
    $serversId = insertApiFixture($pdo, "INSERT INTO subcategories (category_id, name, slug, description, active) VALUES (?, ?, ?, '', TRUE) RETURNING id", [$categoryId, 'Servidores API', "api-servidores-{$suffix}"]);
    $firewallId = insertApiFixture($pdo, "INSERT INTO subjects (subcategory_id, name, slug, description, active) VALUES (?, ?, ?, '', TRUE) RETURNING id", [$networksId, 'Firewall API', "api-firewall-{$suffix}"]);
    $databaseId = insertApiFixture($pdo, "INSERT INTO subjects (subcategory_id, name, slug, description, active) VALUES (?, ?, ?, '', TRUE) RETURNING id", [$serversId, 'Banco de Dados API', "api-database-{$suffix}"]);
    $fixtureIds['subcategories'] = [$networksId, $serversId];
    $fixtureIds['subjects'] = [$firewallId, $databaseId];

    $insideDocumentId = insertApiFixture($pdo, "INSERT INTO documents (subject_id, created_by, title, slug, content_type, status) VALUES (?, ?, ?, ?, 'text', 'draft') RETURNING id", [$firewallId, $localAdminId, 'Documento interno autorizado', "api-inside-{$suffix}"]);
    $outsideDocumentId = insertApiFixture($pdo, "INSERT INTO documents (subject_id, created_by, title, slug, content_type, status) VALUES (?, ?, ?, ?, 'text', 'draft') RETURNING id", [$databaseId, $globalAdminId, 'Documento sigiloso externo', "api-outside-{$suffix}"]);
    $fixtureIds['documents'] = [$insideDocumentId, $outsideDocumentId];

    $service = new PermissionService($pdo);
    $service->saveResourcePermission('subcategory', $networksId, $localAdminId, null, 'admin', $globalAdminId);

    $scopedDocuments = callPermissionEndpoint('documents', 'GET', $localAdminId, 'reader', ['status' => 'all']);
    $scopedDocumentIds = array_map('intval', array_column($scopedDocuments['payload']['data'] ?? [], 'id'));
    apiAssert(in_array($insideDocumentId, $scopedDocumentIds, true), 'Endpoint administrativo omitiu documento do ramo autorizado.');
    apiAssert(!in_array($outsideDocumentId, $scopedDocumentIds, true), 'Endpoint administrativo expôs documento de ramo externo.');

    $authorizedSearch = callPermissionEndpoint('search', 'GET', $localAdminId, 'editor', [
        'type' => 'user', 'q' => 'Samuel API', 'resource_type' => 'subject', 'resource_id' => $firewallId,
    ]);
    apiAssert($authorizedSearch['status'] === 200 && ($authorizedSearch['payload']['success'] ?? false), 'Admin Local não conseguiu pesquisar usuários no descendente autorizado.');

    $authorizedTeamSearch = callPermissionEndpoint('search', 'GET', $localAdminId, 'editor', [
        'type' => 'group', 'q' => 'Equipe API', 'resource_type' => 'subcategory', 'resource_id' => $networksId,
    ]);
    apiAssert($authorizedTeamSearch['status'] === 200 && ($authorizedTeamSearch['payload']['success'] ?? false), 'Admin Local não conseguiu pesquisar equipes.');

    $forbiddenSearch = callPermissionEndpoint('search', 'GET', $localAdminId, 'editor', [
        'type' => 'user', 'resource_type' => 'subcategory', 'resource_id' => $serversId,
    ]);
    apiAssert($forbiddenSearch['status'] === 403, 'Pesquisa fora do ramo do Admin Local não retornou 403.');

    $list = callPermissionEndpoint('permissions', 'GET', $localAdminId, 'editor', [
        'resource_type' => 'subject', 'resource_id' => $firewallId,
    ]);
    apiAssert($list['status'] === 200 && ($list['payload']['success'] ?? false), 'Admin Local não abriu permissões do descendente.');

    $pdo->prepare('UPDATE users SET active = FALSE WHERE id = ?')->execute([$localAdminId]);
    $inactiveManager = callPermissionEndpoint('permissions', 'GET', $localAdminId, 'editor', [
        'resource_type' => 'subject', 'resource_id' => $firewallId,
    ]);
    apiAssert($inactiveManager['status'] === 403, 'Sessão de administrador local inativado continuou autorizada.');
    $pdo->prepare('UPDATE users SET active = TRUE WHERE id = ?')->execute([$localAdminId]);

    $csrfRejected = callPermissionEndpoint('permissions', 'POST', $localAdminId, 'editor', [
        'resource_type' => 'subject',
        'resource_id' => $firewallId,
        'principal_type' => 'user',
        'principal_id' => $targetUserId,
        'permission_level' => 'view',
    ], false);
    apiAssert($csrfRejected['status'] === 419, 'Escrita sem token CSRF não foi bloqueada.');

    $pdo->prepare('UPDATE users SET active = FALSE WHERE id = ?')->execute([$targetUserId]);
    $inactiveTarget = callPermissionEndpoint('permissions', 'POST', $localAdminId, 'editor', [
        'resource_type' => 'subject',
        'resource_id' => $firewallId,
        'principal_type' => 'user',
        'principal_id' => $targetUserId,
        'permission_level' => 'view',
    ]);
    apiAssert($inactiveTarget['status'] === 400, 'API criou permissão para usuário inativo.');
    $pdo->prepare('UPDATE users SET active = TRUE WHERE id = ?')->execute([$targetUserId]);

    $globalTarget = callPermissionEndpoint('permissions', 'POST', $localAdminId, 'editor', [
        'resource_type' => 'subject',
        'resource_id' => $firewallId,
        'principal_type' => 'user',
        'principal_id' => $globalAdminId,
        'permission_level' => 'view',
    ]);
    apiAssert($globalTarget['status'] === 400, 'API criou regra redundante para Admin Global.');

    foreach (['view', 'edit', 'admin'] as $level) {
        $save = callPermissionEndpoint('permissions', 'POST', $localAdminId, 'editor', [
            'resource_type' => 'subject',
            'resource_id' => $firewallId,
            'principal_type' => 'user',
            'principal_id' => $targetUserId,
            'permission_level' => $level,
        ]);
        apiAssert($save['status'] === 200 && ($save['payload']['success'] ?? false), "Falha ao conceder {$level} como Admin Local.");
    }

    $permissionStmt = $pdo->prepare('SELECT id FROM permissions WHERE user_id = ? AND subject_id = ?');
    $permissionStmt->execute([$targetUserId, $firewallId]);
    $permissionId = (int)$permissionStmt->fetchColumn();
    apiAssert($permissionId > 0, 'A regra direta criada pela API não foi encontrada.');

    $delete = callPermissionEndpoint('permissions', 'DELETE', $localAdminId, 'editor', [
        'permission_id' => $permissionId,
        'resource_type' => 'subject',
        'resource_id' => $firewallId,
    ]);
    apiAssert($delete['status'] === 200 && ($delete['payload']['success'] ?? false), 'Admin Local não removeu a regra direta.');

    $forbiddenWrite = callPermissionEndpoint('permissions', 'POST', $localAdminId, 'editor', [
        'resource_type' => 'subcategory',
        'resource_id' => $serversId,
        'principal_type' => 'user',
        'principal_id' => $targetUserId,
        'permission_level' => 'view',
    ]);
    apiAssert($forbiddenWrite['status'] === 403, 'Escrita fora do ramo do Admin Local não retornou 403.');

    $auditStmt = $pdo->prepare("SELECT action, COUNT(*) FROM permission_audit WHERE user_id = ? AND principal_id = ? AND resource_type = 'SUBJECT' AND resource_id = ? GROUP BY action");
    $auditStmt->execute([$localAdminId, $targetUserId, $firewallId]);
    $auditCounts = $auditStmt->fetchAll(PDO::FETCH_KEY_PAIR);
    foreach (['PERMISSION_CREATED', 'PERMISSION_CHANGED', 'PERMISSION_REMOVED'] as $action) {
        apiAssert(!empty($auditCounts[$action]), "Auditoria HTTP ausente para {$action}.");
    }

    echo "[OK] Admin Local: CSRF, ativos, GET, busca, VIEW/EDIT/ADMIN, DELETE, auditoria e escopo 403.\n";
} finally {
    foreach ($fixtureIds['documents'] as $id) {
        $pdo->prepare('DELETE FROM documents WHERE id = ?')->execute([$id]);
    }
    foreach ($fixtureIds['subjects'] as $id) {
        $pdo->prepare('DELETE FROM subjects WHERE id = ?')->execute([$id]);
    }
    foreach ($fixtureIds['subcategories'] as $id) {
        $pdo->prepare('DELETE FROM subcategories WHERE id = ?')->execute([$id]);
    }
    foreach ($fixtureIds['categories'] as $id) {
        $pdo->prepare('DELETE FROM categories WHERE id = ?')->execute([$id]);
    }
    foreach ($fixtureIds['groups'] as $id) {
        $pdo->prepare('DELETE FROM groups WHERE id = ?')->execute([$id]);
    }
    foreach (array_reverse($fixtureIds['users']) as $id) {
        $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
    }
}
