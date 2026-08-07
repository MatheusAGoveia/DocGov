<?php
// api/subcategories.php - Endpoint JSON para Subcategorias (PostgreSQL com PermissionService)
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
$permService = new PermissionService($pdo);

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $allowedSubcatIds = $permService->getAllowedSubcategoryIds($userId);
    if (empty($allowedSubcatIds)) {
        echo json_encode(['success' => true, 'data' => []]);
        exit;
    }

    $categoryId = (int)($_GET['category_id'] ?? $_GET['categoria_id'] ?? 0);
    if ($categoryId <= 0) {
        $catSlug = trim($_GET['category_slug'] ?? $_GET['cat'] ?? '');
        if (!empty($catSlug)) {
            $stmtCat = $pdo->prepare("SELECT id FROM categories WHERE (slug = :s OR id::text = :s) AND active = TRUE");
            $stmtCat->execute([':s' => $catSlug]);
            $categoryId = (int)$stmtCat->fetchColumn();
        }
    }

    $inSql = implode(',', array_map('intval', $allowedSubcatIds));

    if ($categoryId <= 0) {
        $stmt = $pdo->query("SELECT id, category_id, name, slug, description FROM subcategories WHERE active = TRUE AND id IN ($inSql) ORDER BY name ASC");
        echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        exit;
    }

    $stmt = $pdo->prepare("SELECT id, category_id, name, slug, description FROM subcategories WHERE category_id = :cat_id AND active = TRUE AND id IN ($inSql) ORDER BY name ASC");
    $stmt->execute([':cat_id' => $categoryId]);
    $subcategories = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'data' => $subcategories]);
    exit;
}

if ($method === 'POST') {
    $categoryId = (int)($_POST['category_id'] ?? $_POST['categoria_id'] ?? 0);
    if ($categoryId <= 0 || !$permService->canEditCategory($userId, $categoryId)) {
        echo json_encode(['success' => false, 'error' => 'Acesso negado. Você não possui permissão de edição nesta categoria.']);
        exit;
    }

    $name = trim($_POST['name'] ?? $_POST['nome'] ?? '');
    $description = trim($_POST['description'] ?? $_POST['descricao'] ?? '');

    if (empty($name)) {
        echo json_encode(['success' => false, 'error' => 'O nome da subcategoria é obrigatório.']);
        exit;
    }

    $slug = slugify($name);

    try {
        $stmt = $pdo->prepare("INSERT INTO subcategories (category_id, name, slug, description, active) VALUES (:cat_id, :name, :slug, :description, TRUE) RETURNING id");
        $stmt->execute([':cat_id' => $categoryId, ':name' => $name, ':slug' => $slug, ':description' => $description]);
        $newId = $stmt->fetchColumn();

        echo json_encode(['success' => true, 'id' => (int)$newId, 'category_id' => $categoryId, 'name' => $name, 'slug' => $slug]);
    } catch (PDOException $e) {
        if ($e->getCode() == '23505') {
            echo json_encode(['success' => false, 'error' => 'Já existe uma subcategoria com este nome nesta categoria.']);
        } else {
            echo json_encode(['success' => false, 'error' => 'Erro ao salvar subcategoria no banco de dados.']);
        }
    }
    exit;
}

echo json_encode(['success' => false, 'error' => 'Método HTTP não suportado.']);
