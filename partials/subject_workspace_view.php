<?php
$sectionEscape = static fn(mixed $value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
$sectionBaseUrl = 'index.php?' . http_build_query([
    'cat' => $selectedCat,
    'subcat' => $selectedSubcat,
    'assunto' => $selectedAssunto,
]) . '&section=';
$activeSectionDefinition = null;
foreach ($subjectDocumentSections as $definition) {
    if ((string)$definition['section_key'] === $subjectSection) {
        $activeSectionDefinition = $definition;
        break;
    }
}
$activeSectionDocuments = $subjectDocumentsBySection[$subjectSection] ?? [];
$canAddSubjectDocument = $permissionService->canCreateDocument($userId, (int)$assRes['id']);
$newDocumentUrl = 'admin/index.php?' . http_build_query([
    'tab' => 'novo_documento',
    'cat' => $selectedCat,
    'subcat' => $selectedSubcat,
    'subject_id' => (int)$assRes['id'],
    'section' => $subjectSection !== '' ? $subjectSection : 'documents',
]);

$decodeStructuredContent = static function (mixed $value): array {
    if (is_array($value)) return $value;
    if (!is_string($value) || trim($value) === '') return [];
    $decoded = json_decode($value, true);
    return is_array($decoded) ? $decoded : [];
};
?>

<?php if ($subjectDocumentSections === []): ?>
    <section class="rounded-lg border border-dashed border-slate-300 bg-white px-6 py-12 text-center shadow-xs dark:border-[#454956] dark:bg-[#353842]">
        <svg class="mx-auto h-8 w-8 text-slate-300 dark:text-slate-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.6L19 9.4V19a2 2 0 01-2 2z"/></svg>
        <h2 class="mt-3 text-sm font-bold text-slate-900 dark:text-slate-100">Nenhum conteúdo publicado neste assunto</h2>
        <p class="mx-auto mt-1 max-w-lg text-xs leading-5 text-slate-500 dark:text-slate-400">As seções serão criadas automaticamente quando o primeiro conteúdo relacionado for publicado.</p>
        <?php if ($canAddSubjectDocument): ?>
            <a href="<?= $sectionEscape($newDocumentUrl) ?>" class="mt-5 inline-flex rounded-md bg-slate-900 px-4 py-2 text-xs font-semibold text-white transition hover:bg-slate-700 dark:bg-white dark:text-slate-900">Criar conteúdo</a>
        <?php endif; ?>
    </section>
    <?php return; ?>
<?php endif; ?>

<?php if (trim((string)($subjectWorkspace['objective'] ?? '')) !== ''): ?>
    <p class="-mt-4 mb-4 max-w-4xl text-xs leading-5 text-slate-500 dark:text-slate-400"><?= $sectionEscape($subjectWorkspace['objective']) ?></p>
<?php endif; ?>

<div class="mb-5 flex flex-col gap-3 border-b border-slate-200 dark:border-[#454956] sm:flex-row sm:items-end sm:justify-between">
    <nav class="min-w-0 overflow-x-auto" aria-label="Seções disponíveis neste assunto">
        <div class="flex min-w-max items-end gap-1">
            <?php foreach ($subjectDocumentSections as $definition): ?>
                <?php $key = (string)$definition['section_key']; ?>
                <a href="<?= $sectionEscape($sectionBaseUrl . urlencode($key)) ?>"
                   class="flex items-center gap-2 border-b-2 px-3 py-2.5 text-[11px] font-semibold transition <?= $subjectSection === $key ? 'border-slate-900 text-slate-900 dark:border-white dark:text-white' : 'border-transparent text-slate-500 hover:text-slate-800 dark:text-slate-400 dark:hover:text-slate-200' ?>"
                   <?= $subjectSection === $key ? 'aria-current="page"' : '' ?>>
                    <span><?= $sectionEscape($definition['label']) ?></span>
                    <span class="rounded-full bg-slate-100 px-1.5 py-0.5 text-[9px] text-slate-500 dark:bg-[#2c2e33] dark:text-slate-400"><?= (int)$definition['document_count'] ?></span>
                </a>
            <?php endforeach; ?>
        </div>
    </nav>
    <?php if ($canAddSubjectDocument): ?>
        <a href="<?= $sectionEscape($newDocumentUrl) ?>" class="mb-2 inline-flex shrink-0 items-center gap-1.5 self-start rounded-md border border-slate-200 bg-white px-3 py-2 text-[11px] font-semibold text-slate-700 shadow-xs transition hover:bg-slate-50 dark:border-[#454956] dark:bg-[#353842] dark:text-slate-200 dark:hover:bg-[#3e424e]">
            <span aria-hidden="true">+</span> Adicionar nesta seção
        </a>
    <?php endif; ?>
</div>

<header class="mb-4">
    <h2 class="text-base font-bold text-slate-900 dark:text-slate-100"><?= $sectionEscape($activeSectionDefinition['label'] ?? 'Conteúdo') ?></h2>
    <?php if (trim((string)($activeSectionDefinition['description'] ?? '')) !== ''): ?>
        <p class="mt-1 text-xs text-slate-500 dark:text-slate-400"><?= $sectionEscape($activeSectionDefinition['description']) ?></p>
    <?php endif; ?>
</header>

<?php $editorKind = (string)($activeSectionDefinition['editor_kind'] ?? 'file'); ?>

<?php if ($editorKind === 'richtext'): ?>
    <div class="space-y-4">
        <?php foreach ($activeSectionDocuments as $document): ?>
            <?php
            try {
                $safeRichContent = RichTextSanitizer::sanitize((string)($document['text_content'] ?? ''));
            } catch (Throwable) {
                $safeRichContent = '';
            }
            ?>
            <article class="rounded-lg border border-slate-200 bg-white p-5 shadow-xs dark:border-[#454956] dark:bg-[#353842] sm:p-6">
                <div class="flex flex-wrap items-start justify-between gap-3 border-b border-slate-100 pb-3 dark:border-[#454956]">
                    <div><h3 class="text-sm font-bold text-slate-900 dark:text-slate-100"><?= $sectionEscape($document['title']) ?></h3><?php if (!empty($document['description'])): ?><p class="mt-1 text-[11px] leading-5 text-slate-500 dark:text-slate-400"><?= $sectionEscape($document['description']) ?></p><?php endif; ?></div>
                    <a href="ver_conteudo.php?id=<?= (int)$document['id'] ?>" class="text-[11px] font-semibold text-slate-500 hover:text-slate-900 dark:text-slate-400 dark:hover:text-white">Abrir página →</a>
                </div>
                <?php if ($safeRichContent !== ''): ?><div class="govdoc-rich-content mt-5 text-slate-700 dark:text-slate-200"><?= $safeRichContent ?></div><?php else: ?><p class="mt-5 text-xs text-slate-400">Este conteúdo ainda não possui texto.</p><?php endif; ?>
            </article>
        <?php endforeach; ?>
    </div>

<?php elseif ($editorKind === 'flow'): ?>
    <div class="space-y-6">
        <?php foreach ($activeSectionDocuments as $document): ?>
            <?php $structure = $decodeStructuredContent($document['structured_content'] ?? null); $nodes = (array)($structure['nodes'] ?? []); ?>
            <article class="rounded-lg border border-slate-200 bg-white p-5 shadow-xs dark:border-[#454956] dark:bg-[#353842] sm:p-6">
                <div class="mb-5 flex items-start justify-between gap-3"><div><h3 class="text-sm font-bold"><?= $sectionEscape($document['title']) ?></h3><?php if (!empty($document['description'])): ?><p class="mt-1 text-[11px] text-slate-500 dark:text-slate-400"><?= $sectionEscape($document['description']) ?></p><?php endif; ?></div><a href="ver_conteudo.php?id=<?= (int)$document['id'] ?>" class="shrink-0 text-[11px] font-semibold text-slate-500 hover:text-slate-900 dark:hover:text-white">Detalhes →</a></div>
                <ol class="govdoc-flow" aria-label="Etapas de <?= $sectionEscape($document['title']) ?>">
                    <?php foreach ($nodes as $index => $node): ?>
                        <?php $nodeType = (string)($node['type'] ?? 'process'); ?>
                        <li class="govdoc-flow-node <?= in_array($nodeType, ['start', 'end'], true) ? 'govdoc-flow-node--terminal' : ($nodeType === 'decision' ? 'govdoc-flow-node--decision' : '') ?>">
                            <span class="text-[9px] font-bold uppercase tracking-wider text-slate-400"><?= $sectionEscape(['start' => 'Início', 'process' => 'Etapa', 'decision' => 'Decisão', 'end' => 'Fim'][$nodeType] ?? 'Etapa') ?> <?= $index + 1 ?></span>
                            <strong class="mt-1 block text-xs text-slate-900 dark:text-slate-100"><?= $sectionEscape($node['title'] ?? '') ?></strong>
                            <?php if (!empty($node['description'])): ?><p class="mt-1 whitespace-pre-line text-[11px] leading-5 text-slate-500 dark:text-slate-400"><?= $sectionEscape($node['description']) ?></p><?php endif; ?>
                            <?php if (!empty($node['owner'])): ?><span class="mt-2 inline-flex rounded-full bg-slate-100 px-2 py-1 text-[9px] font-semibold text-slate-500 dark:bg-[#2c2e33] dark:text-slate-300">Responsável: <?= $sectionEscape($node['owner']) ?></span><?php endif; ?>
                        </li>
                        <?php if ($index < count($nodes) - 1): ?><li class="govdoc-flow-connector" aria-hidden="true"></li><?php endif; ?>
                    <?php endforeach; ?>
                </ol>
            </article>
        <?php endforeach; ?>
    </div>

<?php elseif ($editorKind === 'orgchart'): ?>
    <div class="space-y-6">
        <?php foreach ($activeSectionDocuments as $document): ?>
            <?php
            $structure = $decodeStructuredContent($document['structured_content'] ?? null);
            $orgNodes = array_values((array)($structure['nodes'] ?? []));
            $orgByParent = [];
            $orgIds = [];
            foreach ($orgNodes as $node) $orgIds[(string)($node['id'] ?? '')] = true;
            foreach ($orgNodes as $node) {
                $parent = (string)($node['parent_id'] ?? '');
                if ($parent === '' || !isset($orgIds[$parent])) $parent = '__root__';
                $orgByParent[$parent][] = $node;
            }
            $orgVisited = [];
            $renderOrgBranch = function (array $node) use (&$renderOrgBranch, &$orgVisited, $orgByParent, $sectionEscape): void {
                $id = (string)($node['id'] ?? '');
                if ($id === '' || isset($orgVisited[$id])) return;
                $orgVisited[$id] = true;
                ?><li class="govdoc-orgchart-branch"><div class="govdoc-orgchart-card"><strong><?= $sectionEscape($node['name'] ?? '') ?></strong><?php if (!empty($node['role'])): ?><span><?= $sectionEscape($node['role']) ?></span><?php endif; ?><?php if (!empty($node['description'])): ?><span><?= $sectionEscape($node['description']) ?></span><?php endif; ?></div><?php if (!empty($orgByParent[$id])): ?><ul class="govdoc-orgchart-children"><?php foreach ($orgByParent[$id] as $child) $renderOrgBranch($child); ?></ul><?php endif; ?></li><?php
            };
            ?>
            <article class="overflow-hidden rounded-lg border border-slate-200 bg-white p-5 shadow-xs dark:border-[#454956] dark:bg-[#353842] sm:p-6">
                <div class="mb-5 flex items-start justify-between gap-3"><div><h3 class="text-sm font-bold"><?= $sectionEscape($document['title']) ?></h3><?php if (!empty($document['description'])): ?><p class="mt-1 text-[11px] text-slate-500 dark:text-slate-400"><?= $sectionEscape($document['description']) ?></p><?php endif; ?></div><a href="ver_conteudo.php?id=<?= (int)$document['id'] ?>" class="shrink-0 text-[11px] font-semibold text-slate-500 hover:text-slate-900 dark:hover:text-white">Detalhes →</a></div>
                <div class="overflow-x-auto pb-2"><div class="govdoc-orgchart"><ul class="govdoc-orgchart-roots"><?php foreach (($orgByParent['__root__'] ?? []) as $rootNode) $renderOrgBranch($rootNode); ?></ul></div></div>
            </article>
        <?php endforeach; ?>
    </div>

<?php elseif ($editorKind === 'video'): ?>
    <div class="grid gap-4 xl:grid-cols-2">
        <?php foreach ($activeSectionDocuments as $document): ?>
            <?php $video = !empty($document['external_url']) ? VideoEmbedService::resolve((string)$document['external_url']) : ['kind' => 'direct', 'url' => 'document-file.php?id=' . (int)$document['id']]; ?>
            <article class="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-xs dark:border-[#454956] dark:bg-[#353842]">
                <div class="flex items-start justify-between gap-3 p-4"><div><h3 class="text-xs font-bold"><?= $sectionEscape($document['title']) ?></h3><?php if (!empty($document['description'])): ?><p class="mt-1 text-[10px] text-slate-500 dark:text-slate-400"><?= $sectionEscape($document['description']) ?></p><?php endif; ?></div><a href="ver_conteudo.php?id=<?= (int)$document['id'] ?>" class="text-[10px] font-semibold text-slate-500">Detalhes →</a></div>
                <?php if (in_array($video['kind'] ?? '', ['youtube', 'vimeo'], true)): ?><iframe class="aspect-video w-full bg-black" src="<?= $sectionEscape($video['embed_url']) ?>" title="<?= $sectionEscape($document['title']) ?>" allow="accelerometer; autoplay; encrypted-media; gyroscope; picture-in-picture" referrerpolicy="strict-origin-when-cross-origin" allowfullscreen></iframe><?php elseif (($video['kind'] ?? '') === 'direct'): ?><video controls preload="metadata" class="aspect-video w-full bg-black"><source src="<?= $sectionEscape($video['url']) ?>"></video><?php else: ?><div class="flex aspect-video items-center justify-center bg-slate-950"><a href="<?= $sectionEscape($video['url'] ?? $document['external_url']) ?>" target="_blank" rel="noopener noreferrer" class="rounded-md bg-white px-4 py-2 text-xs font-semibold text-slate-900">Abrir vídeo</a></div><?php endif; ?>
            </article>
        <?php endforeach; ?>
    </div>

<?php else: ?>
    <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
        <?php foreach ($activeSectionDocuments as $document): ?>
            <?php $isFavorite = isset($favMapDocs[(int)$document['id']]); ?>
            <article class="group relative min-h-32 rounded-lg border <?= $isFavorite ? 'border-amber-500/40' : 'border-slate-200' ?> bg-white p-4 shadow-xs transition hover:-translate-y-0.5 hover:border-slate-400 dark:border-[#454956] dark:bg-[#353842] dark:hover:border-slate-500">
                <a href="ver_conteudo.php?id=<?= (int)$document['id'] ?>" class="absolute inset-0 z-0" aria-label="Abrir <?= $sectionEscape($document['title']) ?>"></a>
                <div class="pointer-events-none relative z-[1] flex items-start justify-between gap-3"><div><span class="text-[9px] font-bold uppercase tracking-wider text-slate-400"><?= $sectionEscape($document['content_type']) ?></span><h3 class="mt-1 text-xs font-bold text-slate-900 dark:text-slate-100"><?= $sectionEscape($document['title']) ?></h3><p class="mt-1 line-clamp-3 text-[10px] leading-4 text-slate-500 dark:text-slate-400"><?= $sectionEscape($document['description'] ?: 'Conteúdo publicado neste assunto.') ?></p></div><button type="button" onclick="toggleEntityFavorito(<?= (int)$document['id'] ?>, 'document', this, event)" class="favorite-card-button pointer-events-auto" aria-label="<?= $isFavorite ? 'Remover dos favoritos' : 'Adicionar aos favoritos' ?>"><svg class="favorite-card-button__icon<?= $isFavorite ? ' is-saved' : '' ?>" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11.049 2.927c.3-.921 1.603-.921 1.902 0l1.519 4.674a1 1 0 00.95.69h4.915c.969 0 1.371 1.24.588 1.81l-3.976 2.888a1 1 0 00-.363 1.118l1.518 4.674c.3.922-.755 1.688-1.538 1.118l-3.976-2.888a1 1 0 00-1.176 0l-3.976 2.888c-.783.57-1.838-.197-1.538-1.118l1.518-4.674a1 1 0 00-.363-1.118l-3.976-2.888c-.784-.57-.38-1.81.588-1.81h4.914a1 1 0 00.951-.69l1.519-4.674z"/></svg></button></div>
                <div class="pointer-events-none relative z-[1] mt-4 border-t border-slate-100 pt-2 text-[9px] font-semibold text-slate-400 dark:border-[#454956]">Abrir conteúdo →</div>
            </article>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
