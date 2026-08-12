<?php
// Adaptador CLI para testes das ações POST do painel administrativo.
if ($argc < 4) {
    fwrite(STDERR, "Uso inválido\n");
    exit(2);
}

$userId = (int)$argv[1];
$role = (string)$argv[2];
$params = json_decode(base64_decode($argv[3], true) ?: '', true);
if (!is_array($params)) {
    $params = [];
}

require __DIR__ . '/../config/db.php';
$stmt = $pdo->prepare('SELECT id, name, username, email, active FROM users WHERE id = ?');
$stmt->execute([$userId]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$user) {
    fwrite(STDERR, "Usuário inexistente\n");
    exit(2);
}

session_start();
$_SESSION['user'] = [
    'id' => (int)$user['id'],
    'nome' => $user['name'],
    'login' => $user['username'],
    'email' => $user['email'],
    'role' => $role,
    'active' => (bool)$user['active'],
];
$_SESSION['admin_logged'] = true;
$_SESSION['csrf_token'] = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_GET = [
    'tab' => (string)($params['_tab'] ?? 'grupos'),
    'id' => (int)($params['group_id'] ?? 0),
    'group_tab' => (string)($params['_group_tab'] ?? 'users'),
];
unset($params['_tab'], $params['_group_tab']);
$_POST = $params;

register_shutdown_function(static function (): void {
    fwrite(STDERR, 'HTTP_STATUS:' . (http_response_code() ?: 200));
});

require __DIR__ . '/../admin/index.php';
