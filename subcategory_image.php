<?php

require_once __DIR__ . '/config/session.php';
docgovStartSession();

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/services/AccessService.php';
require_once __DIR__ . '/services/CategoryImageService.php';

$userId = (int)($_SESSION['user']['id'] ?? 0);
$subcategoryId = (int)($_GET['id'] ?? 0);

if ($userId <= 0 || $subcategoryId <= 0) {
    http_response_code(403);
    exit;
}

$accessService = new AccessService($pdo);
if (!$accessService->canAccessSubcategory($userId, $subcategoryId)) {
    http_response_code(403);
    exit;
}

$stmt = $pdo->prepare('SELECT image_path FROM subcategories WHERE id = :id');
$stmt->execute([':id' => $subcategoryId]);
$imagePath = $stmt->fetchColumn();

$imageService = new CategoryImageService(__DIR__);
$filePath = $imageService->resolve(is_string($imagePath) ? $imagePath : null);
if ($filePath === null) {
    http_response_code(404);
    exit;
}

$mime = mime_content_type($filePath) ?: 'application/octet-stream';
header('Content-Type: ' . $mime);
header('Content-Length: ' . (string)filesize($filePath));
header('Cache-Control: private, max-age=3600');
header('X-Content-Type-Options: nosniff');
readfile($filePath);
