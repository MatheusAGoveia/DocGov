<?php

declare(strict_types=1);

require_once __DIR__ . '/PermissionService.php';
require_once __DIR__ . '/VideoEmbedService.php';
require_once __DIR__ . '/DocumentWorkflowService.php';
require_once __DIR__ . '/DocumentSectionService.php';
require_once __DIR__ . '/DocumentSlugService.php';
require_once __DIR__ . '/RichTextSanitizer.php';
require_once __DIR__ . '/StructuredContentService.php';

/** Salvamento compartilhado entre a API de sessão e a API de integração. */
final class DocumentWriteService
{
    public function __construct(private PDO $pdo) {}

    /** @return array{id:int,title:string,slug:string,status:string,document_version:string} */
    public function save(int $userId, array $input, array $files = []): array
    {
        $pdo = $this->pdo;
        $permService = new PermissionService($pdo);
        $workflowService = new DocumentWorkflowService($pdo, $permService);
        $documentSectionService = new DocumentSectionService($pdo);
        $documentSlugService = new DocumentSlugService($pdo);
        foreach ($input as $field => $value) {
            if ($field !== 'structured_content' && !is_scalar($value) && $value !== null) {
                throw new RuntimeException('Campo inválido: ' . $field, 422);
            }
        }
        $docId = (int)($input['id'] ?? $input['document_id'] ?? 0);
        $subjectId = (int)($input['subject_id'] ?? $input['assunto_id'] ?? 0);

        if ($docId > 0) {
            if (!$permService->canEditDocument($userId, $docId)) {
                throw new RuntimeException('Acesso negado. Você não possui permissão para editar este documento.', 403);
            }
            if ($subjectId > 0 && !$permService->canCreateDocument($userId, $subjectId)) {
                throw new RuntimeException('Acesso negado. Você não possui permissão no assunto especificado.', 403);
            }
        } else {
            if ($subjectId <= 0 || !$permService->canCreateDocument($userId, $subjectId)) {
                throw new RuntimeException('Acesso negado. Você não possui permissão para criar documentos neste assunto.', 403);
            }
        }

        $previousStatus = null;
        $previousDocument = null;
        if ($docId > 0) {
            $stmtPrevious = $pdo->prepare('SELECT status, subject_id, section_key, content_type, updated_at, trashed_at FROM documents WHERE id = :id');
            $stmtPrevious->execute([':id' => $docId]);
            $previousDocument = $stmtPrevious->fetch(PDO::FETCH_ASSOC) ?: null;
            if (!$previousDocument) {
                throw new RuntimeException('Documento não encontrado.', 404);
            }
            $previousStatus = $previousDocument['status'] ?? null;
            if ($previousDocument['trashed_at'] !== null) {
                throw new RuntimeException('Documento na lixeira não pode ser editado.', 409);
            }
            $this->assertWritableSubject($permService, $userId, (int)$previousDocument['subject_id']);
            if ($subjectId <= 0 && $previousDocument) {
                $subjectId = (int)$previousDocument['subject_id'];
            }
        }

        $this->assertWritableSubject($permService, $userId, $subjectId);
        $title = trim($input['title'] ?? $input['titulo'] ?? '');
        $description = trim($input['description'] ?? $input['descricao'] ?? '');
        $legacyContentType = trim($input['content_type'] ?? $input['tipo_conteudo'] ?? ($previousDocument['content_type'] ?? 'file'));
        $sectionKey = strtolower(trim((string)($input['section_key'] ?? ($previousDocument['section_key'] ?? ''))));
        if ($sectionKey === '') {
            $sectionKey = $documentSectionService->defaultSectionForContentType($legacyContentType);
        }
        try {
            $sectionSelection = $documentSectionService->resolveSelection($sectionKey);
            $contentType = $sectionSelection['content_type'];
        } catch (Throwable $exception) {
            throw new RuntimeException($exception->getMessage(), 422);
        }
        $legacyStatus = trim($input['status'] ?? 'draft');
        $workflowAction = trim($input['workflow_action'] ?? '');
        if ($workflowAction === '') {
            $workflowAction = match ($legacyStatus) {
                'published', 'review' => 'submit_review',
                'inactive' => 'archive',
                default => 'save_draft',
            };
        }
        $workflowNote = trim($input['workflow_note'] ?? '');
        $textContent = str_replace(["\r\n", "\r"], "\n", (string)($input['text_content'] ?? $input['conteudo_html'] ?? $input['codigo_fonte'] ?? ''));
        $structuredContent = null;
        $codeLanguage = strtolower(trim($input['code_language'] ?? $input['linguagem_codigo'] ?? 'auto'));
        $externalUrl = trim($input['external_url'] ?? $input['link_externo'] ?? '');
        $videoSource = trim($input['video_source'] ?? 'upload');
        $videoUrl = trim($input['video_url'] ?? '');

        if (!in_array($videoSource, ['upload', 'url'], true)) {
            $videoSource = 'upload';
        }
        if ($contentType === 'video' && $videoSource === 'url') {
            $externalUrl = $videoUrl;
        }

        if (mb_strlen($title) > 255) {
            throw new RuntimeException('O título deve ter até 255 caracteres.', 422);
        }
        if (empty($title)) {
            throw new RuntimeException('O título do documento é obrigatório.', 422);
        }
        if ($contentType === 'link' && (empty($externalUrl) || !filter_var($externalUrl, FILTER_VALIDATE_URL))) {
            throw new RuntimeException('Informe uma URL externa válida para o tipo Link.', 422);
        }
        $apiVideoUpload = $files['video_file'] ?? $files['file'] ?? null;
        if ($contentType === 'video' && $videoSource === 'url' && VideoEmbedService::normalizeExternalUrl($externalUrl) === null) {
            throw new RuntimeException('Informe uma URL HTTP ou HTTPS válida para o vídeo.', 422);
        }
        if ($contentType === 'video' && $videoSource === 'upload' && $docId <= 0 && (!is_array($apiVideoUpload) || ($apiVideoUpload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK)) {
            throw new RuntimeException('Selecione um arquivo de vídeo para publicar.', 422);
        }
        if ($contentType === 'code' && trim($textContent) === '') {
            throw new RuntimeException('Informe o trecho de código.', 422);
        }
        if ($contentType === 'code' && strlen($textContent) > 1048576) {
            throw new RuntimeException('O trecho de código excede o limite de 1 MB.', 422);
        }

        $allowedCodeLanguages = [
            'auto', 'plaintext', 'javascript', 'typescript', 'xml', 'css', 'php', 'python',
            'sql', 'bash', 'json', 'java', 'csharp', 'cpp', 'go', 'yaml', 'markdown'
        ];
        if (!in_array($codeLanguage, $allowedCodeLanguages, true)) {
            $codeLanguage = 'auto';
        }
        if ($contentType === 'text') {
            try {
                $textContent = RichTextSanitizer::sanitize($textContent);
            } catch (Throwable $exception) {
                throw new RuntimeException($exception->getMessage(), 422);
            }
        } elseif (in_array($contentType, ['flow', 'orgchart'], true)) {
            try {
                $structuredContent = json_encode(
                    StructuredContentService::normalize($contentType, $input['structured_content'] ?? ''),
                    JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                );
            } catch (Throwable $exception) {
                throw new RuntimeException($exception->getMessage(), 422);
            }
            $textContent = '';
        }

        try {
            $workflowTransition = $workflowService->prepareAction($workflowAction, $previousStatus === false ? null : $previousStatus, $userId, $docId ?: null, $workflowNote);
            $status = $workflowTransition['status'];
        } catch (Throwable $exception) {
            throw new RuntimeException($exception->getMessage(), 422);
        }

        $slug = '';
        $publishedAt = $status === 'published' ? date(DATE_ATOM) : null;
        $originalFilename = null;
        $storedFilename = null;
        $filePath = null;
        $mimeType = null;
        $fileExtension = null;
        $fileSize = 0;

        $apiUpload = $contentType === 'video' ? $apiVideoUpload : ($files['file'] ?? null);
        if ($contentType === 'file' && $docId === 0 && (!is_array($apiUpload) || ($apiUpload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK)) {
            throw new RuntimeException('Selecione o arquivo da publicação.', 422);
        }
        if (in_array($contentType, ['file', 'video'], true) && is_array($apiUpload) && ($apiUpload['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
            $file = $apiUpload;
            $originalFilename = basename($file['name']);
            $fileSize = (int)$file['size'];
            $fileExtension = strtolower(pathinfo($originalFilename, PATHINFO_EXTENSION));
            $allowedFileExtensions = [
                'pdf', 'png', 'jpg', 'jpeg', 'webp', 'gif', 'bmp', 'avif',
                'txt', 'log', 'csv', 'md', 'json', 'xml', 'doc', 'docx',
                'mp3', 'wav', 'ogg', 'mp4', 'webm', 'ogv', 'm4v', 'mov'
            ];
            if (!in_array($fileExtension, $allowedFileExtensions, true)) {
                throw new RuntimeException('Formato de arquivo não suportado.', 422);
            }
            if ($fileSize > ($contentType === 'video' ? 50 : 25) * 1024 * 1024) {
                throw new RuntimeException($contentType === 'video' ? 'O vídeo excede o limite máximo de 50 MB.' : 'O arquivo excede o limite máximo de 25 MB.', 422);
            }
            $storedFilename = md5(uniqid(microtime(), true)) . '.' . $fileExtension;

            $targetDir = __DIR__ . '/../storage/documents';
            if (!is_dir($targetDir)) {
                mkdir($targetDir, 0755, true);
            }
            $targetPath = $targetDir . '/' . $storedFilename;

            if (move_uploaded_file($file['tmp_name'], $targetPath)) {
                $filePath = 'storage/documents/' . $storedFilename;
                $mimeType = mime_content_type($targetPath) ?: 'application/octet-stream';
            } else {
                throw new RuntimeException('Erro ao salvar o arquivo no armazenamento do servidor.', 422);
            }
        }

        try {
            $params = [
                ':subject_id' => $subjectId,
                ':created_by' => $userId > 0 ? $userId : null,
                ':title' => $title,
                ':slug' => $slug,
                ':description' => $description,
                ':content_type' => $contentType,
                ':section_key' => $sectionKey,
                ':structured_content' => $structuredContent,
                ':status' => $status,
                ':published_at' => $publishedAt,
                ':is_published' => $status === 'published' ? 1 : 0,
                ':original_filename' => $originalFilename,
                ':stored_filename' => $storedFilename,
                ':file_path' => $filePath,
                ':mime_type' => $mimeType,
                ':file_extension' => $fileExtension,
                ':file_size' => $fileSize,
                ':text_content' => $textContent,
                ':code_language' => $codeLanguage,
                ':external_url' => $externalUrl
            ];

            if ($docId > 0) {
                $fileAssignments = '';
                if ($storedFilename !== null) {
                    $fileAssignments = ', original_filename = :original_filename, stored_filename = :stored_filename,
                        file_path = :file_path, mime_type = :mime_type, file_extension = :file_extension, file_size = :file_size';
                } else {
                    $clearLocalVideo = $contentType === 'video' && $videoSource === 'url';
                    unset(
                        $params[':original_filename'], $params[':stored_filename'], $params[':file_path'],
                        $params[':mime_type'], $params[':file_extension'], $params[':file_size']
                    );
                    $fileAssignments = $clearLocalVideo
                        ? ', original_filename = NULL, stored_filename = NULL, file_path = NULL, mime_type = NULL, file_extension = NULL, file_size = NULL'
                        : '';
                }
                unset($params[':created_by']);
                $params[':id'] = $docId;

                $stmt = $pdo->prepare("
                    UPDATE documents SET
                        subject_id = :subject_id, title = :title, slug = :slug, description = :description,
                        content_type = :content_type, section_key = :section_key,
                        structured_content = CAST(:structured_content AS JSONB), status = :status,
                        published_at = CASE WHEN CAST(:is_published AS BOOLEAN) THEN COALESCE(:published_at, published_at, CURRENT_TIMESTAMP) ELSE NULL END,
                        approval_expires_at = CASE WHEN CAST(:is_published AS BOOLEAN) THEN NULL ELSE COALESCE(approval_expires_at, CURRENT_TIMESTAMP + INTERVAL '1 month') END,
                        text_content = :text_content, code_language = :code_language, external_url = :external_url
                        $fileAssignments
                    WHERE id = :id
                    RETURNING id
                ");
            } else {
                unset($params[':is_published']);
                $stmt = $pdo->prepare("
                    INSERT INTO documents (
                        subject_id, created_by, title, slug, description, content_type, section_key, structured_content,
                        status, published_at,
                        original_filename, stored_filename, file_path, mime_type, file_extension, file_size,
                        text_content, code_language, external_url
                    ) VALUES (
                        :subject_id, :created_by, :title, :slug, :description, :content_type, :section_key,
                        CAST(:structured_content AS JSONB), :status, :published_at,
                        :original_filename, :stored_filename, :file_path, :mime_type, :file_extension, :file_size,
                        :text_content, :code_language, :external_url
                    ) RETURNING id
                ");
            }

            $pdo->beginTransaction();
            $this->assertWritableSubject($permService, $userId, $subjectId);
            if ($docId > 0) {
                $lockedDocument = $workflowService->lockDocument($docId, (string)($input['document_version'] ?? $previousDocument['updated_at']));
                if ($lockedDocument['trashed_at'] !== null) {
                    throw new RuntimeException('Documento na lixeira não pode ser editado.', 409);
                }
                $this->assertWritableSubject($permService, $userId, (int)$lockedDocument['subject_id']);
                if (!$permService->canEditDocument($userId, $docId) || !$permService->canCreateDocument($userId, $subjectId)) {
                    throw new RuntimeException('Você não possui permissão para editar este documento.', 403);
                }
                $previousStatus = (string)$lockedDocument['status'];
                $workflowTransition = $workflowService->prepareAction($workflowAction, $previousStatus, $userId, $docId, $workflowNote);
                $status = $workflowTransition['status'];
                $params[':status'] = $status;
                $params[':is_published'] = $status === 'published' ? 1 : 0;
                $params[':published_at'] = $status === 'published' ? date(DATE_ATOM) : null;
                if ($workflowTransition['action'] === 'approved_and_published') {
                    $approvalChanges = ['subject_id' => $subjectId, 'title' => $title, 'description' => $description,
                        'content_type' => $contentType, 'section_key' => $sectionKey, 'text_content' => $textContent,
                        'structured_content' => $structuredContent, 'external_url' => $externalUrl];
                    if ($contentType === 'code') $approvalChanges['code_language'] = $codeLanguage;
                    if ($storedFilename !== null) $approvalChanges['stored_filename'] = $storedFilename;
                    $workflowService->assertReviewedContentUnchanged($lockedDocument, $approvalChanges);
                }
            }
            $slug = $documentSlugService->reserve($subjectId, $title, $docId > 0 ? $docId : null);
            $params[':slug'] = $slug;
            $stmt->execute($params);
            $newId = (int)$stmt->fetchColumn();
            if ($newId <= 0) throw new RuntimeException('Documento não encontrado.', 404);
            $workflowService->applyTransitionMetadata($newId, $userId, $workflowTransition['action'], $workflowTransition['note']);
            $workflowService->record($newId, $userId, $workflowTransition['action'], $previousStatus ?: 'draft', $status, $workflowTransition['note']);
            $versionStmt = $pdo->prepare('SELECT updated_at FROM documents WHERE id = :id');
            $versionStmt->execute([':id' => $newId]);
            $documentVersion = (string)$versionStmt->fetchColumn();
            $pdo->commit();
            $workflowService->notifyAfterCommit($newId, $userId, $workflowTransition['action']);

            return ['id' => $newId, 'title' => $title, 'slug' => $slug, 'status' => $status, 'document_version' => $documentVersion];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($filePath !== null && is_file(__DIR__ . '/../' . $filePath)) {
                unlink(__DIR__ . '/../' . $filePath);
            }
            throw $e;
        }
    }

    private function assertWritableSubject(PermissionService $permissions, int $userId, int $subjectId): void
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM subjects s JOIN subcategories sc ON sc.id = s.subcategory_id JOIN categories c ON c.id = sc.category_id WHERE s.id = ? AND s.active = TRUE AND sc.active = TRUE AND c.active = TRUE');
        $stmt->execute([$subjectId]);
        if (!$stmt->fetchColumn() || !$permissions->canCreateDocument($userId, $subjectId)) {
            throw new RuntimeException('Assunto indisponível ou sem permissão de edição.', 403);
        }
    }
}
