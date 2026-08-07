<?php
// api/subjects.php - Endpoint JSON para Assuntos (PostgreSQL com PermissionService)
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
    $allowedSubjectIds = $permService->getAllowedSubjectIds($userId);
    if (empty($allowedSubjectIds)) {
        echo json_encode(['success' => true, 'data' => []]);
        exit;
    }

    $subcategoryId = (int)($_GET['subcategory_id'] ?? $_GET['subcategoria_id'] ?? 0);
    if ($subcategoryId <= 0) {
        $subSlug = trim($_GET['subcategory_slug'] ?? $_GET['subcat'] ?? '');
        if (!empty($subSlug)) {
            $stmtSub = $pdo->prepare("SELECT id FROM subcategories WHERE (slug = :s OR id::text = :s) AND active = TRUE");
            $stmtSub->execute([':s' => $subSlug]);
            $subcategoryId = (int)$stmtSub->fetchColumn();
        }
    }

    $inSql = implode(',', array_map('intval', $allowedSubjectIds));

    if ($subcategoryId <= 0) {
        $stmt = $pdo->query("SELECT id, subcategory_id, name, slug, description FROM subjects WHERE active = TRUE AND id IN ($inSql) ORDER BY name ASC");
        echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        exit;
    }

    $stmt = $pdo->prepare("SELECT id, subcategory_id, name, slug, description FROM subjects WHERE subcategory_id = :sub_id AND active = TRUE AND id IN ($inSql) ORDER BY name ASC");
    $stmt->execute([':sub_id' => $subcategoryId]);
    $subjects = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'data' => $subjects]);
    exit;
}

if ($method === 'POST') {
    $subcategoryId = (int)($_POST['subcategory_id'] ?? $_POST['subcategoria_id'] ?? 0);
    if ($subcategoryId <= 0 || !$permService->canEditSubcategory($userId, $subcategoryId)) {
        echo json_encode(['success' => false, 'error' => 'Acesso negado. Você não possui permissão de edição nesta subcategoria.']);
        exit;
    }

    $name = trim($_POST['name'] ?? $_POST['nome'] ?? '');
    $description = trim($_POST['description'] ?? $_POST['descricao'] ?? '');

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
