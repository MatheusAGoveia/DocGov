<?php
// api/permissions.php - REST API Central para Gestão de Permissões Hierárquicas (DocGov)
if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
    session_start();
}
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../services/PermissionService.php';

if (!headers_sent()) {
    header('Content-Type: application/json');
}

$loggedUser = $_SESSION['user'] ?? null;
$userId = $loggedUser ? (int)$loggedUser['id'] : 0;

if ($userId <= 0) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Sessão expirada ou usuário não autenticado.']);
    exit;
}

$permService = new PermissionService($pdo);
$method = $_SERVER['REQUEST_METHOD'];

// Suporte a payloads JSON em requisições POST e DELETE
$rawInput = file_get_contents('php://input');
$inputData = [];
if (!empty($rawInput)) {
    $decoded = json_decode($rawInput, true);
    if (is_array($decoded)) {
        $inputData = $decoded;
    }
}

// Merge com $_POST e $_GET para flexibilidade
$params = array_merge($_GET, $_POST, $inputData);

try {
    // --------------------------------------------------------------------------
    // 1. GET - Listar permissões diretas e herdadas de um recurso
    // --------------------------------------------------------------------------
    if ($method === 'GET') {
        $resourceType = strtolower(trim($params['resource_type'] ?? ''));
        $resourceId = (int)($params['resource_id'] ?? 0);

        if (!in_array($resourceType, ['category', 'subcategory', 'subject']) || $resourceId <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Recurso inválido. Informe resource_type (category|subcategory|subject) e resource_id.']);
            exit;
        }

        // Validação de Autorização: Deve possuir ADMIN no recurso ou ser Admin Global
        if (!$permService->canAdmin($userId, $resourceType, $resourceId)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Acesso negado. Você precisa de permissão de Administrador neste recurso para gerenciar suas permissões.']);
            exit;
        }

        $permissions = $permService->getResourcePermissions($resourceType, $resourceId);
        echo json_encode([
            'success' => true,
            'resource_type' => $resourceType,
            'resource_id' => $resourceId,
            'data' => $permissions
        ]);
        exit;
    }

    // --------------------------------------------------------------------------
    // 2. POST - Adicionar ou atualizar uma permissão direta (UPSERT)
    // --------------------------------------------------------------------------
    if ($method === 'POST') {
        $resourceType = strtolower(trim($params['resource_type'] ?? ''));
        $resourceId = (int)($params['resource_id'] ?? 0);
        $principalType = strtolower(trim($params['principal_type'] ?? '')); // 'user' ou 'group'/'team'
        $principalId = (int)($params['principal_id'] ?? 0);
        $level = strtolower(trim($params['permission_level'] ?? $params['level'] ?? ''));

        if (!in_array($resourceType, ['category', 'subcategory', 'subject']) || $resourceId <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Recurso alvo inválido.']);
            exit;
        }

        if (!in_array($principalType, ['user', 'group', 'team']) || $principalId <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Principal (Usuário ou Equipe) inválido.']);
            exit;
        }

        if (!in_array($level, ['view', 'edit', 'admin'])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Nível de permissão inválido. Escolha entre: view, edit, admin.']);
            exit;
        }

        // Validação de Autorização: Deve possuir ADMIN no recurso ou ser Admin Global
        if (!$permService->canAdmin($userId, $resourceType, $resourceId)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Acesso negado. Você não possui privilégios administrativos neste recurso.']);
            exit;
        }

        $targetUserId = ($principalType === 'user') ? $principalId : null;
        $targetGroupId = ($principalType === 'group' || $principalType === 'team') ? $principalId : null;

        $saved = $permService->saveResourcePermission(
            $resourceType,
            $resourceId,
            $targetUserId,
            $targetGroupId,
            $level,
            $userId
        );

        if ($saved) {
            echo json_encode(['success' => true, 'message' => 'Permissão salva com sucesso!']);
        } else {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Falha ao salvar permissão no banco de dados.']);
        }
        exit;
    }

    // --------------------------------------------------------------------------
    // 3. DELETE - Remover uma permissão direta pelo ID
    // --------------------------------------------------------------------------
    if ($method === 'DELETE' || ($method === 'POST' && isset($params['_method']) && strtoupper($params['_method']) === 'DELETE')) {
        $permissionId = (int)($params['id'] ?? $params['permission_id'] ?? 0);

        if ($permissionId <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'ID da permissão inválido.']);
            exit;
        }

        // Buscar a regra para validar a permissão administrativa do solicitante sobre o recurso
        $stmtRule = $pdo->prepare("SELECT category_id, subcategory_id, subject_id FROM permissions WHERE id = ?");
        $stmtRule->execute([$permissionId]);
        $rule = $stmtRule->fetch(PDO::FETCH_ASSOC);

        if (!$rule) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Regra de permissão não encontrada.']);
            exit;
        }

        $resType = !empty($rule['category_id']) ? 'category' : (!empty($rule['subcategory_id']) ? 'subcategory' : 'subject');
        $resId = !empty($rule['category_id']) ? (int)$rule['category_id'] : (!empty($rule['subcategory_id']) ? (int)$rule['subcategory_id'] : (int)$rule['subject_id']);

        // Validação de Autorização
        if (!$permService->canAdmin($userId, $resType, $resId)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Acesso negado. Você não possui autorização para remover permissões neste recurso.']);
            exit;
        }

        $deleted = $permService->deletePermission($permissionId, $userId);

        if ($deleted) {
            echo json_encode(['success' => true, 'message' => 'Permissão removida com sucesso!']);
        } else {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Falha ao remover permissão.']);
        }
        exit;
    }

    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Método HTTP não suportado.']);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Erro no servidor: ' . $e->getMessage()]);
}
