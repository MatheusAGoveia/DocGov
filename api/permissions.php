<?php
// API única de leitura e escrita das permissões hierárquicas do DocGov.
require_once __DIR__ . '/../config/session.php';
docgovStartSession();

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../services/PermissionService.php';
require_once __DIR__ . '/../services/CsrfService.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, private');
header('X-Content-Type-Options: nosniff');

function permissionsResponse(int $status, array $payload): never {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function permissionRuleResource(array $rule): array {
    if (!empty($rule['category_id'])) {
        return ['category', (int)$rule['category_id']];
    }
    if (!empty($rule['subcategory_id'])) {
        return ['subcategory', (int)$rule['subcategory_id']];
    }
    return ['subject', (int)($rule['subject_id'] ?? 0)];
}

$currentUserId = (int)($_SESSION['user']['id'] ?? 0);
if ($currentUserId <= 0) {
    permissionsResponse(401, ['success' => false, 'error' => 'Sessão expirada ou usuário não autenticado.']);
}

$rawInput = file_get_contents('php://input');
$jsonInput = [];
if ($rawInput !== '') {
    $decoded = json_decode($rawInput, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        permissionsResponse(400, ['success' => false, 'error' => 'JSON inválido.']);
    }
    $jsonInput = is_array($decoded) ? $decoded : [];
}

$params = array_merge($_GET, $_POST, $jsonInput);
$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if ($method === 'POST' && strtoupper((string)($params['_method'] ?? '')) === 'DELETE') {
    $method = 'DELETE';
}
if (in_array($method, ['POST', 'DELETE'], true)) {
    $csrfCandidate = (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? $params['csrf_token'] ?? '');
    if (!CsrfService::isValid($csrfCandidate)) {
        permissionsResponse(419, ['success' => false, 'error' => 'Sessão de segurança expirada. Atualize a página e tente novamente.']);
    }
}

$resourceType = strtolower(trim((string)($params['resource_type'] ?? '')));
$resourceId = (int)($params['resource_id'] ?? 0);
if (!in_array($resourceType, ['category', 'subcategory', 'subject'], true) || $resourceId <= 0) {
    permissionsResponse(400, ['success' => false, 'error' => 'Informe um recurso válido.']);
}

$permissionService = new PermissionService($pdo);
$resource = $permissionService->getResourceContext($resourceType, $resourceId);
if ($resource === null) {
    permissionsResponse(404, ['success' => false, 'error' => 'Recurso não encontrado.']);
}
if (!$permissionService->canAdmin($currentUserId, $resourceType, $resourceId)) {
    permissionsResponse(403, ['success' => false, 'error' => 'Você precisa de permissão Admin neste recurso.']);
}

try {
    if ($method === 'GET') {
        permissionsResponse(200, [
            'success' => true,
            'resource' => $resource,
            'roles' => [[
                'principal_type' => 'role',
                'principal_name' => 'Admin Geral',
                'principal_subtext' => 'Bypass global do sistema',
                'permission_level' => 'admin',
                'effective_level' => 'admin',
                'is_direct' => false,
                'locked' => true,
            ]],
            'data' => $permissionService->getResourcePermissions($resourceType, $resourceId),
        ]);
    }

    if ($method === 'POST') {
        $principalType = strtolower(trim((string)($params['principal_type'] ?? '')));
        if ($principalType === 'team') {
            $principalType = 'group';
        }
        $principalId = (int)($params['principal_id'] ?? 0);
        $level = strtolower(trim((string)($params['permission_level'] ?? $params['level'] ?? '')));

        if (!in_array($principalType, ['user', 'group'], true) || $principalId <= 0) {
            permissionsResponse(400, ['success' => false, 'error' => 'Selecione um usuário ou equipe válido.']);
        }
        if (!in_array($level, ['view', 'edit', 'admin'], true)) {
            permissionsResponse(400, ['success' => false, 'error' => 'Permissão inválida.']);
        }

        $resourceColumn = [
            'category' => 'category_id',
            'subcategory' => 'subcategory_id',
            'subject' => 'subject_id',
        ][$resourceType];
        $principalColumn = $principalType === 'user' ? 'user_id' : 'group_id';
        $principalTable = $principalType === 'user' ? 'users' : 'groups';

        $stmtPrincipal = $pdo->prepare("SELECT active FROM {$principalTable} WHERE id = ?");
        $stmtPrincipal->execute([$principalId]);
        $principal = $stmtPrincipal->fetch(PDO::FETCH_ASSOC);
        if (!$principal) {
            permissionsResponse(404, ['success' => false, 'error' => 'Usuário ou equipe não encontrado.']);
        }
        $principalActive = filter_var($principal['active'], FILTER_VALIDATE_BOOLEAN);

        $stmtExisting = $pdo->prepare("SELECT id, permission_level FROM permissions WHERE {$principalColumn} = ? AND {$resourceColumn} = ? LIMIT 1");
        $stmtExisting->execute([$principalId, $resourceId]);
        $existing = $stmtExisting->fetch(PDO::FETCH_ASSOC);
        if (!$principalActive && !$existing) {
            permissionsResponse(400, ['success' => false, 'error' => 'Não é possível criar uma permissão para um usuário ou equipe inativa.']);
        }

        $permissionService->saveResourcePermission(
            $resourceType,
            $resourceId,
            $principalType === 'user' ? $principalId : null,
            $principalType === 'group' ? $principalId : null,
            $level,
            $currentUserId
        );

        $wasChanged = $existing && strtolower($existing['permission_level']) !== $level;
        $message = $existing
            ? ($wasChanged ? 'Permissão atualizada.' : 'Permissão já estava configurada.')
            : 'Permissão adicionada.';
        permissionsResponse(200, [
            'success' => true,
            'message' => $message,
            'data' => $permissionService->getResourcePermissions($resourceType, $resourceId),
        ]);
    }

    if ($method === 'DELETE') {
        $permissionId = (int)($params['permission_id'] ?? $params['id'] ?? 0);
        if ($permissionId <= 0) {
            permissionsResponse(400, ['success' => false, 'error' => 'Permissão inválida.']);
        }

        $stmtRule = $pdo->prepare('SELECT id, category_id, subcategory_id, subject_id FROM permissions WHERE id = ?');
        $stmtRule->execute([$permissionId]);
        $rule = $stmtRule->fetch(PDO::FETCH_ASSOC);
        if (!$rule) {
            permissionsResponse(404, ['success' => false, 'error' => 'Regra de permissão não encontrada.']);
        }

        [$ruleResourceType, $ruleResourceId] = permissionRuleResource($rule);
        if ($ruleResourceType !== $resourceType || $ruleResourceId !== $resourceId) {
            permissionsResponse(409, ['success' => false, 'error' => 'Apenas regras diretas deste recurso podem ser removidas aqui.']);
        }

        if (!$permissionService->deletePermission($permissionId, $currentUserId)) {
            permissionsResponse(404, ['success' => false, 'error' => 'Regra de permissão não encontrada.']);
        }

        permissionsResponse(200, [
            'success' => true,
            'message' => 'Permissão removida.',
            'data' => $permissionService->getResourcePermissions($resourceType, $resourceId),
        ]);
    }

    permissionsResponse(405, ['success' => false, 'error' => 'Método HTTP não suportado.']);
} catch (InvalidArgumentException $e) {
    permissionsResponse(400, ['success' => false, 'error' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('Erro em permissions.php: ' . $e->getMessage());
    permissionsResponse(500, ['success' => false, 'error' => 'Não foi possível concluir a operação de permissão.']);
}
