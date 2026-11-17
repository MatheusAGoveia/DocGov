<?php
/**
 * api/v1/documents.php — consulta e salvamento autorizado de publicações.
 *
 * Lista:   documents.php?subject_id=&subcategory_id=&category_id=&q=&content_type=&tag=&updated_since=&page=&per_page=
 * Detalhe: documents.php?id=<id> (inclui o conteúdo conforme o tipo)
 * Escrita: POST com escopo user_write e edição no assunto; rascunho/revisão.
 */
declare(strict_types=1);
define('DOCGOV_API_V1_ENDPOINT', true);
define('DOCGOV_API_ALLOWED_METHODS', ['GET', 'HEAD', 'POST']);
require __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../../services/TagService.php';
require_once __DIR__ . '/../../services/RichTextSanitizer.php';

if ($requestMethod === 'POST') {
    if ($apiClient['access_scope'] !== ApiClientService::SCOPE_USER_WRITE) {
        docgovApiError(403, 'forbidden', 'Esta chave permite somente leitura.');
    }
    require_once __DIR__ . '/../../services/UploadRequestService.php';
    require_once __DIR__ . '/../../services/DocumentWriteService.php';
    if (($uploadError = UploadRequestService::requestError() ?? UploadRequestService::filesError($_FILES)) !== null) {
        docgovApiError(413, 'invalid_parameter', $uploadError);
    }
    $mediaType = strtolower(trim(explode(';', (string)($_SERVER['CONTENT_TYPE'] ?? ''))[0]));
    if (!in_array($mediaType, ['application/json', 'application/x-www-form-urlencoded', 'multipart/form-data'], true)) {
        docgovApiError(415, 'unsupported_media_type', 'Use JSON, formulário ou multipart/form-data.');
    }
    try {
        $input = $_POST;
        if ($mediaType === 'application/json') {
            $rawBody = file_get_contents('php://input', false, null, 0, 8388609);
            if ($rawBody === false || strlen($rawBody) > 8388608) {
                docgovApiError(413, 'invalid_parameter', 'O JSON excede o limite de 8 MB.');
            }
            $input = json_decode($rawBody, true, 64, JSON_THROW_ON_ERROR);
            if (!is_array($input) || !str_starts_with(ltrim($rawBody), '{')) {
                throw new InvalidArgumentException('Envie um objeto JSON.');
            }
        }
        $allowedFields = ['id', 'subject_id', 'title', 'description', 'content_type', 'section_key', 'text_content',
            'code_language', 'structured_content', 'external_url', 'video_source', 'video_url', 'workflow_action',
            'workflow_note', 'document_version'];
        if (array_diff(array_keys($input), $allowedFields) !== []) {
            throw new InvalidArgumentException('A requisição contém campos não suportados.');
        }
        foreach ($input as $field => $value) {
            if (!in_array($field, ['id', 'subject_id', 'structured_content'], true) && $value !== null && !is_string($value)) {
                throw new InvalidArgumentException('O campo ' . $field . ' deve ser texto.');
            }
        }
        foreach (['id', 'subject_id'] as $idField) {
            if (isset($input[$idField]) && ((!is_int($input[$idField]) && !is_string($input[$idField]))
                || !preg_match('/^\d{1,10}$/', (string)$input[$idField]) || (int)$input[$idField] > DOCGOV_API_MAX_ID)) {
                throw new InvalidArgumentException('ID inválido: ' . $idField);
            }
        }
        $isCreate = (int)($input['id'] ?? 0) === 0;
        if ($isCreate && (int)($input['subject_id'] ?? 0) <= 0) {
            throw new InvalidArgumentException('Informe subject_id para criar a publicação.');
        }
        if (!$isCreate && (!is_string($input['document_version'] ?? null) || trim($input['document_version']) === '')) {
            throw new InvalidArgumentException('Envie document_version para editar o documento.');
        }
        $workflowAction = $input['workflow_action'] ?? 'save_draft';
        if (!in_array($workflowAction, ['save_draft', 'submit_review'], true)) {
            throw new InvalidArgumentException('A integração pode salvar rascunhos ou enviar para revisão.');
        }
        $input['workflow_action'] = $workflowAction;
        $saved = (new DocumentWriteService($pdo))->save($apiViewerId, $input, $_FILES);
        $apiUsageAudit->log('admin_action', $apiViewerId, 'DOCUMENT', $saved['id'], [
            'action' => $isCreate ? 'api_document_created' : 'api_document_updated',
            'channel' => 'api', 'api_client_id' => $apiClient['id'],
        ]);
        docgovApiSend($isCreate ? 201 : 200, ['success' => true, 'data' => $saved]);
    } catch (Throwable $exception) {
        $errorStatus = in_array((int)$exception->getCode(), [403, 404, 409, 422], true) ? (int)$exception->getCode()
            : (($exception instanceof InvalidArgumentException || $exception instanceof JsonException) ? 422 : 500);
        if ($errorStatus === 500) error_log('DocGov API v1 escrita: ' . $exception->getMessage());
        $errorCode = [403 => 'forbidden', 404 => 'not_found', 409 => 'conflict', 422 => 'invalid_parameter'][$errorStatus] ?? 'internal_error';
        docgovApiError($errorStatus, $errorCode, $errorStatus === 500 ? 'Não foi possível salvar o documento.' : $exception->getMessage());
    }
}

const DOCGOV_API_CONTENT_TYPES = ['file', 'text', 'link', 'code', 'video', 'flow', 'orgchart'];
const DOCGOV_API_QUERY_MAX = 120;

$tagService = new TagService($pdo);
$allowedSubjectIds = docgovApiSubjectIds($apiPermissions->getAccessibleResourceTree($apiViewerId));
$documentId = docgovApiIntParam('id', 0, 0, DOCGOV_API_MAX_ID);
$statusFilter = $documentId > 0 ? 'all' : (docgovApiStringParam('status', 16) ?: 'published');
if (!in_array($statusFilter, ['published', 'draft', 'review', 'inactive', 'all'], true)) {
    docgovApiError(422, 'invalid_parameter', 'Status inválido.');
}
$readUnpublished = $apiClient['access_scope'] === ApiClientService::SCOPE_USER_WRITE && $statusFilter !== 'published';
if ($documentId === 0 && $statusFilter !== 'published' && !$readUnpublished) {
    docgovApiError(403, 'forbidden', 'Esta chave consulta somente documentos publicados.');
}
$baseParams = [':allowed_subjects' => docgovApiIntArray($allowedSubjectIds)];
$statusCondition = "d.status = 'published'";
if ($readUnpublished) {
    $editableSubjectIds = array_values(array_filter($allowedSubjectIds, static fn(int $id): bool => $apiPermissions->canCreateDocument($apiViewerId, $id)));
    $statusCondition = "(d.status = 'published' OR d.subject_id = ANY(CAST(:editable_subjects AS int[])))";
    $baseParams[':editable_subjects'] = docgovApiIntArray($editableSubjectIds);
    if ($statusFilter !== 'all') {
        $statusCondition .= ' AND d.status = :document_status';
        $baseParams[':document_status'] = $statusFilter;
    }
}

$baseSelect = "
    SELECT d.id, d.title, d.slug, d.description, d.content_type, d.section_key, ds.label AS section_label,
           d.published_at, d.updated_at, d.status, d.original_filename, d.stored_filename, d.mime_type,
           d.file_extension, d.file_size, d.external_url,
           s.id AS subject_id, s.name AS subject_name, s.slug AS subject_slug, s.visibility AS subject_visibility,
           sc.id AS subcategory_id, sc.name AS subcategory_name, sc.slug AS subcategory_slug,
           c.id AS category_id, c.name AS category_name, c.slug AS category_slug
";
$baseFrom = "
    FROM documents d
    JOIN document_sections ds ON ds.section_key = d.section_key
    JOIN subjects s ON s.id = d.subject_id
    JOIN subcategories sc ON sc.id = s.subcategory_id
    JOIN categories c ON c.id = sc.category_id
    WHERE {$statusCondition} AND d.trashed_at IS NULL
      AND s.active = TRUE AND sc.active = TRUE AND c.active = TRUE
      AND d.subject_id = ANY(CAST(:allowed_subjects AS int[]))
";

function docgovApiHasStoredFile(array $row): bool
{
    return in_array($row['content_type'], ['file', 'video'], true) && trim((string)($row['stored_filename'] ?? '')) !== '';
}

function docgovApiFormatDocument(array $row, array $tags): array
{
    $id = (int)$row['id'];
    $hasFile = docgovApiHasStoredFile($row);
    return [
        'id' => $id,
        'title' => (string)$row['title'],
        'slug' => (string)$row['slug'],
        'description' => (string)($row['description'] ?? ''),
        'content_type' => (string)$row['content_type'],
        'status' => (string)$row['status'],
        'document_version' => (string)$row['updated_at'],
        'section' => ['key' => (string)$row['section_key'], 'label' => (string)$row['section_label']],
        'category' => ['id' => (int)$row['category_id'], 'name' => (string)$row['category_name'], 'slug' => (string)$row['category_slug']],
        'subcategory' => ['id' => (int)$row['subcategory_id'], 'name' => (string)$row['subcategory_name'], 'slug' => (string)$row['subcategory_slug']],
        'subject' => [
            'id' => (int)$row['subject_id'],
            'name' => (string)$row['subject_name'],
            'slug' => (string)$row['subject_slug'],
            'visibility' => (string)$row['subject_visibility'],
        ],
        'tags' => array_values(array_map(static fn(array $tag): string => (string)$tag['name'], $tags)),
        'published_at' => docgovApiIsoDate($row['published_at'] ?? null),
        'updated_at' => docgovApiIsoDate($row['updated_at'] ?? null),
        'file' => $hasFile ? [
            'name' => (string)($row['original_filename'] ?: $row['stored_filename']),
            'mime_type' => (string)($row['mime_type'] ?? ''),
            'extension' => (string)($row['file_extension'] ?? ''),
            'size_bytes' => $row['file_size'] !== null ? (int)$row['file_size'] : null,
        ] : null,
        'external_url' => !$hasFile && in_array($row['content_type'], ['link', 'video'], true) && !empty($row['external_url'])
            ? (string)$row['external_url']
            : null,
        'links' => [
            'self' => 'documents.php?id=' . $id,
            'file' => $hasFile ? 'document-file.php?id=' . $id : null,
        ],
    ];
}

function docgovApiDocumentContent(array $row): array
{
    return match (true) {
        docgovApiHasStoredFile($row) => ['format' => 'file'],
        $row['content_type'] === 'text' => ['format' => 'html', 'html' => RichTextSanitizer::sanitize((string)($row['text_content'] ?? ''))],
        $row['content_type'] === 'code' => ['format' => 'code', 'language' => (string)$row['code_language'], 'source' => (string)($row['text_content'] ?? '')],
        in_array($row['content_type'], ['flow', 'orgchart'], true) => [
            'format' => 'structured',
            'data' => json_decode((string)($row['structured_content'] ?? 'null'), true),
        ],
        in_array($row['content_type'], ['link', 'video'], true) => ['format' => 'url', 'url' => (string)($row['external_url'] ?? '')],
        default => ['format' => 'none'],
    };
}

// ---------------------------------------------------------------------------
// Detalhe
// ---------------------------------------------------------------------------
if ($documentId > 0) {
    $stmt = $pdo->prepare($baseSelect . ', d.text_content, d.code_language, d.structured_content' . $baseFrom . ' AND d.id = :id');
    $stmt->execute($baseParams + [':id' => $documentId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    // Defesa em profundidade: a consulta já restringe o escopo; o motor confirma.
    if (!$row || !$apiPermissions->canViewDocument($apiViewerId, $documentId)) {
        docgovApiError(404, 'not_found', 'Documento não encontrado ou fora do escopo desta chave.');
    }
    $apiUsageAudit->log('document_view', $apiViewerId > 0 ? $apiViewerId : null, 'DOCUMENT', $documentId, [
        'channel' => 'api',
        'api_client_id' => $apiClient['id'],
    ]);
    $tags = $tagService->mapDocumentTags([$documentId])[$documentId] ?? [];
    docgovApiOk(docgovApiFormatDocument($row, $tags) + ['content' => docgovApiDocumentContent($row)]);
}

// ---------------------------------------------------------------------------
// Lista paginada
// ---------------------------------------------------------------------------
$page = docgovApiIntParam('page', 1, 1, 100000);
$perPage = docgovApiIntParam('per_page', DOCGOV_API_DEFAULT_PER_PAGE, 1, DOCGOV_API_MAX_PER_PAGE);
$subjectFilter = docgovApiIntParam('subject_id', 0, 0, DOCGOV_API_MAX_ID);
$subcategoryFilter = docgovApiIntParam('subcategory_id', 0, 0, DOCGOV_API_MAX_ID);
$categoryFilter = docgovApiIntParam('category_id', 0, 0, DOCGOV_API_MAX_ID);
$searchText = docgovApiStringParam('q', DOCGOV_API_QUERY_MAX);
$contentType = docgovApiStringParam('content_type', 20);
$tagFilter = docgovApiStringParam('tag', 80);
$updatedSinceRaw = docgovApiStringParam('updated_since', 40);

if ($subjectFilter > 0 && !in_array($subjectFilter, $allowedSubjectIds, true)) {
    docgovApiError(404, 'not_found', 'Assunto não encontrado ou fora do escopo desta chave.');
}
if ($contentType !== '' && !in_array($contentType, DOCGOV_API_CONTENT_TYPES, true)) {
    docgovApiError(422, 'invalid_parameter', 'content_type deve ser um de: ' . implode(', ', DOCGOV_API_CONTENT_TYPES) . '.');
}

$where = '';
$params = $baseParams;
if ($subjectFilter > 0) {
    $where .= ' AND s.id = :subject_id';
    $params[':subject_id'] = $subjectFilter;
}
if ($subcategoryFilter > 0) {
    $where .= ' AND sc.id = :subcategory_id';
    $params[':subcategory_id'] = $subcategoryFilter;
}
if ($categoryFilter > 0) {
    $where .= ' AND c.id = :category_id';
    $params[':category_id'] = $categoryFilter;
}
if ($searchText !== '') {
    $where .= " AND (d.title ILIKE :q OR COALESCE(d.description, '') ILIKE :q)";
    $params[':q'] = '%' . addcslashes($searchText, '\\%_') . '%';
}
if ($contentType !== '') {
    $where .= ' AND d.content_type = :content_type';
    $params[':content_type'] = $contentType;
}
if ($tagFilter !== '') {
    $tag = $tagService->resolveName($tagFilter);
    if ($tag === null) {
        docgovApiOk([], ['page' => $page, 'per_page' => $perPage, 'total' => 0, 'total_pages' => 0]);
    }
    $where .= ' AND EXISTS (SELECT 1 FROM document_tags dt WHERE dt.document_id = d.id AND dt.tag_id = :tag_id)';
    $params[':tag_id'] = (int)$tag['id'];
}
if ($updatedSinceRaw !== '') {
    $updatedSince = docgovApiIsoDate($updatedSinceRaw);
    if ($updatedSince === null) {
        docgovApiError(422, 'invalid_parameter', 'updated_since deve ser uma data ISO 8601, por exemplo 2026-11-01T00:00:00-03:00.');
    }
    $where .= ' AND d.updated_at >= CAST(:updated_since AS timestamptz)';
    $params[':updated_since'] = $updatedSince;
}

$countStmt = $pdo->prepare('SELECT COUNT(*)' . $baseFrom . $where);
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();

$listStmt = $pdo->prepare($baseSelect . $baseFrom . $where . ' ORDER BY d.updated_at DESC, d.id DESC LIMIT :limit OFFSET :offset');
foreach ($params as $name => $value) {
    $listStmt->bindValue($name, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
}
$listStmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$listStmt->bindValue(':offset', ($page - 1) * $perPage, PDO::PARAM_INT);
$listStmt->execute();
$rows = $listStmt->fetchAll(PDO::FETCH_ASSOC);

$tagsByDocument = $tagService->mapDocumentTags(array_map(static fn(array $row): int => (int)$row['id'], $rows));
$items = array_map(
    static fn(array $row): array => docgovApiFormatDocument($row, $tagsByDocument[(int)$row['id']] ?? []),
    $rows
);

docgovApiOk($items, [
    'page' => $page,
    'per_page' => $perPage,
    'total' => $total,
    'total_pages' => (int)ceil($total / $perPage),
]);
