<?php
declare(strict_types=1);
require_once __DIR__ . '/_cli_only.php';
$options = getopt('', ['username:']);
$username = trim((string)($options['username'] ?? ''));
if ($username === '') {
    fwrite(STDERR, "Informe exatamente a conta desejada com --username.\n");
    exit(1);
}
define('DOCGOV_SKIP_APP_RUNTIME', true);
require __DIR__ . '/../config/db.php';
$stmt = $pdo->prepare("UPDATE users SET role = 'admin', active = TRUE WHERE LOWER(username) = LOWER(:username)");
$stmt->execute([':username' => $username]);
echo 'Contas atualizadas: ' . $stmt->rowCount() . PHP_EOL;
