<?php
// api/documents.php - Endpoint JSON para Cadastro e Filtros de Documentos (PostgreSQL)
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../config/db.php';
header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $subjectId = (int)($_GET['subject_id'] ?? $_GET['assunto_id'] ?? 0);
    $status = trim($_GET['status'] ?? 'published');

    if ($subjectId > 0) {
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
        $documents = $stmt->fetchAll();
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
            WHERE (:status = 'all' OR d.status = :status)
            ORDER BY d.created_at DESC LIMIT 50
        ");
        $stmt->execute([':status' => $status]);
        $documents = $stmt->fetchAll();
    }

    echo json_encode(['success' => true, 'data' => $documents]);
    exit;
}

if ($method === 'POST') {
    $loggedUser = $_SESSION['user'] ?? null;
    if (!$loggedUser || ($loggedUser['role'] !== 'admin' && $loggedUser['role'] !== 'editor')) {
        echo json_encode(['success' => false, 'error' => 'Acesso negado. Apenas administradores e editores podem criar documentos.']);
        exit;
    }

    $subjectId = (int)($_POST['subject_id'] ?? $_POST['assunto_id'] ?? 0);
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

    if ($subjectId <= 0) {
        echo json_encode(['success' => false, 'error' => 'Selecione um assunto válido para o documento.']);
        exit;
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

    // Processar Upload de Arquivo
    if ($contentType === 'file' && isset($_FILES['file']) && $_FILES['file']['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES['file'];
        $originalFilename = basename($file['name']);
        $fileSize = (int)$file['size'];
        $fileExtension = strtolower(pathinfo($originalFilename, PATHINFO_EXTENSION));

        $allowedExts = ['pdf', 'png', 'jpg', 'jpeg', 'webp', 'gif', 'txt', 'doc', 'docx'];
        if (!in_array($fileExtension, $allowedExts)) {
            echo json_encode(['success' => false, 'error' => 'Extensão de arquivo não permitida. Use PDF, PNG, JPG, WEBP, TXT ou DOCX.']);
            exit;
        }
        if ($fileSize > 25 * 1024 * 1024) {
            echo json_encode(['success' => false, 'error' => 'O arquivo excede o limite máximo de 25MB.']);
            exit;
        }

        // Armazenamento Físico com UUID/Uniqid
        $storedFilename = sprintf('%s_%s.%s', uniqid('doc_'), md5($originalFilename . microtime()), $fileExtension);
        $targetDir = __DIR__ . '/../storage/documents';
        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0755, true);
        }
        $targetPath = $targetDir . '/' . $storedFilename;

        if (move_uploaded_file($file['tmp_name'], $targetPath)) {
            $filePath = 'storage/documents/' . $storedFilename;
            $mimeType = mime_content_type($targetPath) ?: 'application/octet-stream';
        } else {
            echo json_encode(['success' => false, 'error' => 'Falha ao gravar arquivo no sistema de arquivos local.']);
            exit;
        }
    }

    try {
        $pdo->beginTransaction();

        $sql = "
            INSERT INTO documents (
                subject_id, created_by, title, slug, description, content_type, status, published_at,
                original_filename, stored_filename, file_path, mime_type, file_extension, file_size,
                text_content, external_url
            ) VALUES (
                :subject_id, :created_by, :title, :slug, :description, :content_type, :status, 
                " . ($status === 'published' ? 'CURRENT_TIMESTAMP' : 'NULL') . ",
                :original_filename, :stored_filename, :file_path, :mime_type, :file_extension, :file_size,
                :text_content, :external_url
            ) RETURNING id;
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':subject_id' => $subjectId,
            ':created_by' => (int)$loggedUser['id'],
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

        $newId = $stmt->fetchColumn();
        $pdo->commit();

        echo json_encode([
            'success' => true,
            'id' => (int)$newId,
            'title' => $title,
            'slug' => $slug,
            'subject_id' => $subjectId,
            'content_type' => $contentType,
            'status' => $status
        ]);
    } catch (Exception $e) {
        $pdo->rollBack();
        if (!empty($filePath) && file_exists(__DIR__ . '/../' . $filePath)) {
            @unlink(__DIR__ . '/../' . $filePath);
        }
        echo json_encode(['success' => false, 'error' => 'Erro ao registrar documento no PostgreSQL: ' . $e->getMessage()]);
    }
    exit;
}

echo json_encode(['success' => false, 'error' => 'Método HTTP não suportado.']);
