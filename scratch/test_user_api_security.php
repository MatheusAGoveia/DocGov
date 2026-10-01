<?php

require_once __DIR__ . '/_cli_only.php';
require_once __DIR__ . '/../config/db.php';

function userApiAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function userApiRequest(string $method, int $userId, array $params, string $endpoint = 'user'): array
{
    $command = escapeshellarg(PHP_BINARY)
        . ' ' . escapeshellarg(__DIR__ . '/request_permission_api.php')
        . ' ' . escapeshellarg($endpoint) . ' ' . escapeshellarg($method)
        . ' ' . escapeshellarg((string)$userId)
        . ' reader ' . escapeshellarg(base64_encode(json_encode($params, JSON_THROW_ON_ERROR)));
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('Não foi possível executar a API de usuário.');
    }
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);
    preg_match('/HTTP_STATUS:(\d+)/', $stderr, $match);
    return [
        'status' => isset($match[1]) ? (int)$match[1] : 0,
        'payload' => json_decode($stdout, true),
        'stdout' => $stdout,
        'stderr' => $stderr,
    ];
}

$userId = (int)$pdo->query("SELECT id FROM users WHERE username = 'matheus.damiao' AND active = TRUE")->fetchColumn();
$documentId = (int)$pdo->query("SELECT id FROM documents WHERE status = 'published' ORDER BY id LIMIT 1")->fetchColumn();
userApiAssert($userId > 0 && $documentId > 0, 'Super Admin e documento publicado são necessários para o teste.');

$favoriteStmt = $pdo->prepare('SELECT COUNT(*) FROM favorites WHERE user_id = ? AND document_id = ?');
$favoriteStmt->execute([$userId, $documentId]);
$favoriteInitiallyExists = (int)$favoriteStmt->fetchColumn() > 0;

try {
    $getMutation = userApiRequest('GET', $userId, ['action' => 'toggle_favorito', 'doc_id' => $documentId]);
    userApiAssert($getMutation['status'] === 405, 'A API aceitou mutação por GET.');

    $invalidCsrf = userApiRequest('POST', $userId, [
        'action' => 'toggle_favorito',
        'doc_id' => $documentId,
        'csrf_token' => 'token-invalido',
    ]);
    userApiAssert($invalidCsrf['status'] === 419, 'A API aceitou mutação sem CSRF válido.');

    foreach (['categories', 'subcategories', 'subjects'] as $structureEndpoint) {
        $structureMutation = userApiRequest('POST', $userId, ['name' => 'Não deve ser criado'], $structureEndpoint);
        userApiAssert($structureMutation['status'] === 419, "A API {$structureEndpoint} aceitou criação sem CSRF válido.");
    }

    $validToken = str_repeat('a', 64);
    $recordView = userApiRequest('POST', $userId, [
        'action' => 'record_view',
        'doc_id' => $documentId,
        'csrf_token' => $validToken,
    ]);
    userApiAssert($recordView['status'] === 200 && !empty($recordView['payload']['success']), 'Registro de consulta autorizado falhou.');

    $toggle = userApiRequest('POST', $userId, [
        'action' => 'toggle_favorito',
        'doc_id' => $documentId,
        'csrf_token' => $validToken,
    ]);
    userApiAssert($toggle['status'] === 200 && !empty($toggle['payload']['success']), 'Favorito com CSRF válido falhou.');
    userApiAssert((bool)$toggle['payload']['is_favorite'] !== $favoriteInitiallyExists, 'Favorito não mudou de estado.');

    $restore = userApiRequest('POST', $userId, [
        'action' => 'toggle_favorito',
        'doc_id' => $documentId,
        'csrf_token' => $validToken,
    ]);
    userApiAssert($restore['status'] === 200 && (bool)$restore['payload']['is_favorite'] === $favoriteInitiallyExists, 'Estado original do favorito não foi restaurado.');

    echo "[OK] API de usuário exige POST/CSRF, respeita acesso e restaura o estado do favorito.\n";
} finally {
    $pdo->prepare("DELETE FROM usage_audit_events WHERE user_id = ? AND resource_id = ? AND event_type = 'document_view' AND metadata->>'source' = 'portal_card'")
        ->execute([$userId, $documentId]);
    $favoriteStmt->execute([$userId, $documentId]);
    $favoriteExistsNow = (int)$favoriteStmt->fetchColumn() > 0;
    if ($favoriteExistsNow !== $favoriteInitiallyExists) {
        if ($favoriteInitiallyExists) {
            $pdo->prepare('INSERT INTO favorites (user_id, document_id) VALUES (?, ?) ON CONFLICT DO NOTHING')->execute([$userId, $documentId]);
        } else {
            $pdo->prepare('DELETE FROM favorites WHERE user_id = ? AND document_id = ?')->execute([$userId, $documentId]);
        }
    }
}
