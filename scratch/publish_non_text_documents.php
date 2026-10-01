<?php

declare(strict_types=1);

require_once __DIR__ . '/_cli_only.php';
// Publicação idempotente de materiais de referência para exercitar os editores
// não textuais. Executar a partir da raiz com: php scratch/publish_non_text_documents.php
define('DOCGOV_SKIP_APP_RUNTIME', true);
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../services/PermissionService.php';
require_once __DIR__ . '/../services/AccessService.php';
require_once __DIR__ . '/../services/DocumentSectionService.php';
require_once __DIR__ . '/../services/DocumentSlugService.php';
require_once __DIR__ . '/../services/DocumentWorkflowService.php';
require_once __DIR__ . '/../services/StructuredContentService.php';
require_once __DIR__ . '/../services/VideoEmbedService.php';

$actorId = 148;
$actor = $pdo->prepare('SELECT name FROM users WHERE id = ? AND active = TRUE');
$actor->execute([$actorId]);
if (!$actor->fetchColumn() || !(new PermissionService($pdo))->isGlobalAdmin($actorId)) {
    throw new RuntimeException('O Super Admin responsável pela publicação não está ativo.');
}

$pdfPath = dirname(__DIR__) . '/output/pdf/checklist-publicacao-acessivel.pdf';
if (!is_file($pdfPath) || filesize($pdfPath) === 0) {
    throw new RuntimeException('Gere e verifique o checklist PDF antes de publicar os conteúdos.');
}

$flow = StructuredContentService::normalize('flow', ['nodes' => [
    ['id' => 'documento', 'type' => 'start', 'title' => 'Separar o documento eletrônico assinado', 'description' => 'Tenha o arquivo, o link ou o QR Code disponível para consulta.', 'owner' => 'Pessoa interessada'],
    ['id' => 'abrir', 'type' => 'process', 'title' => 'Acessar o VALIDAR', 'description' => 'Abra o serviço oficial do Instituto Nacional de Tecnologia da Informação em validar.iti.gov.br.', 'owner' => 'Pessoa interessada'],
    ['id' => 'enviar', 'type' => 'process', 'title' => 'Enviar para validação', 'description' => 'Utilize uma das opções disponibilizadas pelo serviço: arquivo, URL ou QR Code.', 'owner' => 'Pessoa interessada'],
    ['id' => 'resultado', 'type' => 'end', 'title' => 'Conferir o resultado', 'description' => 'Leia o resultado apresentado pelo VALIDAR e, em caso de dúvida, consulte os canais oficiais do ITI.', 'owner' => 'Pessoa interessada'],
]]);

$orgchart = StructuredContentService::normalize('orgchart', ['nodes' => [
    ['id' => 'govdoc', 'name' => 'Papéis de acesso do GovDoc', 'role' => 'Mapa funcional', 'description' => 'Representa capacidades do sistema; não é o organograma formal da prefeitura.'],
    ['id' => 'super-admin', 'parent_id' => 'govdoc', 'name' => 'Super Admin', 'role' => 'Administração global', 'description' => 'Gerencia o sistema e pode administrar os ramos.'],
    ['id' => 'gestor', 'parent_id' => 'govdoc', 'name' => 'Administrador de categoria', 'role' => 'Gestão do ramo', 'description' => 'Administra conteúdo e permissões dentro do escopo autorizado.'],
    ['id' => 'editor', 'parent_id' => 'govdoc', 'name' => 'Editor de categoria', 'role' => 'Produção de conteúdo', 'description' => 'Cria e edita conteúdos no ramo autorizado e envia para revisão.'],
    ['id' => 'leitor', 'parent_id' => 'govdoc', 'name' => 'Leitor', 'role' => 'Consulta', 'description' => 'Visualiza conteúdos publicados conforme suas permissões.'],
]]);

$code = <<<'HTML'
<figure>
  <img
    src="imagem.jpg"
    alt="Descreva aqui a informação essencial transmitida pela imagem"
  >
  <figcaption>Legenda contextual da imagem</figcaption>
</figure>
HTML;

$entries = [
    [
        'subject_id' => 76, 'type' => 'file', 'title' => 'Checklist de publicação acessível (PDF)',
        'description' => 'Roteiro original de apoio para conferir estrutura, linguagem, imagens, links, arquivos e revisão. Baseado em referências do Governo Digital/eMAG e da ENAP; não é norma municipal.',
        'file' => $pdfPath,
    ],
    [
        'subject_id' => 74, 'type' => 'flow', 'title' => 'Fluxo de validação de assinatura eletrônica no VALIDAR',
        'description' => 'Etapas de consulta ao serviço oficial do ITI. Fonte: https://www.gov.br/pt-br/servicos/realizar-validacao-de-assinaturas-eletronicas-validar',
        'structured' => $flow,
    ],
    [
        'subject_id' => 71, 'type' => 'orgchart', 'title' => 'Mapa funcional dos papéis de acesso do GovDoc',
        'description' => 'Representação visual das responsabilidades de Super Admin, administrador de categoria, editor e leitor no GovDoc. Não representa o organograma administrativo da prefeitura.',
        'structured' => $orgchart,
    ],
    [
        'subject_id' => 71, 'type' => 'video', 'title' => 'Palestra do CERT.br sobre autenticação',
        'description' => 'Vídeo indicado pelo próprio CERT.br na Cartilha de Segurança para Internet, fascículo Autenticação. Origem: https://cartilha.cert.br/guardiao/',
        'url' => 'https://youtu.be/5QZRsH7vg5g',
    ],
    [
        'subject_id' => 76, 'type' => 'code', 'title' => 'HTML: imagem com texto alternativo e legenda',
        'description' => 'Modelo de marcação para copiar e adaptar. Substitua o arquivo e descreva a informação real da imagem; não use o texto de exemplo na publicação final.',
        'code' => $code, 'language' => 'xml',
    ],
    [
        'subject_id' => 74, 'type' => 'link', 'title' => 'VALIDAR — serviço oficial do ITI',
        'description' => 'Acesso ao serviço gratuito de validação de assinaturas eletrônicas mantido pelo Instituto Nacional de Tecnologia da Informação.',
        'url' => 'https://validar.iti.gov.br/',
    ],
];

$sections = new DocumentSectionService($pdo);
$slugs = new DocumentSlugService($pdo);
$workflow = new DocumentWorkflowService($pdo, new PermissionService($pdo));
$created = [];
$storedFilePath = null;
$pdo->beginTransaction();
try {
    $subjectStmt = $pdo->prepare('SELECT 1 FROM subjects s JOIN subcategories sc ON sc.id = s.subcategory_id JOIN categories c ON c.id = sc.category_id WHERE s.id = ? AND s.active AND sc.active AND c.active');
    $existingStmt = $pdo->prepare("SELECT id FROM documents WHERE subject_id = ? AND title = ? AND status <> 'inactive' LIMIT 1");
    $insertStmt = $pdo->prepare('INSERT INTO documents (subject_id, created_by, title, slug, description, content_type, section_key, status, original_filename, stored_filename, file_path, mime_type, file_extension, file_size, text_content, code_language, structured_content, external_url) VALUES (:subject_id, :created_by, :title, :slug, :description, :content_type, :section_key, :status, :original_filename, :stored_filename, :file_path, :mime_type, :file_extension, :file_size, :text_content, :code_language, CAST(:structured_content AS JSONB), :external_url) RETURNING id');

    foreach ($entries as $entry) {
        $subjectId = $entry['subject_id'];
        $subjectStmt->execute([$subjectId]);
        if (!$subjectStmt->fetchColumn()) throw new RuntimeException("Assunto {$subjectId} não está ativo.");
        $existingStmt->execute([$subjectId, $entry['title']]);
        if ($existingStmt->fetchColumn()) {
            echo "Já existe: {$entry['title']}\n";
            continue;
        }

        $type = $entry['type'];
        $sectionKey = $sections->defaultSectionForContentType($type);
        $selection = $sections->resolveSelection($sectionKey);
        if ($selection['content_type'] !== $type) throw new RuntimeException("Editor incompatível com {$type}.");
        $externalUrl = $entry['url'] ?? null;
        if ($type === 'video' && VideoEmbedService::resolve((string)$externalUrl)['kind'] !== 'youtube') {
            throw new RuntimeException('Vídeo oficial não foi reconhecido como YouTube.');
        }
        if ($type === 'link' && VideoEmbedService::normalizeExternalUrl((string)$externalUrl) === null) {
            throw new RuntimeException('Link oficial inválido.');
        }

        $filename = null;
        $relativePath = null;
        $fileSize = null;
        if ($type === 'file') {
            $filename = 'doc_' . bin2hex(random_bytes(16)) . '.pdf';
            $storedFilePath = dirname(__DIR__) . '/storage/documents/' . $filename;
            if (!copy($entry['file'], $storedFilePath)) throw new RuntimeException('Falha ao guardar o PDF protegido.');
            $relativePath = 'storage/documents/' . $filename;
            $fileSize = filesize($storedFilePath);
        }

        $insertStmt->execute([
            ':subject_id' => $subjectId,
            ':created_by' => $actorId,
            ':title' => $entry['title'],
            ':slug' => $slugs->reserve($subjectId, $entry['title']),
            ':description' => $entry['description'],
            ':content_type' => $type,
            ':section_key' => $sectionKey,
            ':status' => 'draft',
            ':original_filename' => $type === 'file' ? basename($entry['file']) : null,
            ':stored_filename' => $filename,
            ':file_path' => $relativePath,
            ':mime_type' => $type === 'file' ? 'application/pdf' : null,
            ':file_extension' => $type === 'file' ? 'pdf' : null,
            ':file_size' => $fileSize,
            ':text_content' => $entry['code'] ?? null,
            ':code_language' => $entry['language'] ?? 'auto',
            ':structured_content' => isset($entry['structured']) ? json_encode($entry['structured'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            ':external_url' => $externalUrl,
        ]);
        $documentId = (int)$insertStmt->fetchColumn();
        $workflow->record($documentId, $actorId, 'saved_as_draft', null, 'draft', 'Material de referência para os tipos de conteúdo do GovDoc.');
        foreach ([
            ['submit_review', 'draft', 'Pronto para conferência editorial.'],
            ['review_document', 'review', 'Fonte e apresentação conferidas.'],
            ['approve_publish', 'review', 'Publicado para consulta dos usuários autorizados.'],
        ] as [$requestedAction, $previousStatus, $note]) {
            $transition = $workflow->prepareAction($requestedAction, $previousStatus, $actorId, $documentId, $note);
            $workflow->applyStatus($documentId, $transition['status']);
            $workflow->applyTransitionMetadata($documentId, $actorId, $transition['action'], $transition['note']);
            $workflow->record($documentId, $actorId, $transition['action'], $previousStatus, $transition['status'], $transition['note']);
        }
        $created[] = ['id' => $documentId, 'type' => $type, 'section' => $sectionKey, 'subject' => $subjectId, 'title' => $entry['title']];
    }
    $pdo->commit();
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    if ($storedFilePath !== null && is_file($storedFilePath)) unlink($storedFilePath);
    throw $exception;
}

echo json_encode($created, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
