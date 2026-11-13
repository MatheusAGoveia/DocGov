<?php
/**
 * documents/actions/batch-upload.php
 * Entradas: $loggedUser, $batchDocumentUploadService, $hierarchyService.
 * Estado compartilhado: resposta HTTP/efeitos da acao.
 * Incluido somente pelo despacho administrativo; preserva o escopo da entrada.
 */
if (!defined('DOCGOV_ADMIN_REQUEST') || DOCGOV_ADMIN_REQUEST !== true) {
    http_response_code(404);
    exit;
}


// 1. ENVIO EM LOTE DE ARQUIVOS (assíncrono, com uma entrada por arquivo)
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['save_doc']) && ($_POST['batch_upload'] ?? '') === '1') {
    $batchResponse = static function (int $status, array $payload): never {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    };

    if (!CsrfService::isValid($_POST['csrf_token'] ?? null)) {
        $batchResponse(419, ['success' => false, 'error' => 'A sessão de segurança expirou. Atualize a página e tente novamente.']);
    }
    if (!empty($_POST['id'])) {
        $batchResponse(422, ['success' => false, 'error' => 'O envio em lote está disponível apenas para novos documentos.']);
    }

    $assuntoInput = trim((string)($_POST['assunto'] ?? ''));
    $subcategoriaInput = trim((string)($_POST['subcategoria'] ?? ''));
    $categoriaInput = trim((string)($_POST['categoria'] ?? ''));
    $workflowAction = trim((string)($_POST['workflow_action'] ?? $_POST['publication_action'] ?? 'save_draft'));
    if ($workflowAction === '') {
        $workflowAction = 'save_draft';
    }

    try {
        $subjectContext = $hierarchyService->resolveActiveSubject($assuntoInput, $subcategoriaInput, $categoriaInput);
        $subjectId = (int)($subjectContext['id'] ?? 0);
        if ($subjectId <= 0) {
            throw new InvalidArgumentException('Selecione uma categoria, uma subcategoria e um assunto ativos.');
        }
        $createdDocuments = $batchDocumentUploadService->create(
            (int)($loggedUser['id'] ?? 0),
            $subjectId,
            trim((string)($_POST['descricao'] ?? '')),
            $workflowAction,
            trim((string)($_POST['workflow_note'] ?? '')),
            is_array($_FILES['arquivo_file'] ?? null) ? $_FILES['arquivo_file'] : [],
            array_values(array_map('strval', (array)($_POST['batch_titles'] ?? []))),
            array_values(array_map('intval', (array)($_POST['tag_ids'] ?? []))),
            array_values(array_map('strval', (array)($_POST['new_tags'] ?? []))),
        );
        $batchResponse(201, [
            'success' => true,
            'created_count' => count($createdDocuments),
            'documents' => $createdDocuments,
            'redirect' => 'index.php?tab=documentos&msg=batch_docs_created&count=' . count($createdDocuments),
        ]);
    } catch (Throwable $exception) {
        $batchResponse(422, ['success' => false, 'error' => $exception->getMessage()]);
    }
}
