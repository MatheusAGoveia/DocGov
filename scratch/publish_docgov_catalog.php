<?php

declare(strict_types=1);

require_once __DIR__ . '/_cli_only.php';
// Publicação controlada e idempotente do manual em TI > DocGov.
// Uso: php scratch/publish_docgov_catalog.php [--dry-run]
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../services/RichTextSanitizer.php';
require_once __DIR__ . '/../services/StructuredContentService.php';
require_once __DIR__ . '/../services/DocumentWorkflowService.php';
require_once __DIR__ . '/../services/TagService.php';
require_once __DIR__ . '/../services/UsageAuditService.php';

$catalog = require __DIR__ . '/../docs/docgov_catalog.php';
$dryRun = in_array('--dry-run', $argv, true);
$categorySlug = 'tecnologia-da-informacao';
$subcategorySlug = 'docgov';

$categoryStmt = $pdo->prepare('SELECT id FROM categories WHERE slug = :slug AND active = TRUE');
$categoryStmt->execute([':slug' => $categorySlug]);
$categoryId = (int)$categoryStmt->fetchColumn();
if ($categoryId <= 0) {
    throw new RuntimeException('Categoria Tecnologia da Informação não encontrada ou inativa.');
}

$actorId = (int)$pdo->query("SELECT id FROM users WHERE role = 'admin' AND active = TRUE ORDER BY id LIMIT 1")->fetchColumn();
if ($actorId <= 0) {
    throw new RuntimeException('É necessário um administrador global ativo para auditar a publicação.');
}

if ($dryRun) {
    $documentCount = array_sum(array_map(static fn(array $subject): int => count($subject['docs']), $catalog));
    echo "Prévia: categoria {$categoryId}; 1 subcategoria; " . count($catalog) . " assuntos; {$documentCount} documentos; 1 fluxo nativo.\n";
    exit;
}

$permissions = new PermissionService($pdo);
if (!$permissions->isGlobalAdmin($actorId)) {
    throw new RuntimeException('O executor não possui papel de administrador global.');
}
$workflow = new DocumentWorkflowService($pdo, $permissions);
$tags = new TagService($pdo);
$audit = new UsageAuditService($pdo);
$created = ['subcategory' => 0, 'subjects' => 0, 'documents' => 0];
$documentIds = [];

try {
    $pdo->beginTransaction();

    $findSubcategory = $pdo->prepare('SELECT id FROM subcategories WHERE category_id = :category_id AND slug = :slug');
    $findSubcategory->execute([':category_id' => $categoryId, ':slug' => $subcategorySlug]);
    $subcategoryId = (int)$findSubcategory->fetchColumn();
    if ($subcategoryId <= 0) {
        $insertSubcategory = $pdo->prepare('INSERT INTO subcategories (category_id, name, slug, description, active) VALUES (:category_id, :name, :slug, :description, TRUE) RETURNING id');
        $insertSubcategory->execute([
            ':category_id' => $categoryId,
            ':name' => 'DocGov',
            ':slug' => $subcategorySlug,
            ':description' => 'Documentação oficial do portal: uso, arquitetura, configuração, autenticação, publicação, manutenção e recuperação.',
        ]);
        $subcategoryId = (int)$insertSubcategory->fetchColumn();
        $created['subcategory']++;
        $audit->logAdminAction($actorId, 'docgov_manual_subcategory_created', 'SUBCATEGORY', $subcategoryId);
    }
    $activeStmt = $pdo->prepare('SELECT active FROM subcategories WHERE id = :id');
    $activeStmt->execute([':id' => $subcategoryId]);
    if (!filter_var($activeStmt->fetchColumn(), FILTER_VALIDATE_BOOLEAN)) {
        throw new RuntimeException('A subcategoria DocGov existente está inativa. Ative-a pelo painel antes de publicar.');
    }

    $findSubject = $pdo->prepare('SELECT id, active FROM subjects WHERE subcategory_id = :subcategory_id AND slug = :slug');
    $insertSubject = $pdo->prepare('INSERT INTO subjects (subcategory_id, name, slug, description, active) VALUES (:subcategory_id, :name, :slug, :description, TRUE) RETURNING id');
    $findDocument = $pdo->prepare('SELECT id FROM documents WHERE subject_id = :subject_id AND slug = :slug');
    $insertDocument = $pdo->prepare('INSERT INTO documents (subject_id, created_by, title, slug, description, content_type, section_key, status, text_content, code_language, structured_content) VALUES (:subject_id, :created_by, :title, :slug, :description, :content_type, :section_key, :status, :text_content, :code_language, CAST(:structured_content AS JSONB)) RETURNING id');

    foreach ($catalog as $subject) {
        $findSubject->execute([':subcategory_id' => $subcategoryId, ':slug' => $subject['slug']]);
        $existingSubject = $findSubject->fetch(PDO::FETCH_ASSOC);
        if ($existingSubject) {
            if (!filter_var($existingSubject['active'], FILTER_VALIDATE_BOOLEAN)) {
                throw new RuntimeException('Assunto inativo: ' . $subject['slug']);
            }
            $subjectId = (int)$existingSubject['id'];
        } else {
            $insertSubject->execute([
                ':subcategory_id' => $subcategoryId,
                ':name' => $subject['name'],
                ':slug' => $subject['slug'],
                ':description' => $subject['description'],
            ]);
            $subjectId = (int)$insertSubject->fetchColumn();
            $created['subjects']++;
            $audit->logAdminAction($actorId, 'docgov_manual_subject_created', 'SUBJECT', $subjectId);
        }

        foreach ($subject['docs'] as $document) {
            $findDocument->execute([':subject_id' => $subjectId, ':slug' => $document['slug']]);
            $existingDocumentId = (int)$findDocument->fetchColumn();
            if ($existingDocumentId > 0) {
                $documentIds[$document['slug']] = $existingDocumentId;
                continue; // Nunca sobrescreve edições feitas no sistema.
            }

            $isCode = $document['section'] === 'source-code';
            $content = $isCode
                ? (string)$document['code']
                : RichTextSanitizer::sanitize((string)$document['html']);
            if ($content === '') {
                throw new RuntimeException('Conteúdo vazio após sanitização: ' . $document['slug']);
            }
            $insertDocument->execute([
                ':subject_id' => $subjectId,
                ':created_by' => $actorId,
                ':title' => $document['title'],
                ':slug' => $document['slug'],
                ':description' => $document['description'],
                ':content_type' => $isCode ? 'code' : 'text',
                ':section_key' => $document['section'],
                ':status' => 'draft',
                ':text_content' => $content,
                ':code_language' => $isCode ? ($document['language'] ?? 'plaintext') : 'auto',
                ':structured_content' => null,
            ]);
            $documentId = (int)$insertDocument->fetchColumn();
            $documentIds[$document['slug']] = $documentId;
            $tagIds = $tags->resolveForDocument([], $document['tags'] ?? ['DocGov'], $actorId);
            $tags->syncDocumentTags($documentId, $tagIds);

            // Mesmas transições e metadados de revisão usados pelo editor do portal.
            $submitted = $workflow->prepareAction('submit_review', 'draft', $actorId, $documentId, 'Publicação inicial do manual DocGov.');
            $workflow->applyStatus($documentId, $submitted['status']);
            $workflow->applyTransitionMetadata($documentId, $actorId, $submitted['action'], $submitted['note']);
            $workflow->record($documentId, $actorId, $submitted['action'], 'draft', 'review', $submitted['note']);

            $reviewed = $workflow->prepareAction('review_document', 'review', $actorId, $documentId, 'Revisado contra o código e o esquema atuais.');
            $workflow->applyTransitionMetadata($documentId, $actorId, $reviewed['action'], $reviewed['note']);
            $workflow->record($documentId, $actorId, $reviewed['action'], 'review', 'review', $reviewed['note']);

            $published = $workflow->prepareAction('approve_publish', 'review', $actorId, $documentId, 'Manual inicial publicado. Revisar após mudanças do sistema.');
            $workflow->applyStatus($documentId, $published['status']);
            $workflow->applyTransitionMetadata($documentId, $actorId, $published['action'], $published['note']);
            $workflow->record($documentId, $actorId, $published['action'], 'review', 'published', $published['note']);
            $audit->logAdminAction($actorId, 'docgov_manual_document_published', 'DOCUMENT', $documentId);
            $created['documents']++;
        }
    }

    // Fluxo nativo complementa o manual textual e aparece na seção gráfica do assunto.
    $flowSubjectStmt = $pdo->prepare('SELECT id FROM subjects WHERE subcategory_id = :subcategory_id AND slug = :slug');
    $flowSubjectStmt->execute([':subcategory_id' => $subcategoryId, ':slug' => 'fluxo-editorial-e-publicacao']);
    $flowSubjectId = (int)$flowSubjectStmt->fetchColumn();
    $findDocument->execute([':subject_id' => $flowSubjectId, ':slug' => 'ciclo-editorial-do-documento']);
    $flowId = (int)$findDocument->fetchColumn();
    if ($flowId <= 0) {
        $flow = StructuredContentService::normalize('flow', ['nodes' => [
            ['id' => 'inicio', 'type' => 'start', 'title' => 'Criar rascunho', 'description' => 'Editor prepara conteúdo, resumo e tags.', 'owner' => 'Editor'],
            ['id' => 'submeter', 'type' => 'process', 'title' => 'Enviar para revisão', 'description' => 'A transição e a nota são registradas no histórico.', 'owner' => 'Editor'],
            ['id' => 'revisar', 'type' => 'decision', 'title' => 'Revisar conteúdo', 'description' => 'Conferir precisão, acessibilidade, permissão e dados sensíveis. Se necessário, devolver para ajustes.', 'owner' => 'Administrador do recurso'],
            ['id' => 'publicar', 'type' => 'process', 'title' => 'Aprovar e publicar', 'description' => 'Somente após parecer concluído; validar com leitor autorizado.', 'owner' => 'Administrador do recurso'],
            ['id' => 'acompanhar', 'type' => 'end', 'title' => 'Acompanhar e atualizar', 'description' => 'Revisar quando o processo ou o sistema mudar; arquivar material obsoleto.', 'owner' => 'Responsável pelo assunto'],
        ]]);
        $insertDocument->execute([
            ':subject_id' => $flowSubjectId,
            ':created_by' => $actorId,
            ':title' => 'Ciclo editorial do documento',
            ':slug' => 'ciclo-editorial-do-documento',
            ':description' => 'Fluxo visual do rascunho até a publicação e a revisão contínua.',
            ':content_type' => 'flow',
            ':section_key' => 'process-flow',
            ':status' => 'draft',
            ':text_content' => null,
            ':code_language' => 'auto',
            ':structured_content' => json_encode($flow, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        ]);
        $flowId = (int)$insertDocument->fetchColumn();
        $tagIds = $tags->resolveForDocument([], ['DocGov', 'Publicação'], $actorId);
        $tags->syncDocumentTags($flowId, $tagIds);
        foreach ([
            ['submit_review', 'draft', 'review', 'Fluxo visual enviado para revisão.'],
            ['review_document', 'review', 'review', 'Etapas conferidas com o fluxo editorial.'],
            ['approve_publish', 'review', 'published', 'Fluxo visual aprovado.'],
        ] as [$action, $before, $after, $note]) {
            $transition = $workflow->prepareAction($action, $before, $actorId, $flowId, $note);
            $workflow->applyStatus($flowId, $after);
            $workflow->applyTransitionMetadata($flowId, $actorId, $transition['action'], $transition['note']);
            $workflow->record($flowId, $actorId, $transition['action'], $before, $after, $transition['note']);
        }
        $audit->logAdminAction($actorId, 'docgov_manual_document_published', 'DOCUMENT', $flowId);
        $created['documents']++;
    }
    $documentIds['ciclo-editorial-do-documento'] = $flowId;

    $pdo->commit();
    echo json_encode([
        'subcategory_id' => $subcategoryId,
        'created' => $created,
        'total_subjects' => count($catalog),
        'total_documents' => count($documentIds),
        'documents' => $documentIds,
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, 'Publicação cancelada sem alterações parciais: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
