<?php
/**
 * documents/actions/recover-submission.php
 * Entradas: contexto administrativo.
 * Estado compartilhado: $errorMessage, $failedDocumentSubmission.
 * Incluido somente pelo despacho administrativo; preserva o escopo da entrada.
 */
if (!defined('DOCGOV_ADMIN_REQUEST') || DOCGOV_ADMIN_REQUEST !== true) {
    http_response_code(404);
    exit;
}


// Também preserva o formulário quando a validação falha antes da transação.
if (isset($_POST['save_doc']) && !empty($errorMessage) && $failedDocumentSubmission === null && isset($titulo)) {
    $failedDocumentSubmission = ['id' => $id, 'titulo' => $titulo, 'descricao' => $descricao,
        'tipo_conteudo' => $tipoConteudo, 'section_key' => $documentSectionKey,
        'conteudo_html' => $conteudoArmazenado, 'structured_content' => $structuredContent !== null ? json_encode($structuredContent, JSON_THROW_ON_ERROR) : null,
        'linguagem_codigo' => $linguagemCodigo, 'link_externo' => $linkExterno,
        'assunto_id' => $subjectId, 'subcategoria_id' => (int)($subjectContext['subcategory_id'] ?? $subInput),
        'categoria_id' => (int)($subjectContext['category_id'] ?? $catInput),
        'document_version' => (string)($_POST['document_version'] ?? '')];
}
