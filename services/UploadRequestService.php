<?php

declare(strict_types=1);

/** Limites reais do PHP, inclusive quando ele descarta $_POST e $_FILES. */
final class UploadRequestService
{
    public static function bytes(string $value): int
    {
        $value = trim($value);
        $factor = ['K' => 1024, 'M' => 1048576, 'G' => 1073741824][strtoupper(substr($value, -1))] ?? 1;
        return max(0, (int)((float)$value * $factor));
    }

    public static function limits(): array
    {
        return [
            'post' => self::bytes((string)ini_get('post_max_size')),
            'file' => self::bytes((string)ini_get('upload_max_filesize')),
        ];
    }

    public static function requestError(): ?string
    {
        $limit = self::limits()['post'];
        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && $limit > 0
            && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > $limit) {
            return 'O envio excede o limite total do servidor (' . self::format($limit) . '). Reduza o tamanho dos arquivos ou envie em lotes menores.';
        }
        return null;
    }

    public static function filesError(array $files): ?string
    {
        foreach ($files as $file) {
            $errors = $file['error'] ?? UPLOAD_ERR_NO_FILE;
            foreach (is_array($errors) ? $errors : [$errors] as $error) {
                if (in_array((int)$error, [UPLOAD_ERR_OK, UPLOAD_ERR_NO_FILE], true)) continue;
                return match ((int)$error) {
                    UPLOAD_ERR_INI_SIZE => 'O arquivo excede o limite por arquivo do servidor (' . self::format(self::limits()['file']) . '). Reduza o arquivo e tente novamente.',
                    UPLOAD_ERR_FORM_SIZE => 'O arquivo excede o limite permitido pelo formulário.',
                    UPLOAD_ERR_PARTIAL => 'O envio do arquivo foi interrompido. Selecione o arquivo e tente novamente.',
                    default => 'Não foi possível receber o arquivo. Tente novamente ou contate o administrador.',
                };
            }
        }
        return null;
    }

    private static function format(int $bytes): string
    {
        return rtrim(rtrim(number_format($bytes / 1048576, 2, ',', ''), '0'), ',') . ' MB';
    }
}
