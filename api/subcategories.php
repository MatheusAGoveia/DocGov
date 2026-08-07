<?php
// api/subcategories.php - Endpoint JSON para Subcategorias por Categoria (PostgreSQL)
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../config/db.php';
header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $categoryId = (int)($_GET['category_id'] ?? $_GET['categoria_id'] ?? 0);
    
    if ($categoryId <= 0) {
        // Se passar slug da categoria
        $catSlug = trim($_GET['category_slug'] ?? $_GET['cat'] ?? '');
        if (!empty($catSlug)) {
            $stmtCat = $pdo->prepare("SELECT id FROM categories WHERE (slug = :s OR id::text = :s) AND active = TRUE");
            $stmtCat->execute([':s' => $catSlug]);
            $categoryId = (int)$stmtCat->fetchColumn();
        }
    }

    if ($categoryId <= 0) {
        $stmt = $pdo->query("SELECT id, category_id, name, slug, description FROM subcategories WHERE active = TRUE ORDER BY name ASC");
        echo json_encode(['success' => true, 'data' => $stmt->fetchAll()]);
        exit;
    }

    $stmt = $pdo->prepare("SELECT id, category_id, name, slug, description FROM subcategories WHERE category_id = :cat_id AND active = TRUE ORDER BY name ASC");
    $stmt->execute([':cat_id' => $categoryId]);
    $subcategories = $stmt->fetchAll();

    echo json_encode(['success' => true, 'data' => $subcategories]);
    exit;
}

if ($method === 'POST') {
    $loggedUser = $_SESSION['user'] ?? null;
    if (!$loggedUser || ($loggedUser['role'] !== 'admin' && $loggedUser['role'] !== 'editor')) {
        echo json_encode(['success' => false, 'error' => 'Acesso negado. Apenas administradores e editores podem criar subcategorias.']);
        exit;
    }

    $categoryId = (int)($_POST['category_id'] ?? $_POST['categoria_id'] ?? 0);
    $name = trim($_POST['name'] ?? $_POST['nome'] ?? '');
    $description = trim($_POST['description'] ?? $_POST['descricao'] ?? '');

    if ($categoryId <= 0) {
        echo json_encode(['success' => false, 'error' => 'Selecione uma categoria válida.']);
        exit;
    }
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
