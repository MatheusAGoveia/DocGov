<?php
/**
 * users/actions/grant-permission.php
 * Entradas: $pdo, $currentAdminUserId, $isGlobalAdminCurrent, $permService, $usageAuditService.
 * Estado compartilhado: $errorMessage.
 * Incluido somente pelo despacho administrativo; preserva o escopo da entrada.
 */
if (!defined('DOCGOV_ADMIN_REQUEST') || DOCGOV_ADMIN_REQUEST !== true) {
    http_response_code(404);
    exit;
}


// 0.1. ACESSO INDIVIDUAL DO USUÁRIO (SOMENTE SUPER ADMIN)
// A regra é gravada diretamente para a pessoa escolhida, sem depender de equipe.
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['save_direct_user_permission'])) {
    $targetUserId = (int)($_POST['target_user_id'] ?? 0);
    $resourceSelection = trim((string)($_POST['direct_resource'] ?? ''));
    $permissionLevel = strtolower(trim((string)($_POST['permission_level'] ?? '')));
    [$resourceType, $resourceIdRaw] = array_pad(explode(':', $resourceSelection, 2), 2, '');
    $resourceType = strtolower(trim($resourceType));
    $resourceId = (int)$resourceIdRaw;

    if (!CsrfService::isValid($_POST['csrf_token'] ?? null)) {
        http_response_code(419);
        $errorMessage = 'A sessão de segurança expirou. Atualize a página e tente novamente.';
    } elseif (!$isGlobalAdminCurrent) {
        http_response_code(403);
        $errorMessage = 'Somente o Super Admin pode conceder acesso individual pela tela de usuários.';
    } elseif ($targetUserId <= 0 || !in_array($resourceType, ['category', 'subcategory', 'subject'], true) || $resourceId <= 0) {
        $errorMessage = 'Selecione um usuário e uma área válida para liberar o acesso.';
    } elseif (!in_array($permissionLevel, ['view', 'edit', 'admin'], true)) {
        $errorMessage = 'Selecione um nível de acesso válido.';
    } else {
        $targetUserStmt = $pdo->prepare('SELECT id, active, role FROM users WHERE id = :id');
        $targetUserStmt->execute([':id' => $targetUserId]);
        $targetUser = $targetUserStmt->fetch(PDO::FETCH_ASSOC);

        if (!$targetUser) {
            $errorMessage = 'Usuário não encontrado.';
        } elseif (!filter_var($targetUser['active'], FILTER_VALIDATE_BOOLEAN)) {
            $errorMessage = 'Não é possível liberar acesso para um usuário inativo.';
        } elseif (strtolower((string)$targetUser['role']) === 'admin') {
            $errorMessage = 'Este usuário já é Administrador Global e possui acesso completo.';
        } else {
            try {
                $permService->saveResourcePermission(
                    $resourceType,
                    $resourceId,
                    $targetUserId,
                    null,
                    $permissionLevel,
                    $currentAdminUserId
                );
                $usageAuditService->logAdminAction(
                    $currentAdminUserId,
                    'direct_user_access_granted',
                    strtoupper($resourceType),
                    $resourceId
                );
                header('Location: index.php?tab=editar_usuario&id=' . $targetUserId . '&user_tab=access&msg=direct_user_access_saved');
                exit;
            } catch (Throwable $exception) {
                error_log('DocGov: falha ao conceder acesso individual: ' . $exception->getMessage());
                $errorMessage = 'Não foi possível salvar o acesso individual. Verifique a área selecionada e tente novamente.';
            }
        }
    }
}
