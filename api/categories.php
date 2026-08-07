<?php
// api/categories.php - Endpoint JSON para Categorias (PostgreSQL)
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../config/db.php';
header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $stmt = $pdo->query("SELECT id, name, slug, description, active, created_at FROM categories WHERE active = TRUE ORDER BY name ASC");
    $categories = $stmt->fetchAll();
    echo json_encode(['success' => true, 'data' => $categories]);
    exit;
}

if ($method === 'POST') {
    $loggedUser = $_SESSION['user'] ?? null;
    if (!$loggedUser || ($loggedUser['role'] !== 'admin' && $loggedUser['role'] !== 'editor')) {
        echo json_encode(['success' => false, 'error' => 'Acesso negado. Apenas administradores e editores podem criar categorias.']);
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

echo json_encode(['success' => false, 'error' => 'Método HTTP não suportado.']);
