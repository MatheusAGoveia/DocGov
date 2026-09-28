<?php

declare(strict_types=1);

require_once __DIR__ . '/config/session.php';
docgovStartSession();
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/services/PermissionService.php';
require_once __DIR__ . '/services/DocumentInlineMediaService.php';

$id = (int)($_GET['id'] ?? 0);
$userId = (int)($_SESSION['user']['id'] ?? 0);
if ($id <= 0 || $userId <= 0) {
    http_response_code($userId <= 0 ? 401 : 404);
    exit;
}

try {
    $stmt = $pdo->prepare('SELECT document_id, uploaded_by, stored_filename, mime_type, file_size FROM document_inline_media WHERE id = ?');
    $stmt->execute([$id]);
    $media = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$media) {
        http_response_code(404);
        exit;
    }
    $documentId = (int)($media['document_id'] ?? 0);
    $permissions = new PermissionService($pdo);
    $allowed = $documentId > 0
        ? $permissions->canViewDocument($userId, $documentId)
        : (int)($media['uploaded_by'] ?? 0) === $userId;
    if (!$allowed) {
        http_response_code(403);
        exit;
    }

    $service = new DocumentInlineMediaService($pdo, __DIR__);
    $storage = realpath($service->storageDirectory());
    $storedName = (string)$media['stored_filename'];
    $path = $storage && preg_match('/^[a-f0-9]{40}\.(?:jpg|png|gif|webp)$/', $storedName)
        ? realpath($storage . DIRECTORY_SEPARATOR . $storedName)
        : false;
    if (!$path || !str_starts_with($path, $storage . DIRECTORY_SEPARATOR) || !is_readable($path)) {
        http_response_code(404);
        exit;
    }
    header('Content-Type: ' . $media['mime_type']);
    header('Content-Length: ' . filesize($path));
    header('Content-Disposition: inline');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store');
    readfile($path);
} catch (Throwable $exception) {
    error_log('DocGov: falha ao carregar imagem de documento: ' . $exception->getMessage());
    http_response_code(500);
}
