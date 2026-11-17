<?php
// api/v1/status.php — verificação da chave e do serviço (GET).
declare(strict_types=1);
define('DOCGOV_API_V1_ENDPOINT', true);
require __DIR__ . '/bootstrap.php';

docgovApiOk([
    'api_version' => DOCGOV_API_VERSION,
    'server_time' => (new DateTimeImmutable('now'))->format(DateTimeInterface::ATOM),
    'client' => [
        'id' => $apiClient['id'],
        'name' => $apiClient['name'],
        'access_scope' => $apiClient['access_scope'],
        'rate_limit_per_minute' => $apiClient['rate_limit_per_minute'],
    ],
]);
