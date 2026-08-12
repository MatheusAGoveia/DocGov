<?php
// api/tree.php - Endpoint JSON para a árvore hierárquica filtrada por permissões (DocGov)
require_once __DIR__ . '/../config/session.php';
docgovStartSession();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../services/PermissionService.php';

if (!headers_sent()) {
    header('Content-Type: application/json');
}

$loggedUser = $_SESSION['user'] ?? null;
$userId = $loggedUser ? (int)$loggedUser['id'] : 0;

$permService = new PermissionService($pdo);

try {
    $tree = $permService->getAccessibleResourceTree($userId);
    echo json_encode(['success' => true, 'data' => $tree]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Erro ao carregar árvore: ' . $e->getMessage()]);
}
