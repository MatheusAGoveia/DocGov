<?php
/**
 * tags/actions/manage.php
 * Entradas: $currentAdminUserId, $canManageTags, $usageAuditService, $tagService.
 * Estado compartilhado: $errorMessage.
 * Incluido somente pelo despacho administrativo; preserva o escopo da entrada.
 */
if (!defined('DOCGOV_ADMIN_REQUEST') || DOCGOV_ADMIN_REQUEST !== true) {
    http_response_code(404);
    exit;
}


// 0.0 CURADORIA DO CATÁLOGO DE TAGS (SOMENTE SUPER ADMIN)
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['tag_admin_action'])) {
    if (!CsrfService::isValid($_POST['csrf_token'] ?? null)) {
        http_response_code(419);
        $errorMessage = 'A sessão de segurança expirou. Atualize a página e tente novamente.';
    } elseif (!$canManageTags) {
        http_response_code(403);
        $errorMessage = 'Seu usuário não possui acesso à curadoria de tags.';
    } else {
        try {
            $tagAction = (string)$_POST['tag_admin_action'];
            $tagId = (int)($_POST['tag_id'] ?? 0);
            if ($tagAction === 'create') {
                $tagService->create((string)($_POST['tag_name'] ?? ''), (string)($_POST['tag_type'] ?? 'topic'), $currentAdminUserId);
            } elseif ($tagAction === 'update') {
                $tagService->update($tagId, (string)($_POST['tag_name'] ?? ''), (string)($_POST['tag_type'] ?? 'topic'));
            } elseif ($tagAction === 'toggle') {
                $tagService->setActive($tagId, (string)($_POST['active'] ?? '') === '1');
            } elseif ($tagAction === 'add_alias') {
                $tagService->addAlias($tagId, (string)($_POST['tag_alias'] ?? ''));
            } else {
                throw new InvalidArgumentException('Ação de tag inválida.');
            }
            $usageAuditService->logAdminAction($currentAdminUserId, 'tag_catalog_' . $tagAction, 'TAG', $tagId ?: null);
            header('Location: index.php?tab=tags&msg=tag_catalog_saved');
            exit;
        } catch (Throwable $exception) {
            $errorMessage = $exception->getMessage();
        }
    }
}
