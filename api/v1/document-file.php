<?php
// api/v1/document-file.php?id=<id> — download do arquivo de um documento publicado (GET).
declare(strict_types=1);
define('DOCGOV_API_V1_ENDPOINT', true);
require __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../../services/DocumentFileLocator.php';

$documentId = docgovApiIntParam('id', 0, 0, DOCGOV_API_MAX_ID);
if ($documentId <= 0) {
    docgovApiError(422, 'invalid_parameter', 'Informe o parâmetro id do documento.');
}

$allowedSubjectIds = docgovApiSubjectIds($apiPermissions->getAccessibleResourceTree($apiViewerId));
$stmt = $pdo->prepare("
    SELECT d.id, d.content_type, d.stored_filename, d.file_path, d.original_filename, d.mime_type
    FROM documents d
    JOIN subjects s ON s.id = d.subject_id
    JOIN subcategories sc ON sc.id = s.subcategory_id
    JOIN categories c ON c.id = sc.category_id
    WHERE d.id = :id AND d.status = 'published' AND d.trashed_at IS NULL
      AND s.active = TRUE AND sc.active = TRUE AND c.active = TRUE
      AND d.subject_id = ANY(CAST(:allowed_subjects AS int[]))
");
$stmt->execute([':id' => $documentId, ':allowed_subjects' => docgovApiIntArray($allowedSubjectIds)]);
$document = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$document || !$apiPermissions->canViewDocument($apiViewerId, $documentId)) {
    docgovApiError(404, 'not_found', 'Documento não encontrado ou fora do escopo desta chave.');
}
if (!in_array($document['content_type'], ['file', 'video'], true) || trim((string)$document['stored_filename']) === '') {
    docgovApiError(404, 'no_file', 'Este documento não possui arquivo anexo; consulte o conteúdo em documents.php?id=' . $documentId . '.');
}

$filePath = DocumentFileLocator::resolve(dirname(__DIR__, 2), $document);
if ($filePath === null || !is_readable($filePath)) {
    docgovApiError(404, 'file_missing', 'O arquivo físico do documento não está disponível.');
}

$apiUsageAudit->log('document_download', $apiViewerId > 0 ? $apiViewerId : null, 'DOCUMENT', $documentId, [
    'channel' => 'api',
    'api_client_id' => $apiClient['id'],
]);

$mimeType = (string)($document['mime_type'] ?? '');
if (!preg_match('#^[a-z0-9][a-z0-9.+-]*/[a-z0-9][a-z0-9.+-]*$#i', $mimeType)) {
    $mimeType = 'application/octet-stream';
}
$originalName = (string)($document['original_filename'] ?: basename($filePath));
$asciiName = preg_replace('/[^A-Za-z0-9._-]/', '_', basename($originalName)) ?: 'documento';

while (ob_get_level()) {
    ob_end_clean();
}
header('Content-Type: ' . $mimeType);
header('Content-Length: ' . (string)filesize($filePath));
header('Content-Disposition: attachment; filename="' . $asciiName . '"; filename*=UTF-8\'\'' . rawurlencode(basename($originalName)));
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: sandbox; default-src 'none'");
header('X-DocGov-API-Version: ' . DOCGOV_API_VERSION);
if ($requestMethod !== 'HEAD') {
    readfile($filePath);
}
exit;
