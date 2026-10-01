<?php

require_once __DIR__ . '/_cli_only.php';
require_once __DIR__ . '/../config/db.php';

function duplicateDocumentAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function callDocumentSave(int $userId, array $payload): array
{
    $payload['_tab'] = 'novo_documento';
    $command = escapeshellarg(PHP_BINARY)
        . ' ' . escapeshellarg(__DIR__ . '/request_admin_action.php')
        . ' ' . escapeshellarg((string)$userId)
        . ' admin '
        . escapeshellarg(base64_encode((string)json_encode($payload)));
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('Não foi possível executar o salvamento do documento.');
    }
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);
    preg_match('/HTTP_STATUS:(\d+)/', $stderr, $statusMatch);
    return [
        'status' => isset($statusMatch[1]) ? (int)$statusMatch[1] : 0,
        'stdout' => $stdout,
        'stderr' => $stderr,
    ];
}

$suffix = bin2hex(random_bytes(5));
$userId = 0;
$categoryId = 0;
$subcategoryId = 0;
$subjectId = 0;
try {
    $stmt = $pdo->prepare("INSERT INTO users (name, username, email, role, auth_source, active) VALUES (?, ?, ?, 'admin', 'ad', TRUE) RETURNING id");
    $stmt->execute(['Admin temporário', "duplicate.admin.{$suffix}", "duplicate.admin.{$suffix}@invalid.local"]);
    $userId = (int)$stmt->fetchColumn();
    $stmt = $pdo->prepare("INSERT INTO categories (name, slug, active) VALUES (?, ?, TRUE) RETURNING id");
    $stmt->execute(["Categoria duplicidade {$suffix}", "categoria-duplicidade-{$suffix}"]);
    $categoryId = (int)$stmt->fetchColumn();
    $stmt = $pdo->prepare("INSERT INTO subcategories (category_id, name, slug, active) VALUES (?, ?, ?, TRUE) RETURNING id");
    $stmt->execute([$categoryId, "Subcategoria duplicidade {$suffix}", "subcategoria-duplicidade-{$suffix}"]);
    $subcategoryId = (int)$stmt->fetchColumn();
    $stmt = $pdo->prepare("INSERT INTO subjects (subcategory_id, name, slug, active) VALUES (?, ?, ?, TRUE) RETURNING id");
    $stmt->execute([$subcategoryId, "Assunto duplicidade {$suffix}", "assunto-duplicidade-{$suffix}"]);
    $subjectId = (int)$stmt->fetchColumn();

    $basePayload = [
        'save_doc' => '1',
        'csrf_token' => str_repeat('a', 64),
        'titulo' => 'Teste CMD',
        'descricao' => 'Validação automática de slug duplicado.',
        'categoria' => (string)$categoryId,
        'subcategoria' => (string)$subcategoryId,
        'assunto' => (string)$subjectId,
        'tipo_conteudo' => 'text',
        'conteudo_html' => '<p>Conteúdo temporário.</p>',
        'workflow_action' => 'save_draft',
    ];

    $first = callDocumentSave($userId, $basePayload);
    $second = callDocumentSave($userId, $basePayload);
    $third = callDocumentSave($userId, $basePayload);
    duplicateDocumentAssert($first['status'] === 302, 'A primeira criação falhou: ' . $first['stderr']);
    duplicateDocumentAssert($second['status'] === 302, 'A segunda criação com o mesmo título falhou: ' . $second['stderr']);
    duplicateDocumentAssert($third['status'] === 302, 'A terceira criação com o mesmo título falhou: ' . $third['stderr']);

    $slugStmt = $pdo->prepare('SELECT id, slug FROM documents WHERE subject_id = ? ORDER BY id');
    $slugStmt->execute([$subjectId]);
    $documents = $slugStmt->fetchAll(PDO::FETCH_ASSOC);
    duplicateDocumentAssert(array_column($documents, 'slug') === ['teste-cmd', 'teste-cmd-2', 'teste-cmd-3'], 'A sequência de slugs não foi gerada corretamente: ' . json_encode($documents));

    $editPayload = $basePayload;
    $editPayload['id'] = (string)$documents[1]['id'];
    $edited = callDocumentSave($userId, $editPayload);
    duplicateDocumentAssert($edited['status'] === 302, 'Editar um documento com título repetido falhou.');
    $editedSlugStmt = $pdo->prepare('SELECT slug FROM documents WHERE id = ?');
    $editedSlugStmt->execute([(int)$documents[1]['id']]);
    duplicateDocumentAssert($editedSlugStmt->fetchColumn() === 'teste-cmd-2', 'A edição trocou indevidamente o slug estável do documento.');

    echo "OK: títulos repetidos geram slugs únicos e a edição permanece estável.\n";
} finally {
    if ($subjectId > 0) {
        $pdo->prepare("DELETE FROM usage_audit_events WHERE resource_type = 'DOCUMENT' AND resource_id IN (SELECT id FROM documents WHERE subject_id = ?)")->execute([$subjectId]);
        $pdo->prepare('DELETE FROM documents WHERE subject_id = ?')->execute([$subjectId]);
        $pdo->prepare('DELETE FROM subjects WHERE id = ?')->execute([$subjectId]);
    }
    if ($subcategoryId > 0) {
        $pdo->prepare('DELETE FROM subcategories WHERE id = ?')->execute([$subcategoryId]);
    }
    if ($categoryId > 0) {
        $pdo->prepare('DELETE FROM categories WHERE id = ?')->execute([$categoryId]);
    }
    if ($userId > 0) {
        $pdo->prepare('DELETE FROM usage_audit_events WHERE user_id = ?')->execute([$userId]);
        $pdo->prepare('DELETE FROM notifications WHERE user_id = ?')->execute([$userId]);
        $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$userId]);
    }
}
