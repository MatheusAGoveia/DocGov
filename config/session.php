<?php

/**
 * Inicia uma sessão não persistente e com atributos seguros de cookie.
 * O cookie é descartado ao encerrar o navegador; o atributo Secure é aplicado
 * automaticamente em HTTPS para manter o ambiente local HTTP funcional.
 */
if (!function_exists('docgovStartSession')) {
    function docgovStartSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        $https = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
            || (int)($_SERVER['SERVER_PORT'] ?? 0) === 443;

        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_strict_mode', '1');
        ini_set('session.cookie_lifetime', '0');
        ini_set('session.cookie_httponly', '1');
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => $https,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        session_start();
    }
}
