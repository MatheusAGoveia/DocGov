<?php
// api/tree.php - Endpoint JSON para a árvore hierárquica filtrada por permissões (DocGov)
require_once __DIR__ . '/../config/session.php';
docgovStartSession();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../services/PermissionService.php';

if (!headers_sent()) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, private');
    header('X-Content-Type-Options: nosniff');
}

$loggedUser = $_SESSION['user'] ?? null;
$userId = $loggedUser ? (int)$loggedUser['id'] : 0;

if ($userId <= 0) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Sessão expirada ou usuário não autenticado.']);
    exit;
}

$permService = new PermissionService($pdo);

try {
    $tree = $permService->getAccessibleResourceTree($userId);
    echo json_encode(['success' => true, 'data' => $tree]);
} catch (Exception $e) {
    error_log('DocGov tree API: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Não foi possível carregar a árvore agora.']);
}
