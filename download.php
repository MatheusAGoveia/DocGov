<?php
// download.php - Gateway Seguro de Streaming e Download de Arquivos (PostgreSQL)
require_once __DIR__ . '/config/session.php';
docgovStartSession();
require_once __DIR__ . '/config/db.php';

$loggedUser = $_SESSION['user'] ?? null;
$docId = (int)($_GET['id'] ?? 0);
$inline = isset($_GET['inline']) && ($_GET['inline'] == '1' || $_GET['inline'] == 'true');

if ($docId <= 0) {
    http_response_code(404);
    die("Documento não encontrado.");
}

// Busca o documento na tabela `documents` do PostgreSQL
$stmt = $pdo->prepare("
    SELECT d.*, s.name AS subject_name, sc.name AS subcategory_name, c.name AS category_name
    FROM documents d
    JOIN subjects s ON d.subject_id = s.id
    JOIN subcategories sc ON s.subcategory_id = sc.id
    JOIN categories c ON sc.category_id = c.id
    WHERE d.id = :id
");
$stmt->execute([':id' => $docId]);
$doc = $stmt->fetch();

if (!$doc) {
    http_response_code(404);
    die("Documento não encontrado.");
}

require_once __DIR__ . '/services/AccessService.php';
$accessService = new AccessService($pdo);
$userId = $loggedUser ? (int)$loggedUser['id'] : 0;

if (!$accessService->canAccessDocument($userId, $docId)) {
    http_response_code(403);
    die("Acesso negado. Sua conta não possui permissão para acessar este arquivo.");
}

require_once __DIR__ . '/services/UsageAuditService.php';
$usageAuditService = new UsageAuditService($pdo);
$usageEventType = !empty($doc['external_url']) && in_array($doc['content_type'], ['link', 'video'], true) && empty($doc['stored_filename'])
    ? 'external_open'
    : ($inline ? 'document_file_view' : 'document_download');
$usageAuditService->log($usageEventType, $userId, 'DOCUMENT', $docId, [
    'content_type' => (string)($doc['content_type'] ?? 'file'),
    'inline' => $inline,
]);

// 1. CONTEÚDO DO TIPO LINK
if (in_array($doc['content_type'], ['link', 'video'], true) && empty($doc['stored_filename'])) {
    $externalUrl = trim((string)($doc['external_url'] ?? ''));
    $externalScheme = strtolower((string)parse_url($externalUrl, PHP_URL_SCHEME));
    if ($externalUrl !== '' && filter_var($externalUrl, FILTER_VALIDATE_URL) && in_array($externalScheme, ['http', 'https'], true)) {
        header('Location: ' . $externalUrl);
        exit;
    } else {
        die("Link externo não configurado.");
    }
}

// 2. CONTEÚDO DO TIPO ARQUIVO (FILE)
if (in_array($doc['content_type'], ['file', 'video'], true)) {
    $filename = basename((string)($doc['stored_filename'] ?: ($doc['file_path'] ? basename($doc['file_path']) : '')));
    $filePath = null;

    // Aceita somente um arquivo real contido em uma das duas raízes permitidas.
    foreach ([__DIR__ . '/storage/documents', __DIR__ . '/uploads/docs'] as $candidateRoot) {
        $root = realpath($candidateRoot);
        if ($root === false || $filename === '') continue;
        $candidate = realpath($root . DIRECTORY_SEPARATOR . $filename);
        if ($candidate !== false && str_starts_with($candidate, $root . DIRECTORY_SEPARATOR) && is_file($candidate)) {
            $filePath = $candidate;
            break;
        }
    }

    if ($filePath === null) {
        http_response_code(404);
        die("Arquivo físico não encontrado no servidor.");
    }

    $mimeType = $doc['mime_type'] ?: mime_content_type($filePath) ?: 'application/octet-stream';
    $originalName = preg_replace('/[^a-zA-Z0-9._-]/', '_', basename((string)($doc['original_filename'] ?: basename($filePath))));

    header('Content-Type: ' . $mimeType);
    header('Content-Length: ' . filesize($filePath));
    header('X-Content-Type-Options: nosniff');
    if ($inline) {
        header("Content-Security-Policy: sandbox; default-src 'none'; style-src 'unsafe-inline'; media-src 'self'; img-src 'self' data:");
    }

    if ($inline) {
        header('Content-Disposition: inline; filename="' . rawurlencode($originalName) . '"');
    } else {
        header('Content-Disposition: attachment; filename="' . rawurlencode($originalName) . '"');
    }

    if (ob_get_level()) {
        ob_end_clean();
    }
    readfile($filePath);
    exit;
}

// 3. CONTEÚDO DO TIPO TEXTO (TEXT)
if ($doc['content_type'] === 'text') {
    $downloadName = slugify($doc['title']) . '.txt';
    $plainText = strip_tags($doc['text_content'] ?: $doc['description']);

    header('Content-Type: text/plain; charset=utf-8');
    if ($inline) {
        header('Content-Disposition: inline; filename="' . $downloadName . '"');
    } else {
        header('Content-Disposition: attachment; filename="' . $downloadName . '"');
    }
    echo $plainText;
    exit;
}

http_response_code(400);
die("Tipo de conteúdo não suportado para visualização/download.");
