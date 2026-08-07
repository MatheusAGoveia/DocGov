<?php
// api/subjects.php - Endpoint JSON para Assuntos por Subcategoria (PostgreSQL)
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../config/db.php';
header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $subcategoryId = (int)($_GET['subcategory_id'] ?? $_GET['subcategoria_id'] ?? 0);
    
    if ($subcategoryId <= 0) {
        $subSlug = trim($_GET['subcategory_slug'] ?? $_GET['subcat'] ?? '');
        if (!empty($subSlug)) {
            $stmtSub = $pdo->prepare("SELECT id FROM subcategories WHERE (slug = :s OR id::text = :s) AND active = TRUE");
            $stmtSub->execute([':s' => $subSlug]);
            $subcategoryId = (int)$stmtSub->fetchColumn();
        }
    }

    if ($subcategoryId <= 0) {
        $stmt = $pdo->query("SELECT id, subcategory_id, name, slug, description FROM subjects WHERE active = TRUE ORDER BY name ASC");
        echo json_encode(['success' => true, 'data' => $stmt->fetchAll()]);
        exit;
    }

    $stmt = $pdo->prepare("SELECT id, subcategory_id, name, slug, description FROM subjects WHERE subcategory_id = :sub_id AND active = TRUE ORDER BY name ASC");
    $stmt->execute([':sub_id' => $subcategoryId]);
    $subjects = $stmt->fetchAll();

    echo json_encode(['success' => true, 'data' => $subjects]);
    exit;
}

if ($method === 'POST') {
    $loggedUser = $_SESSION['user'] ?? null;
    if (!$loggedUser || ($loggedUser['role'] !== 'admin' && $loggedUser['role'] !== 'editor')) {
        echo json_encode(['success' => false, 'error' => 'Acesso negado. Apenas administradores e editores podem criar assuntos.']);
        exit;
    }

    $subcategoryId = (int)($_POST['subcategory_id'] ?? $_POST['subcategoria_id'] ?? 0);
    $name = trim($_POST['name'] ?? $_POST['nome'] ?? '');
    $description = trim($_POST['description'] ?? $_POST['descricao'] ?? '');

    if ($subcategoryId <= 0) {
        echo json_encode(['success' => false, 'error' => 'Selecione uma subcategoria válida.']);
        exit;
    }
    if (empty($name)) {
        echo json_encode(['success' => false, 'error' => 'O nome do assunto é obrigatório.']);
        exit;
    }

    $slug = slugify($name);

    try {
        $stmt = $pdo->prepare("INSERT INTO subjects (subcategory_id, name, slug, description, active) VALUES (:sub_id, :name, :slug, :description, TRUE) RETURNING id");
        $stmt->execute([':sub_id' => $subcategoryId, ':name' => $name, ':slug' => $slug, ':description' => $description]);
        $newId = $stmt->fetchColumn();

        echo json_encode(['success' => true, 'id' => (int)$newId, 'subcategory_id' => $subcategoryId, 'name' => $name, 'slug' => $slug]);
    } catch (PDOException $e) {
        if ($e->getCode() == '23505') {
            echo json_encode(['success' => false, 'error' => 'Já existe um assunto com este nome nesta subcategoria.']);
        } else {
            echo json_encode(['success' => false, 'error' => 'Erro ao salvar assunto no banco de dados.']);
        }
    }
    exit;
}

echo json_encode(['success' => false, 'error' => 'Método HTTP não suportado.']);
