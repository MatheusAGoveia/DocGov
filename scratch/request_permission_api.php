<?php
// Adaptador CLI usado exclusivamente pelos testes de integração dos endpoints.
if ($argc < 6) {
    fwrite(STDERR, "Uso inválido\n");
    exit(2);
}

$endpoint = $argv[1];
$method = strtoupper($argv[2]);
$userId = (int)$argv[3];
$role = $argv[4];
$params = json_decode(base64_decode($argv[5], true) ?: '', true);
if (!is_array($params)) {
    $params = [];
}

session_start();
$_SESSION['user'] = ['id' => $userId, 'role' => $role, 'active' => true];
$_SESSION['csrf_token'] = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
$_SERVER['REQUEST_METHOD'] = $method;
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_GET = $method === 'GET' || $method === 'DELETE' ? $params : [];
$_POST = $method === 'POST' ? $params : [];

register_shutdown_function(static function (): void {
    fwrite(STDERR, 'HTTP_STATUS:' . (http_response_code() ?: 200));
});

$allowedEndpoints = [
    'permissions' => __DIR__ . '/../api/permissions.php',
    'search' => __DIR__ . '/../api/search_principals.php',
    'documents' => __DIR__ . '/../api/documents.php',
];
if (!isset($allowedEndpoints[$endpoint])) {
    http_response_code(404);
    exit;
}

require $allowedEndpoints[$endpoint];
