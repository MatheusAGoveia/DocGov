<?php
/**
 * subject-workspaces/actions/transition.php
 * Entradas: $pdo, $currentAdminUserId, $permService, $subjectWorkspaceService, $notificationService, $usageAuditService.
 * Estado compartilhado: $errorMessage.
 * Incluido somente pelo despacho administrativo; preserva o escopo da entrada.
 */
if (!defined('DOCGOV_ADMIN_REQUEST') || DOCGOV_ADMIN_REQUEST !== true) {
    http_response_code(404);
    exit;
}


// DOCUMENTAÇÃO ESTRUTURADA DO PROCESSO (NÍVEL ASSUNTO)
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['subject_workspace_action'])) {
    $workspaceSubjectId = (int)($_POST['subject_id'] ?? 0);
    $workspaceAction = strtolower(trim((string)($_POST['subject_workspace_action'] ?? '')));
    $workspaceNote = trim((string)($_POST['workflow_note'] ?? ''));
    $canEditWorkspaceSubject = $workspaceSubjectId > 0
        && $permService->canEditSubject($currentAdminUserId, $workspaceSubjectId);
    $canAdminWorkspaceSubject = $workspaceSubjectId > 0
        && $permService->canAdminSubject($currentAdminUserId, $workspaceSubjectId);
    if (!CsrfService::isValid($_POST['csrf_token'] ?? null)) {
        http_response_code(419);
        $errorMessage = 'A sessão de segurança expirou. Atualize a página e tente novamente.';
    } elseif (!$canEditWorkspaceSubject) {
        http_response_code(403);
        $errorMessage = 'Você não possui permissão para executar ações neste assunto.';
    } elseif ($workspaceAction !== 'submit_review' && !$canAdminWorkspaceSubject) {
        http_response_code(403);
        $errorMessage = 'Somente um administrador deste assunto pode concluir a revisão.';
    } else {
        try {
            $transition = $subjectWorkspaceService->transition(
                $workspaceSubjectId,
                $currentAdminUserId,
                $workspaceAction,
                $workspaceNote,
                $canAdminWorkspaceSubject
            );

            $subjectNameStmt = $pdo->prepare('SELECT name FROM subjects WHERE id = :id');
            $subjectNameStmt->execute([':id' => $workspaceSubjectId]);
            $subjectName = (string)($subjectNameStmt->fetchColumn() ?: 'Assunto');
            try {
                if ($workspaceAction === 'submit_review') {
                    $reviewers = [];
                    $candidateIds = $pdo->query('SELECT id FROM users WHERE active = TRUE')->fetchAll(PDO::FETCH_COLUMN);
                    foreach ($candidateIds as $candidateId) {
                        if ($permService->canAdminSubject((int)$candidateId, $workspaceSubjectId)) {
                            $reviewers[] = (int)$candidateId;
                        }
                    }
                    $notificationService->createForUsers(
                        $reviewers,
                        'subject_review_requested',
                        'Documentação aguardando revisão',
                        '“' . $subjectName . '” foi enviada para sua homologação.',
                        null,
                        $currentAdminUserId
                    );
                } else {
                    $workspaceAfter = $transition['workspace'];
                    $authors = [
                        (int)($workspaceAfter['created_by'] ?? 0),
                        (int)($workspaceAfter['submitted_by'] ?? 0),
                    ];
                    $notificationData = match ($workspaceAction) {
                        'return_changes' => ['subject_changes_requested', 'Ajustes solicitados', '“' . $subjectName . '” voltou para elaboração. Motivo: ' . $workspaceNote],
                        'approve' => ['subject_approved', 'Documentação homologada', '“' . $subjectName . '” foi homologada e está disponível no portal.'],
                        'deprecate' => ['subject_deprecated', 'Documentação marcada como obsoleta', '“' . $subjectName . '” foi retirada da visualização principal.'],
                        'reopen' => ['subject_reopened', 'Documentação reaberta', '“' . $subjectName . '” foi reaberta para edição.'],
                        default => null,
                    };
                    if ($notificationData !== null) {
                        $notificationService->createForUsers(
                            $authors,
                            $notificationData[0],
                            $notificationData[1],
                            $notificationData[2],
                            null,
                            $currentAdminUserId
                        );
                    }
                }
            } catch (Throwable) {
                // A transição principal permanece válida mesmo se a notificação falhar.
            }

            $usageAuditService->logAdminAction(
                $currentAdminUserId,
                'subject_workspace_' . $workspaceAction,
                'SUBJECT',
                $workspaceSubjectId
            );
            $messageByAction = [
                'submit_review' => 'workspace_submitted',
                'return_changes' => 'workspace_returned',
                'approve' => 'workspace_approved',
                'deprecate' => 'workspace_deprecated',
                'reopen' => 'workspace_reopened',
            ];
            header('Location: index.php?' . http_build_query([
                'tab' => 'editar_estrutura',
                'type' => 'assunto',
                'id' => $workspaceSubjectId,
                'res_tab' => $workspaceAction === 'return_changes' || $workspaceAction === 'reopen' ? 'overview' : 'history',
                'msg' => $messageByAction[$workspaceAction] ?? 'workspace_saved',
            ]));
            exit;
        } catch (Throwable $exception) {
            $errorMessage = $exception->getMessage();
        }
    }
}
