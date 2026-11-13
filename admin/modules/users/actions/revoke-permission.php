<?php
/**
 * users/actions/revoke-permission.php
 * Entradas: $pdo, $currentAdminUserId, $isGlobalAdminCurrent, $permService, $usageAuditService.
 * Estado compartilhado: $errorMessage.
 * Incluido somente pelo despacho administrativo; preserva o escopo da entrada.
 */
if (!defined('DOCGOV_ADMIN_REQUEST') || DOCGOV_ADMIN_REQUEST !== true) {
    http_response_code(404);
    exit;
}


// 0.2. REMOÇÃO DE UMA REGRA INDIVIDUAL DE USUÁRIO (SOMENTE SUPER ADMIN)
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['remove_direct_user_permission'])) {
    $targetUserId = (int)($_POST['target_user_id'] ?? 0);
    $permissionId = (int)($_POST['permission_id'] ?? 0);

    if (!CsrfService::isValid($_POST['csrf_token'] ?? null)) {
        http_response_code(419);
        $errorMessage = 'A sessão de segurança expirou. Atualize a página e tente novamente.';
    } elseif (!$isGlobalAdminCurrent) {
        http_response_code(403);
        $errorMessage = 'Somente o Super Admin pode remover acessos individuais pela tela de usuários.';
    } elseif ($targetUserId <= 0 || $permissionId <= 0) {
        $errorMessage = 'Regra de acesso inválida.';
    } else {
        $ruleStmt = $pdo->prepare('SELECT id FROM permissions WHERE id = :id AND user_id = :user_id');
        $ruleStmt->execute([':id' => $permissionId, ':user_id' => $targetUserId]);

        if (!$ruleStmt->fetchColumn()) {
            $errorMessage = 'A regra individual não foi encontrada para este usuário.';
        } else {
            try {
                if (!$permService->deletePermission($permissionId, $currentAdminUserId)) {
                    throw new RuntimeException('Regra não encontrada.');
                }
                $usageAuditService->logAdminAction($currentAdminUserId, 'direct_user_access_removed', 'ADMIN', null);
                header('Location: index.php?tab=editar_usuario&id=' . $targetUserId . '&user_tab=access&msg=direct_user_access_removed');
                exit;
            } catch (Throwable $exception) {
                error_log('DocGov: falha ao remover acesso individual: ' . $exception->getMessage());
                $errorMessage = 'Não foi possível remover o acesso individual.';
            }
        }
    }
}
