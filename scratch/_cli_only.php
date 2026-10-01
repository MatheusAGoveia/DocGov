<?php
declare(strict_types=1);

// Utilitários e fixtures nunca devem executar pelo servidor HTTP.
if (!in_array(PHP_SAPI, ['cli', 'phpdbg'], true)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    exit('Not Found');
}
