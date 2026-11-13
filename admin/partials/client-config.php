<?php
/** Dados da página para os scripts administrativos; sem credenciais operacionais. */
if (!defined('DOCGOV_ADMIN_REQUEST') || DOCGOV_ADMIN_REQUEST !== true) {
    http_response_code(404);
    exit;
}

$adminClientConfig = [
    'hierarchy' => [
        'map' => $hierarchyMap,
        'documentSubcategories' => $mapSubcatsParaDocumento,
        'documentSubjects' => $mapAssuntosParaDocumento,
        'subjectSubcategories' => $mapSubcatsParaAssunto,
    ],
    'filters' => ['category' => $filterCat, 'subcategory' => $filterSubcat, 'subject' => $filterAssunto],
    'tags' => [
        'catalog' => $tagCatalog,
        'inactiveNameKeys' => $inactiveTagNameKeys,
        'selectedIds' => $editDocumentTagIds,
        'newNames' => $failedDocumentSubmission !== null ? $requestedNewTagNames : [],
    ],
    'editor' => [
        'category' => $parentCatName ?? '',
        'subcategory' => $selAssItem['subcategoria_nome'] ?? '',
        'subject' => $selAssItem['nome'] ?? '',
        'contents' => $docsAssList ?? [],
        'initialType' => $selectedContentType ?? 'text',
        'initialStructuredContent' => $initialStructuredContent ?? [],
        'hierarchy' => [
            'category' => $editDoc['categoria_id'] ?? null,
            'subcategory' => $editDoc['subcategoria_id'] ?? null,
            'subject' => $editDoc['assunto_id'] ?? null,
        ],
    ],
];
?>
<script id="docgov-admin-config">window.DocGovAdminConfig = <?= json_encode($adminClientConfig, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR) ?>;</script>
