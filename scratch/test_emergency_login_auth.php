<?php
require_once __DIR__ . '/_cli_only.php';
// scratch/test_emergency_login_auth.php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../services/ActiveDirectoryAuthService.php';

$adAuthService = new ActiveDirectoryAuthService($pdo);

echo "=== TESTANDO LOGIN COM A SENHA DE EMERGÊNCIA VIA AUTHENTICATE() ===\n\n";

$res = $adAuthService->authenticate('BETIM\\matheus.damiao', 'Admin#2026@Betim');

echo "Resultado do Login: " . json_encode($res, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
