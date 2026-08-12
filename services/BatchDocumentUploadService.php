<?php

require_once __DIR__ . '/PermissionService.php';
require_once __DIR__ . '/DocumentWorkflowService.php';
require_once __DIR__ . '/UsageAuditService.php';
require_once __DIR__ . '/TagService.php';

/** Cria documentos a partir de uma fila de arquivos, com validação integral. */
final class BatchDocumentUploadService
{
    private const MAX_FILES = 20;
    private const MAX_TOTAL_BYTES = 500 * 1024 * 1024;
    private const VIDEO_EXTENSIONS = ['mp4', 'webm', 'ogv', 'm4v', 'mov'];
    private const ALLOWED_EXTENSIONS = [
        'pdf', 'png', 'jpg', 'jpeg', 'webp', 'gif', 'bmp', 'avif',
        'txt', 'log', 'csv', 'md', 'json', 'xml', 'doc', 'docx',
        'mp3', 'wav', 'ogg', 'mp4', 'webm', 'ogv', 'm4v', 'mov',
    ];

    public function __construct(
        private PDO $pdo,
        private PermissionService $permissionService,
        private DocumentWorkflowService $workflowService,
        private UsageAuditService $usageAuditService,
        private TagService $tagService,
        private string $projectRoot,
    ) {
    }

    /**
     * @return array<int, array{id:int,title:string,type:string,filename:string}>
     */
    public function create(
        int $actorId,
        int $subjectId,
        string $description,
        string $workflowAction,
        string $workflowNote,
        array $uploadInput,
        array $requestedTitles,
        array $tagIds = [],
        array $newTagNames = [],
    ): array {
        if ($actorId <= 0 || $subjectId <= 0 || !$this->permissionService->canCreateDocument($actorId, $subjectId)) {
            throw new RuntimeException('Você não possui permissão para criar documentos neste assunto.');
        }

        $workflow = $this->workflowService->prepareAction($workflowAction, null, $actorId, null, $workflowNote);
        $files = $this->normaliseFiles($uploadInput, $requestedTitles);
        $storageDirectory = rtrim(str_replace('\\', '/', $this->projectRoot), '/') . '/storage/documents';
        if (!is_dir($storageDirectory) && !mkdir($storageDirectory, 0755, true) && !is_dir($storageDirectory)) {
            throw new RuntimeException('Não foi possível preparar o armazenamento dos arquivos.');
        }

        $storedFiles = [];
        $createdDocuments = [];
        try {
            foreach ($files as $index => $file) {
                $storedFilename = 'doc_' . bin2hex(random_bytes(16)) . '.' . $file['extension'];
                $targetPath = $storageDirectory . '/' . $storedFilename;
                if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
                    throw new RuntimeException('Não foi possível salvar “' . $file['original_filename'] . '”.');
                }

                $files[$index]['stored_filename'] = $storedFilename;
                $files[$index]['relative_path'] = 'storage/documents/' . $storedFilename;
                $files[$index]['mime_type'] = mime_content_type($targetPath) ?: 'application/octet-stream';
                $storedFiles[] = $targetPath;
            }

            $this->pdo->beginTransaction();
            $resolvedTagIds = $this->tagService->resolveForDocument($tagIds, $newTagNames, $actorId);
            $insert = $this->pdo->prepare('
                INSERT INTO documents (
                    subject_id, created_by, title, slug, description, content_type, status, published_at,
                    original_filename, stored_filename, file_path, mime_type, file_extension, file_size,
                    text_content, code_language, external_url
                ) VALUES (
                    :subject_id, :created_by, :title, :slug, :description, :content_type, :status, NULL,
                    :original_filename, :stored_filename, :file_path, :mime_type, :file_extension, :file_size,
                    NULL, :code_language, NULL
                ) RETURNING id
            ');

            foreach ($files as $file) {
                $insert->execute([
                    ':subject_id' => $subjectId,
                    ':created_by' => $actorId,
                    ':title' => $file['title'],
                    ':slug' => slugify($file['title']),
                    ':description' => $description,
                    ':content_type' => $file['content_type'],
                    ':status' => $workflow['status'],
                    ':original_filename' => $file['original_filename'],
                    ':stored_filename' => $file['stored_filename'],
                    ':file_path' => $file['relative_path'],
                    ':mime_type' => $file['mime_type'],
                    ':file_extension' => $file['extension'],
                    ':file_size' => $file['size'],
                    ':code_language' => 'auto',
                ]);
                $documentId = (int)$insert->fetchColumn();
                $this->tagService->syncDocumentTags($documentId, $resolvedTagIds);
                $this->workflowService->applyTransitionMetadata($documentId, $actorId, $workflow['action'], $workflow['note']);
                $this->workflowService->record($documentId, $actorId, $workflow['action'], 'draft', $workflow['status'], $workflow['note']);
                $createdDocuments[] = [
                    'id' => $documentId,
                    'title' => $file['title'],
                    'type' => $file['content_type'],
                    'filename' => $file['original_filename'],
                ];
            }
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            foreach ($storedFiles as $storedFile) {
                if (is_file($storedFile)) {
                    unlink($storedFile);
                }
            }
            throw $exception;
        }

        foreach ($createdDocuments as $document) {
            try {
                $this->workflowService->notifyForTransition($document['id'], $actorId, $workflow['action']);
                $this->usageAuditService->logAdminAction($actorId, 'document_created_batch', 'DOCUMENT', $document['id']);
            } catch (Throwable $exception) {
                error_log('DocGov batch upload: falha pós-criação do documento ' . $document['id'] . ': ' . $exception->getMessage());
            }
        }

        return $createdDocuments;
    }

    /** @return array<int, array<string, mixed>> */
    private function normaliseFiles(array $uploadInput, array $requestedTitles): array
    {
        $names = $uploadInput['name'] ?? null;
        if (!is_array($names) || $names === []) {
            throw new InvalidArgumentException('Selecione ao menos um arquivo para o envio em lote.');
        }
        if (count($names) > self::MAX_FILES) {
            throw new InvalidArgumentException('Envie no máximo ' . self::MAX_FILES . ' arquivos por vez.');
        }

        $files = [];
        $totalBytes = 0;
        foreach ($names as $index => $rawName) {
            $error = (int)($uploadInput['error'][$index] ?? UPLOAD_ERR_NO_FILE);
            if ($error !== UPLOAD_ERR_OK) {
                throw new InvalidArgumentException('Não foi possível receber um dos arquivos da fila.');
            }

            $tmpName = (string)($uploadInput['tmp_name'][$index] ?? '');
            $originalFilename = basename(str_replace('\\', '/', (string)$rawName));
            $size = (int)($uploadInput['size'][$index] ?? 0);
            if ($originalFilename === '' || $size <= 0 || !is_uploaded_file($tmpName)) {
                throw new InvalidArgumentException('Um dos arquivos enviados é inválido ou está vazio.');
            }

            $extension = strtolower(pathinfo($originalFilename, PATHINFO_EXTENSION));
            if (!in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
                throw new InvalidArgumentException('Formato não suportado: ' . $originalFilename . '.');
            }
            $isVideo = in_array($extension, self::VIDEO_EXTENSIONS, true);
            $limit = ($isVideo ? 250 : 25) * 1024 * 1024;
            if ($size > $limit) {
                throw new InvalidArgumentException('O arquivo “' . $originalFilename . '” excede o limite permitido.');
            }
            $totalBytes += $size;
            if ($totalBytes > self::MAX_TOTAL_BYTES) {
                throw new InvalidArgumentException('A fila excede o limite total de 500 MB.');
            }

            $requestedTitle = trim((string)($requestedTitles[$index] ?? ''));
            $derivedTitle = trim((string)pathinfo($originalFilename, PATHINFO_FILENAME));
            $title = $requestedTitle !== '' ? $requestedTitle : $derivedTitle;
            $title = preg_replace('/[ _-]+/u', ' ', $title) ?: $derivedTitle;
            $title = trim(mb_substr($title, 0, 255));
            if ($title === '') {
                throw new InvalidArgumentException('Não foi possível gerar o título de “' . $originalFilename . '”.');
            }

            $files[] = [
                'tmp_name' => $tmpName,
                'original_filename' => $originalFilename,
                'size' => $size,
                'extension' => $extension,
                'title' => $title,
                'content_type' => $isVideo ? 'video' : 'file',
            ];
        }

        return $files;
    }
}
