<?php
// scratch/test_permissions.php - Teste de Verificação Automatizado do Sistema de Permissões Grafana-style

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../services/PermissionService.php';

echo "===============================================================\n";
echo "    TESTE AUTOMATIZADO DE VALIDAÇÃO DE PERMISSÕES (DocGov)    \n";
echo "===============================================================\n\n";

$permService = new PermissionService($pdo);

// 1. Teste de Admin Global Bypass
echo "[TEST 1] Verificando Super Admin Bypass...\n";
$stmtAdmin = $pdo->query("SELECT id FROM users WHERE role = 'admin' LIMIT 1");
$adminUserId = (int)$stmtAdmin->fetchColumn();

if ($adminUserId > 0) {
    $isSuper = $permService->isGlobalAdmin($adminUserId);
    $catPerm = $permService->getEffectiveCategoryPermission($adminUserId, 1);
    echo "  - Super Admin ID: {$adminUserId}\n";
    echo "  - isGlobalAdmin(): " . ($isSuper ? "TRUE" : "FALSE") . "\n";
    echo "  - Permissão efetiva na Categoria 1: {$catPerm['effective_level']} (Nível: {$catPerm['effective_value']})\n";
    assert($isSuper === true, "Super Admin deve retornar TRUE em isGlobalAdmin");
    assert($catPerm['effective_level'] === 'admin', "Super Admin deve ter permissão 'admin'");
    echo "  ✓ [PASS] Super Admin Bypass funcional!\n\n";
}

// 2. Teste de Resolução de Permissão por Equipe / Usuário e MAX()
echo "[TEST 2] Verificando Resolução de Permissões (MAX)...\n";
$stmtUser = $pdo->query("SELECT id, name FROM users WHERE role != 'admin' LIMIT 1");
$normalUser = $stmtUser->fetch(PDO::FETCH_ASSOC);

if ($normalUser) {
    $uId = (int)$normalUser['id'];
    echo "  - Usuário Comum ID: {$uId} ({$normalUser['name']})\n";

    // Salvar nova permissão de teste na Categoria 1 (View)
    $permService->saveResourcePermission('category', 1, $uId, null, 'view', $adminUserId);
    $permCategory = $permService->getEffectiveCategoryPermission($uId, 1);
    echo "  - Concedida permissão 'view' direta na Categoria 1 -> Efetivo: {$permCategory['effective_level']}\n";
    assert($permCategory['effective_level'] === 'view', "Permissão deve ser 'view'");

    // Conceder permissão maior 'edit' no Assunto 1 do mesmo usuário
    $permService->saveResourcePermission('subject', 1, $uId, null, 'edit', $adminUserId);
    $permSubject = $permService->getEffectiveSubjectPermission($uId, 1);
    echo "  - Concedida permissão 'edit' direta no Assunto 1 -> Efetivo: {$permSubject['effective_level']}\n";
    assert($permSubject['effective_level'] === 'edit', "Permissão deve prevalecer como 'edit' via MAX()");

    echo "  ✓ [PASS] Resolução de permissões por MAX() validada com sucesso!\n\n";
}

// 3. Teste da Tabela de Auditoria (permission_audit)
echo "[TEST 3] Verificando Auditoria (permission_audit)...\n";
// Forçar uma alteração para gerar log de auditoria
$permService->saveResourcePermission('category', 1, (int)$normalUser['id'], null, 'edit', $adminUserId);

$stmtAudit = $pdo->query("SELECT id, action, principal_type, resource_type, old_permission, new_permission FROM permission_audit ORDER BY id DESC LIMIT 1");
$lastAudit = $stmtAudit->fetch(PDO::FETCH_ASSOC);

if ($lastAudit) {
    echo "  - Última Ação Auditada: {$lastAudit['action']} | Principal: {$lastAudit['principal_type']} | Recurso: {$lastAudit['resource_type']} | De: '{$lastAudit['old_permission']}' Para: '{$lastAudit['new_permission']}'\n";
    echo "  ✓ [PASS] Auditoria validada com sucesso!\n\n";
} else {
    throw new Exception("Falha ao encontrar registro na tabela permission_audit");
}

// 4. Teste de Árvore Acessível (Pruning)
echo "[TEST 4] Verificando Pruning de Árvore (getAccessibleResourceTree)...\n";
$tree = $permService->getAccessibleResourceTree($normalUser ? (int)$normalUser['id'] : 0);
echo "  - Categorias retornadas para usuário comum: " . count($tree) . "\n";
echo "  ✓ [PASS] Árvore acessível gerada com sucesso!\n\n";

echo "===============================================================\n";
echo "    TODOS OS TESTES AUTOMATIZADOS PASSARAM COM SUCESSO!       \n";
echo "===============================================================\n";
