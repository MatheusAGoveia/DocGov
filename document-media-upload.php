<?php

declare(strict_types=1);

require_once __DIR__ . '/config/session.php';
docgovStartSession();
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/services/CsrfService.php';
require_once __DIR__ . '/services/PermissionService.php';
require_once __DIR__ . '/services/DocumentInlineMediaService.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        http_response_code(405);
        throw new RuntimeException('Método não permitido.');
    }
    $postLimit = trim((string)ini_get('post_max_size'));
    $multiplier = ['K' => 1024, 'M' => 1048576, 'G' => 1073741824][strtoupper(substr($postLimit, -1))] ?? 1;
    $postLimitBytes = (int)$postLimit * $multiplier;
    if ($postLimitBytes > 0 && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > $postLimitBytes) {
        http_response_code(413);
        throw new InvalidArgumentException('A imagem excede o limite de envio do servidor. Reduza o arquivo.');
    }
    $userId = (int)($_SESSION['user']['id'] ?? 0);
    if ($userId <= 0) {
        http_response_code(401);
        throw new RuntimeException('Faça login antes de inserir imagens.');
    }
    if (!CsrfService::isValid($_POST['csrf_token'] ?? null)) {
        http_response_code(419);
        throw new RuntimeException('A sessão de segurança expirou. Atualize a página.');
    }
    $permissions = new PermissionService($pdo);
    $documentId = (int)($_POST['document_id'] ?? 0);
    $subjectId = (int)($_POST['subject_id'] ?? 0);
    if ($documentId > 0) {
        if (!$permissions->canEditDocument($userId, $documentId)) {
            http_response_code(403);
            throw new RuntimeException('Você não pode editar este documento.');
        }
    } elseif ($subjectId <= 0 || !$permissions->canCreateDocument($userId, $subjectId)) {
        http_response_code(403);
        throw new RuntimeException('Selecione um assunto em que você possa criar documentos.');
    }
    $service = new DocumentInlineMediaService($pdo, __DIR__);
    $service->pruneAbandoned();
    $result = $service->upload((array)($_FILES['image'] ?? []), $userId);
    echo json_encode(['ok' => true] + $result, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    if (http_response_code() < 400) http_response_code(400);
    $safeError = $exception instanceof InvalidArgumentException || (http_response_code() !== 500 && $exception instanceof RuntimeException);
    if (!$safeError) {
        http_response_code(500);
        error_log('DocGov: falha ao enviar mídia de artigo: ' . $exception->getMessage());
    }
    echo json_encode(['ok' => false, 'error' => $safeError ? $exception->getMessage() : 'Não foi possível enviar a imagem. Tente novamente.'], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
}
