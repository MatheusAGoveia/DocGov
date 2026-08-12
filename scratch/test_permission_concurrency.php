<?php
// Testa duas concessões simultâneas para a mesma combinação principal/recurso.
require_once __DIR__ . '/../config/db.php';

function concurrencyInsert(PDO $pdo, string $sql, array $params): int {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (int)$stmt->fetchColumn();
}

function startPermissionRequest(int $actorId, array $params): array {
    $params['csrf_token'] = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    $command = escapeshellarg(PHP_BINARY)
        . ' ' . escapeshellarg(__DIR__ . '/request_permission_api.php')
        . ' permissions POST '
        . escapeshellarg((string)$actorId)
        . ' admin '
        . escapeshellarg(base64_encode(json_encode($params)));
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('Não foi possível iniciar o subprocesso concorrente.');
    }
    return [$process, $pipes];
}

function finishPermissionRequest(array $running): array {
    [$process, $pipes] = $running;
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    preg_match('/HTTP_STATUS:(\d+)/', $stderr, $match);
    return ['status' => (int)($match[1] ?? 0), 'exit' => $exitCode, 'stdout' => $stdout, 'stderr' => $stderr];
}

$suffix = bin2hex(random_bytes(5));
$ids = ['users' => [], 'categories' => []];

try {
    $actorId = concurrencyInsert($pdo, "INSERT INTO users (name, username, email, role, active) VALUES (?, ?, ?, 'admin', TRUE) RETURNING id", ['Admin Concorrência', "concurrency.admin.{$suffix}", "concurrency.admin.{$suffix}@example.invalid"]);
    $targetId = concurrencyInsert($pdo, "INSERT INTO users (name, username, email, role, active) VALUES (?, ?, ?, 'reader', TRUE) RETURNING id", ['Alvo Concorrência', "concurrency.target.{$suffix}", "concurrency.target.{$suffix}@example.invalid"]);
    $ids['users'] = [$actorId, $targetId];
    $categoryId = concurrencyInsert($pdo, "INSERT INTO categories (name, slug, description, active) VALUES (?, ?, '', TRUE) RETURNING id", ['Categoria Concorrência', "concurrency-{$suffix}"]);
    $ids['categories'][] = $categoryId;

    $base = [
        'resource_type' => 'category',
        'resource_id' => $categoryId,
        'principal_type' => 'user',
        'principal_id' => $targetId,
    ];
    $first = startPermissionRequest($actorId, $base + ['permission_level' => 'view']);
    $second = startPermissionRequest($actorId, $base + ['permission_level' => 'admin']);
    $results = [finishPermissionRequest($first), finishPermissionRequest($second)];

    foreach ($results as $index => $result) {
        if ($result['status'] !== 200 || $result['exit'] !== 0) {
            throw new RuntimeException('Concessão concorrente ' . ($index + 1) . " falhou: {$result['stderr']} {$result['stdout']}");
        }
    }

    $stmt = $pdo->prepare('SELECT COUNT(*), MAX(permission_level) FROM permissions WHERE user_id = ? AND category_id = ?');
    $stmt->execute([$targetId, $categoryId]);
    $row = $stmt->fetch(PDO::FETCH_NUM);
    if ((int)$row[0] !== 1) {
        throw new RuntimeException('Concorrência gerou mais de uma regra para o mesmo principal/recurso.');
    }
    if (!in_array($row[1], ['view', 'admin'], true)) {
        throw new RuntimeException('Nível final inválido após concorrência.');
    }

    echo "[OK] Concessões concorrentes foram serializadas sem duplicidade ou erro.\n";
} finally {
    foreach ($ids['categories'] as $id) {
        $pdo->prepare('DELETE FROM categories WHERE id = ?')->execute([$id]);
    }
    foreach (array_reverse($ids['users']) as $id) {
        $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
    }
}
