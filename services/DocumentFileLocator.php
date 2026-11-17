<?php

/**
 * Localiza o arquivo físico de um documento dentro das raízes permitidas,
 * recusando caminhos fora delas (path traversal) e arquivos inexistentes.
 */
final class DocumentFileLocator
{
    private const ALLOWED_ROOTS = ['storage/documents', 'uploads/docs'];

    /** @param array{stored_filename?: ?string, file_path?: ?string} $document */
    public static function resolve(string $projectRoot, array $document): ?string
    {
        $storedName = trim((string)($document['stored_filename'] ?? ''));
        $legacyPath = trim((string)($document['file_path'] ?? ''));
        $filename = basename($storedName !== '' ? $storedName : ($legacyPath !== '' ? basename($legacyPath) : ''));
        if ($filename === '' || $filename === '.' || $filename === '..') {
            return null;
        }

        foreach (self::ALLOWED_ROOTS as $relativeRoot) {
            $root = realpath(rtrim($projectRoot, '/\\') . DIRECTORY_SEPARATOR . $relativeRoot);
            if ($root === false) {
                continue;
            }
            $candidate = realpath($root . DIRECTORY_SEPARATOR . $filename);
            if ($candidate !== false && str_starts_with($candidate, $root . DIRECTORY_SEPARATOR) && is_file($candidate)) {
                return $candidate;
            }
        }
        return null;
    }
}
