<?php
require_once __DIR__ . '/_cli_only.php';
if (PHP_SAPI !== 'cli' || !str_starts_with((string)getenv('DB_NAME'), 'docgov_nesting_test_')) exit(2);
define('DOCGOV_SKIP_APP_RUNTIME', true);
require __DIR__ . '/../config/db.php';
require __DIR__ . '/../config/session.php';
if (($argv[1] ?? '') === 'cleanup') {
    session_id((string)$argv[2]); docgovStartSession(); session_destroy(); exit;
}
$user = $pdo->query("SELECT id, name, username, email, role FROM users WHERE username = 'nest.admin'")->fetch();
$ids = [];
foreach (['Browser Financeiro', 'Browser Contas a Pagar'] as $name) {
    $stmt = $pdo->prepare('INSERT INTO groups (name) VALUES (?) RETURNING id'); $stmt->execute([$name]); $ids[] = (int)$stmt->fetchColumn();
}
$member = (int)$pdo->query("SELECT id FROM users WHERE username = 'nest.leaf'")->fetchColumn();
$pdo->prepare('INSERT INTO user_groups (user_id, group_id) VALUES (?, ?)')->execute([$member, $ids[1]]);
$category = (int)$pdo->query('SELECT id FROM categories ORDER BY id LIMIT 1')->fetchColumn();
$pdo->prepare("INSERT INTO permissions (group_id, category_id, permission_level) VALUES (?, ?, 'view')")->execute([$ids[0], $category]);
$sessionId = bin2hex(random_bytes(16)); session_id($sessionId); docgovStartSession();
$sessionId = session_id();
$_SESSION['user'] = ['id' => (int)$user['id'], 'nome' => $user['name'], 'login' => $user['username'], 'email' => $user['email'], 'role' => $user['role'], 'active' => true, 'inicial' => 'A'];
$_SESSION['admin_logged'] = true; session_write_close();
echo json_encode(['session' => $sessionId, 'parent' => $ids[0], 'child' => $ids[1], 'member' => $member, 'category' => $category]);
