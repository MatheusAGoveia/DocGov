<?php

final class CategoryImageService
{
    private const MAX_BYTES = 3 * 1024 * 1024;
    private const MIME_TYPES = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    private string $projectRoot;
    public function __construct(string $projectRoot)
    {
        $this->projectRoot = rtrim(str_replace('\\', '/', $projectRoot), '/');
    }

    public function validate(?array $file, string $resourceLabel = 'categoria'): ?string
    {
        if ($file === null || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return null;
        }

        if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
            return 'Não foi possível receber a imagem da ' . $resourceLabel . '.';
        }

        if (!isset($file['tmp_name'], $file['size']) || !is_uploaded_file((string)$file['tmp_name'])) {
            return 'O arquivo enviado para a ' . $resourceLabel . ' é inválido.';
        }

        if ((int)$file['size'] > self::MAX_BYTES) {
            return 'A imagem da ' . $resourceLabel . ' deve ter no máximo 3 MB.';
        }

        $mime = $this->getMimeType((string)$file['tmp_name']);
        if (!isset(self::MIME_TYPES[$mime])) {
            return 'Use uma imagem JPG, PNG ou WEBP para a ' . $resourceLabel . '.';
        }

        return null;
    }

    public function store(array $file, int $categoryId): string
    {
        return $this->storeFor($file, $categoryId, 'category');
    }

    public function storeFor(array $file, int $resourceId, string $resourceType): string
    {
        $resource = $this->getResourceConfig($resourceType);
        $error = $this->validate($file, $resource['label']);
        if ($error !== null) {
            throw new RuntimeException($error);
        }

        $storageDirectory = $this->projectRoot . '/' . $resource['directory'];
        if (!is_dir($storageDirectory) && !mkdir($storageDirectory, 0755, true) && !is_dir($storageDirectory)) {
            throw new RuntimeException('Não foi possível preparar o armazenamento da imagem da ' . $resource['label'] . '.');
        }

        $extension = self::MIME_TYPES[$this->getMimeType((string)$file['tmp_name'])];
        $filename = sprintf('%s_%d_%s.%s', $resource['filename_prefix'], $resourceId, bin2hex(random_bytes(12)), $extension);
        $targetPath = $storageDirectory . '/' . $filename;

        if (!move_uploaded_file((string)$file['tmp_name'], $targetPath)) {
            throw new RuntimeException('Não foi possível salvar a imagem da ' . $resource['label'] . '.');
        }

        return $resource['directory'] . '/' . $filename;
    }

    public function remove(?string $relativePath): void
    {
        $targetPath = $this->getManagedAbsolutePath($relativePath);
        if ($targetPath === null) {
            return;
        }

        if (is_file($targetPath)) {
            unlink($targetPath);
        }
    }

    public function resolve(?string $relativePath): ?string
    {
        $targetPath = $this->getManagedAbsolutePath($relativePath);
        return $targetPath !== null && is_file($targetPath) ? $targetPath : null;
    }

    private function getMimeType(string $filePath): string
    {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo === false) {
            return '';
        }

        $mime = finfo_file($finfo, $filePath) ?: '';
        finfo_close($finfo);
        return $mime;
    }

    private function getResourceConfig(string $resourceType): array
    {
        return match ($resourceType) {
            'category' => [
                'label' => 'categoria',
                'directory' => 'storage/categories',
                'filename_prefix' => 'category',
            ],
            'subcategory' => [
                'label' => 'subcategoria',
                'directory' => 'storage/subcategories',
                'filename_prefix' => 'subcategory',
            ],
            'system_logo' => [
                'label' => 'logo do sistema',
                'directory' => 'storage/system',
                'filename_prefix' => 'portal_logo',
            ],
            default => throw new InvalidArgumentException('Tipo de recurso de imagem inválido.'),
        };
    }

    private function getManagedAbsolutePath(?string $relativePath): ?string
    {
        if (!is_string($relativePath)) {
            return null;
        }

        $normalizedPath = str_replace('\\', '/', $relativePath);
        if (preg_match('#^storage/(categories|subcategories|system)/(category|subcategory|portal_logo)_[0-9]+_[a-f0-9]{24}\.(jpg|png|webp)$#', $normalizedPath, $matches) !== 1) {
            return null;
        }

        if (($matches[1] === 'categories' && $matches[2] !== 'category')
            || ($matches[1] === 'subcategories' && $matches[2] !== 'subcategory')
            || ($matches[1] === 'system' && $matches[2] !== 'portal_logo')) {
            return null;
        }

        return $this->projectRoot . '/' . $normalizedPath;
    }
}
