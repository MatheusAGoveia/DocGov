<?php
// api/categories.php - Endpoint JSON para Categorias (PostgreSQL com PermissionService)
require_once __DIR__ . '/../config/session.php';
docgovStartSession();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../services/PermissionService.php';
require_once __DIR__ . '/../services/CsrfService.php';

if (!headers_sent()) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, private');
    header('X-Content-Type-Options: nosniff');
}

$loggedUser = $_SESSION['user'] ?? null;
$userId = $loggedUser ? (int)$loggedUser['id'] : 0;
$permService = new PermissionService($pdo);

$method = $_SERVER['REQUEST_METHOD'];

if ($userId <= 0) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Sessão expirada ou usuário não autenticado.']);
    exit;
}
if ($method === 'POST') {
    $csrfCandidate = (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf_token'] ?? '');
    if (!CsrfService::isValid($csrfCandidate)) {
        http_response_code(419);
        echo json_encode(['success' => false, 'error' => 'Sessão de segurança expirada. Atualize a página e tente novamente.']);
        exit;
    }
}

if ($method === 'GET') {
    $allowedCatIds = $permService->getAllowedCategoryIds($userId);
    if (empty($allowedCatIds)) {
        echo json_encode(['success' => true, 'data' => []]);
        exit;
    }

    $inSql = implode(',', array_map('intval', $allowedCatIds));
    $stmt = $pdo->query("SELECT id, name, slug, description, active, created_at FROM categories WHERE active = TRUE AND id IN ($inSql) ORDER BY name ASC");
    $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['success' => true, 'data' => $categories]);
    exit;
}

if ($method === 'POST') {
    if (!$permService->canCreateCategory($userId)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Acesso negado. Apenas o Administrador Global pode criar categorias no nível raiz.']);
        exit;
    }

    $name = trim($_POST['name'] ?? $_POST['nome'] ?? '');
    $description = trim($_POST['description'] ?? $_POST['descricao'] ?? '');

    if (empty($name)) {
        echo json_encode(['success' => false, 'error' => 'O nome da categoria é obrigatório.']);
        exit;
    }

    $slug = slugify($name);

    try {
        $stmt = $pdo->prepare("INSERT INTO categories (name, slug, description, active) VALUES (:name, :slug, :description, TRUE) RETURNING id");
        $stmt->execute([':name' => $name, ':slug' => $slug, ':description' => $description]);
        $newId = $stmt->fetchColumn();

        echo json_encode(['success' => true, 'id' => (int)$newId, 'name' => $name, 'slug' => $slug]);
    } catch (PDOException $e) {
        if ($e->getCode() == '23505') {
            echo json_encode(['success' => false, 'error' => 'Já existe uma categoria cadastrada com este nome/slug.']);
        } else {
            echo json_encode(['success' => false, 'error' => 'Erro ao salvar categoria no banco de dados.']);
        }
    }
    exit;
}

http_response_code(405);
header('Allow: GET, POST');
echo json_encode(['success' => false, 'error' => 'Método HTTP não suportado.']);
