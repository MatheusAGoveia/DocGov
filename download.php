<?php
// download.php - Gateway Seguro de Streaming e Download de Arquivos (PostgreSQL)
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
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

// 1. CONTEÚDO DO TIPO LINK
if ($doc['content_type'] === 'link') {
    if (!empty($doc['external_url'])) {
        header('Location: ' . $doc['external_url']);
        exit;
    } else {
        die("Link externo não configurado.");
    }
}

// 2. CONTEÚDO DO TIPO ARQUIVO (FILE)
if ($doc['content_type'] === 'file') {
    $filename = $doc['stored_filename'] ?: ($doc['file_path'] ? basename($doc['file_path']) : '');
    
    // Tenta primeiro no storage/documents/ depois em uploads/docs/
    $filePath = __DIR__ . '/storage/documents/' . $filename;
    if (!file_exists($filePath)) {
        $filePath = __DIR__ . '/uploads/docs/' . $filename;
    }

    if (!file_exists($filePath) || is_dir($filePath)) {
        http_response_code(404);
        die("Arquivo físico não encontrado no servidor.");
    }

    $mimeType = $doc['mime_type'] ?: mime_content_type($filePath) ?: 'application/octet-stream';
    $originalName = $doc['original_filename'] ?: basename($filePath);

    header('Content-Type: ' . $mimeType);
    header('Content-Length: ' . filesize($filePath));

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
