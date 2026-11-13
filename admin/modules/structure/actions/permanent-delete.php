<?php
/**
 * structure/actions/permanent-delete.php
 * Entradas: $pdo, $currentAdminUserId, $isGlobalAdminCurrent, $structureDeletionService, $categoryImageService, $usageAuditService, $inlineMediaService.
 * Estado compartilhado: $errorMessage.
 * Incluido somente pelo despacho administrativo; preserva o escopo da entrada.
 */
if (!defined('DOCGOV_ADMIN_REQUEST') || DOCGOV_ADMIN_REQUEST !== true) {
    http_response_code(404);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['structure_delete_action'])) {
    $deleteType = strtolower(trim((string)($_POST['structure_type'] ?? '')));
    $deleteId = (int)($_POST['structure_id'] ?? 0);
    $confirmedName = (string)($_POST['confirmation_name'] ?? '');
    if (!CsrfService::isValid($_POST['csrf_token'] ?? null)) {
        http_response_code(419);
        $errorMessage = 'A sessão de segurança expirou. Atualize a página e tente novamente.';
    } elseif (!$isGlobalAdminCurrent) {
        http_response_code(403);
        $errorMessage = 'Somente Super Admins podem excluir itens da estrutura permanentemente.';
    } elseif ($_POST['structure_delete_action'] !== 'permanent_delete') {
        http_response_code(400);
        $errorMessage = 'Ação de exclusão inválida.';
    } else {
        try {
            $deletion = $structureDeletionService->deletePermanently($deleteType, $deleteId, $currentAdminUserId, $confirmedName);
            $summary = $deletion['summary'];
            $usageAuditService->log('admin_action', $currentAdminUserId, strtoupper($deleteType), $deleteId, [
                'action' => 'structure_permanently_deleted',
                'name' => $summary['name'],
                'subcategories' => $summary['subcategories'],
                'subjects' => $summary['subjects'],
                'documents' => $summary['documents'],
            ]);

            $cleanupWarning = false;
            foreach ($deletion['document_files'] as $storedPath) {
                try {
                    $stillUsed = $pdo->prepare('SELECT 1 FROM documents WHERE file_path = :path LIMIT 1');
                    $stillUsed->execute([':path' => $storedPath]);
                    if ($stillUsed->fetchColumn()) continue;
                    $storageRoot = realpath(DOCGOV_PROJECT_ROOT . '/storage/documents');
                    $candidate = realpath(DOCGOV_PROJECT_ROOT . '/' . ltrim(str_replace('\\', '/', $storedPath), '/'));
                    if ($storageRoot !== false && $candidate !== false
                        && str_starts_with($candidate, $storageRoot . DIRECTORY_SEPARATOR)
                        && is_file($candidate) && !@unlink($candidate)) {
                        $cleanupWarning = true;
                        error_log('DocGov: arquivo de documento não removido após exclusão definitiva: ' . $candidate);
                    }
                } catch (Throwable $cleanupException) {
                    $cleanupWarning = true;
                    error_log('DocGov: falha ao limpar arquivo de documento: ' . $cleanupException->getMessage());
                }
            }
            foreach ($deletion['inline_media_files'] as $storedName) {
                $inlineMediaService->removeStoredFile($storedName);
            }
            foreach ($deletion['image_files'] as $imagePath) {
                try {
                    $stillUsed = $pdo->prepare('SELECT 1 WHERE EXISTS (SELECT 1 FROM categories WHERE image_path = :category_path) OR EXISTS (SELECT 1 FROM subcategories WHERE image_path = :subcategory_path)');
                    $stillUsed->execute([':category_path' => $imagePath, ':subcategory_path' => $imagePath]);
                    if ($stillUsed->fetchColumn()) continue;
                    $categoryImageService->remove($imagePath);
                    if ($categoryImageService->resolve($imagePath) !== null) {
                        $cleanupWarning = true;
                        error_log('DocGov: imagem da estrutura não removida após exclusão definitiva: ' . $imagePath);
                    }
                } catch (Throwable $cleanupException) {
                    $cleanupWarning = true;
                    error_log('DocGov: falha ao limpar imagem da estrutura: ' . $cleanupException->getMessage());
                }
            }
            header('Location: index.php?tab=editar_estrutura&view=discarded&msg=structure_permanently_deleted' . ($cleanupWarning ? '&cleanup=partial' : ''));
            exit;
        } catch (Throwable $exception) {
            http_response_code($exception instanceof InvalidArgumentException ? 400 : 403);
            $errorMessage = $exception->getMessage();
        }
    }
}
