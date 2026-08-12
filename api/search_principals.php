<?php
// Pesquisa contextual de usuários e equipes para o painel de permissões.
require_once __DIR__ . '/../config/session.php';
docgovStartSession();

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../services/PermissionService.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, private');
header('X-Content-Type-Options: nosniff');

function searchPrincipalsResponse(int $status, array $payload): never {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$currentUserId = (int)($_SESSION['user']['id'] ?? 0);
if ($currentUserId <= 0) {
    searchPrincipalsResponse(401, ['success' => false, 'error' => 'Sessão expirada ou usuário não autenticado.']);
}

$query = trim((string)($_GET['q'] ?? ''));
$principalType = strtolower(trim((string)($_GET['type'] ?? 'user')));
$resourceType = strtolower(trim((string)($_GET['resource_type'] ?? '')));
$resourceId = (int)($_GET['resource_id'] ?? 0);

if (!in_array($principalType, ['user', 'group', 'team'], true)) {
    searchPrincipalsResponse(400, ['success' => false, 'error' => 'Tipo de principal inválido.']);
}
if (!in_array($resourceType, ['category', 'subcategory', 'subject'], true) || $resourceId <= 0) {
    searchPrincipalsResponse(400, ['success' => false, 'error' => 'O contexto do recurso é obrigatório.']);
}

$permissionService = new PermissionService($pdo);
$resource = $permissionService->getResourceContext($resourceType, $resourceId);
if ($resource === null) {
    searchPrincipalsResponse(404, ['success' => false, 'error' => 'Recurso não encontrado.']);
}
if (!$permissionService->canAdmin($currentUserId, $resourceType, $resourceId)) {
    searchPrincipalsResponse(403, ['success' => false, 'error' => 'Acesso negado para pesquisar principais neste recurso.']);
}

$categoryId = $resourceType === 'category' ? $resourceId : null;
$subcategoryId = $resourceType === 'subcategory' ? $resourceId : null;
$subjectId = $resourceType === 'subject' ? $resourceId : null;
$results = [];

try {
    if ($principalType === 'user') {
        $sql = "
            SELECT u.id, u.name, u.username, u.email,
                   (
                       SELECT p.permission_level
                       FROM permissions p
                       WHERE p.user_id = u.id
                         AND p.category_id IS NOT DISTINCT FROM :category_id
                         AND p.subcategory_id IS NOT DISTINCT FROM :subcategory_id
                         AND p.subject_id IS NOT DISTINCT FROM :subject_id
                       LIMIT 1
                   ) AS existing_level
            FROM users u
            WHERE u.active = TRUE AND u.role <> 'admin'
        ";
        $params = [
            ':category_id' => $categoryId,
            ':subcategory_id' => $subcategoryId,
            ':subject_id' => $subjectId,
        ];
        if ($query !== '') {
            $sql .= ' AND (u.name ILIKE :query OR u.username ILIKE :query OR u.email ILIKE :query)';
            $params[':query'] = '%' . $query . '%';
        }
        $sql .= ' ORDER BY u.name ASC LIMIT 25';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $results[] = [
                'id' => (int)$row['id'],
                'name' => $row['name'],
                'type' => 'user',
                'subtext' => '@' . $row['username'] . ' · ' . $row['email'],
                'existing_level' => $row['existing_level'] ? strtolower($row['existing_level']) : null,
            ];
        }
    } else {
        $sql = "
            SELECT g.id, g.name, g.description, COUNT(ug.user_id) AS member_count,
                   (
                       SELECT p.permission_level
                       FROM permissions p
                       WHERE p.group_id = g.id
                         AND p.category_id IS NOT DISTINCT FROM :category_id
                         AND p.subcategory_id IS NOT DISTINCT FROM :subcategory_id
                         AND p.subject_id IS NOT DISTINCT FROM :subject_id
                       LIMIT 1
                   ) AS existing_level
            FROM groups g
            LEFT JOIN user_groups ug ON ug.group_id = g.id
            WHERE g.active = TRUE
        ";
        $params = [
            ':category_id' => $categoryId,
            ':subcategory_id' => $subcategoryId,
            ':subject_id' => $subjectId,
        ];
        if ($query !== '') {
            $sql .= ' AND (g.name ILIKE :query OR g.description ILIKE :query)';
            $params[':query'] = '%' . $query . '%';
        }
        $sql .= ' GROUP BY g.id, g.name, g.description ORDER BY g.name ASC LIMIT 25';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $memberCount = (int)$row['member_count'];
            $results[] = [
                'id' => (int)$row['id'],
                'name' => $row['name'],
                'type' => 'group',
                'subtext' => $memberCount . ($memberCount === 1 ? ' membro' : ' membros'),
                'existing_level' => $row['existing_level'] ? strtolower($row['existing_level']) : null,
            ];
        }
    }

    searchPrincipalsResponse(200, ['success' => true, 'data' => $results]);
} catch (Throwable $e) {
    error_log('Erro em search_principals.php: ' . $e->getMessage());
    searchPrincipalsResponse(500, ['success' => false, 'error' => 'Não foi possível pesquisar usuários e equipes.']);
}
