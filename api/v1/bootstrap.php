<?php
/**
 * api/v1/bootstrap.php — base comum da API de integração.
 *
 * Incluído pelos endpoints de api/v1; recusa acesso HTTP direto. A API é sem
 * estado: não inicia sessão PHP, não usa cookies e autentica cada requisição
 * por chave (Authorization: Bearer <token> ou X-API-Key).
 *
 * Variáveis disponibilizadas aos endpoints:
 *   $pdo, $apiClient, $apiViewerId (0 = chave pública), $apiPermissions, $apiUsageAudit.
 */
declare(strict_types=1);

if (!defined('DOCGOV_API_V1_ENDPOINT')) {
    http_response_code(404);
    exit;
}

const DOCGOV_API_VERSION = '1.0';
const DOCGOV_API_DEFAULT_PER_PAGE = 20;
const DOCGOV_API_MAX_PER_PAGE = 100;
const DOCGOV_API_MAX_ID = 2147483647;

function docgovApiSend(int $status, array $payload, array $headers = []): never
{
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-DocGov-API-Version: ' . DOCGOV_API_VERSION);
        foreach ($headers as $name => $value) {
            header($name . ': ' . $value);
        }
    }
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function docgovApiOk(mixed $data, array $meta = []): never
{
    $payload = ['success' => true, 'data' => $data];
    if ($meta !== []) {
        $payload['meta'] = $meta;
    }
    docgovApiSend(200, $payload);
}

function docgovApiError(int $status, string $code, string $message, array $headers = []): never
{
    docgovApiSend($status, ['success' => false, 'error' => $message, 'code' => $code], $headers);
}

/** Inteiro opcional da query string, validado contra o intervalo permitido. */
function docgovApiIntParam(string $name, int $default, int $min, int $max): int
{
    $raw = $_GET[$name] ?? null;
    if ($raw === null || $raw === '') {
        return $default;
    }
    if (!is_string($raw) || !preg_match('/^\d{1,10}$/', $raw) || (int)$raw < $min || (int)$raw > $max) {
        docgovApiError(422, 'invalid_parameter', "O parâmetro {$name} deve ser um inteiro entre {$min} e {$max}.");
    }
    return (int)$raw;
}

function docgovApiStringParam(string $name, int $maxLength): string
{
    $raw = $_GET[$name] ?? '';
    if (!is_string($raw) || mb_strlen($raw) > $maxLength || preg_match('/[\x00-\x1F\x7F]/', $raw)) {
        docgovApiError(422, 'invalid_parameter', "O parâmetro {$name} é inválido ou excede {$maxLength} caracteres.");
    }
    return trim($raw);
}

function docgovApiIsoDate(mixed $value): ?string
{
    if ($value === null || $value === '') {
        return null;
    }
    try {
        return (new DateTimeImmutable((string)$value))->format(DateTimeInterface::ATOM);
    } catch (Throwable) {
        return null;
    }
}

/** IDs dos assuntos legíveis na árvore já filtrada pelo PermissionService. */
function docgovApiSubjectIds(array $tree): array
{
    $ids = [];
    foreach ($tree as $category) {
        foreach ($category['subcategories'] ?? [] as $subcategory) {
            foreach ($subcategory['subjects'] ?? [] as $subject) {
                $ids[] = (int)$subject['id'];
            }
        }
    }
    return array_values(array_unique($ids));
}

/** Literal de array PostgreSQL para uso com CAST(:param AS int[]). */
function docgovApiIntArray(array $ids): string
{
    return '{' . implode(',', array_map('intval', $ids)) . '}';
}

set_exception_handler(static function (Throwable $exception): void {
    error_log('DocGov API v1: ' . $exception->getMessage());
    docgovApiError(500, 'internal_error', 'Falha interna ao processar a requisição.');
});

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../services/PermissionService.php';
require_once __DIR__ . '/../../services/ApiClientService.php';
require_once __DIR__ . '/../../services/UsageAuditService.php';

$requestMethod = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$allowedMethods = defined('DOCGOV_API_ALLOWED_METHODS') ? DOCGOV_API_ALLOWED_METHODS : ['GET', 'HEAD'];
if (!in_array($requestMethod, $allowedMethods, true)) {
    docgovApiError(405, 'method_not_allowed', 'Método HTTP não permitido neste endpoint.', ['Allow' => implode(', ', $allowedMethods)]);
}

$apiClientService = new ApiClientService($pdo);
$apiClient = $apiClientService->authenticate(ApiClientService::extractToken($_SERVER));
if ($apiClient === null) {
    docgovApiError(401, 'unauthorized', 'Chave de API ausente, inválida, expirada ou revogada.', [
        'WWW-Authenticate' => 'Bearer realm="DocGov API"',
    ]);
}

$apiRate = $apiClientService->consumeRateLimit($apiClient['id'], $apiClient['rate_limit_per_minute']);
header('X-RateLimit-Limit: ' . $apiRate['limit']);
header('X-RateLimit-Remaining: ' . $apiRate['remaining']);
header('X-RateLimit-Reset: ' . $apiRate['reset_seconds']);
if (!$apiRate['allowed']) {
    docgovApiError(429, 'rate_limited', 'Limite de requisições por minuto excedido.', [
        'Retry-After' => (string)$apiRate['reset_seconds'],
    ]);
}
$apiClientService->touch($apiClient, isset($_SERVER['REMOTE_ADDR']) ? (string)$_SERVER['REMOTE_ADDR'] : null);

// Chave pública lê como visitante, independentemente da opção de visitantes do
// portal; chave vinculada lê com as permissões de leitura da conta associada.
$apiViewerId = ApiClientService::isUserScope($apiClient['access_scope']) ? (int)$apiClient['user_id'] : 0;
$apiPermissions = new PermissionService($pdo, true);
$apiUsageAudit = new UsageAuditService($pdo);
