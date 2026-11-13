<?php
/**
 * documents/actions/trash.php
 * Entradas: $pdo, $currentAdminUserId, $permService, $workflowService, $usageAuditService, $inlineMediaService.
 * Estado compartilhado: $errorMessage.
 * Incluido somente pelo despacho administrativo; preserva o escopo da entrada.
 */
if (!defined('DOCGOV_ADMIN_REQUEST') || DOCGOV_ADMIN_REQUEST !== true) {
    http_response_code(404);
    exit;
}


// 1.15 LIXEIRA: ações individuais protegidas por CSRF. A restauração usa o
// estado preservado no momento do arquivamento e nunca reativa expirações.
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['document_trash_action'])) {
    $trashAction = trim((string)$_POST['document_trash_action']);
    $documentId = (int)($_POST['document_id'] ?? 0);

    if (!CsrfService::isValid($_POST['csrf_token'] ?? null)) {
        http_response_code(419);
        $errorMessage = 'A sessão de segurança expirou. Atualize a página e tente novamente.';
    } elseif (!in_array($trashAction, ['trash', 'restore', 'permanent_delete'], true) || $documentId <= 0) {
        $errorMessage = 'Ação de lixeira inválida.';
    } elseif (!$permService->canEditDocument($currentAdminUserId, $documentId)) {
        http_response_code(403);
        $errorMessage = 'Você não possui acesso a este documento.';
    } else {
        try {
            $pdo->beginTransaction();
            $currentDocumentStmt = $pdo->prepare('SELECT id, status, file_path, trashed_at FROM documents WHERE id = :id FOR UPDATE');
            $currentDocumentStmt->execute([':id' => $documentId]);
            $currentDocument = $currentDocumentStmt->fetch(PDO::FETCH_ASSOC);
            if (!$currentDocument) {
                throw new RuntimeException('Documento não encontrado.');
            }

            if ($trashAction === 'trash') {
                $workflowService->moveToTrash($documentId, $currentAdminUserId, (string)$currentDocument['status'], 'Movido para a lixeira.');
                $pdo->commit();
                $usageAuditService->logAdminAction($currentAdminUserId, 'document_trashed', 'DOCUMENT', $documentId);
                $returnFilters = array_intersect_key($_GET, array_flip(['search', 'filter_cat', 'filter_subcat', 'filter_assunto', 'filter_tipo', 'filter_status', 'page']));
                header('Location: index.php?' . http_build_query(array_merge(['tab' => 'documentos'], $returnFilters, ['msg' => 'moved_to_trash'])));
                exit;
            }

            if ($trashAction === 'restore') {
                $restoreStatus = $workflowService->restoreFromTrash($documentId, $currentAdminUserId);
                $pdo->commit();
                $usageAuditService->logAdminAction($currentAdminUserId, 'document_restored_from_trash', 'DOCUMENT', $documentId);
                header('Location: index.php?tab=lixeira&msg=restored&status=' . urlencode($restoreStatus));
                exit;
            }

            if (!$permService->canAdminDocument($currentAdminUserId, $documentId)) {
                throw new RuntimeException('Somente o Administrador da categoria pode excluir um documento definitivamente.');
            }
            if ((string)$currentDocument['status'] !== 'inactive' || empty($currentDocument['trashed_at'])) {
                throw new InvalidArgumentException('Somente documentos enviados à lixeira podem ser excluídos definitivamente.');
            }
            $filePath = (string)($currentDocument['file_path'] ?? '');
            $inlineMediaFiles = $inlineMediaService->pathsForDocuments([$documentId]);
            $deleteDocumentStmt = $pdo->prepare('DELETE FROM documents WHERE id = :id');
            $deleteDocumentStmt->execute([':id' => $documentId]);
            $pdo->commit();
            foreach ($inlineMediaFiles as $inlineMediaFile) {
                $inlineMediaService->removeStoredFile($inlineMediaFile);
            }

            // Arquivos só são removidos depois da confirmação no banco e se
            // pertencem inequivocamente ao diretório protegido de documentos.
            if ($filePath !== '') {
                $storageRoot = realpath(DOCGOV_PROJECT_ROOT . '/storage/documents');
                $candidatePath = realpath(DOCGOV_PROJECT_ROOT . '/' . ltrim(str_replace('\\', '/', $filePath), '/'));
                if ($storageRoot !== false && $candidatePath !== false
                    && str_starts_with($candidatePath, $storageRoot . DIRECTORY_SEPARATOR)
                    && is_file($candidatePath)) {
                    @unlink($candidatePath);
                }
            }
            $usageAuditService->logAdminAction($currentAdminUserId, 'document_permanently_deleted', 'DOCUMENT', $documentId);
            header('Location: index.php?tab=lixeira&msg=perm_deleted');
            exit;
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errorMessage = $exception->getMessage();
        }
    }
}
