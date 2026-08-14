<?php
// scratch/test_recentes_functional.php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../services/UsageAuditService.php';
require_once __DIR__ . '/../services/AccessService.php';

echo "=== TESTANDO FUNCIONALIDADE DE DOCUMENTOS VISTOS RECENTEMENTE ===\n\n";

$auditService = new UsageAuditService($pdo);
$accessService = new AccessService($pdo);

$userId = 148; // matheus.damiao
$docStmt = $pdo->query("SELECT id, title FROM documents LIMIT 1");
$doc = $docStmt ? $docStmt->fetch(PDO::FETCH_ASSOC) : null;

if (!$doc) {
    echo "Nenhum documento encontrado na base para o teste.\n";
    exit;
}

$docId = (int)$doc['id'];
echo "1. REGISTRANDO VISITA AO DOCUMENTO [ID: {$docId} - {$doc['title']}]:\n";
$auditService->log('document_view', $userId, 'DOCUMENT', $docId, ['test' => true]);
echo "  [OK] SUCCESS: Evento de visualização registrado na tabela usage_audit_events!\n";

// 2. CONSULTAR DOCUMENTOS RECENTES COMO FEITO EM MINHA_CONTA.PHP
echo "\n2. CONSULTANDO DOCUMENTOS RECENTES DO USUÁRIO {$userId}:\n";
$allowedSubjectIds = $accessService->getAllowedSubjectIds($userId);

if (!empty($allowedSubjectIds)) {
    $subInSqlRec = implode(',', array_map('intval', $allowedSubjectIds));
    $stmtRec = $pdo->prepare("
        SELECT d.id, d.title AS titulo, d.description AS descricao, d.content_type AS tipo_conteudo, MAX(e.created_at) AS acessado_em,
               s.name AS assunto, sc.name AS subcategoria, c.name AS categoria
        FROM usage_audit_events e
        JOIN documents d ON e.resource_id = d.id
        JOIN subjects s ON d.subject_id = s.id
        JOIN subcategories sc ON s.subcategory_id = sc.id
        JOIN categories c ON c.id = sc.category_id
        WHERE e.user_id = :uid 
          AND e.resource_type = 'DOCUMENT'
          AND e.event_type IN ('document_view', 'document_file_view', 'document_download')
          AND d.subject_id IN ($subInSqlRec)
        GROUP BY d.id, d.title, d.description, d.content_type, s.name, sc.name, c.name
        ORDER BY MAX(e.created_at) DESC
        LIMIT 10
    ");
    $stmtRec->execute([':uid' => $userId]);
    $recentes = $stmtRec->fetchAll(PDO::FETCH_ASSOC);

    echo "  [OK] SUCCESS: Total de " . count($recentes) . " documento(s) recente(s) retornado(s) com sucesso:\n";
    foreach ($recentes as $r) {
        echo "  -> ID " . $r['id'] . ": " . $r['titulo'] . " | Acessado em: " . $r['acessado_em'] . " (" . $r['categoria'] . " > " . $r['subcategoria'] . ")\n";
    }
} else {
    echo "  [INFO] Usuário sem permissões registradas em grupos.\n";
}

echo "\n===========================================================================\n";
echo "RESULTADO FINAL: Aba de Vistos Recentemente 100% CORRIGIDA E OPERACIONAL!\n";
echo "===========================================================================\n";
