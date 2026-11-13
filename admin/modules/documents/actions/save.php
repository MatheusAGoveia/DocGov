<?php
/**
 * documents/actions/save.php
 * Entradas: $pdo, $loggedUser, $permService, $workflowService, $usageAuditService, $tagService, $hierarchyService, $documentSlugService, $documentSectionService, $inlineMediaService.
 * Estado compartilhado: $errorMessage, $failedDocumentSubmission.
 * Incluido somente pelo despacho administrativo; preserva o escopo da entrada.
 */
if (!defined('DOCGOV_ADMIN_REQUEST') || DOCGOV_ADMIN_REQUEST !== true) {
    http_response_code(404);
    exit;
}


// 1.1 SALVAR / EDITAR DOCUMENTO E LAYOUT VISUAL
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['save_doc'])) {
    if (UploadRequestService::filesError($_FILES) !== null) {
        http_response_code(400);
        $errorMessage = UploadRequestService::filesError($_FILES);
    } elseif (!CsrfService::isValid($_POST['csrf_token'] ?? null)) {
        http_response_code(419);
        $errorMessage = 'A sessão de segurança expirou. Atualize a página e tente novamente.';
    }
    $titulo = trim($_POST['titulo'] ?? '');
    $descricao = trim($_POST['descricao'] ?? '');
    $catInput = trim($_POST['categoria'] ?? '');
    $subInput = trim($_POST['subcategoria'] ?? '');
    $assuntoInput = trim($_POST['assunto'] ?? '');
    $publicationAction = trim($_POST['publication_action'] ?? '');
    $workflowAction = trim($_POST['workflow_action'] ?? '');
    if ($workflowAction === '') {
        $workflowAction = $publicationAction === 'publish' ? 'submit_review' : 'save_draft';
    }
    $workflowNote = trim($_POST['workflow_note'] ?? '');
    $tipoConteudo = trim($_POST['tipo_conteudo'] ?? 'file');
    $documentSectionKey = strtolower(trim((string)($_POST['section_key'] ?? '')));
    $conteudoHtmlRaw = trim($_POST['conteudo_html'] ?? '');
    $structuredContentRaw = (string)($_POST['structured_content'] ?? '');
    $codigoFonteRaw = str_replace(["\r\n", "\r"], "\n", (string)($_POST['codigo_fonte'] ?? ''));
    $linguagemCodigo = strtolower(trim($_POST['linguagem_codigo'] ?? 'auto'));
    $linkExterno = trim($_POST['link_externo'] ?? '');
    $videoSource = trim($_POST['video_source'] ?? 'upload');
    $videoUrl = trim($_POST['video_url'] ?? '');
    $id = isset($_POST['id']) && $_POST['id'] !== '' ? (int)$_POST['id'] : null;
    $requestedTagIds = array_values(array_map('intval', (array)($_POST['tag_ids'] ?? [])));
    $requestedNewTagNames = array_values(array_map('strval', (array)($_POST['new_tags'] ?? [])));
    $previousDocumentStatus = null;
    $previousDocumentSectionKey = null;

    if ($id && $id > 0) {
        $stmtCurrentStatus = $pdo->prepare('SELECT status, section_key, updated_at FROM documents WHERE id = :id');
        $stmtCurrentStatus->execute([':id' => $id]);
        $currentDocumentState = $stmtCurrentStatus->fetch(PDO::FETCH_ASSOC);
        $previousDocumentStatus = $currentDocumentState['status'] ?? false;
        $previousDocumentSectionKey = $currentDocumentState['section_key'] ?? null;
        if (!$currentDocumentState) {
            $errorMessage = 'Documento não encontrado.';
        }
    }

    if ($documentSectionKey === '') {
        $documentSectionKey = $previousDocumentSectionKey
            ?: $documentSectionService->defaultSectionForContentType($tipoConteudo);
    }
    try {
        $documentSectionSelection = $documentSectionService->resolveSelection($documentSectionKey);
        $tipoConteudo = $documentSectionSelection['content_type'];
    } catch (Throwable $exception) {
        $errorMessage = $exception->getMessage();
    }
    if (!in_array($videoSource, ['upload', 'url'], true)) {
        $videoSource = 'upload';
    }
    if ($tipoConteudo === 'video' && $videoSource === 'url') {
        $linkExterno = $videoUrl;
    }

    $linguagensPermitidas = [
        'auto', 'plaintext', 'javascript', 'typescript', 'xml', 'css', 'php', 'python',
        'sql', 'bash', 'json', 'java', 'csharp', 'cpp', 'go', 'yaml', 'markdown'
    ];
    if (!in_array($linguagemCodigo, $linguagensPermitidas, true)) {
        $linguagemCodigo = 'auto';
    }

    $structuredContent = null;
    try {
        $conteudoArmazenado = match ($tipoConteudo) {
            'text' => RichTextSanitizer::sanitize($conteudoHtmlRaw),
            'code' => $codigoFonteRaw,
            default => null,
        };
        if (in_array($tipoConteudo, ['flow', 'orgchart'], true)) {
            $structuredContent = StructuredContentService::normalize($tipoConteudo, $structuredContentRaw);
        }
    } catch (Throwable $exception) {
        $errorMessage = $exception->getMessage();
        $conteudoArmazenado = null;
    }

    // Resolver o ramo completo. IDs são usados pelo formulário; nomes/slugs antigos
    // continuam aceitos somente quando identificam um único ramo ativo.
    $subjectId = 0;
    try {
        $subjectContext = $hierarchyService->resolveActiveSubject($assuntoInput, $subInput, $catInput);
        $subjectId = (int)($subjectContext['id'] ?? 0);
    } catch (Throwable $exception) {
        $errorMessage = $exception->getMessage();
    }

    // VALIDAÇÃO DE AUTORIZAÇÃO NO BACKEND (canCreateDocument / canEditDocument)
    $editorUserId = (int)($loggedUser['id'] ?? 0);

    if (empty($errorMessage) && (!$id || $id <= 0)) {
        if ($subjectId <= 0 || !$permService->canCreateDocument($editorUserId, $subjectId)) {
            if ($subjectId <= 0) {
                $errorMessage = 'Selecione uma categoria, uma subcategoria e um assunto ativos.';
            } else {
                http_response_code(403);
                $errorMessage = "Acesso Negado: Você não possui permissão para criar documentos neste Assunto.";
            }
        }
    } elseif (empty($errorMessage) && $id && $id > 0) {
        if (!$permService->canEditDocument($editorUserId, $id)) {
            http_response_code(403);
            $errorMessage = "Acesso Negado: Você não possui permissão para editar este documento.";
        } elseif ($subjectId > 0 && !$permService->canCreateDocument($editorUserId, $subjectId)) {
            http_response_code(403);
            $errorMessage = "Acesso Negado: Você não possui permissão no Assunto de destino especificado.";
        }
    }

    $workflowTransition = null;
    if (empty($errorMessage)) {
        try {
            $workflowTransition = $workflowService->prepareAction(
                $workflowAction,
                $previousDocumentStatus,
                $editorUserId,
                $id,
                $workflowNote
            );
            $status = $workflowTransition['status'];
        } catch (Throwable $exception) {
            $errorMessage = $exception->getMessage();
        }
    }

    if (!empty($errorMessage)) {
        // Mantém $errorMessage e ignora o salvamento
    } elseif (empty($titulo) || $subjectId <= 0) {
        $errorMessage = "Por favor, preencha o Título e selecione um Assunto válido.";
    } elseif ($tipoConteudo === 'link' && (empty($linkExterno) || !filter_var($linkExterno, FILTER_VALIDATE_URL))) {
        $errorMessage = "Por favor, informe uma URL válida para o link externo.";
    } elseif ($tipoConteudo === 'video' && $videoSource === 'url' && VideoEmbedService::normalizeExternalUrl($linkExterno) === null) {
        $errorMessage = "Por favor, informe uma URL HTTP ou HTTPS válida para o vídeo.";
    } elseif ($tipoConteudo === 'video' && $videoSource === 'upload' && (!$id || $id <= 0) && (!isset($_FILES['video_file']) || ($_FILES['video_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK)) {
        $errorMessage = "Selecione um arquivo de vídeo para publicar.";
    } elseif ($tipoConteudo === 'code' && trim($codigoFonteRaw) === '') {
        $errorMessage = "Por favor, informe o trecho de código.";
    } elseif ($tipoConteudo === 'code' && strlen($codigoFonteRaw) > 1048576) {
        $errorMessage = "O trecho de código excede o limite de 1 MB.";
    } else {
        try {
            // Tags existentes são validadas antes de qualquer upload. As novas são criadas apenas no salvamento.
            $requestedTagIds = $tagService->assertActiveIds($requestedTagIds);
            $requestedNewTagNames = $tagService->assertNewNames($requestedNewTagNames, count($requestedTagIds));
        } catch (Throwable $exception) {
            $errorMessage = $exception->getMessage();
        }
    }

    if (empty($errorMessage) && !empty($titulo) && $subjectId > 0) {
        $storedFilename = null;
        $originalFilename = null;
        $tipoMime = null;
        $tamanhoBytes = 0;
        $fileExt = null;
        $filePath = null;

        $uploadField = $tipoConteudo === 'video' ? 'video_file' : 'arquivo_file';
        if (in_array($tipoConteudo, ['file', 'video'], true) && isset($_FILES[$uploadField]) && $_FILES[$uploadField]['error'] === UPLOAD_ERR_OK) {
            $file = $_FILES[$uploadField];
            $originalFilename = basename($file['name']);
            $tamanhoBytes = (int)$file['size'];
            $fileExt = strtolower(pathinfo($originalFilename, PATHINFO_EXTENSION));

            $extsPermitidas = [
                'pdf', 'png', 'jpg', 'jpeg', 'webp', 'gif', 'bmp', 'avif',
                'txt', 'log', 'csv', 'md', 'json', 'xml', 'doc', 'docx',
                'mp3', 'wav', 'ogg', 'mp4', 'webm', 'ogv', 'm4v', 'mov'
            ];
            if (!in_array($fileExt, $extsPermitidas)) {
                $errorMessage = "Formato não suportado. Utilize PDF, imagens, textos, DOC/DOCX, áudio ou vídeo.";
            } elseif ($tamanhoBytes > ($tipoConteudo === 'video' ? 50 : 25) * 1024 * 1024) {
                $errorMessage = $tipoConteudo === 'video'
                    ? "O vídeo excede o limite máximo permitido de 50 MB."
                    : "O arquivo excede o limite máximo permitido de 25MB.";
            } else {
                $storedFilename = sprintf('%s_%s.%s', uniqid('doc_'), md5($originalFilename . microtime()), $fileExt);
                $targetDir = DOCGOV_PROJECT_ROOT . '/storage/documents';
                if (!is_dir($targetDir)) {
                    mkdir($targetDir, 0755, true);
                }
                $targetPath = $targetDir . '/' . $storedFilename;

                if (move_uploaded_file($file['tmp_name'], $targetPath)) {
                    $filePath = 'storage/documents/' . $storedFilename;
                    $tipoMime = mime_content_type($targetPath) ?: 'application/octet-stream';
                } else {
                    $errorMessage = "Falha ao salvar o arquivo no servidor.";
                }
            }
        }

        if (empty($errorMessage)) {
            try {
                $pdo->beginTransaction();
                if ($id) {
                    $lockedDocument = $workflowService->lockDocument($id, (string)($_POST['document_version'] ?? $currentDocumentState['updated_at']));
                    if (!$permService->canEditDocument($editorUserId, $id) || !$permService->canCreateDocument($editorUserId, $subjectId)) {
                        throw new RuntimeException('Você não possui permissão para editar este documento.', 403);
                    }
                    $previousDocumentStatus = (string)$lockedDocument['status'];
                    $workflowTransition = $workflowService->prepareAction($workflowAction, $previousDocumentStatus, $editorUserId, $id, $workflowNote);
                    $status = $workflowTransition['status'];
                    if ($workflowTransition['action'] === 'approved_and_published') {
                        $approvalChanges = ['subject_id' => $subjectId, 'title' => $titulo, 'description' => $descricao,
                            'content_type' => $tipoConteudo, 'section_key' => $documentSectionKey,
                            'text_content' => $conteudoArmazenado, 'structured_content' => $structuredContent, 'external_url' => $linkExterno];
                        if ($tipoConteudo === 'code') $approvalChanges['code_language'] = $linguagemCodigo;
                        if ($storedFilename !== null) $approvalChanges['stored_filename'] = $storedFilename;
                        $workflowService->assertReviewedContentUnchanged($lockedDocument, $approvalChanges);
                        $beforeTagIds = array_map('intval', array_column($tagService->getDocumentTags($id), 'id'));
                        $afterTagIds = array_values(array_unique($requestedTagIds));
                        sort($beforeTagIds); sort($afterTagIds);
                        if ($beforeTagIds !== $afterTagIds || array_filter($requestedNewTagNames, static fn($name) => trim($name) !== '')) {
                            throw new DocumentConflictException('As tags foram alteradas. Salve e envie o conteúdo novamente para revisão antes de publicar.', 409);
                        }
                    }
                }
                $slug = $documentSlugService->reserve($subjectId, $titulo, $id ?: null);
                $publishedAt = $status === 'published' ? date(DATE_ATOM) : null;
                $structuredContentJson = $structuredContent !== null
                    ? json_encode($structuredContent, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
                    : null;
                $staleInlineFiles = [];
                if ($id) {
                if ($storedFilename) {
                    $stmt = $pdo->prepare("
                        UPDATE documents SET
                            subject_id = :sub_id, title = :title, slug = :slug, description = :desc,
                            content_type = :type, section_key = :section_key, status = :status,
                            published_at = CASE WHEN CAST(:is_published AS BOOLEAN) THEN COALESCE(:published_at, published_at, CURRENT_TIMESTAMP) ELSE NULL END,
                            approval_expires_at = CASE WHEN CAST(:is_published AS BOOLEAN) THEN NULL ELSE COALESCE(approval_expires_at, CURRENT_TIMESTAMP + INTERVAL '1 month') END,
                            text_content = :text_content,
                            structured_content = CAST(:structured_content AS JSONB),
                            code_language = :code_language, external_url = :url,
                            stored_filename = :stored_name, original_filename = :orig_name, mime_type = :mime,
                            file_extension = :ext, file_size = :size, file_path = :path
                        WHERE id = :id
                    ");
                    $stmt->execute([
                        ':sub_id' => $subjectId, ':title' => $titulo, ':slug' => $slug, ':desc' => $descricao,
                        ':type' => $tipoConteudo, ':section_key' => $documentSectionKey, ':status' => $status, ':published_at' => $publishedAt, ':is_published' => $status === 'published' ? 1 : 0,
                        ':text_content' => $conteudoArmazenado,
                        ':structured_content' => $structuredContentJson,
                        ':code_language' => $linguagemCodigo, ':url' => $linkExterno,
                        ':stored_name' => $storedFilename, ':orig_name' => $originalFilename, ':mime' => $tipoMime,
                        ':ext' => $fileExt, ':size' => $tamanhoBytes, ':path' => $filePath, ':id' => $id
                    ]);
                } else {
                    $stmt = $pdo->prepare("
                        UPDATE documents SET
                            subject_id = :sub_id, title = :title, slug = :slug, description = :desc,
                            content_type = :type, section_key = :section_key, status = :status,
                            published_at = CASE WHEN CAST(:is_published AS BOOLEAN) THEN COALESCE(:published_at, published_at, CURRENT_TIMESTAMP) ELSE NULL END,
                            approval_expires_at = CASE WHEN CAST(:is_published AS BOOLEAN) THEN NULL ELSE COALESCE(approval_expires_at, CURRENT_TIMESTAMP + INTERVAL '1 month') END,
                            text_content = :text_content,
                            structured_content = CAST(:structured_content AS JSONB),
                            code_language = :code_language, external_url = :url
                            " . ($tipoConteudo === 'video' && $videoSource === 'url'
                                ? ', stored_filename = NULL, original_filename = NULL, mime_type = NULL, file_extension = NULL, file_size = NULL, file_path = NULL'
                                : '') . "
                        WHERE id = :id
                    ");
                    $stmt->execute([
                        ':sub_id' => $subjectId, ':title' => $titulo, ':slug' => $slug, ':desc' => $descricao,
                        ':type' => $tipoConteudo, ':section_key' => $documentSectionKey, ':status' => $status, ':published_at' => $publishedAt, ':is_published' => $status === 'published' ? 1 : 0,
                        ':text_content' => $conteudoArmazenado,
                        ':structured_content' => $structuredContentJson,
                        ':code_language' => $linguagemCodigo, ':url' => $linkExterno,
                        ':id' => $id
                    ]);
                }
                if ($tipoConteudo === 'text') {
                    $inlineMediaService->bindReferenced($id, $editorUserId, (string)$conteudoArmazenado);
                }
                $staleInlineFiles = $inlineMediaService->removeUnreferenced($id, $tipoConteudo === 'text' ? (string)$conteudoArmazenado : '');
                $resolvedTagIds = $tagService->resolveForDocument($requestedTagIds, $requestedNewTagNames, $editorUserId);
                $tagService->syncDocumentTags($id, $resolvedTagIds);
                $workflowService->applyTransitionMetadata($id, $editorUserId, $workflowTransition['action'], $workflowTransition['note']);
                $workflowService->record($id, $editorUserId, $workflowTransition['action'], $previousDocumentStatus, $status, $workflowTransition['note']);
                $pdo->commit();
                $usageAuditService->logAdminAction($editorUserId, 'document_updated', 'DOCUMENT', $id);
                $workflowService->notifyAfterCommit($id, $editorUserId, $workflowTransition['action']);
                foreach ($staleInlineFiles as $staleInlineFile) {
                    $inlineMediaService->removeStoredFile($staleInlineFile);
                }
                header('Location: index.php?tab=detalhes_documento&id=' . $id . '&msg=doc_updated');
                exit;
                } else {
                $stmt = $pdo->prepare("
                    INSERT INTO documents (
                        subject_id, created_by, title, slug, description, content_type, section_key, status, published_at,
                        original_filename, stored_filename, file_path, mime_type, file_extension, file_size,
                        text_content, structured_content, code_language, external_url
                    ) VALUES (
                        :sub_id, :created_by, :title, :slug, :desc, :type, :section_key, :status, :published_at,
                        :orig_name, :stored_name, :path, :mime, :ext, :size,
                        :text_content, CAST(:structured_content AS JSONB), :code_language, :url
                    ) RETURNING id
                ");
                $stmt->execute([
                    ':sub_id' => $subjectId, ':created_by' => (int)$loggedUser['id'], ':title' => $titulo, ':slug' => $slug,
                    ':desc' => $descricao, ':type' => $tipoConteudo, ':section_key' => $documentSectionKey, ':status' => $status, ':published_at' => $publishedAt,
                    ':orig_name' => $originalFilename, ':stored_name' => $storedFilename, ':path' => $filePath,
                    ':mime' => $tipoMime, ':ext' => $fileExt, ':size' => $tamanhoBytes,
                    ':text_content' => $conteudoArmazenado, ':structured_content' => $structuredContentJson, ':code_language' => $linguagemCodigo, ':url' => $linkExterno
                ]);
                $newId = (int)$stmt->fetchColumn();
                if ($tipoConteudo === 'text') {
                    $inlineMediaService->bindReferenced($newId, $editorUserId, (string)$conteudoArmazenado);
                }
                $resolvedTagIds = $tagService->resolveForDocument($requestedTagIds, $requestedNewTagNames, $editorUserId);
                $tagService->syncDocumentTags($newId, $resolvedTagIds);
                if ($newId <= 0) throw new RuntimeException('Não foi possível criar o documento.');
                $workflowService->applyTransitionMetadata($newId, $editorUserId, $workflowTransition['action'], $workflowTransition['note']);
                $workflowService->record($newId, $editorUserId, $workflowTransition['action'], 'draft', $status, $workflowTransition['note']);
                $pdo->commit();
                $usageAuditService->logAdminAction($editorUserId, 'document_created', 'DOCUMENT', $newId);
                $workflowService->notifyAfterCommit($newId, $editorUserId, $workflowTransition['action']);
                header('Location: index.php?tab=detalhes_documento&id=' . $newId . '&msg=doc_created');
                exit;
                }
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                if ($storedFilename !== null) {
                    $uploadedPath = DOCGOV_PROJECT_ROOT . '/storage/documents/' . basename($storedFilename);
                    if (is_file($uploadedPath)) {
                        @unlink($uploadedPath);
                    }
                }
                $isDocumentSlugConflict = $exception instanceof PDOException
                    && (string)$exception->getCode() === '23505'
                    && str_contains((string)($exception->errorInfo[2] ?? ''), 'uk_documents_subject_slug');
                $failedDocumentSubmission = ['id' => $id, 'titulo' => $titulo, 'descricao' => $descricao,
                    'tipo_conteudo' => $tipoConteudo, 'section_key' => $documentSectionKey,
                    'conteudo_html' => $conteudoArmazenado, 'structured_content' => $structuredContent !== null ? json_encode($structuredContent, JSON_THROW_ON_ERROR) : null,
                    'linguagem_codigo' => $linguagemCodigo, 'link_externo' => $linkExterno,
                    'assunto_id' => $subjectId, 'subcategoria_id' => (int)($subjectContext['subcategory_id'] ?? $subInput),
                    'categoria_id' => (int)($subjectContext['category_id'] ?? $catInput),
                    'document_version' => (string)($_POST['document_version'] ?? $currentDocumentState['updated_at'] ?? '')];
                if ($exception instanceof DocumentConflictException) {
                    http_response_code(409);
                    $errorMessage = $exception->getMessage();
                } elseif ($isDocumentSlugConflict) {
                    // Proteção de última linha caso outro processo externo grave
                    // sem passar pelo serviço transacional de slugs.
                    $errorMessage = 'Já existe um conteúdo com este endereço no assunto. Tente salvar novamente para gerar um novo endereço.';
                } elseif ($exception instanceof InvalidArgumentException) {
                    http_response_code(422);
                    $errorMessage = $exception->getMessage();
                } else {
                    error_log('DocGov: falha ao salvar documento: ' . $exception->getMessage());
                    $errorMessage = 'Não foi possível salvar o documento. Nenhuma alteração parcial foi mantida.';
                }
            }
        }
    }
}
