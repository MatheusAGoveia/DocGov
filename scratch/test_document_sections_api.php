<?php

declare(strict_types=1);

define('DOCGOV_SKIP_APP_RUNTIME', true);
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../services/DocumentSectionService.php';

function sectionApiAssert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function callSectionsApi(int $userId, string $method, array $params): array
{
    $command = escapeshellarg(PHP_BINARY)
        . ' ' . escapeshellarg(__DIR__ . '/request_permission_api.php')
        . ' documents ' . escapeshellarg($method)
        . ' ' . escapeshellarg((string)$userId)
        . ' admin ' . escapeshellarg(base64_encode((string)json_encode($params)));
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) throw new RuntimeException('Não foi possível executar a API de documentos.');
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);
    preg_match('/HTTP_STATUS:(\d+)/', $stderr, $match);
    return [
        'status' => isset($match[1]) ? (int)$match[1] : 0,
        'body' => json_decode($stdout, true),
        'raw' => $stdout . $stderr,
    ];
}

$suffix = bin2hex(random_bytes(5));
$sectionService = new DocumentSectionService($pdo);
$userId = $categoryId = $subcategoryId = $subjectId = $documentId = 0;
try {
    $stmt = $pdo->prepare("INSERT INTO users (name, username, email, role, auth_source, active) VALUES (?, ?, ?, 'admin', 'ad', TRUE) RETURNING id");
    $stmt->execute(['Admin API seções', "sections.api.{$suffix}", "sections.api.{$suffix}@invalid.local"]);
    $userId = (int)$stmt->fetchColumn();
    $stmt = $pdo->prepare('INSERT INTO categories (name, slug, active) VALUES (?, ?, TRUE) RETURNING id');
    $stmt->execute(["Categoria API {$suffix}", "categoria-api-{$suffix}"]);
    $categoryId = (int)$stmt->fetchColumn();
    $stmt = $pdo->prepare('INSERT INTO subcategories (category_id, name, slug, active) VALUES (?, ?, ?, TRUE) RETURNING id');
    $stmt->execute([$categoryId, "Subcategoria API {$suffix}", "subcategoria-api-{$suffix}"]);
    $subcategoryId = (int)$stmt->fetchColumn();
    $stmt = $pdo->prepare('INSERT INTO subjects (subcategory_id, name, slug, active) VALUES (?, ?, ?, TRUE) RETURNING id');
    $stmt->execute([$subcategoryId, "Assunto API {$suffix}", "assunto-api-{$suffix}"]);
    $subjectId = (int)$stmt->fetchColumn();

    $withoutCsrf = callSectionsApi($userId, 'POST', ['subject_id' => $subjectId]);
    sectionApiAssert($withoutCsrf['status'] === 419, 'A API aceitou escrita sem CSRF.');

    $flowPayload = json_encode(['version' => 1, 'nodes' => [
        ['id' => 'inicio', 'type' => 'start', 'title' => 'Iniciar'],
        ['id' => 'fim', 'type' => 'end', 'title' => 'Concluir'],
    ]]);
    $created = callSectionsApi($userId, 'POST', [
        'csrf_token' => str_repeat('a', 64),
        'subject_id' => $subjectId,
        'title' => 'Fluxo via API',
        'section_key' => 'process-flow',
        'content_type' => 'file',
        'structured_content' => $flowPayload,
        'workflow_action' => 'save_draft',
    ]);
    sectionApiAssert($created['status'] === 200 && !empty($created['body']['success']), 'A API não criou o fluxo: ' . $created['raw']);
    $documentId = (int)$created['body']['id'];
    $stmt = $pdo->prepare('SELECT content_type, section_key, structured_content FROM documents WHERE id = ?');
    $stmt->execute([$documentId]);
    $saved = $stmt->fetch(PDO::FETCH_ASSOC);
    sectionApiAssert(($saved['content_type'] ?? '') === 'flow' && ($saved['section_key'] ?? '') === 'process-flow', 'A API confiou no tipo adulterado em vez da seção.');
    sectionApiAssert(count((array)(json_decode((string)$saved['structured_content'], true)['nodes'] ?? [])) === 2, 'A API não persistiu a estrutura do fluxo.');
    sectionApiAssert(array_column($sectionService->existingSectionsForSubject($subjectId), 'section_key') === ['process-flow'], 'Criar fluxo não gerou a aba administrativa.');

    $orgPayload = json_encode(['version' => 1, 'nodes' => [
        ['id' => 'raiz', 'name' => 'Diretoria'],
        ['id' => 'filho', 'parent_id' => 'raiz', 'name' => 'Equipe'],
    ]]);
    $updated = callSectionsApi($userId, 'POST', [
        'csrf_token' => str_repeat('a', 64),
        'id' => $documentId,
        'subject_id' => $subjectId,
        'title' => 'Organograma via API',
        'section_key' => 'organization-chart',
        'structured_content' => $orgPayload,
        'workflow_action' => 'save_draft',
    ]);
    sectionApiAssert($updated['status'] === 200 && !empty($updated['body']['success']), 'A API não editou o conteúdo estruturado.');
    $stmt->execute([$documentId]);
    $saved = $stmt->fetch(PDO::FETCH_ASSOC);
    sectionApiAssert(($saved['content_type'] ?? '') === 'orgchart' && ($saved['section_key'] ?? '') === 'organization-chart', 'A edição não alterou editor e seção de forma consistente.');
    sectionApiAssert(array_column($sectionService->existingSectionsForSubject($subjectId), 'section_key') === ['organization-chart'], 'Editar o tipo não substituiu a aba administrativa.');

    $listed = callSectionsApi($userId, 'GET', ['subject_id' => $subjectId, 'status' => 'all']);
    $listedDocument = $listed['body']['data'][0] ?? [];
    sectionApiAssert(($listedDocument['section_label'] ?? '') === 'Organograma', 'A listagem da API não devolveu metadados da seção.');

    echo "OK: API validou CSRF, seção, tipo, criação, edição e JSON estruturado.\n";
} finally {
    if ($documentId > 0) {
        $pdo->prepare("DELETE FROM usage_audit_events WHERE resource_type = 'DOCUMENT' AND resource_id = ?")->execute([$documentId]);
        $pdo->prepare('DELETE FROM document_workflow_history WHERE document_id = ?')->execute([$documentId]);
        $pdo->prepare('DELETE FROM document_tags WHERE document_id = ?')->execute([$documentId]);
        $pdo->prepare('DELETE FROM documents WHERE id = ?')->execute([$documentId]);
    }
    if ($subjectId > 0) $pdo->prepare('DELETE FROM subjects WHERE id = ?')->execute([$subjectId]);
    if ($subcategoryId > 0) $pdo->prepare('DELETE FROM subcategories WHERE id = ?')->execute([$subcategoryId]);
    if ($categoryId > 0) $pdo->prepare('DELETE FROM categories WHERE id = ?')->execute([$categoryId]);
    if ($userId > 0) {
        $pdo->prepare('DELETE FROM notifications WHERE user_id = ?')->execute([$userId]);
        $pdo->prepare('DELETE FROM usage_audit_events WHERE user_id = ?')->execute([$userId]);
        $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$userId]);
    }
}
