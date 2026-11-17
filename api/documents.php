<?php
// api/documents.php - Endpoint JSON para Cadastro e Filtros de Documentos (PostgreSQL com PermissionService)
require_once __DIR__ . '/../services/UploadRequestService.php';
if (($uploadRequestError = UploadRequestService::requestError()) !== null) {
    http_response_code(413);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'error' => $uploadRequestError], JSON_UNESCAPED_UNICODE);
    exit;
}
require_once __DIR__ . '/../config/session.php';
docgovStartSession();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../services/PermissionService.php';
require_once __DIR__ . '/../services/VideoEmbedService.php';
require_once __DIR__ . '/../services/DocumentWorkflowService.php';
require_once __DIR__ . '/../services/DocumentSectionService.php';
require_once __DIR__ . '/../services/DocumentSlugService.php';
require_once __DIR__ . '/../services/RichTextSanitizer.php';
require_once __DIR__ . '/../services/StructuredContentService.php';
require_once __DIR__ . '/../services/CsrfService.php';

if (!headers_sent()) {
    header('Content-Type: application/json');
    header('Cache-Control: no-store, no-cache, must-revalidate, private');
    header('X-Content-Type-Options: nosniff');
}

$loggedUser = $_SESSION['user'] ?? null;
$userId = $loggedUser ? (int)$loggedUser['id'] : 0;
$permService = new PermissionService($pdo);
$workflowService = new DocumentWorkflowService($pdo, $permService);
$documentSectionService = new DocumentSectionService($pdo);
$documentSlugService = new DocumentSlugService($pdo);

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
    if (($uploadFileError = UploadRequestService::filesError($_FILES)) !== null) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => $uploadFileError], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

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
            SELECT d.id, d.title, d.slug, d.description, d.content_type, d.section_key, d.structured_content,
                   d.code_language, d.status, d.published_at, d.updated_at AS document_version,
                   d.original_filename, d.file_size, d.external_url, d.created_at,
                   ds.label AS section_label, ds.editor_kind AS section_editor_kind,
                   s.name AS subject_name, sc.name AS subcategory_name, c.name AS category_name,
                   u.name AS author_name
            FROM documents d
            JOIN document_sections ds ON ds.section_key = d.section_key
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
            SELECT d.id, d.title, d.slug, d.description, d.content_type, d.section_key, d.structured_content,
                   d.code_language, d.status, d.published_at, d.updated_at AS document_version,
                   ds.label AS section_label, ds.editor_kind AS section_editor_kind,
                   s.name AS subject_name, sc.name AS subcategory_name, c.name AS category_name,
                   u.name AS author_name
            FROM documents d
            JOIN document_sections ds ON ds.section_key = d.section_key
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
    require_once __DIR__ . '/../services/DocumentWriteService.php';
    try {
        $result = (new DocumentWriteService($pdo))->save($userId, $_POST, $_FILES);
        echo json_encode(['success' => true] + $result, JSON_UNESCAPED_UNICODE);
    } catch (Throwable $exception) {
        error_log('DocGov documents API: erro ao salvar documento: ' . $exception->getMessage());
        $errorStatus = in_array((int)$exception->getCode(), [403, 404, 409, 422], true) ? (int)$exception->getCode() : 500;
        http_response_code($errorStatus);
        echo json_encode(['success' => false, 'error' => $errorStatus === 500 ? 'Falha ao salvar documento.' : $exception->getMessage()]);
    }
    exit;
}

echo json_encode(['success' => false, 'error' => 'Método HTTP não suportado.']);
