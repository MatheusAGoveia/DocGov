<?php
// scratch/test_user_details_json.php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../services/PermissionService.php';

$userId = 148; // matheus.damiao

$stmt = $pdo->prepare("SELECT id, name, username, email, role, active, auth_source, ad_domain, ad_object_guid, department, job_title, phone, avatar, created_at, last_login_at FROM users WHERE id = ?");
$stmt->execute([$userId]);
$userData = $stmt->fetch(PDO::FETCH_ASSOC);

$stmtLogs = $pdo->prepare("
    SELECT created_at, domain_key, username, server_name, server_uri, status, status_message, latency_ms, user_ip 
    FROM ad_auth_logs 
    WHERE LOWER(username) = LOWER(?) 
    ORDER BY created_at DESC 
    LIMIT 5
");
$stmtLogs->execute([$userData['username']]);
$authLogs = $stmtLogs->fetchAll(PDO::FETCH_ASSOC);

$stmtGroups = $pdo->prepare("
    SELECT g.id, g.name, g.description, ug.created_at AS joined_at
    FROM user_groups ug
    JOIN groups g ON g.id = ug.group_id
    WHERE ug.user_id = ?
    ORDER BY g.name ASC
");
$stmtGroups->execute([$userId]);
$userGroups = $stmtGroups->fetchAll(PDO::FETCH_ASSOC);

$permService = new PermissionService($pdo);
$diagnosis = $permService->getUserEffectiveAccessDiagnosis($userId);

echo "=== FICHA 360° DO USUÁRIO {$userData['username']} ===\n\n";
echo "Nome: {$userData['name']}\n";
echo "E-mail: {$userData['email']}\n";
echo "Domínio AD: {$userData['ad_domain']}\n";
echo "GUID AD: {$userData['ad_object_guid']}\n";
echo "Departamento: {$userData['department']}\n";
echo "Cargo: {$userData['job_title']}\n";
echo "Telefone: {$userData['phone']}\n";
echo "Último Acesso: {$userData['last_login_at']}\n";
echo "Logs de Autenticação Gravados: " . count($authLogs) . "\n";
echo "Equipes/Grupos: " . count($userGroups) . "\n";
echo "Categorias Autorizadas: " . count($diagnosis['resources']['categories'] ?? []) . "\n";
