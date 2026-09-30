<?php
// api/subjects.php - Endpoint JSON para Assuntos (PostgreSQL com PermissionService)
require_once __DIR__ . '/../config/session.php';
docgovStartSession();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../services/PermissionService.php';
require_once __DIR__ . '/../services/CsrfService.php';
require_once __DIR__ . '/../services/UsageAuditService.php';

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
    $allowedSubjectIds = $permService->getAllowedSubjectIds($userId);
    if (empty($allowedSubjectIds)) {
        echo json_encode(['success' => true, 'data' => []]);
        exit;
    }

    $subcategoryId = (int)($_GET['subcategory_id'] ?? $_GET['subcategoria_id'] ?? 0);
    if ($subcategoryId <= 0) {
        $subSlug = trim($_GET['subcategory_slug'] ?? $_GET['subcat'] ?? '');
        if (!empty($subSlug)) {
            $stmtSub = $pdo->prepare("SELECT sc.id FROM subcategories sc JOIN categories c ON c.id = sc.category_id WHERE (sc.slug = :s OR sc.id::text = :s) AND sc.active = TRUE AND c.active = TRUE");
            $stmtSub->execute([':s' => $subSlug]);
            $subcategoryId = (int)$stmtSub->fetchColumn();
        }
    }

    $inSql = implode(',', array_map('intval', $allowedSubjectIds));

    if ($subcategoryId <= 0) {
        $stmt = $pdo->query("SELECT s.id, s.subcategory_id, s.name, s.slug, s.description, s.visibility FROM subjects s JOIN subcategories sc ON sc.id = s.subcategory_id JOIN categories c ON c.id = sc.category_id WHERE s.active = TRUE AND sc.active = TRUE AND c.active = TRUE AND s.id IN ($inSql) ORDER BY s.name ASC");
        echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        exit;
    }

    $stmt = $pdo->prepare("SELECT s.id, s.subcategory_id, s.name, s.slug, s.description, s.visibility FROM subjects s JOIN subcategories sc ON sc.id = s.subcategory_id JOIN categories c ON c.id = sc.category_id WHERE s.subcategory_id = :sub_id AND s.active = TRUE AND sc.active = TRUE AND c.active = TRUE AND s.id IN ($inSql) ORDER BY s.name ASC");
    $stmt->execute([':sub_id' => $subcategoryId]);
    $subjects = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'data' => $subjects]);
    exit;
}

if ($method === 'POST') {
    $subcategoryId = (int)($_POST['subcategory_id'] ?? $_POST['subcategoria_id'] ?? 0);
    if ($subcategoryId <= 0 || !$permService->canCreateSubject($userId, $subcategoryId)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Acesso negado. Você não possui permissão para criar assuntos nesta subcategoria.']);
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
        $visibility = PermissionService::normalizeSubjectVisibility($_POST['visibility'] ?? 'private');
        if ($visibility === 'public' && !$permService->canAdminSubcategory($userId, $subcategoryId)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Somente administradores da subcategoria podem criar assuntos públicos.']);
            exit;
        }
        $stmt = $pdo->prepare("INSERT INTO subjects (subcategory_id, name, slug, description, active, visibility) VALUES (:sub_id, :name, :slug, :description, TRUE, :visibility) RETURNING id");
        $stmt->execute([':sub_id' => $subcategoryId, ':name' => $name, ':slug' => $slug, ':description' => $description, ':visibility' => $visibility]);
        $newId = $stmt->fetchColumn();
        (new UsageAuditService($pdo))->logAdminAction($userId, 'subject_created_' . $visibility, 'SUBJECT', (int)$newId);

        echo json_encode(['success' => true, 'id' => (int)$newId, 'subcategory_id' => $subcategoryId, 'name' => $name, 'slug' => $slug, 'visibility' => $visibility]);
    } catch (InvalidArgumentException $e) {
        http_response_code(422);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    } catch (PDOException $e) {
        if ($e->getCode() == '23505') {
            echo json_encode(['success' => false, 'error' => 'Já existe um assunto com este nome nesta subcategoria.']);
        } else {
            echo json_encode(['success' => false, 'error' => 'Erro ao salvar assunto no banco de dados.']);
        }
    }
    exit;
}

http_response_code(405);
header('Allow: GET, POST');
echo json_encode(['success' => false, 'error' => 'Método HTTP não suportado.']);
