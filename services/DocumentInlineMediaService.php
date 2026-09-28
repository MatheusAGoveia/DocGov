<?php

declare(strict_types=1);

/** Guarda imagens de artigos fora da pasta pública e vincula-as no salvamento. */
final class DocumentInlineMediaService
{
    public const MAX_BYTES = 10 * 1024 * 1024;
    private const MIME_EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
    ];

    public function __construct(private PDO $pdo, private string $projectRoot)
    {
    }

    /** @return array{id:int,url:string} */
    public function upload(array $file, int $userId): array
    {
        $uploadError = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if (in_array($uploadError, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
            throw new InvalidArgumentException('A imagem excede o limite de upload configurado no PHP. Reduza o arquivo ou ajuste o servidor.');
        }
        if ($userId <= 0 || $uploadError !== UPLOAD_ERR_OK) {
            throw new InvalidArgumentException('Selecione uma imagem válida para inserir no documento.');
        }
        $size = (int)($file['size'] ?? 0);
        $temporary = (string)($file['tmp_name'] ?? '');
        if ($size <= 0 || $size > self::MAX_BYTES || !is_uploaded_file($temporary)) {
            throw new InvalidArgumentException('A imagem deve ter no máximo 10 MB. Verifique também o limite de upload do PHP.');
        }
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($temporary);
        $dimensions = @getimagesize($temporary);
        if (!isset(self::MIME_EXTENSIONS[$mime]) || $dimensions === false
            || ($dimensions['mime'] ?? '') !== $mime
            || $dimensions[0] > 8000 || $dimensions[1] > 8000) {
            throw new InvalidArgumentException('Use uma imagem JPEG, PNG, GIF ou WebP válida, com até 8000 px por lado.');
        }

        $directory = $this->storageDirectory();
        if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
            throw new RuntimeException('Não foi possível preparar o armazenamento de imagens.');
        }
        $storedName = bin2hex(random_bytes(20)) . '.' . self::MIME_EXTENSIONS[$mime];
        $target = $directory . DIRECTORY_SEPARATOR . $storedName;
        if (!move_uploaded_file($temporary, $target)) {
            throw new RuntimeException('Não foi possível salvar a imagem no servidor.');
        }
        try {
            $original = mb_substr(basename(str_replace('\\', '/', (string)($file['name'] ?? 'imagem'))), 0, 255);
            $stmt = $this->pdo->prepare('INSERT INTO document_inline_media (uploaded_by, stored_filename, original_filename, mime_type, file_size) VALUES (?, ?, ?, ?, ?) RETURNING id');
            $stmt->execute([$userId, $storedName, $original, $mime, $size]);
            $id = (int)$stmt->fetchColumn();
            return ['id' => $id, 'url' => '../document-media.php?id=' . $id];
        } catch (Throwable $exception) {
            @unlink($target);
            throw $exception;
        }
    }

    /**
     * Deve rodar na mesma transação que cria/edita o documento.
     * Imagens de outro documento ou upload de outro usuário não podem ser vinculadas.
     */
    public function bindReferenced(int $documentId, int $userId, string $html): void
    {
        $ids = self::referencedIds($html);
        if ($ids === []) return;
        if (count($ids) > 50) {
            throw new InvalidArgumentException('Um documento pode conter até 50 imagens.');
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->pdo->prepare("SELECT id, document_id, uploaded_by FROM document_inline_media WHERE id IN ($placeholders) FOR UPDATE");
        $stmt->execute($ids);
        $media = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (count($media) !== count($ids)) {
            throw new InvalidArgumentException('Uma imagem do conteúdo não está mais disponível. Insira-a novamente.');
        }
        foreach ($media as $row) {
            $ownerDocument = (int)($row['document_id'] ?? 0);
            if ($ownerDocument === $documentId) continue;
            if ($ownerDocument !== 0 || (int)($row['uploaded_by'] ?? 0) !== $userId) {
                throw new InvalidArgumentException('Uma imagem do conteúdo pertence a outro documento ou usuário.');
            }
            $bind = $this->pdo->prepare('UPDATE document_inline_media SET document_id = ? WHERE id = ? AND document_id IS NULL');
            $bind->execute([$documentId, (int)$row['id']]);
        }
    }

    /** Exclui vínculos removidos do artigo; apagar os arquivos só após o commit. @return list<string> */
    public function removeUnreferenced(int $documentId, string $html): array
    {
        $keep = self::referencedIds($html);
        $sql = 'DELETE FROM document_inline_media WHERE document_id = ?';
        $parameters = [$documentId];
        if ($keep !== []) {
            $sql .= ' AND id NOT IN (' . implode(',', array_fill(0, count($keep), '?')) . ')';
            array_push($parameters, ...$keep);
        }
        $stmt = $this->pdo->prepare($sql . ' RETURNING stored_filename');
        $stmt->execute($parameters);
        return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /** @return list<int> */
    public static function referencedIds(string $html): array
    {
        if ($html === '') return [];
        $dom = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8" ?><div>' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $ids = [];
        foreach ($dom->getElementsByTagName('img') as $image) {
            $source = (string)$image->getAttribute('src');
            if (preg_match('~^(?:\.\./)?document-media\.php\?id=([1-9][0-9]*)$~', $source, $match)) {
                $ids[(int)$match[1]] = (int)$match[1];
            }
        }
        return array_values($ids);
    }

    /** @return list<string> */
    public function pathsForDocuments(array $documentIds): array
    {
        $documentIds = array_values(array_unique(array_filter(array_map('intval', $documentIds))));
        if ($documentIds === []) return [];
        $stmt = $this->pdo->prepare('SELECT stored_filename FROM document_inline_media WHERE document_id IN (' . implode(',', array_fill(0, count($documentIds), '?')) . ')');
        $stmt->execute($documentIds);
        return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    public function removeStoredFile(string $storedName): void
    {
        if (!preg_match('/^[a-f0-9]{40}\.(?:jpg|png|gif|webp)$/', $storedName)) return;
        $path = $this->storageDirectory() . DIRECTORY_SEPARATOR . $storedName;
        if (is_file($path)) @unlink($path);
    }

    public function storageDirectory(): string
    {
        return rtrim($this->projectRoot, '/\\') . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'document-media';
    }

    /** Remove uploads nunca utilizados após um dia; mantém as imagens vinculadas. */
    public function pruneAbandoned(): void
    {
        $stmt = $this->pdo->query("DELETE FROM document_inline_media WHERE document_id IS NULL AND created_at < CURRENT_TIMESTAMP - INTERVAL '1 day' RETURNING stored_filename");
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $storedName) {
            $this->removeStoredFile((string)$storedName);
        }
    }
}
