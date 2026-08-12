<?php
// Adaptador CLI para testes de isolamento das páginas administrativas.
if ($argc < 4) {
    fwrite(STDERR, "Uso inválido\n");
    exit(2);
}

$userId = (int)$argv[1];
$role = $argv[2];
$params = json_decode(base64_decode($argv[3], true) ?: '', true);
if (!is_array($params)) {
    $params = [];
}

require __DIR__ . '/../config/db.php';
$stmtUser = $pdo->prepare('SELECT id, name, username, email, role, active FROM users WHERE id = ?');
$stmtUser->execute([$userId]);
$user = $stmtUser->fetch(PDO::FETCH_ASSOC);
if (!$user) {
    fwrite(STDERR, "Usuário de teste inexistente\n");
    exit(2);
}

session_start();
$_SESSION['user'] = [
    'id' => (int)$user['id'],
    'nome' => $user['name'],
    'login' => $user['username'],
    'email' => $user['email'],
    'role' => $role ?: $user['role'],
    'active' => (bool)$user['active'],
    'inicial' => mb_strtoupper(mb_substr($user['name'], 0, 1)),
];
$_SESSION['admin_logged'] = true;
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_GET = $params;
$_POST = [];

register_shutdown_function(static function (): void {
    fwrite(STDERR, 'HTTP_STATUS:' . (http_response_code() ?: 200));
});

require __DIR__ . '/../admin/index.php';
