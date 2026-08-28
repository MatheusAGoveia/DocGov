<?php
require_once __DIR__ . '/../config/db.php';

echo "=== ÚLTIMOS 10 LOGS DE AUTENTICAÇÃO AD (ad_auth_logs) ===\n\n";
$stmt = $pdo->query("SELECT created_at, domain_key, username, server_name, server_uri, status, status_message, latency_ms, user_ip FROM ad_auth_logs ORDER BY created_at DESC LIMIT 10");
$logs = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
foreach ($logs as $l) {
    echo "[{$l['created_at']}] Usuário: {$l['domain_key']}\\{$l['username']} | Status: {$l['status']} | Msg: {$l['status_message']} | Latência: {$l['latency_ms']}ms\n";
}

echo "\n=== USUÁRIOS NO BANCO LOCAL (users) ===\n\n";
$stmtUsers = $pdo->query("SELECT id, name, username, email, role, active, auth_source, password_hash IS NOT NULL as has_password FROM users ORDER BY id ASC");
$users = $stmtUsers ? $stmtUsers->fetchAll(PDO::FETCH_ASSOC) : [];
foreach ($users as $u) {
    echo "ID: {$u['id']} | Username: {$u['username']} | Email: {$u['email']} | Role: {$u['role']} | Active: {$u['active']} | AuthSource: {$u['auth_source']} | HasPassword: " . ($u['has_password'] ? 'SIM' : 'NÃO') . "\n";
}
