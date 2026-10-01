<?php
declare(strict_types=1);
require_once __DIR__ . '/_cli_only.php';

/** A senha chega por stdin redirecionado, nunca por argv ou saída do script. */
function docgovCredentialInput(): array
{
    $options = getopt('', ['username:', 'user-id:', 'password-stdin']);
    $username = trim((string)($options['username'] ?? ''));
    $userId = (int)($options['user-id'] ?? 0);
    if (($username === '') === ($userId <= 0) || !array_key_exists('password-stdin', $options)
        || (function_exists('stream_isatty') && stream_isatty(STDIN))) {
        throw new InvalidArgumentException('Informe --username ou --user-id e --password-stdin. Use entrada redirecionada a partir de uma leitura protegida.');
    }
    $password = rtrim((string)stream_get_contents(STDIN, 8193), "\r\n");
    if (strlen($password) < 16 || strlen($password) > 72 || strpbrk($password, "\r\n\0") !== false) {
        throw new InvalidArgumentException('A senha de emergência deve ter de 16 a 72 bytes e não conter quebras de linha.');
    }
    return ['username' => $username, 'user_id' => $userId, 'password' => $password];
}
