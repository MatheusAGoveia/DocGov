<?php
// api/documents.php - Endpoint JSON para Cadastro e Filtros de Documentos (PostgreSQL com PermissionService)
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

    $subjectId = (int)($_GET['subject_id'] ?? $_GET['assunto_id'] ?? 0);
    $status = trim($_GET['status'] ?? 'published');

    $subInSql = implode(',', array_map('intval', $allowedSubjectIds));

    if ($subjectId > 0) {
        if (!in_array($subjectId, $allowedSubjectIds)) {
            echo json_encode(['success' => true, 'data' => []]);
            exit;
        }

        $stmt = $pdo->prepare("
            SELECT d.id, d.title, d.slug, d.description, d.content_type, d.status, d.published_at,
                   d.original_filename, d.file_size, d.external_url, d.created_at,
                   s.name AS subject_name, sc.name AS subcategory_name, c.name AS category_name,
                   u.name AS author_name
            FROM documents d
            JOIN subjects s ON d.subject_id = s.id
            JOIN subcategories sc ON s.subcategory_id = sc.id
            JOIN categories c ON sc.category_id = c.id
            LEFT JOIN users u ON d.created_by = u.id
            WHERE d.subject_id = :subj_id AND (:status = 'all' OR d.status = :status)
            ORDER BY d.title ASC
        ");
        $stmt->execute([':subj_id' => $subjectId, ':status' => $status]);
        $documents = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $stmt = $pdo->prepare("
            SELECT d.id, d.title, d.slug, d.description, d.content_type, d.status, d.published_at,
                   s.name AS subject_name, sc.name AS subcategory_name, c.name AS category_name,
                   u.name AS author_name
            FROM documents d
            JOIN subjects s ON d.subject_id = s.id
            JOIN subcategories sc ON s.subcategory_id = sc.id
            JOIN categories c ON sc.category_id = c.id
            LEFT JOIN users u ON d.created_by = u.id
            WHERE d.subject_id IN ($subInSql) AND (:status = 'all' OR d.status = :status)
            ORDER BY d.created_at DESC LIMIT 50
        ");
        $stmt->execute([':status' => $status]);
        $documents = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    echo json_encode(['success' => true, 'data' => $documents]);
    exit;
}

if ($method === 'POST') {
    $subjectId = (int)($_POST['subject_id'] ?? $_POST['assunto_id'] ?? 0);
    if ($subjectId <= 0 || !$permService->canEditSubject($userId, $subjectId)) {
        echo json_encode(['success' => false, 'error' => 'Acesso negado. Você não possui permissão de edição neste assunto.']);
        exit;
    }

    $title = trim($_POST['title'] ?? $_POST['titulo'] ?? '');
    $description = trim($_POST['description'] ?? $_POST['descricao'] ?? '');
    $contentType = trim($_POST['content_type'] ?? $_POST['tipo_conteudo'] ?? 'file');
    $status = trim($_POST['status'] ?? 'published');
    $textContent = trim($_POST['text_content'] ?? $_POST['conteudo_html'] ?? '');
    $externalUrl = trim($_POST['external_url'] ?? $_POST['link_externo'] ?? '');

    if (!in_array($contentType, ['file', 'text', 'link'])) {
        $contentType = 'file';
    }
    if (!in_array($status, ['draft', 'published', 'inactive'])) {
        $status = 'published';
    }

    if (empty($title)) {
        echo json_encode(['success' => false, 'error' => 'O título do documento é obrigatório.']);
        exit;
    }
    if ($contentType === 'link' && (empty($externalUrl) || !filter_var($externalUrl, FILTER_VALIDATE_URL))) {
        echo json_encode(['success' => false, 'error' => 'Informe uma URL externa válida para o tipo Link.']);
        exit;
    }

    $slug = slugify($title);
    $originalFilename = null;
    $storedFilename = null;
    $filePath = null;
    $mimeType = null;
    $fileExtension = null;
    $fileSize = 0;

    if ($contentType === 'file' && isset($_FILES['file']) && $_FILES['file']['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES['file'];
        $originalFilename = basename($file['name']);
        $fileSize = (int)$file['size'];
        $fileExtension = strtolower(pathinfo($originalFilename, PATHINFO_EXTENSION));
        $storedFilename = md5(uniqid(microtime(), true)) . '.' . $fileExtension;

        $targetDir = __DIR__ . '/../storage/documents';
        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0755, true);
        }
        $targetPath = $targetDir . '/' . $storedFilename;

        if (move_uploaded_file($file['tmp_name'], $targetPath)) {
            $filePath = 'storage/documents/' . $storedFilename;
            $mimeType = mime_content_type($targetPath) ?: 'application/octet-stream';
        } else {
            echo json_encode(['success' => false, 'error' => 'Erro ao salvar o arquivo no armazenamento do servidor.']);
            exit;
        }
    }

    try {
        $stmt = $pdo->prepare("
            INSERT INTO documents (
                subject_id, created_by, title, slug, description, content_type, status, published_at,
                original_filename, stored_filename, file_path, mime_type, file_extension, file_size,
                text_content, external_url, active
            ) VALUES (
                :subject_id, :created_by, :title, :slug, :description, :content_type, :status, CURRENT_TIMESTAMP,
                :original_filename, :stored_filename, :file_path, :mime_type, :file_extension, :file_size,
                :text_content, :external_url, TRUE
            ) RETURNING id
        ");

        $stmt->execute([
            ':subject_id' => $subjectId,
            ':created_by' => $userId > 0 ? $userId : null,
            ':title' => $title,
            ':slug' => $slug,
            ':description' => $description,
            ':content_type' => $contentType,
            ':status' => $status,
            ':original_filename' => $originalFilename,
            ':stored_filename' => $storedFilename,
            ':file_path' => $filePath,
            ':mime_type' => $mimeType,
            ':file_extension' => $fileExtension,
            ':file_size' => $fileSize,
            ':text_content' => $textContent,
            ':external_url' => $externalUrl
        ]);

        $newId = (int)$stmt->fetchColumn();

        echo json_encode(['success' => true, 'id' => $newId, 'title' => $title, 'slug' => $slug]);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'error' => 'Erro ao cadastrar documento: ' . $e->getMessage()]);
    }
    exit;
}

echo json_encode(['success' => false, 'error' => 'Método HTTP não suportado.']);
