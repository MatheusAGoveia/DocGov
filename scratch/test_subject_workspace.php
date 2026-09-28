<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../services/SubjectWorkspaceService.php';

$actorId = (int)$pdo->query("SELECT id FROM users WHERE active = TRUE ORDER BY CASE WHEN role = 'admin' THEN 0 ELSE 1 END, id LIMIT 1")->fetchColumn();
if ($actorId <= 0) throw new RuntimeException('Nenhum usuário ativo para executar o teste.');

$token = bin2hex(random_bytes(5));
$categoryId = $subcategoryId = $subjectId = $documentId = 0;
$service = new SubjectWorkspaceService($pdo);

try {
    $stmt = $pdo->prepare("INSERT INTO categories (name, slug, active) VALUES (:name, :slug, TRUE) RETURNING id");
    $stmt->execute([':name' => 'Teste workspace ' . $token, ':slug' => 'test-workspace-' . $token]);
    $categoryId = (int)$stmt->fetchColumn();
    $stmt = $pdo->prepare("INSERT INTO subcategories (category_id, name, slug, active) VALUES (:category_id, :name, :slug, TRUE) RETURNING id");
    $stmt->execute([':category_id' => $categoryId, ':name' => 'Sub teste', ':slug' => 'sub-' . $token]);
    $subcategoryId = (int)$stmt->fetchColumn();
    $stmt = $pdo->prepare("INSERT INTO subjects (subcategory_id, name, slug, active) VALUES (:subcategory_id, :name, :slug, TRUE) RETURNING id");
    $stmt->execute([':subcategory_id' => $subcategoryId, ':name' => 'Processo teste', ':slug' => 'process-' . $token]);
    $subjectId = (int)$stmt->fetchColumn();
    $stmt = $pdo->prepare("INSERT INTO documents (subject_id, created_by, title, slug, content_type, section_key, status) VALUES (:subject_id, :actor_id, 'Vídeo e evidência teste', :slug, 'video', 'videos', 'published') RETURNING id");
    $stmt->execute([':subject_id' => $subjectId, ':actor_id' => $actorId, ':slug' => 'evidence-' . $token]);
    $documentId = (int)$stmt->fetchColumn();

    $service->saveSection($subjectId, $actorId, 'overview', [
        'objective' => 'Validar o workspace', 'owner_name' => 'Equipe de teste', 'audience' => 'Auditores',
        'version_label' => '1.0', 'next_review_on' => '2027-01-10',
    ], true);
    $service->saveSection($subjectId, $actorId, 'description', [
        'description' => 'Descrição completa', 'process_summary' => 'Resumo', 'process_start' => 'Entrada',
        'process_validation' => 'Validação', 'expected_result' => 'Saída',
    ], true);
    $service->saveSection($subjectId, $actorId, 'flow', [
        'flow_title' => ['Receber'], 'flow_description' => ['Receber solicitação'],
        'flow_owner' => ['Atendimento'], 'flow_result' => ['Solicitação registrada'],
    ], true);
    $service->saveSection($subjectId, $actorId, 'steps', [
        'procedure_title' => ['Conferir'], 'procedure_description' => ['Conferir os campos'],
        'procedure_responsible' => ['Analista'], 'procedure_warning' => ['Não aceitar campos vazios'],
    ], true);
    $service->saveSection($subjectId, $actorId, 'video', [
        'video_title' => 'Demonstração', 'video_url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
        'video_document_id' => $documentId,
    ], true);
    $service->saveSection($subjectId, $actorId, 'evidence', [
        'evidence_title' => ['Registro aprovado'], 'evidence_kind' => ['document'],
        'evidence_url' => [''], 'evidence_document_id' => [(string)$documentId],
        'evidence_description' => ['Documento relacionado'],
    ], true);
    $service->saveSection($subjectId, $actorId, 'faq', [
        'faq_kind' => ['error'], 'faq_question' => ['Registro não localizado'],
        'faq_answer' => ['Confirme o identificador e tente novamente.'],
    ], true);
    $workspace = $service->saveSection($subjectId, $actorId, 'integrations', [
        'integration_name' => ['Sistema corporativo'], 'integration_type' => ['API'],
        'integration_url' => ['https://example.com/api'], 'integration_description' => ['Recebe os dados'],
        'integration_status' => ['active'],
    ], true);

    try {
        $service->saveSection($subjectId, $actorId, 'visibility', [
            'enabled_sections' => ['overview'],
        ], true);
        throw new RuntimeException('Foi possível configurar manualmente a visibilidade das seções.');
    } catch (InvalidArgumentException $expected) {
        // Regra esperada: seções públicas agora são derivadas dos documentos publicados.
    }

    $completeness = $service->completeness($workspace);
    if (in_array(false, $completeness, true)) throw new RuntimeException('A validação de preenchimento ficou incompleta.');
    if (!$service->readiness($workspace)['ready']) throw new RuntimeException('O conteúdo completo não foi liberado para revisão.');

    $transition = $service->transition($subjectId, $actorId, 'submit_review', '', false);
    if (($transition['workspace']['documentation_status'] ?? '') !== 'review') throw new RuntimeException('O envio para revisão falhou.');
    try {
        $service->saveSection($subjectId, $actorId, 'description', ['description' => 'Tentativa indevida'], false);
        throw new RuntimeException('Foi possível editar a versão congelada em revisão.');
    } catch (RuntimeException $expected) {
        if (!str_contains($expected->getMessage(), 'só pode ser alterada')) throw $expected;
    }
    try {
        $service->transition($subjectId, $actorId, 'approve', '', false);
        throw new RuntimeException('Um editor conseguiu homologar a documentação.');
    } catch (RuntimeException $expected) {
        if (!str_contains($expected->getMessage(), 'Somente um administrador')) throw $expected;
    }

    $transition = $service->transition($subjectId, $actorId, 'return_changes', 'Ajustar a descrição.', true);
    if (($transition['workspace']['documentation_status'] ?? '') !== 'draft') throw new RuntimeException('A devolução não reabriu o rascunho.');
    $service->transition($subjectId, $actorId, 'submit_review', '', false);
    $transition = $service->transition($subjectId, $actorId, 'approve', 'Conteúdo conferido.', true);
    if (($transition['workspace']['documentation_status'] ?? '') !== 'approved') throw new RuntimeException('A homologação falhou.');
    $service->transition($subjectId, $actorId, 'reopen', 'Preparar a próxima versão.', true);
    $publishedSnapshot = $service->latestApprovedSnapshot($subjectId);
    if (($publishedSnapshot['documentation_status'] ?? '') !== 'approved') throw new RuntimeException('A última versão homologada não foi preservada durante a reabertura.');
    $service->saveSection($subjectId, $actorId, 'description', [
        'description' => 'Descrição da próxima versão', 'process_summary' => 'Resumo', 'process_start' => 'Entrada',
        'process_validation' => 'Validação', 'expected_result' => 'Saída',
    ], true);
    if (count($service->history($subjectId)) !== 14) throw new RuntimeException('O histórico não registrou conteúdo e decisões do fluxo.');
    echo "Teste do workspace aprovado: requisitos, congelamento, papéis, devolução, homologação, versão pública e histórico válidos.\n";
} finally {
    if ($documentId > 0) $pdo->prepare('DELETE FROM documents WHERE id = :id')->execute([':id' => $documentId]);
    if ($subjectId > 0) $pdo->prepare('DELETE FROM subjects WHERE id = :id')->execute([':id' => $subjectId]);
    if ($subcategoryId > 0) $pdo->prepare('DELETE FROM subcategories WHERE id = :id')->execute([':id' => $subcategoryId]);
    if ($categoryId > 0) $pdo->prepare('DELETE FROM categories WHERE id = :id')->execute([':id' => $categoryId]);
}
