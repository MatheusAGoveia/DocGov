<?php
// api/documents.php - Endpoint JSON para Cadastro e Filtros de Documentos (PostgreSQL com PermissionService)
require_once __DIR__ . '/../config/session.php';
docgovStartSession();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../services/PermissionService.php';
require_once __DIR__ . '/../services/VideoEmbedService.php';
require_once __DIR__ . '/../services/DocumentWorkflowService.php';

if (!headers_sent()) {
    header('Content-Type: application/json');
    header('Cache-Control: no-store, no-cache, must-revalidate, private');
    header('X-Content-Type-Options: nosniff');
}

$loggedUser = $_SESSION['user'] ?? null;
$userId = $loggedUser ? (int)$loggedUser['id'] : 0;
$permService = new PermissionService($pdo);
$workflowService = new DocumentWorkflowService($pdo, $permService);

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    // Este endpoint expõe metadados administrativos (autor, rascunhos e inativos),
    // portanto usa somente o escopo EDIT/ADMIN, nunca o escopo de mera leitura.
    $administrativeScope = $permService->getAdministrativeScope($userId);
    $allowedSubjectIds = array_values(array_unique(array_map('intval', $administrativeScope['subject_ids'])));
    if (empty($allowedSubjectIds)) {
        echo json_encode(['success' => true, 'data' => []]);
        exit;
    }

    $subjectId = (int)($_GET['subject_id'] ?? $_GET['assunto_id'] ?? 0);
    $status = trim($_GET['status'] ?? 'published');
    if (!in_array($status, ['published', 'draft', 'review', 'inactive', 'all'], true)) {
        $status = 'published';
    }

    $subInSql = implode(',', array_map('intval', $allowedSubjectIds));
    $statusConditionSql = $status === 'all' ? '' : ' AND d.status = :document_status';
    $statusParams = $status === 'all' ? [] : [':document_status' => $status];

    if ($subjectId > 0) {
        if (!in_array($subjectId, $allowedSubjectIds)) {
            echo json_encode(['success' => true, 'data' => []]);
            exit;
        }

        $stmt = $pdo->prepare("
            SELECT d.id, d.title, d.slug, d.description, d.content_type, d.code_language, d.status, d.published_at,
                   d.original_filename, d.file_size, d.external_url, d.created_at,
                   s.name AS subject_name, sc.name AS subcategory_name, c.name AS category_name,
                   u.name AS author_name
            FROM documents d
            JOIN subjects s ON d.subject_id = s.id
            JOIN subcategories sc ON s.subcategory_id = sc.id
            JOIN categories c ON sc.category_id = c.id
            LEFT JOIN users u ON d.created_by = u.id
            WHERE d.subject_id = :subj_id{$statusConditionSql}
            ORDER BY d.title ASC
        ");
        $stmt->execute([':subj_id' => $subjectId] + $statusParams);
        $documents = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $stmt = $pdo->prepare("
            SELECT d.id, d.title, d.slug, d.description, d.content_type, d.code_language, d.status, d.published_at,
                   s.name AS subject_name, sc.name AS subcategory_name, c.name AS category_name,
                   u.name AS author_name
            FROM documents d
            JOIN subjects s ON d.subject_id = s.id
            JOIN subcategories sc ON s.subcategory_id = sc.id
            JOIN categories c ON sc.category_id = c.id
            LEFT JOIN users u ON d.created_by = u.id
            WHERE d.subject_id IN ($subInSql){$statusConditionSql}
            ORDER BY d.created_at DESC LIMIT 50
        ");
        $stmt->execute($statusParams);
        $documents = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    echo json_encode(['success' => true, 'data' => $documents]);
    exit;
}

if ($method === 'POST') {
    $docId = (int)($_POST['id'] ?? $_POST['document_id'] ?? 0);
    $subjectId = (int)($_POST['subject_id'] ?? $_POST['assunto_id'] ?? 0);

    if ($docId > 0) {
        if (!$permService->canEditDocument($userId, $docId)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Acesso negado. Você não possui permissão para editar este documento.']);
            exit;
        }
        if ($subjectId > 0 && !$permService->canCreateDocument($userId, $subjectId)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Acesso negado. Você não possui permissão no assunto especificado.']);
            exit;
        }
    } else {
        if ($subjectId <= 0 || !$permService->canCreateDocument($userId, $subjectId)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Acesso negado. Você não possui permissão para criar documentos neste assunto.']);
            exit;
        }
    }

    $previousStatus = null;
    if ($docId > 0) {
        $stmtPrevious = $pdo->prepare('SELECT status FROM documents WHERE id = :id');
        $stmtPrevious->execute([':id' => $docId]);
        $previousStatus = $stmtPrevious->fetchColumn();
    }

    $title = trim($_POST['title'] ?? $_POST['titulo'] ?? '');
    $description = trim($_POST['description'] ?? $_POST['descricao'] ?? '');
    $contentType = trim($_POST['content_type'] ?? $_POST['tipo_conteudo'] ?? 'file');
    $legacyStatus = trim($_POST['status'] ?? 'draft');
    $workflowAction = trim($_POST['workflow_action'] ?? '');
    if ($workflowAction === '') {
        $workflowAction = match ($legacyStatus) {
            'published', 'review' => 'submit_review',
            'inactive' => 'archive',
            default => 'save_draft',
        };
    }
    $workflowNote = trim($_POST['workflow_note'] ?? '');
    $textContent = str_replace(["\r\n", "\r"], "\n", (string)($_POST['text_content'] ?? $_POST['conteudo_html'] ?? $_POST['codigo_fonte'] ?? ''));
    $codeLanguage = strtolower(trim($_POST['code_language'] ?? $_POST['linguagem_codigo'] ?? 'auto'));
    $externalUrl = trim($_POST['external_url'] ?? $_POST['link_externo'] ?? '');
    $videoSource = trim($_POST['video_source'] ?? 'upload');
    $videoUrl = trim($_POST['video_url'] ?? '');

    if (!in_array($contentType, ['file', 'text', 'link', 'code', 'video'], true)) {
        $contentType = 'file';
    }
    if (!in_array($videoSource, ['upload', 'url'], true)) {
        $videoSource = 'upload';
    }
    if ($contentType === 'video' && $videoSource === 'url') {
        $externalUrl = $videoUrl;
    }

    if (empty($title)) {
        echo json_encode(['success' => false, 'error' => 'O título do documento é obrigatório.']);
        exit;
    }
    if ($contentType === 'link' && (empty($externalUrl) || !filter_var($externalUrl, FILTER_VALIDATE_URL))) {
        echo json_encode(['success' => false, 'error' => 'Informe uma URL externa válida para o tipo Link.']);
        exit;
    }
    $apiVideoUpload = $_FILES['video_file'] ?? $_FILES['file'] ?? null;
    if ($contentType === 'video' && $videoSource === 'url' && VideoEmbedService::normalizeExternalUrl($externalUrl) === null) {
        echo json_encode(['success' => false, 'error' => 'Informe uma URL HTTP ou HTTPS válida para o vídeo.']);
        exit;
    }
    if ($contentType === 'video' && $videoSource === 'upload' && $docId <= 0 && (!is_array($apiVideoUpload) || ($apiVideoUpload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK)) {
        echo json_encode(['success' => false, 'error' => 'Selecione um arquivo de vídeo para publicar.']);
        exit;
    }
    if ($contentType === 'code' && trim($textContent) === '') {
        echo json_encode(['success' => false, 'error' => 'Informe o trecho de código.']);
        exit;
    }
    if ($contentType === 'code' && strlen($textContent) > 1048576) {
        echo json_encode(['success' => false, 'error' => 'O trecho de código excede o limite de 1 MB.']);
        exit;
    }

    $allowedCodeLanguages = [
        'auto', 'plaintext', 'javascript', 'typescript', 'xml', 'css', 'php', 'python',
        'sql', 'bash', 'json', 'java', 'csharp', 'cpp', 'go', 'yaml', 'markdown'
    ];
    if (!in_array($codeLanguage, $allowedCodeLanguages, true)) {
        $codeLanguage = 'auto';
    }
    if ($contentType === 'text') {
        $textContent = strip_tags($textContent, '<h3><h4><p><b><i><strong><em><ul><ol><li><a><br>');
    }

    try {
        $workflowTransition = $workflowService->prepareAction($workflowAction, $previousStatus === false ? null : $previousStatus, $userId, $docId ?: null, $workflowNote);
        $status = $workflowTransition['status'];
    } catch (Throwable $exception) {
        http_response_code(422);
        echo json_encode(['success' => false, 'error' => $exception->getMessage()]);
        exit;
    }

    $slug = slugify($title);
    $publishedAt = $status === 'published' ? date(DATE_ATOM) : null;
    $originalFilename = null;
    $storedFilename = null;
    $filePath = null;
    $mimeType = null;
    $fileExtension = null;
    $fileSize = 0;

    $apiUpload = $contentType === 'video' ? $apiVideoUpload : ($_FILES['file'] ?? null);
    if (in_array($contentType, ['file', 'video'], true) && is_array($apiUpload) && ($apiUpload['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
        $file = $apiUpload;
        $originalFilename = basename($file['name']);
        $fileSize = (int)$file['size'];
        $fileExtension = strtolower(pathinfo($originalFilename, PATHINFO_EXTENSION));
        $allowedFileExtensions = [
            'pdf', 'png', 'jpg', 'jpeg', 'webp', 'gif', 'bmp', 'avif',
            'txt', 'log', 'csv', 'md', 'json', 'xml', 'doc', 'docx',
            'mp3', 'wav', 'ogg', 'mp4', 'webm', 'ogv', 'm4v', 'mov'
        ];
        if (!in_array($fileExtension, $allowedFileExtensions, true)) {
            echo json_encode(['success' => false, 'error' => 'Formato de arquivo não suportado.']);
            exit;
        }
        if ($fileSize > ($contentType === 'video' ? 250 : 25) * 1024 * 1024) {
            echo json_encode(['success' => false, 'error' => $contentType === 'video' ? 'O vídeo excede o limite máximo de 250 MB.' : 'O arquivo excede o limite máximo de 25 MB.']);
            exit;
        }
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
        $params = [
            ':subject_id' => $subjectId,
            ':created_by' => $userId > 0 ? $userId : null,
            ':title' => $title,
            ':slug' => $slug,
            ':description' => $description,
            ':content_type' => $contentType,
            ':status' => $status,
            ':published_at' => $publishedAt,
            ':is_published' => $status === 'published' ? 1 : 0,
            ':original_filename' => $originalFilename,
            ':stored_filename' => $storedFilename,
            ':file_path' => $filePath,
            ':mime_type' => $mimeType,
            ':file_extension' => $fileExtension,
            ':file_size' => $fileSize,
            ':text_content' => $textContent,
            ':code_language' => $codeLanguage,
            ':external_url' => $externalUrl
        ];

        if ($docId > 0) {
            if ($subjectId <= 0) {
                $stmtSubject = $pdo->prepare('SELECT subject_id FROM documents WHERE id = :id');
                $stmtSubject->execute([':id' => $docId]);
                $subjectId = (int)$stmtSubject->fetchColumn();
                $params[':subject_id'] = $subjectId;
            }

            $fileAssignments = '';
            if ($storedFilename !== null) {
                $fileAssignments = ', original_filename = :original_filename, stored_filename = :stored_filename,
                    file_path = :file_path, mime_type = :mime_type, file_extension = :file_extension, file_size = :file_size';
            } else {
                $clearLocalVideo = $contentType === 'video' && $videoSource === 'url';
                unset(
                    $params[':original_filename'], $params[':stored_filename'], $params[':file_path'],
                    $params[':mime_type'], $params[':file_extension'], $params[':file_size']
                );
                $fileAssignments = $clearLocalVideo
                    ? ', original_filename = NULL, stored_filename = NULL, file_path = NULL, mime_type = NULL, file_extension = NULL, file_size = NULL'
                    : '';
            }
            unset($params[':created_by']);
            $params[':id'] = $docId;

            $stmt = $pdo->prepare("
                UPDATE documents SET
                    subject_id = :subject_id, title = :title, slug = :slug, description = :description,
                    content_type = :content_type, status = :status,
                    published_at = CASE WHEN CAST(:is_published AS BOOLEAN) THEN COALESCE(:published_at, published_at, CURRENT_TIMESTAMP) ELSE NULL END,
                    approval_expires_at = CASE WHEN CAST(:is_published AS BOOLEAN) THEN NULL ELSE COALESCE(approval_expires_at, CURRENT_TIMESTAMP + INTERVAL '1 month') END,
                    text_content = :text_content, code_language = :code_language, external_url = :external_url
                    $fileAssignments
                WHERE id = :id
                RETURNING id
            ");
        } else {
            unset($params[':is_published']);
            $stmt = $pdo->prepare("
                INSERT INTO documents (
                    subject_id, created_by, title, slug, description, content_type, status, published_at,
                    original_filename, stored_filename, file_path, mime_type, file_extension, file_size,
                    text_content, code_language, external_url
                ) VALUES (
                    :subject_id, :created_by, :title, :slug, :description, :content_type, :status, :published_at,
                    :original_filename, :stored_filename, :file_path, :mime_type, :file_extension, :file_size,
                    :text_content, :code_language, :external_url
                ) RETURNING id
            ");
        }

        $stmt->execute($params);
        $newId = (int)$stmt->fetchColumn();
        try {
            $workflowService->applyTransitionMetadata($newId, $userId, $workflowTransition['action'], $workflowTransition['note']);
            $workflowService->record($newId, $userId, $workflowTransition['action'], $previousStatus ?: 'draft', $status, $workflowTransition['note']);
            $workflowService->notifyForTransition($newId, $userId, $workflowTransition['action']);
        } catch (Throwable $exception) {
            error_log('DocGov documents API workflow: ' . $exception->getMessage());
        }

        echo json_encode(['success' => true, 'id' => $newId, 'title' => $title, 'slug' => $slug, 'status' => $status]);
    } catch (PDOException $e) {
        error_log('DocGov documents API: erro ao cadastrar documento: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Não foi possível salvar o documento.']);
    }
    exit;
}

echo json_encode(['success' => false, 'error' => 'Método HTTP não suportado.']);
