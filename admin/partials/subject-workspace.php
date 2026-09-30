<?php
/** @var array<string,mixed> $subjectWorkspace */
$workspaceEscape = static fn(mixed $value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
$workspaceBaseUrl = 'index.php?tab=editar_estrutura&type=assunto&id=' . (int)$resId . '&res_tab=';
$workspaceSettingsTabs = [
    'overview' => 'Visão Geral',
    'description' => 'Descrição',
    'flow' => 'Fluxo do Processo',
    'steps' => 'Passo a Passo',
    'video' => 'Vídeo',
    'evidence' => 'Evidências',
    'faq' => 'Erros e FAQ',
    'integrations' => 'Integrações',
];
$workspaceIsSettingsTab = isset($workspaceSettingsTabs[$resTab]);
$workspaceDocumentSectionUrl = $workspaceBaseUrl . 'section&section=';
$workspaceStatusLabels = ['draft' => 'Rascunho', 'review' => 'Em revisão', 'approved' => 'Homologado', 'deprecated' => 'Obsoleto'];
$workspaceStatus = (string)($subjectWorkspace['documentation_status'] ?? 'draft');
$workspaceIsEditable = $workspaceStatus === 'draft';
$workspaceReadiness = $subjectWorkspaceService->readiness($subjectWorkspace);
$workspaceStatusClasses = [
    'draft' => 'border-slate-200 bg-slate-100 text-slate-700 dark:border-slate-600 dark:bg-slate-700 dark:text-slate-200',
    'review' => 'border-amber-300 bg-amber-50 text-amber-800 dark:border-amber-700 dark:bg-amber-950/40 dark:text-amber-300',
    'approved' => 'border-emerald-300 bg-emerald-50 text-emerald-800 dark:border-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300',
    'deprecated' => 'border-rose-300 bg-rose-50 text-rose-800 dark:border-rose-700 dark:bg-rose-950/40 dark:text-rose-300',
];
$workspaceReviewDate = trim((string)($subjectWorkspace['next_review_on'] ?? ''));
$workspaceReviewDue = $workspaceStatus === 'approved' && $workspaceReviewDate !== '' && $workspaceReviewDate <= date('Y-m-d');
$workspaceWorkflowForm = static function () use ($csrfToken, $resId): void { ?>
    <input type="hidden" name="subject_id" value="<?= (int)$resId ?>">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
<?php };
$workspaceSectionForm = static function (string $section) use ($csrfToken, $resId): void { ?>
    <input type="hidden" name="save_subject_workspace" value="1">
    <input type="hidden" name="workspace_section" value="<?= htmlspecialchars($section) ?>">
    <input type="hidden" name="subject_id" value="<?= (int)$resId ?>">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
<?php };
?>

<nav class="overflow-x-auto border-b border-slate-200 dark:border-[#454956]" aria-label="Configurações e tipos de conteúdo do assunto">
    <div class="flex min-w-max items-center gap-1">
        <a href="<?= $workspaceEscape($workspaceBaseUrl . 'overview') ?>"
           class="flex items-center gap-1.5 border-b-2 px-3 py-2.5 text-[11px] font-semibold transition <?= $workspaceIsSettingsTab ? 'border-slate-900 text-slate-900 dark:border-white dark:text-white' : 'border-transparent text-slate-500 hover:text-slate-800 dark:text-slate-400 dark:hover:text-slate-200' ?>"
           <?= $workspaceIsSettingsTab ? 'aria-current="page"' : '' ?>>Configurações</a>
        <?php foreach ($subjectDocumentSectionsAdmin as $sectionDefinition): ?>
            <?php $sectionKey = (string)$sectionDefinition['section_key']; ?>
            <a href="<?= $workspaceEscape($workspaceDocumentSectionUrl . urlencode($sectionKey)) ?>"
               class="flex items-center gap-1.5 border-b-2 px-3 py-2.5 text-[11px] font-semibold transition <?= $resTab === 'section' && ($selectedAdminDocumentSection['section_key'] ?? '') === $sectionKey ? 'border-slate-900 text-slate-900 dark:border-white dark:text-white' : 'border-transparent text-slate-500 hover:text-slate-800 dark:text-slate-400 dark:hover:text-slate-200' ?>"
               <?= $resTab === 'section' && ($selectedAdminDocumentSection['section_key'] ?? '') === $sectionKey ? 'aria-current="page"' : '' ?>>
                <?= $workspaceEscape($sectionDefinition['label']) ?>
                <span class="rounded-full bg-slate-100 px-1.5 py-0.5 text-[9px] text-slate-500 dark:bg-[#2c2e33] dark:text-slate-300"><?= (int)$sectionDefinition['document_count'] ?></span>
            </a>
        <?php endforeach; ?>
        <?php if ($canManageResourcePermissions): ?><a href="<?= $workspaceEscape($workspaceBaseUrl . 'permissions') ?>" class="border-b-2 px-3 py-2.5 text-[11px] font-semibold transition <?= $resTab === 'permissions' ? 'border-slate-900 text-slate-900 dark:border-white dark:text-white' : 'border-transparent text-slate-500 hover:text-slate-800 dark:text-slate-400 dark:hover:text-slate-200' ?>" <?= $resTab === 'permissions' ? 'aria-current="page"' : '' ?>>Permissões</a><?php endif; ?>
        <a href="<?= $workspaceEscape($workspaceBaseUrl . 'history') ?>" class="border-b-2 px-3 py-2.5 text-[11px] font-semibold transition <?= $resTab === 'history' ? 'border-slate-900 text-slate-900 dark:border-white dark:text-white' : 'border-transparent text-slate-500 hover:text-slate-800 dark:text-slate-400 dark:hover:text-slate-200' ?>" <?= $resTab === 'history' ? 'aria-current="page"' : '' ?>>Histórico</a>
    </div>
</nav>

<?php if ($workspaceIsSettingsTab): ?>
    <details class="rounded-lg border border-slate-200 bg-white px-4 py-3 text-xs dark:border-[#454956] dark:bg-[#353842]" <?= $resTab !== 'overview' ? 'open' : '' ?>>
        <summary class="cursor-pointer font-semibold text-slate-700 dark:text-slate-200">Campos de configuração do assunto</summary>
        <nav class="mt-3 flex flex-wrap gap-2" aria-label="Campos de configuração do assunto">
            <?php foreach ($workspaceSettingsTabs as $settingsKey => $settingsLabel): ?>
                <a href="<?= $workspaceEscape($workspaceBaseUrl . urlencode($settingsKey)) ?>" class="rounded-md border px-2.5 py-1.5 text-[11px] font-medium <?= $resTab === $settingsKey ? 'border-slate-900 bg-slate-900 text-white dark:border-white dark:bg-white dark:text-slate-900' : 'border-slate-200 text-slate-600 hover:bg-slate-50 dark:border-[#454956] dark:text-slate-300 dark:hover:bg-[#2c2e33]' ?>" <?= $resTab === $settingsKey ? 'aria-current="page"' : '' ?>><?= $workspaceEscape($settingsLabel) ?></a>
            <?php endforeach; ?>
        </nav>
    </details>
<?php endif; ?>
<?php if ($subjectDocumentSectionsAdmin === []): ?>
    <p class="text-[11px] text-slate-500 dark:text-slate-400">As abas de conteúdo aparecem aqui quando um documento do respectivo tipo é criado neste assunto.</p>
<?php endif; ?>

<section class="rounded-lg border border-slate-200 bg-white p-4 shadow-xs dark:border-[#454956] dark:bg-[#353842]" aria-label="Fluxo editorial da documentação">
    <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
        <div class="min-w-0">
            <div class="flex flex-wrap items-center gap-2">
                <span class="inline-flex rounded-full border px-2.5 py-1 text-[10px] font-bold uppercase tracking-wide <?= $workspaceStatusClasses[$workspaceStatus] ?? $workspaceStatusClasses['draft'] ?>"><?= $workspaceEscape($workspaceStatusLabels[$workspaceStatus] ?? 'Rascunho') ?></span>
                <span class="text-[11px] font-semibold text-slate-500 dark:text-slate-400"><?= (int)$workspaceReadiness['percent'] ?>% dos requisitos editoriais</span>
                <?php if ($workspaceReviewDue): ?><span class="rounded-full bg-rose-500/10 px-2.5 py-1 text-[10px] font-bold text-rose-700 dark:text-rose-300">Revisão vencida</span><?php endif; ?>
            </div>
            <p class="mt-2 max-w-3xl text-xs leading-5 text-slate-500 dark:text-slate-400">
                <?php if ($workspaceStatus === 'draft'): ?>Conteúdo editável. Quando os requisitos estiverem completos, envie para revisão do gestor.
                <?php elseif ($workspaceStatus === 'review'): ?>Versão congelada enquanto aguarda decisão de um administrador deste assunto.
                <?php elseif ($workspaceStatus === 'approved'): ?>Versão homologada. Para alterar o conteúdo, um administrador precisa reabrir a edição.
                <?php else: ?>Versão obsoleta e fora da visualização principal do portal. O histórico permanece preservado.<?php endif; ?>
            </p>
            <?php if (!empty($subjectWorkspace['return_reason']) && $workspaceStatus === 'draft'): ?>
                <div class="mt-3 rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-[11px] leading-5 text-amber-900 dark:border-amber-800 dark:bg-amber-950/30 dark:text-amber-200"><strong>Ajustes solicitados:</strong> <?= $workspaceEscape($subjectWorkspace['return_reason']) ?></div>
            <?php endif; ?>
        </div>

        <div class="w-full shrink-0 lg:max-w-md">
            <?php if ($workspaceStatus === 'draft'): ?>
                <?php if (!$workspaceReadiness['ready']): ?>
                    <details class="mb-3 rounded-md border border-slate-200 px-3 py-2 dark:border-[#454956]">
                        <summary class="cursor-pointer text-[11px] font-semibold text-slate-700 dark:text-slate-200"><?= count($workspaceReadiness['missing']) ?> pendência<?= count($workspaceReadiness['missing']) === 1 ? '' : 's' ?> antes da revisão</summary>
                        <div class="mt-2 space-y-1.5"><?php foreach ($workspaceReadiness['missing'] as $missing): ?><a class="block text-[11px] text-slate-500 hover:text-slate-900 dark:text-slate-400 dark:hover:text-white" href="<?= $workspaceBaseUrl . urlencode($missing['tab']) ?>">• <?= $workspaceEscape($missing['label']) ?></a><?php endforeach; ?></div>
                    </details>
                <?php endif; ?>
                <form method="POST" class="flex justify-end">
                    <?php $workspaceWorkflowForm(); ?>
                    <input type="hidden" name="subject_workspace_action" value="submit_review">
                    <button <?= !$workspaceReadiness['ready'] ? 'disabled' : '' ?> class="rounded-md bg-slate-900 px-4 py-2 text-xs font-semibold text-white disabled:cursor-not-allowed disabled:opacity-40 dark:bg-white dark:text-slate-900">Enviar para revisão</button>
                </form>
            <?php elseif ($workspaceStatus === 'review' && $canManageResourcePermissions): ?>
                <div class="grid gap-2 sm:grid-cols-2">
                    <form method="POST" class="rounded-md border border-emerald-200 p-3 dark:border-emerald-900/70">
                        <?php $workspaceWorkflowForm(); ?>
                        <input type="hidden" name="subject_workspace_action" value="approve">
                        <label class="block text-[10px] font-semibold text-slate-500">Observação opcional</label>
                        <textarea name="workflow_note" rows="2" maxlength="3000" class="input-minimal mt-1 w-full px-2 py-1.5 text-[11px]"></textarea>
                        <button class="mt-2 w-full rounded-md bg-emerald-600 px-3 py-2 text-[11px] font-bold text-white hover:bg-emerald-700">Revisar e homologar</button>
                    </form>
                    <form method="POST" class="rounded-md border border-amber-200 p-3 dark:border-amber-900/70">
                        <?php $workspaceWorkflowForm(); ?>
                        <input type="hidden" name="subject_workspace_action" value="return_changes">
                        <label class="block text-[10px] font-semibold text-slate-500">Motivo dos ajustes *</label>
                        <textarea name="workflow_note" required minlength="5" rows="2" maxlength="3000" class="input-minimal mt-1 w-full px-2 py-1.5 text-[11px]"></textarea>
                        <button class="mt-2 w-full rounded-md border border-amber-400 px-3 py-2 text-[11px] font-bold text-amber-800 dark:text-amber-300">Devolver para ajustes</button>
                    </form>
                </div>
            <?php elseif ($workspaceStatus === 'review'): ?>
                <p class="text-right text-[11px] font-semibold text-amber-700 dark:text-amber-300">Aguardando revisão do gestor.</p>
            <?php elseif (in_array($workspaceStatus, ['approved', 'deprecated'], true) && $canManageResourcePermissions): ?>
                <div class="grid gap-2 sm:grid-cols-2">
                    <form method="POST">
                        <?php $workspaceWorkflowForm(); ?>
                        <input type="hidden" name="subject_workspace_action" value="reopen">
                        <input name="workflow_note" required minlength="5" maxlength="3000" class="input-minimal w-full px-2 py-2 text-[11px]" placeholder="Motivo para reabrir *">
                        <button class="mt-2 w-full rounded-md bg-slate-900 px-3 py-2 text-[11px] font-bold text-white dark:bg-white dark:text-slate-900">Reabrir edição</button>
                    </form>
                    <?php if ($workspaceStatus === 'approved'): ?>
                        <form method="POST">
                            <?php $workspaceWorkflowForm(); ?>
                            <input type="hidden" name="subject_workspace_action" value="deprecate">
                            <input name="workflow_note" required minlength="5" maxlength="3000" class="input-minimal w-full px-2 py-2 text-[11px]" placeholder="Motivo da obsolescência *">
                            <button class="mt-2 w-full rounded-md border border-rose-300 px-3 py-2 text-[11px] font-bold text-rose-700 dark:border-rose-800 dark:text-rose-300">Marcar obsoleto</button>
                        </form>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</section>

<?php if ($resTab === 'section' && $selectedAdminDocumentSection !== null): ?>
    <?php
        $selectedSectionKey = (string)$selectedAdminDocumentSection['section_key'];
        $sectionDocuments = array_values(array_filter(
            $subjectWorkspaceDocuments,
            static fn(array $document): bool => (string)$document['section_key'] === $selectedSectionKey
        ));
        $newSectionDocumentUrl = 'index.php?' . http_build_query([
            'tab' => 'novo_documento',
            'subject_id' => (int)$resId,
            'section' => $selectedSectionKey,
        ]);
    ?>
    <section class="rounded-lg border border-slate-200 bg-white p-5 shadow-xs dark:border-[#454956] dark:bg-[#353842]" aria-labelledby="subject-section-title">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h2 id="subject-section-title" class="text-sm font-bold text-slate-900 dark:text-slate-100"><?= $workspaceEscape($selectedAdminDocumentSection['label']) ?></h2>
                <p class="mt-1 text-xs text-slate-500 dark:text-slate-400"><?= $workspaceEscape($selectedAdminDocumentSection['description']) ?></p>
                <p class="mt-2 text-[11px] text-slate-400"><?= count($sectionDocuments) ?> conteúdo<?= count($sectionDocuments) === 1 ? '' : 's' ?> neste assunto · <?= (int)$selectedAdminDocumentSection['published_count'] ?> publicado<?= (int)$selectedAdminDocumentSection['published_count'] === 1 ? '' : 's' ?></p>
            </div>
            <?php if ($permService->canCreateDocument($currentAdminUserId, (int)$resId)): ?>
                <a href="<?= $workspaceEscape($newSectionDocumentUrl) ?>" class="rounded-md bg-slate-900 px-3 py-2 text-[11px] font-semibold text-white dark:bg-white dark:text-slate-900">+ Adicionar conteúdo</a>
            <?php endif; ?>
        </div>
        <div class="mt-4 divide-y divide-slate-100 border-t border-slate-100 dark:divide-[#454956] dark:border-[#454956]">
            <?php foreach ($sectionDocuments as $document): ?>
                <article class="flex flex-wrap items-center justify-between gap-3 py-3">
                    <div class="min-w-0">
                        <h3 class="text-xs font-semibold text-slate-800 dark:text-slate-100"><?= $workspaceEscape($document['title']) ?></h3>
                        <?php if (trim((string)$document['description']) !== ''): ?><p class="mt-1 text-[11px] text-slate-500 dark:text-slate-400"><?= $workspaceEscape($document['description']) ?></p><?php endif; ?>
                        <span class="mt-1 inline-block text-[10px] font-semibold text-slate-400"><?= $workspaceEscape(DocumentWorkflowService::label((string)$document['status'])) ?></span>
                    </div>
                    <div class="flex items-center gap-3 text-[11px] font-semibold">
                        <?php if ($permService->canEditDocument($currentAdminUserId, (int)$document['id'])): ?><a href="index.php?tab=novo_documento&amp;action=edit_doc&amp;id=<?= (int)$document['id'] ?>" class="text-slate-700 hover:underline dark:text-slate-200">Editar</a><?php endif; ?>
                        <?php if ($document['status'] === 'published'): ?><a href="../ver_conteudo.php?id=<?= (int)$document['id'] ?>" class="text-slate-500 hover:underline dark:text-slate-400">Visualizar</a><?php endif; ?>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    </section>

<?php elseif ($resTab === 'overview'): ?>
    <div class="grid gap-4 xl:grid-cols-[minmax(0,1fr)_18rem]">
        <div class="space-y-4">
            <form method="POST" action="index.php?tab=assuntos" class="rounded-lg border border-slate-200 bg-white p-5 shadow-xs dark:border-[#454956] dark:bg-[#353842]">
                <input type="hidden" name="save_subject" value="1">
                <input type="hidden" name="id" value="<?= (int)$resId ?>">
                <input type="hidden" name="subcategory_id" value="<?= (int)$resData['subcategory_id'] ?>">
                <input type="hidden" name="redirect_query" value="tab=editar_estrutura&type=assunto&id=<?= (int)$resId ?>&res_tab=overview">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                <div class="mb-4">
                    <h2 class="text-sm font-bold text-slate-900 dark:text-slate-100">Identidade do processo</h2>
                    <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">Nome e resumo usados na árvore de categorias.</p>
                </div>
                <div class="grid gap-4 md:grid-cols-2">
                    <div class="md:col-span-2 space-y-3">
                        <p class="text-xs text-slate-500 dark:text-slate-400"><?= $workspaceEscape($resData['category_name']) ?> › <?= $workspaceEscape($resData['subcategory_name']) ?></p>
                        <?php
                            $subjectVisibilityValue = $resData['visibility'];
                            $subjectVisibilityControlId = 'resource-subject-visibility';
                            $subjectVisibilityCreationMode = false;
                            $canManageSubjectVisibility = $canManageResourcePermissions;
                            require __DIR__ . '/../../partials/subject_visibility.php';
                        ?>
                    </div>
                    <label class="block md:col-span-2"><span class="mb-1 block text-xs font-semibold">Nome *</span><input name="nome" required maxlength="255" value="<?= $workspaceEscape($resData['name']) ?>" class="input-minimal w-full px-3 py-2 text-xs"></label>
                    <label class="block md:col-span-2"><span class="mb-1 block text-xs font-semibold">Resumo na árvore</span><textarea name="descricao" rows="3" maxlength="2000" class="input-minimal w-full px-3 py-2 text-xs"><?= $workspaceEscape($resData['description']) ?></textarea></label>
                    <label class="block"><span class="mb-1 block text-xs font-semibold">Status do assunto</span><select name="status" class="input-minimal w-full px-3 py-2 text-xs"><option value="ativo" <?= $resData['active'] ? 'selected' : '' ?>>Ativo</option><option value="inativo" <?= !$resData['active'] ? 'selected' : '' ?>>Inativo</option></select></label>
                </div>
                <div class="mt-4 flex justify-end border-t border-slate-100 pt-4 dark:border-[#454956]"><button class="rounded-md bg-slate-900 px-4 py-2 text-xs font-semibold text-white dark:bg-white dark:text-slate-900">Salvar identidade</button></div>
            </form>

            <form method="POST" class="rounded-lg border border-slate-200 bg-white p-5 shadow-xs dark:border-[#454956] dark:bg-[#353842]">
                <?php $workspaceSectionForm('overview'); ?>
                <div class="mb-4">
                    <h2 class="text-sm font-bold text-slate-900 dark:text-slate-100">1. Visão geral</h2>
                    <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">Contexto essencial para quem consulta o processo.</p>
                </div>
                <div class="grid gap-4 md:grid-cols-2">
                    <label class="block md:col-span-2"><span class="mb-1 block text-xs font-semibold">Objetivo</span><textarea name="objective" rows="3" maxlength="600" class="input-minimal w-full px-3 py-2 text-xs" placeholder="Qual resultado este processo precisa garantir?"><?= $workspaceEscape($subjectWorkspace['objective']) ?></textarea></label>
                    <label class="block"><span class="mb-1 block text-xs font-semibold">Responsável</span><input name="owner_name" maxlength="255" value="<?= $workspaceEscape($subjectWorkspace['owner_name']) ?>" class="input-minimal w-full px-3 py-2 text-xs" placeholder="Equipe ou pessoa responsável"></label>
                    <label class="block"><span class="mb-1 block text-xs font-semibold">Público-alvo</span><input name="audience" maxlength="600" value="<?= $workspaceEscape($subjectWorkspace['audience']) ?>" class="input-minimal w-full px-3 py-2 text-xs" placeholder="Quem executa ou consulta"></label>
                    <div class="block"><span class="mb-1 block text-xs font-semibold">Status da documentação</span><div class="input-minimal flex min-h-[34px] w-full items-center px-3 py-2 text-xs"><span class="inline-flex rounded-full border px-2 py-0.5 text-[9px] font-bold uppercase <?= $workspaceStatusClasses[$workspaceStatus] ?? $workspaceStatusClasses['draft'] ?>"><?= $workspaceEscape($workspaceStatusLabels[$workspaceStatus] ?? 'Rascunho') ?></span></div><small class="mt-1 block text-[10px] text-slate-400">O status muda somente pelas ações do fluxo editorial.</small></div>
                    <label class="block"><span class="mb-1 block text-xs font-semibold">Versão</span><input name="version_label" maxlength="30" value="<?= $workspaceEscape($subjectWorkspace['version_label']) ?>" class="input-minimal w-full px-3 py-2 text-xs" placeholder="1.0"></label>
                    <label class="block"><span class="mb-1 block text-xs font-semibold">Próxima revisão</span><input type="date" name="next_review_on" value="<?= $workspaceEscape($subjectWorkspace['next_review_on']) ?>" class="input-minimal w-full px-3 py-2 text-xs"></label>
                </div>
                <div class="mt-4 flex justify-end border-t border-slate-100 pt-4 dark:border-[#454956]"><button class="rounded-md bg-slate-900 px-4 py-2 text-xs font-semibold text-white dark:bg-white dark:text-slate-900">Salvar visão geral</button></div>
            </form>
        </div>

        <aside class="h-fit rounded-lg border border-slate-200 bg-white p-4 shadow-xs dark:border-[#454956] dark:bg-[#353842] xl:sticky xl:top-20">
            <h3 class="text-xs font-bold text-slate-900 dark:text-slate-100">Resumo de preenchimento</h3>
            <div class="mt-3 space-y-2">
                <?php foreach (['overview'=>'Visão geral','description'=>'Descrição','flow'=>'Fluxo','steps'=>'Passo a passo','video'=>'Vídeo','evidence'=>'Evidências','faq'=>'Erros e FAQ','integrations'=>'Integrações'] as $key => $label): ?>
                    <a href="<?= $workspaceBaseUrl . $key ?>" class="flex items-center justify-between gap-3 rounded px-2 py-1.5 text-[11px] hover:bg-slate-50 dark:hover:bg-[#2c2e33]"><span><?= $label ?></span><span class="<?= !empty($subjectWorkspaceCompleteness[$key]) ? 'text-emerald-600' : 'text-slate-300 dark:text-slate-600' ?>"><?= !empty($subjectWorkspaceCompleteness[$key]) ? 'Concluído' : 'Pendente' ?></span></a>
                <?php endforeach; ?>
            </div>
            <div class="mt-4 border-t border-slate-100 pt-3 text-[10px] text-slate-400 dark:border-[#454956]">Última atualização: <?= !empty($subjectWorkspace['updated_at']) ? date('d/m/Y H:i', strtotime((string)$subjectWorkspace['updated_at'])) : 'ainda não preenchida' ?></div>
        </aside>
    </div>

<?php elseif ($resTab === 'description'): ?>
    <form method="POST" class="rounded-lg border border-slate-200 bg-white p-5 shadow-xs dark:border-[#454956] dark:bg-[#353842]">
        <?php $workspaceSectionForm('description'); ?>
        <h2 class="text-sm font-bold">2. Descrição do processo</h2>
        <p class="mt-1 text-xs text-slate-500">Explique o contexto, os limites e o resultado esperado.</p>
        <div class="mt-4 grid gap-4 md:grid-cols-3">
            <label class="block md:col-span-3"><span class="mb-1 block text-xs font-semibold">Descrição completa</span><textarea name="description" rows="8" maxlength="12000" class="input-minimal w-full px-3 py-2 text-xs" placeholder="Contexto, regras, exceções e responsabilidades..."><?= $workspaceEscape($subjectWorkspace['description']) ?></textarea></label>
            <label class="block md:col-span-3"><span class="mb-1 block text-xs font-semibold">Resumo executivo</span><textarea name="process_summary" rows="4" maxlength="6000" class="input-minimal w-full px-3 py-2 text-xs"><?= $workspaceEscape($subjectWorkspace['process_summary']) ?></textarea></label>
            <label class="block"><span class="mb-1 block text-xs font-semibold">Início do processo</span><textarea name="process_start" rows="4" maxlength="2000" class="input-minimal w-full px-3 py-2 text-xs"><?= $workspaceEscape($subjectWorkspace['process_start']) ?></textarea></label>
            <label class="block"><span class="mb-1 block text-xs font-semibold">Validação</span><textarea name="process_validation" rows="4" maxlength="2000" class="input-minimal w-full px-3 py-2 text-xs"><?= $workspaceEscape($subjectWorkspace['process_validation']) ?></textarea></label>
            <label class="block"><span class="mb-1 block text-xs font-semibold">Resultado esperado</span><textarea name="expected_result" rows="4" maxlength="2000" class="input-minimal w-full px-3 py-2 text-xs"><?= $workspaceEscape($subjectWorkspace['expected_result']) ?></textarea></label>
        </div>
        <div class="mt-4 flex justify-end"><button class="rounded-md bg-slate-900 px-4 py-2 text-xs font-semibold text-white dark:bg-white dark:text-slate-900">Salvar descrição</button></div>
    </form>

<?php elseif ($resTab === 'flow'): ?>
    <form method="POST" class="rounded-lg border border-slate-200 bg-white p-5 shadow-xs dark:border-[#454956] dark:bg-[#353842]">
        <?php $workspaceSectionForm('flow'); ?>
        <div class="flex items-start justify-between gap-4"><div><h2 class="text-sm font-bold">3. Fluxo do processo</h2><p class="mt-1 text-xs text-slate-500">Organize as etapas na ordem em que acontecem.</p></div><button type="button" onclick="addWorkspaceRow('flow')" class="rounded-md border border-slate-200 px-3 py-2 text-[11px] font-semibold dark:border-[#454956]">+ Adicionar etapa</button></div>
        <div data-workspace-list="flow" class="mt-4 space-y-3">
            <?php foreach ($subjectWorkspace['flow_steps'] as $index => $row): ?>
                <div data-workspace-row class="rounded-md border border-slate-200 p-4 dark:border-[#454956]"><div class="mb-3 flex items-center justify-between"><strong class="text-xs">Etapa <span data-row-number><?= $index + 1 ?></span></strong><button type="button" onclick="removeWorkspaceRow(this)" class="text-[11px] font-semibold text-red-600">Remover</button></div><div class="grid gap-3 md:grid-cols-2"><input name="flow_title[]" required maxlength="160" value="<?= $workspaceEscape($row['title'] ?? '') ?>" class="input-minimal px-3 py-2 text-xs" placeholder="Título da etapa *"><input name="flow_owner[]" maxlength="160" value="<?= $workspaceEscape($row['owner'] ?? '') ?>" class="input-minimal px-3 py-2 text-xs" placeholder="Responsável"><textarea name="flow_description[]" rows="3" maxlength="1500" class="input-minimal px-3 py-2 text-xs" placeholder="O que acontece nesta etapa"><?= $workspaceEscape($row['description'] ?? '') ?></textarea><textarea name="flow_result[]" rows="3" maxlength="500" class="input-minimal px-3 py-2 text-xs" placeholder="Saída ou decisão"><?= $workspaceEscape($row['result'] ?? '') ?></textarea></div></div>
            <?php endforeach; ?>
        </div>
        <div class="mt-4 flex justify-end"><button class="rounded-md bg-slate-900 px-4 py-2 text-xs font-semibold text-white dark:bg-white dark:text-slate-900">Salvar fluxo</button></div>
    </form>

<?php elseif ($resTab === 'steps'): ?>
    <form method="POST" class="rounded-lg border border-slate-200 bg-white p-5 shadow-xs dark:border-[#454956] dark:bg-[#353842]">
        <?php $workspaceSectionForm('steps'); ?>
        <div class="flex items-start justify-between gap-4"><div><h2 class="text-sm font-bold">4. Passo a passo</h2><p class="mt-1 text-xs text-slate-500">Instruções executáveis, responsáveis e alertas.</p></div><button type="button" onclick="addWorkspaceRow('procedure')" class="rounded-md border border-slate-200 px-3 py-2 text-[11px] font-semibold dark:border-[#454956]">+ Adicionar passo</button></div>
        <div data-workspace-list="procedure" class="mt-4 space-y-3">
            <?php foreach ($subjectWorkspace['procedure_steps'] as $index => $row): ?>
                <div data-workspace-row class="rounded-md border border-slate-200 p-4 dark:border-[#454956]"><div class="mb-3 flex items-center justify-between"><strong class="text-xs">Passo <span data-row-number><?= $index + 1 ?></span></strong><button type="button" onclick="removeWorkspaceRow(this)" class="text-[11px] font-semibold text-red-600">Remover</button></div><div class="grid gap-3 md:grid-cols-2"><input name="procedure_title[]" required maxlength="160" value="<?= $workspaceEscape($row['title'] ?? '') ?>" class="input-minimal px-3 py-2 text-xs" placeholder="Título do passo *"><input name="procedure_responsible[]" maxlength="160" value="<?= $workspaceEscape($row['responsible'] ?? '') ?>" class="input-minimal px-3 py-2 text-xs" placeholder="Responsável"><textarea name="procedure_description[]" required rows="4" maxlength="3000" class="input-minimal px-3 py-2 text-xs" placeholder="Instrução detalhada *"><?= $workspaceEscape($row['description'] ?? '') ?></textarea><textarea name="procedure_warning[]" rows="4" maxlength="600" class="input-minimal px-3 py-2 text-xs" placeholder="Atenção, condição ou exceção"><?= $workspaceEscape($row['warning'] ?? '') ?></textarea></div></div>
            <?php endforeach; ?>
        </div>
        <div class="mt-4 flex justify-end"><button class="rounded-md bg-slate-900 px-4 py-2 text-xs font-semibold text-white dark:bg-white dark:text-slate-900">Salvar passo a passo</button></div>
    </form>

<?php elseif ($resTab === 'video'): ?>
    <div class="grid gap-4 lg:grid-cols-2">
        <form method="POST" class="h-fit rounded-lg border border-slate-200 bg-white p-5 shadow-xs dark:border-[#454956] dark:bg-[#353842]">
            <?php $workspaceSectionForm('video'); ?>
            <h2 class="text-sm font-bold">5. Vídeo</h2><p class="mt-1 text-xs text-slate-500">YouTube, Vimeo ou endereço direto de vídeo.</p>
            <div class="mt-4 space-y-4"><label class="block"><span class="mb-1 block text-xs font-semibold">Título</span><input name="video_title" maxlength="255" value="<?= $workspaceEscape($subjectWorkspace['video_title']) ?>" class="input-minimal w-full px-3 py-2 text-xs"></label><label class="block"><span class="mb-1 block text-xs font-semibold">Vídeo local já cadastrado</span><select name="video_document_id" class="input-minimal w-full px-3 py-2 text-xs"><option value="0">Nenhum vídeo local selecionado</option><?php foreach ($subjectWorkspaceDocuments as $document): ?><?php if (($document['content_type'] ?? '') !== 'video') continue; ?><option value="<?= (int)$document['id'] ?>" <?= (int)($subjectWorkspace['video_document_id'] ?? 0) === (int)$document['id'] ? 'selected' : '' ?>><?= $workspaceEscape($document['title']) ?></option><?php endforeach; ?></select><small class="mt-1 block text-[10px] text-slate-400">O vídeo local tem prioridade sobre a URL externa.</small></label><label class="block"><span class="mb-1 block text-xs font-semibold">URL do vídeo</span><input type="url" name="video_url" maxlength="2000" value="<?= $workspaceEscape($subjectWorkspace['video_url']) ?>" class="input-minimal w-full px-3 py-2 text-xs" placeholder="https://..."></label></div>
            <div class="mt-4 flex justify-end"><button class="rounded-md bg-slate-900 px-4 py-2 text-xs font-semibold text-white dark:bg-white dark:text-slate-900">Salvar vídeo</button></div>
        </form>
        <section class="overflow-hidden rounded-lg border border-slate-200 bg-slate-950 shadow-xs dark:border-[#454956]">
            <?php if (in_array($subjectVideoEmbed['kind'] ?? '', ['youtube','vimeo'], true)): ?><iframe src="<?= $workspaceEscape($subjectVideoEmbed['embed_url']) ?>" class="aspect-video w-full" allow="accelerometer; autoplay; encrypted-media; gyroscope; picture-in-picture" referrerpolicy="strict-origin-when-cross-origin" allowfullscreen title="Pré-visualização do vídeo"></iframe>
            <?php elseif (($subjectVideoEmbed['kind'] ?? '') === 'direct'): ?><video controls class="aspect-video w-full bg-black"><source src="<?= $workspaceEscape($subjectVideoEmbed['url']) ?>"></video>
            <?php elseif (($subjectVideoEmbed['kind'] ?? '') === 'external'): ?><div class="flex aspect-video items-center justify-center p-6 text-center"><a href="<?= $workspaceEscape($subjectVideoEmbed['url']) ?>" target="_blank" rel="noopener noreferrer" class="rounded-md bg-white px-4 py-2 text-xs font-semibold text-slate-900">Abrir vídeo externo</a></div>
            <?php else: ?><div class="flex aspect-video items-center justify-center p-6 text-center text-xs text-slate-400">Informe uma URL para ver a pré-visualização.</div><?php endif; ?>
        </section>
    </div>

<?php elseif ($resTab === 'evidence'): ?>
    <form method="POST" class="rounded-lg border border-slate-200 bg-white p-5 shadow-xs dark:border-[#454956] dark:bg-[#353842]">
        <?php $workspaceSectionForm('evidence'); ?>
        <div class="flex items-start justify-between gap-4"><div><h2 class="text-sm font-bold">6. Evidências</h2><p class="mt-1 text-xs text-slate-500">Relacione documentos do assunto, registros, imagens ou links externos.</p></div><button type="button" onclick="addWorkspaceRow('evidence')" class="rounded-md border border-slate-200 px-3 py-2 text-[11px] font-semibold dark:border-[#454956]">+ Adicionar evidência</button></div>
        <div data-workspace-list="evidence" class="mt-4 space-y-3">
            <?php foreach ($subjectWorkspace['evidences'] as $index => $row): ?>
                <div data-workspace-row class="rounded-md border border-slate-200 p-4 dark:border-[#454956]"><div class="mb-3 flex items-center justify-between"><strong class="text-xs">Evidência <span data-row-number><?= $index + 1 ?></span></strong><button type="button" onclick="removeWorkspaceRow(this)" class="text-[11px] font-semibold text-red-600">Remover</button></div><div class="grid gap-3 md:grid-cols-2"><input name="evidence_title[]" required maxlength="160" value="<?= $workspaceEscape($row['title'] ?? '') ?>" class="input-minimal px-3 py-2 text-xs" placeholder="Título *"><select name="evidence_kind[]" class="input-minimal px-3 py-2 text-xs"><?php foreach (['record'=>'Registro','document'=>'Documento','image'=>'Imagem','file'=>'Arquivo','link'=>'Link'] as $value=>$label): ?><option value="<?= $value ?>" <?= ($row['kind'] ?? '') === $value ? 'selected' : '' ?>><?= $label ?></option><?php endforeach; ?></select><select name="evidence_document_id[]" class="input-minimal px-3 py-2 text-xs"><option value="0">Sem documento vinculado</option><?php foreach ($subjectWorkspaceDocuments as $document): ?><option value="<?= (int)$document['id'] ?>" <?= (int)($row['document_id'] ?? 0) === (int)$document['id'] ? 'selected' : '' ?>><?= $workspaceEscape($document['title']) ?> (<?= $workspaceEscape($document['status']) ?>)</option><?php endforeach; ?></select><input type="url" name="evidence_url[]" maxlength="2000" value="<?= $workspaceEscape($row['url'] ?? '') ?>" class="input-minimal px-3 py-2 text-xs" placeholder="URL externa opcional"><textarea name="evidence_description[]" rows="3" maxlength="1000" class="input-minimal px-3 py-2 text-xs md:col-span-2" placeholder="Descrição e critério de validade"><?= $workspaceEscape($row['description'] ?? '') ?></textarea></div></div>
            <?php endforeach; ?>
        </div>
        <div class="mt-4 flex justify-end"><button class="rounded-md bg-slate-900 px-4 py-2 text-xs font-semibold text-white dark:bg-white dark:text-slate-900">Salvar evidências</button></div>
    </form>

<?php elseif ($resTab === 'faq'): ?>
    <form method="POST" class="rounded-lg border border-slate-200 bg-white p-5 shadow-xs dark:border-[#454956] dark:bg-[#353842]">
        <?php $workspaceSectionForm('faq'); ?>
        <div class="flex items-start justify-between gap-4"><div><h2 class="text-sm font-bold">7. Erros e FAQ</h2><p class="mt-1 text-xs text-slate-500">Centralize dúvidas frequentes e soluções para falhas conhecidas.</p></div><button type="button" onclick="addWorkspaceRow('faq')" class="rounded-md border border-slate-200 px-3 py-2 text-[11px] font-semibold dark:border-[#454956]">+ Adicionar item</button></div>
        <div data-workspace-list="faq" class="mt-4 space-y-3">
            <?php foreach ($subjectWorkspace['faq_items'] as $index => $row): ?>
                <div data-workspace-row class="rounded-md border border-slate-200 p-4 dark:border-[#454956]"><div class="mb-3 flex items-center justify-between"><strong class="text-xs">Item <span data-row-number><?= $index + 1 ?></span></strong><button type="button" onclick="removeWorkspaceRow(this)" class="text-[11px] font-semibold text-red-600">Remover</button></div><div class="grid gap-3"><select name="faq_kind[]" class="input-minimal px-3 py-2 text-xs"><option value="faq" <?= ($row['kind'] ?? '') === 'faq' ? 'selected' : '' ?>>Pergunta frequente</option><option value="error" <?= ($row['kind'] ?? '') === 'error' ? 'selected' : '' ?>>Erro conhecido</option></select><input name="faq_question[]" required maxlength="300" value="<?= $workspaceEscape($row['question'] ?? '') ?>" class="input-minimal px-3 py-2 text-xs" placeholder="Pergunta ou mensagem de erro *"><textarea name="faq_answer[]" required rows="4" maxlength="3000" class="input-minimal px-3 py-2 text-xs" placeholder="Resposta ou solução *"><?= $workspaceEscape($row['answer'] ?? '') ?></textarea></div></div>
            <?php endforeach; ?>
        </div>
        <div class="mt-4 flex justify-end"><button class="rounded-md bg-slate-900 px-4 py-2 text-xs font-semibold text-white dark:bg-white dark:text-slate-900">Salvar erros e FAQ</button></div>
    </form>

<?php elseif ($resTab === 'permissions'): ?>
    <?php
        $resourceTitleParts = array_column($parentBreadcrumbs, 'name');
        $resourceTitleParts[] = $resData['name'];
        $resourceTitle = implode(' > ', $resourceTitleParts);
        require __DIR__ . '/permissions-panel.php';
    ?>

<?php elseif ($resTab === 'integrations'): ?>
    <form method="POST" class="rounded-lg border border-slate-200 bg-white p-5 shadow-xs dark:border-[#454956] dark:bg-[#353842]">
        <?php $workspaceSectionForm('integrations'); ?>
        <div class="flex items-start justify-between gap-4"><div><h2 class="text-sm font-bold">9. Integrações</h2><p class="mt-1 text-xs text-slate-500">Sistemas, APIs, filas, formulários e dependências deste processo.</p></div><button type="button" onclick="addWorkspaceRow('integration')" class="rounded-md border border-slate-200 px-3 py-2 text-[11px] font-semibold dark:border-[#454956]">+ Adicionar integração</button></div>
        <div data-workspace-list="integration" class="mt-4 space-y-3">
            <?php foreach ($subjectWorkspace['integrations'] as $index => $row): ?>
                <div data-workspace-row class="rounded-md border border-slate-200 p-4 dark:border-[#454956]"><div class="mb-3 flex items-center justify-between"><strong class="text-xs">Integração <span data-row-number><?= $index + 1 ?></span></strong><button type="button" onclick="removeWorkspaceRow(this)" class="text-[11px] font-semibold text-red-600">Remover</button></div><div class="grid gap-3 md:grid-cols-2"><input name="integration_name[]" required maxlength="160" value="<?= $workspaceEscape($row['name'] ?? '') ?>" class="input-minimal px-3 py-2 text-xs" placeholder="Nome do sistema *"><input name="integration_type[]" maxlength="80" value="<?= $workspaceEscape($row['type'] ?? '') ?>" class="input-minimal px-3 py-2 text-xs" placeholder="Tipo: API, sistema, formulário..."><input type="url" name="integration_url[]" maxlength="2000" value="<?= $workspaceEscape($row['url'] ?? '') ?>" class="input-minimal px-3 py-2 text-xs" placeholder="URL opcional"><select name="integration_status[]" class="input-minimal px-3 py-2 text-xs"><option value="active" <?= ($row['status'] ?? '') === 'active' ? 'selected' : '' ?>>Ativa</option><option value="attention" <?= ($row['status'] ?? '') === 'attention' ? 'selected' : '' ?>>Requer atenção</option><option value="inactive" <?= ($row['status'] ?? '') === 'inactive' ? 'selected' : '' ?>>Inativa</option></select><textarea name="integration_description[]" rows="3" maxlength="1200" class="input-minimal px-3 py-2 text-xs md:col-span-2" placeholder="Como a integração participa do processo"><?= $workspaceEscape($row['description'] ?? '') ?></textarea></div></div>
            <?php endforeach; ?>
        </div>
        <div class="mt-4 flex justify-end"><button class="rounded-md bg-slate-900 px-4 py-2 text-xs font-semibold text-white dark:bg-white dark:text-slate-900">Salvar integrações</button></div>
    </form>

<?php elseif ($resTab === 'history'): ?>
    <section class="rounded-lg border border-slate-200 bg-white p-5 shadow-xs dark:border-[#454956] dark:bg-[#353842]">
        <h2 class="text-sm font-bold">10. Histórico</h2><p class="mt-1 text-xs text-slate-500">Registro automático das alterações feitas na documentação.</p>
        <?php if (empty($subjectWorkspaceHistory)): ?><div class="py-12 text-center text-xs text-slate-400">Nenhuma alteração registrada ainda.</div><?php else: ?><div class="mt-4 divide-y divide-slate-100 dark:divide-[#454956]"><?php foreach ($subjectWorkspaceHistory as $historyItem): ?><div class="flex items-start justify-between gap-4 py-3"><div><strong class="block text-xs text-slate-800 dark:text-slate-100"><?= $workspaceEscape($historyItem['summary']) ?></strong><span class="mt-0.5 block text-[10px] text-slate-400"><?= $workspaceEscape($subjectWorkspaceService->sectionLabel((string)$historyItem['section'])) ?> · <?= $workspaceEscape($historyItem['actor_name'] ?: 'Sistema') ?></span></div><time class="shrink-0 text-[10px] text-slate-400"><?= date('d/m/Y H:i', strtotime((string)$historyItem['created_at'])) ?></time></div><?php endforeach; ?></div><?php endif; ?>
    </section>
<?php endif; ?>

<template data-workspace-template="flow"><div data-workspace-row class="rounded-md border border-slate-200 p-4 dark:border-[#454956]"><div class="mb-3 flex items-center justify-between"><strong class="text-xs">Etapa <span data-row-number></span></strong><button type="button" onclick="removeWorkspaceRow(this)" class="text-[11px] font-semibold text-red-600">Remover</button></div><div class="grid gap-3 md:grid-cols-2"><input name="flow_title[]" required maxlength="160" class="input-minimal px-3 py-2 text-xs" placeholder="Título da etapa *"><input name="flow_owner[]" maxlength="160" class="input-minimal px-3 py-2 text-xs" placeholder="Responsável"><textarea name="flow_description[]" rows="3" maxlength="1500" class="input-minimal px-3 py-2 text-xs" placeholder="O que acontece nesta etapa"></textarea><textarea name="flow_result[]" rows="3" maxlength="500" class="input-minimal px-3 py-2 text-xs" placeholder="Saída ou decisão"></textarea></div></div></template>
<template data-workspace-template="procedure"><div data-workspace-row class="rounded-md border border-slate-200 p-4 dark:border-[#454956]"><div class="mb-3 flex items-center justify-between"><strong class="text-xs">Passo <span data-row-number></span></strong><button type="button" onclick="removeWorkspaceRow(this)" class="text-[11px] font-semibold text-red-600">Remover</button></div><div class="grid gap-3 md:grid-cols-2"><input name="procedure_title[]" required maxlength="160" class="input-minimal px-3 py-2 text-xs" placeholder="Título do passo *"><input name="procedure_responsible[]" maxlength="160" class="input-minimal px-3 py-2 text-xs" placeholder="Responsável"><textarea name="procedure_description[]" required rows="4" maxlength="3000" class="input-minimal px-3 py-2 text-xs" placeholder="Instrução detalhada *"></textarea><textarea name="procedure_warning[]" rows="4" maxlength="600" class="input-minimal px-3 py-2 text-xs" placeholder="Atenção, condição ou exceção"></textarea></div></div></template>
<template data-workspace-template="evidence"><div data-workspace-row class="rounded-md border border-slate-200 p-4 dark:border-[#454956]"><div class="mb-3 flex items-center justify-between"><strong class="text-xs">Evidência <span data-row-number></span></strong><button type="button" onclick="removeWorkspaceRow(this)" class="text-[11px] font-semibold text-red-600">Remover</button></div><div class="grid gap-3 md:grid-cols-2"><input name="evidence_title[]" required maxlength="160" class="input-minimal px-3 py-2 text-xs" placeholder="Título *"><select name="evidence_kind[]" class="input-minimal px-3 py-2 text-xs"><option value="record">Registro</option><option value="document">Documento</option><option value="image">Imagem</option><option value="file">Arquivo</option><option value="link">Link</option></select><select name="evidence_document_id[]" class="input-minimal px-3 py-2 text-xs"><option value="0">Sem documento vinculado</option><?php foreach ($subjectWorkspaceDocuments as $document): ?><option value="<?= (int)$document['id'] ?>"><?= $workspaceEscape($document['title']) ?></option><?php endforeach; ?></select><input type="url" name="evidence_url[]" maxlength="2000" class="input-minimal px-3 py-2 text-xs" placeholder="URL externa opcional"><textarea name="evidence_description[]" rows="3" maxlength="1000" class="input-minimal px-3 py-2 text-xs md:col-span-2" placeholder="Descrição e critério de validade"></textarea></div></div></template>
<template data-workspace-template="faq"><div data-workspace-row class="rounded-md border border-slate-200 p-4 dark:border-[#454956]"><div class="mb-3 flex items-center justify-between"><strong class="text-xs">Item <span data-row-number></span></strong><button type="button" onclick="removeWorkspaceRow(this)" class="text-[11px] font-semibold text-red-600">Remover</button></div><div class="grid gap-3"><select name="faq_kind[]" class="input-minimal px-3 py-2 text-xs"><option value="faq">Pergunta frequente</option><option value="error">Erro conhecido</option></select><input name="faq_question[]" required maxlength="300" class="input-minimal px-3 py-2 text-xs" placeholder="Pergunta ou mensagem de erro *"><textarea name="faq_answer[]" required rows="4" maxlength="3000" class="input-minimal px-3 py-2 text-xs" placeholder="Resposta ou solução *"></textarea></div></div></template>
<template data-workspace-template="integration"><div data-workspace-row class="rounded-md border border-slate-200 p-4 dark:border-[#454956]"><div class="mb-3 flex items-center justify-between"><strong class="text-xs">Integração <span data-row-number></span></strong><button type="button" onclick="removeWorkspaceRow(this)" class="text-[11px] font-semibold text-red-600">Remover</button></div><div class="grid gap-3 md:grid-cols-2"><input name="integration_name[]" required maxlength="160" class="input-minimal px-3 py-2 text-xs" placeholder="Nome do sistema *"><input name="integration_type[]" maxlength="80" class="input-minimal px-3 py-2 text-xs" placeholder="Tipo"><input type="url" name="integration_url[]" maxlength="2000" class="input-minimal px-3 py-2 text-xs" placeholder="URL opcional"><select name="integration_status[]" class="input-minimal px-3 py-2 text-xs"><option value="active">Ativa</option><option value="attention">Requer atenção</option><option value="inactive">Inativa</option></select><textarea name="integration_description[]" rows="3" maxlength="1200" class="input-minimal px-3 py-2 text-xs md:col-span-2" placeholder="Como participa do processo"></textarea></div></div></template>

<script>
function renumberWorkspaceRows(list) {
    if (!list) return;
    list.querySelectorAll('[data-workspace-row]').forEach((row, index) => {
        const number = row.querySelector('[data-row-number]');
        if (number) number.textContent = String(index + 1);
    });
}
function addWorkspaceRow(kind) {
    const template = document.querySelector('[data-workspace-template="' + kind + '"]');
    const list = document.querySelector('[data-workspace-list="' + kind + '"]');
    if (!template || !list) return;
    list.appendChild(template.content.cloneNode(true));
    renumberWorkspaceRows(list);
    const last = list.querySelector('[data-workspace-row]:last-child input, [data-workspace-row]:last-child textarea');
    if (last) last.focus();
}
function removeWorkspaceRow(button) {
    const row = button.closest('[data-workspace-row]');
    const list = row ? row.parentElement : null;
    if (row) row.remove();
    renumberWorkspaceRows(list);
}
document.querySelectorAll('[data-workspace-list]').forEach(renumberWorkspaceRows);
<?php if (!$workspaceIsEditable): ?>
document.querySelectorAll('input[name="save_subject_workspace"]').forEach((marker) => {
    const form = marker.form;
    if (!form) return;
    form.querySelectorAll('input:not([type="hidden"]), textarea, select, button').forEach((control) => {
        control.disabled = true;
        control.setAttribute('aria-disabled', 'true');
    });
    form.classList.add('opacity-75');
});
<?php endif; ?>
</script>
