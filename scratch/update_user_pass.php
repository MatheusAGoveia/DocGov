<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../services/ActiveDirectoryAuthService.php';

$user = 'matheus.damiao';
$pass = '2603Betim#3';
$hash = password_hash($pass, PASSWORD_DEFAULT);

$stmt = $pdo->prepare("UPDATE users SET password_hash = ? WHERE LOWER(username) = LOWER(?)");
$stmt->execute([$hash, $user]);
echo "Senha de emergência atualizada para '{$pass}' no usuário '{$user}'. Linhas: " . $stmt->rowCount() . "\n";

// Testar login com ActiveDirectoryAuthService
$ad = new ActiveDirectoryAuthService($pdo);
$res = $ad->authenticate('BETIM\\matheus.damiao', $pass);
echo "Resultado do authenticate com {$pass}:\n";
print_r($res);
