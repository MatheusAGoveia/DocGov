<?php

/** Proteção CSRF compartilhada pelas operações administrativas autenticadas. */
final class CsrfService
{
    private const SESSION_KEY = 'csrf_token';

    public static function token(): string
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            throw new RuntimeException('A sessão precisa estar ativa para gerar o token de segurança.');
        }

        $token = (string)($_SESSION[self::SESSION_KEY] ?? '');
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
            $token = bin2hex(random_bytes(32));
            $_SESSION[self::SESSION_KEY] = $token;
        }

        return $token;
    }

    public static function isValid(?string $candidate): bool
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return false;
        }

        $expected = (string)($_SESSION[self::SESSION_KEY] ?? '');
        $candidate = trim((string)$candidate);
        return $expected !== '' && $candidate !== '' && hash_equals($expected, $candidate);
    }
}
