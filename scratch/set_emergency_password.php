<?php
// scratch/set_emergency_password.php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../services/ActiveDirectoryAuthService.php';

$username = 'matheus.damiao';
$emergencyPassword = 'Admin#2026@Betim';
$hash = password_hash($emergencyPassword, PASSWORD_DEFAULT);

$stmt = $pdo->prepare("UPDATE users SET password_hash = ? WHERE LOWER(username) = LOWER(?)");
$stmt->execute([$hash, $username]);
$affected = $stmt->rowCount();

echo "=== DEFININDO SENHA DE EMERGÊNCIA (BREAK-GLASS) ===\n\n";
echo "Usuário: {$username}\n";
echo "Senha de Emergência Configurada: {$emergencyPassword}\n";
echo "Registros atualizados no banco: {$affected}\n\n";

// Testar se o Break-Glass autentica com sucesso no ActiveDirectoryAuthService
$adAuthService = new ActiveDirectoryAuthService($pdo);
$res = $adAuthService->tryBreakGlassEmergencyLogin($username, $emergencyPassword);

if ($res !== null) {
    echo "[OK] SUCCESS: Autenticação de Emergência Break-Glass VALIDADA com sucesso para {$username}!\n";
    echo "  -> ID: {$res['id']} | Nome: {$res['name']} | Role: {$res['role']}\n";
} else {
    echo "[FAIL] ERROR: Falha ao validar login de emergência.\n";
}
