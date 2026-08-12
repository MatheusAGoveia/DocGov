<?php

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/services/CategoryImageService.php';

$logoPath = trim((string)($appSettings['system_logo_path'] ?? ''));
$imageService = new CategoryImageService(__DIR__);
$filePath = $imageService->resolve($logoPath);

if ($filePath === null) {
    http_response_code(404);
    exit;
}

$mime = mime_content_type($filePath) ?: 'application/octet-stream';
header('Content-Type: ' . $mime);
header('Content-Length: ' . (string)filesize($filePath));
header('Cache-Control: public, max-age=3600');
header('X-Content-Type-Options: nosniff');
readfile($filePath);
