<?php
/**
 * subject-workspaces/actions/save.php
 * Entradas: $currentAdminUserId, $permService, $subjectWorkspaceService, $usageAuditService.
 * Estado compartilhado: $errorMessage.
 * Incluido somente pelo despacho administrativo; preserva o escopo da entrada.
 */
if (!defined('DOCGOV_ADMIN_REQUEST') || DOCGOV_ADMIN_REQUEST !== true) {
    http_response_code(404);
    exit;
}


if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['save_subject_workspace'])) {
    $workspaceSubjectId = (int)($_POST['subject_id'] ?? 0);
    $workspaceSection = strtolower(trim((string)($_POST['workspace_section'] ?? 'overview')));
    $canAdminWorkspaceSubject = $workspaceSubjectId > 0
        && $permService->canAdminSubject($currentAdminUserId, $workspaceSubjectId);
    if (!CsrfService::isValid($_POST['csrf_token'] ?? null)) {
        http_response_code(419);
        $errorMessage = 'A sessão de segurança expirou. Atualize a página e tente novamente.';
    } elseif ($workspaceSubjectId <= 0 || !$permService->canEditSubject($currentAdminUserId, $workspaceSubjectId)) {
        http_response_code(403);
        $errorMessage = 'Você não possui permissão para editar a documentação deste assunto.';
    } else {
        try {
            $subjectWorkspaceService->saveSection(
                $workspaceSubjectId,
                $currentAdminUserId,
                $workspaceSection,
                $_POST,
                $canAdminWorkspaceSubject
            );
            $usageAuditService->logAdminAction(
                $currentAdminUserId,
                'subject_workspace_' . $workspaceSection . '_updated',
                'SUBJECT',
                $workspaceSubjectId
            );
            header('Location: index.php?' . http_build_query([
                'tab' => 'editar_estrutura',
                'type' => 'assunto',
                'id' => $workspaceSubjectId,
                'res_tab' => $workspaceSection,
                'msg' => 'workspace_saved',
            ]));
            exit;
        } catch (Throwable $exception) {
            $errorMessage = $exception->getMessage();
        }
    }
}
