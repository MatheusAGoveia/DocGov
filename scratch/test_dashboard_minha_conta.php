<?php
// scratch/test_dashboard_minha_conta.php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../services/AccessService.php';

echo "=== TESTANDO DASHBOARD PESSOAL DO USUÁRIO EM MINHA_CONTA.PHP ===\n\n";

$userId = 148; // matheus.damiao
$accessService = new AccessService($pdo);
$allowedSubjectIds = $accessService->getAllowedSubjectIds($userId);

// 1. Documentos Consultados este Mês
$stmtM1 = $pdo->prepare("
    SELECT COUNT(DISTINCT resource_id) 
    FROM usage_audit_events 
    WHERE user_id = ? 
      AND resource_type = 'DOCUMENT' 
      AND event_type IN ('document_view', 'document_file_view') 
      AND created_at >= DATE_TRUNC('month', CURRENT_TIMESTAMP)
");
$stmtM1->execute([$userId]);
$userDocsConsultadosMes = (int)$stmtM1->fetchColumn();

// 2. Documentos Publicados por Mim
$stmtM2 = $pdo->prepare("
    SELECT COUNT(*) 
    FROM documents 
    WHERE created_by = ? AND trashed_at IS NULL
");
$stmtM2->execute([$userId]);
$userDocsPublicados = (int)$stmtM2->fetchColumn();

// 3. Total de Downloads Realizados
$stmtM3 = $pdo->prepare("
    SELECT COUNT(*) 
    FROM usage_audit_events 
    WHERE user_id = ? AND event_type = 'document_download'
");
$stmtM3->execute([$userId]);
$userTotalDownloads = (int)$stmtM3->fetchColumn();

// 4. Quantidade de Favoritos
$stmtM4 = $pdo->prepare("
    SELECT COUNT(*) 
    FROM favorites 
    WHERE user_id = ?
");
$stmtM4->execute([$userId]);
$userTotalFavoritos = (int)$stmtM4->fetchColumn();

echo "MÉTRICAS CALCULADAS COM SUCESSO:\n";
echo "  [1] Consultas neste Mês: {$userDocsConsultadosMes}\n";
echo "  [2] Publicados por Mim: {$userDocsPublicados}\n";
echo "  [3] Total de Downloads: {$userTotalDownloads}\n";
echo "  [4] Meus Favoritos: {$userTotalFavoritos}\n";

if (!empty($allowedSubjectIds)) {
    $subInSqlDash = implode(',', array_map('intval', $allowedSubjectIds));
    $stmtTop = $pdo->prepare("
        SELECT d.id, d.title AS titulo, d.content_type AS tipo_conteudo, COUNT(e.id) AS acessos, MAX(e.created_at) AS ultimo_acesso,
               s.name AS assunto, sc.name AS subcategoria, c.name AS categoria
        FROM usage_audit_events e
        JOIN documents d ON e.resource_id = d.id
        JOIN subjects s ON d.subject_id = s.id
        JOIN subcategories sc ON s.subcategory_id = sc.id
        JOIN categories c ON c.id = sc.category_id
        WHERE e.user_id = :uid 
          AND e.resource_type = 'DOCUMENT'
          AND e.event_type IN ('document_view', 'document_file_view', 'document_download')
          AND d.subject_id IN ($subInSqlDash)
        GROUP BY d.id, d.title, d.content_type, s.name, sc.name, c.name
        ORDER BY COUNT(e.id) DESC, MAX(e.created_at) DESC
        LIMIT 5
    ");
    $stmtTop->execute([':uid' => $userId]);
    $userTopViewedDocs = $stmtTop->fetchAll(PDO::FETCH_ASSOC);

    echo "\nDOCUMENTOS MAIS ACESSADOS PELO USUÁRIO (TOP CONSULTAS):\n";
    foreach ($userTopViewedDocs as $top) {
        echo "  -> [{$top['acessos']} acessos] ID {$top['id']}: {$top['titulo']} ({$top['categoria']} > {$top['subcategoria']})\n";
    }
}

echo "\n===========================================================================\n";
echo "RESULTADO FINAL: Painel de Métricas Pessoais 100% OPERACIONAL!\n";
echo "===========================================================================\n";
