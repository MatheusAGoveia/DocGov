<?php
// Testa que a interface administrativa não mistura dados entre categorias.
require __DIR__ . '/../config/db.php';
require __DIR__ . '/../services/PermissionService.php';

function scopedAssert(bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function renderAdminPage(int $userId, string $role, array $params): array {
    $command = escapeshellarg(PHP_BINARY)
        . ' ' . escapeshellarg(__DIR__ . '/request_admin_page.php')
        . ' ' . escapeshellarg((string)$userId)
        . ' ' . escapeshellarg($role)
        . ' ' . escapeshellarg(base64_encode(json_encode($params)));

    $process = proc_open($command, [
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('Não foi possível renderizar a página administrativa.');
    }

    $html = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);

    preg_match('/HTTP_STATUS:(\d+)/', $stderr, $statusMatch);
    return [
        'status' => isset($statusMatch[1]) ? (int)$statusMatch[1] : 0,
        'html' => $html,
        'stderr' => $stderr,
    ];
}

$anaId = (int)$pdo->query("SELECT id FROM users WHERE username = 'ana.souza'")->fetchColumn();
$superAdminId = (int)$pdo->query("SELECT id FROM users WHERE username = 'matheus.damiao'")->fetchColumn();
scopedAssert($anaId > 0 && $superAdminId > 0, 'Usuários necessários ao teste não foram encontrados.');

$service = new PermissionService($pdo);
$anaSubjects = $service->getAdministrativeScope($anaId)['subject_ids'];
scopedAssert(!empty($anaSubjects), 'Ana não possui assunto no escopo administrativo.');
$subjectSql = implode(',', array_map('intval', $anaSubjects));

$insideDocument = $pdo->query("SELECT id, title FROM documents WHERE subject_id IN ({$subjectSql}) ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$outsideDocument = $pdo->query("SELECT id, title FROM documents WHERE subject_id NOT IN ({$subjectSql}) ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
scopedAssert((bool)$insideDocument && (bool)$outsideDocument, 'São necessários documentos dentro e fora do escopo para o teste.');

$overview = renderAdminPage($anaId, 'reader', ['tab' => 'visao_geral']);
scopedAssert(str_contains($overview['html'], $insideDocument['title']), 'Visão Geral não mostrou documento autorizado.');
scopedAssert(!str_contains($overview['html'], $outsideDocument['title']), 'Visão Geral expôs documento externo.');
scopedAssert(str_contains($overview['html'], 'Resumo exclusivo das categorias'), 'Visão Geral local não informa o escopo personalizado.');
scopedAssert(!str_contains($overview['html'], 'Pessoas cadastradas'), 'Visão Geral local exibiu indicador global de pessoas.');
scopedAssert(!str_contains($overview['html'], 'Auditoria de segurança'), 'Visão Geral local exibiu auditoria global.');

$documents = renderAdminPage($anaId, 'reader', ['tab' => 'documentos']);
scopedAssert(str_contains($documents['html'], $insideDocument['title']), 'Tabela não mostrou documento autorizado.');
scopedAssert(!str_contains($documents['html'], $outsideDocument['title']), 'Tabela expôs documento externo.');

$treeEditor = renderAdminPage($anaId, 'reader', ['tab' => 'editar_estrutura']);
scopedAssert($treeEditor['status'] === 200, 'A entrada do Editor da Árvore sem recurso retornou erro.');
scopedAssert(str_contains($treeEditor['html'], 'Editor da Árvore'), 'A página inicial do Editor da Árvore não foi renderizada.');
scopedAssert(!str_contains($treeEditor['html'], 'Recurso não encontrado ou fora do seu escopo administrativo.'), 'O Editor da Árvore exibiu falso erro de escopo.');

$insideSubjectId = (int)$anaSubjects[0];
$subjectEditor = renderAdminPage($anaId, 'reader', [
    'tab' => 'editar_estrutura',
    'type' => 'assunto',
    'id' => $insideSubjectId,
]);
scopedAssert($subjectEditor['status'] === 200, 'Editor não conseguiu abrir um assunto do próprio escopo.');
scopedAssert(str_contains($subjectEditor['html'], 'Informações'), 'Editor do assunto não abriu na aba de informações.');

$forbiddenDetail = renderAdminPage($anaId, 'reader', ['tab' => 'detalhes_documento', 'id' => $outsideDocument['id']]);
scopedAssert($forbiddenDetail['status'] === 403, 'Detalhe externo não retornou 403.');
scopedAssert(!str_contains($forbiddenDetail['html'], $outsideDocument['title']), 'Detalhe externo vazou o título do documento.');

$forbiddenConfig = renderAdminPage($anaId, 'reader', ['tab' => 'configuracoes']);
scopedAssert($forbiddenConfig['status'] === 403, 'Configurações globais não retornaram 403 para gestor local.');
scopedAssert(!str_contains($forbiddenConfig['html'], 'Configurações Gerais do Sistema'), 'Configurações globais foram renderizadas para gestor local.');

$globalOverview = renderAdminPage($superAdminId, 'admin', ['tab' => 'visao_geral']);
scopedAssert(str_contains($globalOverview['html'], $outsideDocument['title']), 'Super Admin não recebeu a visão global.');
scopedAssert(str_contains($globalOverview['html'], 'Pessoas cadastradas'), 'Super Admin não recebeu o indicador global de pessoas.');
scopedAssert(str_contains($globalOverview['html'], 'Auditoria de segurança'), 'Super Admin não recebeu a auditoria global.');

echo "[OK] Visão Geral, documentos, Editor da Árvore, detalhes e configurações respeitam o escopo individual.\n";
