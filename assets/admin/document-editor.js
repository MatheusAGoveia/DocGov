// Painel administrativo: document-editor.
// Carregado como script classico; nomes globais sao usados pelos formularios.
// Mapas por ID evitam colisões quando ramos diferentes usam o mesmo nome.
const ncDocSubcatsByCategoryId = window.DocGovAdminConfig.hierarchy.documentSubcategories;
const ncDocAssuntosBySubcategoryId = window.DocGovAdminConfig.hierarchy.documentSubjects;

function setDocumentHierarchyHelper(message = '', actionLabel = '', action = null) {
    const helper = document.getElementById('document-hierarchy-helper');
    const text = document.getElementById('document-hierarchy-helper-text');
    const button = document.getElementById('document-hierarchy-helper-action');
    if (!helper || !text || !button) return;
    const visible = Boolean(message && actionLabel && action);
    helper.classList.toggle('hidden', !visible);
    text.textContent = message;
    button.textContent = actionLabel;
    button.onclick = visible ? action : null;
}

function onCategoryChange() {
    const cat = document.getElementById('select-cat').value;
    const subSelect = document.getElementById('select-subcat');
    const assSelect = document.getElementById('select-assunto');

    subSelect.innerHTML = '<option value="">-- Selecione ▾ --</option>';
    assSelect.innerHTML = '<option value="">-- Selecione ▾ --</option>';

    const availableSubcategories = cat ? (ncDocSubcatsByCategoryId[cat] || []) : [];
    availableSubcategories.forEach(sc => {
            const opt = document.createElement('option');
            opt.value = String(sc.id);
            opt.textContent = sc.nome;
            subSelect.appendChild(opt);
    });

    if (cat && availableSubcategories.length === 0) {
        subSelect.innerHTML = '<option value="">Nenhuma subcategoria ativa</option>';
        setDocumentHierarchyHelper(
            'Esta categoria ainda não possui uma subcategoria ativa. Crie uma para continuar.',
            'Criar subcategoria',
            () => {
                ncSwitchType('subcategoria');
                const target = document.getElementById('nc-sub-cat');
                if (target) target.value = String(cat);
            }
        );
    } else {
        setDocumentHierarchyHelper();
    }
}

function onSubcategoryChange() {
    const sub = document.getElementById('select-subcat').value;
    const assSelect = document.getElementById('select-assunto');

    assSelect.innerHTML = '<option value="">-- Selecione ▾ --</option>';

    const availableSubjects = sub ? (ncDocAssuntosBySubcategoryId[sub] || []) : [];
    availableSubjects.forEach(s => {
            const opt = document.createElement('option');
            opt.value = String(s.id);
            opt.textContent = s.nome;
            assSelect.appendChild(opt);
    });

    if (sub && availableSubjects.length === 0) {
        assSelect.innerHTML = '<option value="">Nenhum assunto ativo</option>';
        const categoryId = document.getElementById('select-cat')?.value || '';
        setDocumentHierarchyHelper(
            'Esta subcategoria ainda não possui um assunto ativo. Crie um para continuar.',
            'Criar assunto',
            () => {
                ncSwitchType('assunto');
                const catTarget = document.getElementById('nc-ass-cat');
                const subTarget = document.getElementById('nc-ass-subcat');
                if (catTarget) catTarget.value = String(categoryId);
                ncLoadSubcatsForAssunto();
                if (subTarget) subTarget.value = String(sub);
            }
        );
    } else {
        setDocumentHierarchyHelper();
    }
}

function toggleFormContent(type) {
    const boxes = ['file', 'text', 'code', 'video', 'link', 'flow', 'orgchart'];
    boxes.forEach(b => {
        const el = document.getElementById('box-' + b);
        if (el) el.classList.add('hidden');
    });
    const target = document.getElementById('box-' + type);
    if (target) target.classList.remove('hidden');
    if (type === 'code') updateCodePreview();
    if (type === 'video') toggleVideoSource();
}

function updateCodePreview() {
    const sourceInput = document.getElementById('code-source-input');
    const languageInput = document.getElementById('code-language');
    const preview = document.getElementById('code-preview');
    if (!sourceInput || !languageInput || !preview) return;

    const code = preview.querySelector('[data-code-source]');
    preview.dataset.codeLanguage = languageInput.value || 'auto';
    if (code) code.textContent = sourceInput.value || '// A pré-visualização aparecerá aqui.';
    if (window.DocGovCodeSnippets) window.DocGovCodeSnippets.refresh(preview);
}

function toggleVideoSource() {
    const source = document.querySelector('input[name="video_source"]:checked')?.value || 'upload';
    const uploadFields = document.getElementById('video-upload-fields');
    const urlFields = document.getElementById('video-url-fields');
    if (uploadFields) uploadFields.classList.toggle('hidden', source !== 'upload');
    if (urlFields) urlFields.classList.toggle('hidden', source !== 'url');
    if (source === 'url') updateVideoUrlPreview();
}

let localVideoPreviewUrl = null;

function updateVideoFilePreview(input) {
    const preview = document.getElementById('video-file-preview-name');
    const playerPreview = document.getElementById('video-url-preview');
    const selectedFile = input.files && input.files[0] ? input.files[0] : null;
    if (preview) preview.textContent = selectedFile ? selectedFile.name : '';
    if (!playerPreview) return;

    if (localVideoPreviewUrl) {
        URL.revokeObjectURL(localVideoPreviewUrl);
        localVideoPreviewUrl = null;
    }
    playerPreview.replaceChildren();
    if (!selectedFile) {
        playerPreview.classList.add('hidden');
        return;
    }

    localVideoPreviewUrl = URL.createObjectURL(selectedFile);
    const video = document.createElement('video');
    video.src = localVideoPreviewUrl;
    video.controls = true;
    video.preload = 'metadata';
    video.className = 'aspect-video w-full';
    playerPreview.append(video);
    playerPreview.classList.remove('hidden');
}

function getVideoEmbedUrl(url) {
    try {
        const parsed = new URL(url);
        const host = parsed.hostname.toLowerCase().replace(/^www\./, '');
        const pathParts = parsed.pathname.split('/').filter(Boolean);
        let youtubeId = '';

        if (host === 'youtu.be') {
            youtubeId = pathParts[0] || '';
        } else if (['youtube.com', 'm.youtube.com', 'youtube-nocookie.com'].includes(host)) {
            youtubeId = parsed.searchParams.get('v') || '';
            if (!youtubeId && ['embed', 'shorts', 'live'].includes(pathParts[0])) {
                youtubeId = pathParts[1] || '';
            }
        }
        if (/^[A-Za-z0-9_-]{11}$/.test(youtubeId)) {
            return { kind: 'embed', src: 'https://www.youtube-nocookie.com/embed/' + youtubeId, provider: 'YouTube' };
        }

        if (['vimeo.com', 'player.vimeo.com'].includes(host)) {
            const vimeoId = [...pathParts].reverse().find(part => /^\d+$/.test(part));
            if (vimeoId) return { kind: 'embed', src: 'https://player.vimeo.com/video/' + vimeoId, provider: 'Vimeo' };
        }

        if (/\.(mp4|webm|ogv|ogg|m4v|mov)$/i.test(parsed.pathname)) {
            return { kind: 'direct', src: parsed.href, provider: 'Vídeo externo' };
        }
    } catch (_) {
        // A validação final também acontece no servidor ao salvar.
    }
    return null;
}

function updateVideoUrlPreview() {
    const input = document.getElementById('video-url');
    const preview = document.getElementById('video-url-preview');
    if (!input || !preview) return;

    preview.replaceChildren();
    const resolved = getVideoEmbedUrl(input.value.trim());
    if (!resolved) {
        preview.classList.add('hidden');
        return;
    }

    preview.classList.remove('hidden');
    if (resolved.kind === 'embed') {
        const frame = document.createElement('iframe');
        frame.src = resolved.src;
        frame.title = 'Pré-visualização de ' + resolved.provider;
        frame.className = 'aspect-video w-full';
        frame.loading = 'lazy';
        frame.allow = 'accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share';
        frame.allowFullscreen = true;
        frame.referrerPolicy = 'strict-origin-when-cross-origin';
        preview.append(frame);
        return;
    }

    const video = document.createElement('video');
    video.src = resolved.src;
    video.controls = true;
    video.preload = 'metadata';
    video.className = 'aspect-video w-full';
    preview.append(video);
}

// Mostra nome do arquivo selecionado
function updateFilePreview(input) {
    const preview = document.getElementById('file-preview-name');
    if (preview && input.files && input.files[0]) {
        preview.textContent = input.files[0].name;
    }
}

// =====================================================================
// TAGS — escolha livre para o autor, com sugestões apenas informativas.
// =====================================================================
const documentTagCatalog = window.DocGovAdminConfig.tags.catalog;
const inactiveDocumentTagNames = window.DocGovAdminConfig.tags.inactiveNameKeys;
const initialDocumentTagIds = window.DocGovAdminConfig.tags.selectedIds;
const initialNewDocumentTagNames = window.DocGovAdminConfig.tags.newNames;

function initDocumentTags() {
    const input = document.getElementById('document-tag-input');
    const addButton = document.getElementById('document-tag-add');
    const selected = document.getElementById('document-tag-selected');
    const datalist = document.getElementById('document-tag-options');
    const suggestions = document.getElementById('document-tag-suggestions');
    const suggestionList = document.getElementById('document-tag-suggestion-list');
    const counter = document.getElementById('document-tag-count');
    const feedback = document.getElementById('document-tag-feedback');
    if (!input || !addButton || !selected || !datalist || !suggestions || !suggestionList || !counter || !feedback) return;

    const normalise = value => String(value || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '');
    const inactiveNames = new Set(inactiveDocumentTagNames.map(normalise));
    const catalogByName = new Map();
    documentTagCatalog.forEach(tag => {
        catalogByName.set(normalise(tag.name), tag);
        (tag.aliases || []).forEach(alias => catalogByName.set(normalise(alias), tag));
        const option = document.createElement('option');
        option.value = tag.name;
        datalist.appendChild(option);
    });
    const state = new Map();
    const setFeedback = message => {
        feedback.textContent = message || '';
        feedback.classList.toggle('hidden', !message);
    };
    const stateKey = item => item.id ? `id:${item.id}` : `new:${normalise(item.name)}`;
    const addTag = rawValue => {
        const rawName = String(rawValue || '').replace(/\s+/g, ' ').trim();
        if (!rawName) return;
        const known = catalogByName.get(normalise(rawName));
        if (!known && inactiveNames.has(normalise(rawName))) {
            setFeedback('Essa tag está desativada. Escolha outra tag.');
            return;
        }
        const item = known ? { id: Number(known.id), name: known.name, type: known.type_label || 'Tag' } : { id: 0, name: rawName, type: 'Nova tag' };
        if (!known && (rawName.length < 2 || rawName.length > 80)) {
            setFeedback('A nova tag deve ter entre 2 e 80 caracteres.');
            return;
        }
        if (state.has(stateKey(item))) {
            setFeedback('Essa tag já foi adicionada.');
            return;
        }
        if (state.size >= 12) {
            setFeedback('Use no máximo 12 tags por conteúdo.');
            return;
        }
        state.set(stateKey(item), item);
        input.value = '';
        setFeedback('');
        render();
    };
    const render = () => {
        selected.replaceChildren();
        state.forEach((item, key) => {
            const chip = document.createElement('span');
            chip.className = 'inline-flex items-center gap-1 rounded-full border border-slate-200 bg-white px-2 py-1 text-[10px] font-semibold text-slate-600 dark:border-[#454956] dark:bg-[#353842] dark:text-slate-200';
            const label = document.createElement('span');
            label.textContent = item.name;
            const remove = document.createElement('button');
            remove.type = 'button';
            remove.className = 'rounded-full text-slate-400 transition hover:text-red-500';
            remove.setAttribute('aria-label', `Remover tag ${item.name}`);
            remove.textContent = '×';
            remove.addEventListener('click', () => { state.delete(key); setFeedback(''); render(); });
            chip.append(label, remove);
            const hidden = document.createElement('input');
            hidden.type = 'hidden';
            hidden.name = item.id ? 'tag_ids[]' : 'new_tags[]';
            hidden.value = item.id ? String(item.id) : item.name;
            selected.append(chip, hidden);
        });
        counter.textContent = `${state.size}/12`;
        refreshSuggestions();
    };
    const stopWords = new Set(['para','com','uma','uns','umas','dos','das','que','por','sem','sobre','entre','este','esta','isso','essa','como','mais','menos','tambem','sistema','documento','conteudo','arquivo','informacao','informações','orientacao','orientações','manual','novo','nova','todos','todas','serao','será','sao','são','quando','onde','cada','seus','suas']);
    const sourceText = () => [
        document.getElementById('document-title')?.value,
        document.getElementById('document-description')?.value,
        document.getElementById('text-content-input')?.value,
        document.getElementById('code-source-input')?.value,
    ].filter(Boolean).join(' ');
    const refreshSuggestions = () => {
        const text = sourceText();
        const normalizedText = ` ${normalise(text).replace(/-/g, ' ')} `;
        const candidates = [];
        documentTagCatalog.forEach(tag => {
            const tagKey = normalise(tag.name);
            if (tagKey && normalizedText.includes(` ${tagKey.replace(/-/g, ' ')} `) && !state.has(`id:${tag.id}`)) candidates.push({ id: tag.id, name: tag.name, known: true });
        });
        const words = (text.match(/[\p{L}\p{N}][\p{L}\p{N}-]{3,}/gu) || [])
            .map(word => word.replace(/[-_]+/g, ' ').trim())
            .filter(word => !stopWords.has(normalise(word)) && normalise(word).length >= 4);
        const frequency = new Map();
        words.forEach(word => { const key = normalise(word); frequency.set(key, (frequency.get(key) || { name: word, count: 0 })); frequency.get(key).count += 1; });
        [...frequency.entries()].sort((a, b) => b[1].count - a[1].count || a[1].name.localeCompare(b[1].name, 'pt-BR')).forEach(([key, value]) => {
            if (candidates.length >= 6 || inactiveNames.has(key) || catalogByName.has(key) || state.has(`new:${key}`)) return;
            candidates.push({ id: 0, name: value.name, known: false });
        });
        suggestionList.replaceChildren();
        candidates.slice(0, 6).forEach(candidate => {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'rounded-full border border-dashed border-slate-300 px-2 py-1 text-[10px] font-semibold text-slate-500 transition hover:border-slate-500 hover:bg-white hover:text-slate-800 dark:border-slate-600 dark:text-slate-300 dark:hover:bg-[#353842]';
            button.textContent = `+ ${candidate.name}`;
            button.title = candidate.known ? 'Adicionar tag existente sugerida' : 'Criar e adicionar esta nova tag sugerida';
            button.addEventListener('click', () => addTag(candidate.name));
            suggestionList.appendChild(button);
        });
        suggestions.classList.toggle('hidden', candidates.length === 0);
    };
    initialDocumentTagIds.forEach(id => {
        const tag = documentTagCatalog.find(item => Number(item.id) === Number(id));
        if (tag) state.set(`id:${tag.id}`, { id: Number(tag.id), name: tag.name, type: tag.type_label || 'Tag' });
    });
    initialNewDocumentTagNames.forEach(name => addTag(name));
    addButton.addEventListener('click', () => addTag(input.value));
    input.addEventListener('keydown', event => { if (event.key === 'Enter' || event.key === ',') { event.preventDefault(); addTag(input.value); } });
    ['document-title', 'document-description', 'text-content-input', 'code-source-input'].forEach(id => document.getElementById(id)?.addEventListener('input', refreshSuggestions));
    render();
}

// =====================================================================
// ENVIO EM LOTE — fila de arquivos, títulos automáticos e progresso XHR
// =====================================================================
function initBatchFileUpload() {
    const dropzone = document.getElementById('batch-dropzone');
    const input = document.getElementById('file-input');
    const form = document.getElementById('document-form');
    const flag = document.getElementById('batch-upload-flag');
    const queue = document.getElementById('batch-file-queue');
    const list = document.getElementById('batch-file-list');
    const count = document.getElementById('batch-file-count');
    const error = document.getElementById('batch-upload-error');
    const selectButton = document.getElementById('batch-select-files');
    const clearButton = document.getElementById('batch-clear-files');
    const progressWrap = document.getElementById('batch-upload-progress-wrap');
    const progress = document.getElementById('batch-upload-progress');
    const percent = document.getElementById('batch-upload-percent');
    const status = document.getElementById('batch-upload-status');
    const titleInput = document.getElementById('document-title');
    if (!dropzone || !input || !form || !flag || !queue || !list || !count) return;

    const maxFiles = 20;
    const videoExtensions = new Set(['mp4', 'webm', 'ogv', 'm4v', 'mov']);
    const imageExtensions = new Set(['png', 'jpg', 'jpeg', 'webp', 'gif', 'bmp', 'avif']);
    const audioExtensions = new Set(['mp3', 'wav', 'ogg']);
    let files = [];
    let uploading = false;

    const extensionOf = file => (file.name.split('.').pop() || '').toLowerCase();
    const formatSize = bytes => bytes < 1024 * 1024
        ? `${Math.max(1, Math.round(bytes / 1024))} KB`
        : `${(bytes / (1024 * 1024)).toFixed(bytes >= 100 * 1024 * 1024 ? 0 : 1)} MB`;
    const suggestedTitle = file => {
        const base = file.name.replace(/\.[^.]+$/, '').replace(/[._-]+/g, ' ').replace(/\s+/g, ' ').trim();
        return base || 'Documento sem título';
    };
    const fileKind = file => {
        const extension = extensionOf(file);
        if (videoExtensions.has(extension)) return { label: 'Vídeo', className: 'bg-violet-500/10 text-violet-700 dark:text-violet-300' };
        if (imageExtensions.has(extension)) return { label: 'Imagem', className: 'bg-sky-500/10 text-sky-700 dark:text-sky-300' };
        if (audioExtensions.has(extension)) return { label: 'Áudio', className: 'bg-amber-500/10 text-amber-700 dark:text-amber-300' };
        return { label: 'Arquivo', className: 'bg-slate-100 text-slate-600 dark:bg-[#353842] dark:text-slate-300' };
    };
    const showError = message => {
        if (!error) return;
        error.textContent = message || '';
        error.classList.toggle('hidden', !message);
    };
    const setProgress = (value, message) => {
        const safeValue = Math.max(0, Math.min(100, Math.round(value)));
        if (progressWrap) progressWrap.classList.remove('hidden');
        if (progress) progress.style.width = `${safeValue}%`;
        if (percent) percent.textContent = `${safeValue}%`;
        if (status && message) status.textContent = message;
    };
    const syncInputFiles = () => {
        const transfer = new DataTransfer();
        files.forEach(item => transfer.items.add(item.file));
        input.files = transfer.files;
        input.name = files.length > 0 ? 'arquivo_file[]' : 'arquivo_file';
        flag.value = files.length > 0 ? '1' : '';
    };
    const render = () => {
        list.replaceChildren();
        queue.classList.toggle('hidden', files.length === 0);
        count.textContent = `${files.length} ${files.length === 1 ? 'arquivo selecionado' : 'arquivos selecionados'}`;
        files.forEach((item, index) => {
            const kind = fileKind(item.file);
            const row = document.createElement('div');
            row.className = 'flex items-center gap-2.5 px-3 py-2.5';

            const icon = document.createElement('span');
            icon.className = 'flex h-7 w-7 shrink-0 items-center justify-center rounded-md bg-slate-100 text-slate-500 dark:bg-[#353842] dark:text-slate-300';
            icon.innerHTML = '<svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z"/></svg>';

            const content = document.createElement('div');
            content.className = 'min-w-0 flex-1';
            const title = document.createElement('input');
            title.type = 'text';
            title.name = 'batch_titles[]';
            title.value = item.title;
            title.maxLength = 255;
            title.className = 'input-minimal w-full border-0 bg-transparent p-0 text-xs font-semibold text-slate-800 focus:ring-0 dark:text-slate-100';
            title.setAttribute('aria-label', `Título de ${item.file.name}`);
            title.addEventListener('input', event => { files[index].title = event.target.value; });
            const detail = document.createElement('div');
            detail.className = 'mt-0.5 flex min-w-0 items-center gap-1.5 text-[10px] text-slate-400';
            const filename = document.createElement('span');
            filename.className = 'truncate';
            filename.textContent = item.file.name;
            const size = document.createElement('span');
            size.className = 'shrink-0';
            size.textContent = formatSize(item.file.size);
            detail.append(filename, size);
            content.append(title, detail);

            const badge = document.createElement('span');
            badge.className = `shrink-0 rounded px-1.5 py-1 text-[10px] font-bold ${kind.className}`;
            badge.textContent = kind.label;
            const remove = document.createElement('button');
            remove.type = 'button';
            remove.className = 'shrink-0 rounded p-1 text-slate-400 transition hover:bg-red-500/10 hover:text-red-600 disabled:opacity-50';
            remove.setAttribute('aria-label', `Remover ${item.file.name}`);
            remove.innerHTML = '<svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m6 6 12 12M18 6 6 18"/></svg>';
            remove.disabled = uploading;
            remove.addEventListener('click', () => {
                files.splice(index, 1);
                syncInputFiles();
                render();
                if (!files.length && titleInput && titleInput.dataset.batchAutofilled === '1') {
                    titleInput.value = '';
                    delete titleInput.dataset.batchAutofilled;
                }
            });
            row.append(icon, content, badge, remove);
            list.append(row);
        });
    };
    const addFiles = newFiles => {
        if (uploading || !newFiles.length) return;
        const uniqueFiles = newFiles.filter(file => !files.some(item => item.file.name === file.name && item.file.size === file.size && item.file.lastModified === file.lastModified));
        if (files.length + uniqueFiles.length > maxFiles) {
            showError(`A fila aceita no máximo ${maxFiles} arquivos por vez.`);
            return;
        }
        files.push(...uniqueFiles.map(file => ({ file, title: suggestedTitle(file) })));
        showError('');
        syncInputFiles();
        render();
        if (files.length && titleInput && (!titleInput.value.trim() || titleInput.dataset.batchAutofilled === '1')) {
            titleInput.value = files[0].title;
            titleInput.dataset.batchAutofilled = '1';
        }
    };
    const setControlsDisabled = disabled => {
        uploading = disabled;
        if (selectButton) selectButton.disabled = disabled;
        if (clearButton) clearButton.disabled = disabled;
        form.querySelectorAll('button[type="submit"]').forEach(button => { button.disabled = disabled; });
        render();
    };

    titleInput?.addEventListener('input', () => { delete titleInput.dataset.batchAutofilled; });
    selectButton?.addEventListener('click', event => { event.stopPropagation(); input.click(); });
    clearButton?.addEventListener('click', () => {
        if (uploading) return;
        files = [];
        syncInputFiles();
        render();
        showError('');
        if (titleInput && titleInput.dataset.batchAutofilled === '1') {
            titleInput.value = '';
            delete titleInput.dataset.batchAutofilled;
        }
    });
    input.addEventListener('change', () => addFiles(Array.from(input.files || [])));
    dropzone.addEventListener('click', event => { if (!event.target.closest('button')) input.click(); });
    dropzone.addEventListener('keydown', event => {
        if (event.key === 'Enter' || event.key === ' ') {
            event.preventDefault();
            input.click();
        }
    });
    ['dragenter', 'dragover'].forEach(type => dropzone.addEventListener(type, event => {
        event.preventDefault();
        if (!uploading) dropzone.classList.add('border-slate-700', 'bg-slate-100');
    }));
    ['dragleave', 'drop'].forEach(type => dropzone.addEventListener(type, event => {
        event.preventDefault();
        dropzone.classList.remove('border-slate-700', 'bg-slate-100');
    }));
    dropzone.addEventListener('drop', event => addFiles(Array.from(event.dataTransfer?.files || [])));

    form.addEventListener('submit', event => {
        const selectedType = form.querySelector('input[name="tipo_conteudo"]:checked')?.value || 'file';
        const isEdit = !!(form.querySelector('input[name="id"]')?.value);
        if (selectedType !== 'file' || isEdit || !flag.value || !files.length) {
            if (selectedType === 'file' && !isEdit && !files.length) {
                event.preventDefault();
                showError('Selecione ao menos um arquivo antes de salvar.');
            }
            return;
        }
        if (uploading) {
            event.preventDefault();
            return;
        }
        event.preventDefault();
        if (!form.reportValidity()) return;
        const submitter = event.submitter;
        const data = new FormData(form);
        if (submitter?.name) data.set(submitter.name, submitter.value);
        data.set('batch_upload', '1');
        showError('');
        setControlsDisabled(true);
        setProgress(0, 'Enviando arquivos…');

        const xhr = new XMLHttpRequest();
        xhr.open('POST', form.action, true);
        xhr.setRequestHeader('Accept', 'application/json');
        xhr.upload.addEventListener('progress', progressEvent => {
            if (progressEvent.lengthComputable) {
                setProgress((progressEvent.loaded / progressEvent.total) * 100, 'Enviando arquivos…');
            }
        });
        xhr.upload.addEventListener('load', () => setProgress(100, 'Criando documentos…'));
        xhr.addEventListener('load', () => {
            let payload = null;
            try { payload = JSON.parse(xhr.responseText || '{}'); } catch (_) { /* resposta inválida abaixo */ }
            if (xhr.status >= 200 && xhr.status < 300 && payload?.success) {
                setProgress(100, `${payload.created_count} ${payload.created_count === 1 ? 'documento criado' : 'documentos criados'}!`);
                window.setTimeout(() => { window.location.assign(payload.redirect || 'index.php?tab=documentos'); }, 350);
                return;
            }
            setControlsDisabled(false);
            showError(payload?.error || (xhr.status === 413 ? 'O envio excede o limite do servidor. Reduza os arquivos ou envie em lotes menores.' : 'Não foi possível concluir o envio. Tente novamente.'));
        });
        xhr.addEventListener('error', () => {
            setControlsDisabled(false);
            showError('A conexão foi interrompida durante o envio. Nenhum documento foi criado.');
        });
        xhr.send(data);
    });
}

if (currentFilterCat) onFilterCategoryChange();

// =========================================================================
// EDITOR VISUAL - ESTADO CENTRALIZADO E GRIDSTACK.JS (BASE)
// =========================================================================
const EditorState = {
    category: window.DocGovAdminConfig.editor.category,
    subcategory: window.DocGovAdminConfig.editor.subcategory,
    assunto: window.DocGovAdminConfig.editor.subject,
    contents: window.DocGovAdminConfig.editor.contents,
    selectedItemId: null,
    mode: 'edit',
    isDirty: false
};

let gridInstance = null;

document.addEventListener('DOMContentLoaded', function() {
    if (window.GovDocStructuredEditors) {
        window.GovDocStructuredEditors.init({
            initialType: window.DocGovAdminConfig.editor.initialType,
            initialContent: window.DocGovAdminConfig.editor.initialStructuredContent
        });
    } else {
        const selectedContentType = document.querySelector('input[name="tipo_conteudo"]:checked');
        if (selectedContentType) toggleFormContent(selectedContentType.value);
    }
    initDocumentTags();
    initBatchFileUpload();

    const gridEl = document.querySelector('.grid-stack');
    if (gridEl && typeof GridStack !== 'undefined') {
        gridInstance = GridStack.init({
            column: 12,
            cellHeight: 85,
            disableDrag: true,
            disableResize: true,
            staticGrid: true,
            margin: 6
        }, gridEl);
    }

    // Continuidade do fluxo Categoria → Subcategoria → Assunto → Documento.
    const urlParams = new URLSearchParams(window.location.search);
    const setupStep = urlParams.get('setup') || '';
    const editHierarchy = window.DocGovAdminConfig.editor.hierarchy;
    const preCat = urlParams.get('cat_id') || urlParams.get('cat') || editHierarchy.category;
    const preSub = urlParams.get('subcat_id') || urlParams.get('subcat') || editHierarchy.subcategory;
    const preAss = urlParams.get('subject_id') || urlParams.get('assunto') || editHierarchy.subject;
    const selectMatchingOption = (select, requested) => {
        if (!select || requested === null || requested === undefined || requested === '') return '';
        const requestedText = String(requested);
        const option = Array.from(select.options).find(item => item.value === requestedText || item.textContent.trim() === requestedText);
        if (!option) return '';
        select.value = option.value;
        return option.value;
    };

    const documentCategory = document.getElementById('select-cat');
    if (preCat && documentCategory) {
        selectMatchingOption(documentCategory, preCat);
        onCategoryChange();
        const documentSubcategory = document.getElementById('select-subcat');
        if (preSub && documentSubcategory) {
            selectMatchingOption(documentSubcategory, preSub);
            onSubcategoryChange();
            const documentSubject = document.getElementById('select-assunto');
            if (preAss && documentSubject) {
                selectMatchingOption(documentSubject, preAss);
            }
        }
    }

    if (setupStep === 'subcategory') {
        ncSwitchType('subcategoria');
        selectMatchingOption(document.getElementById('nc-sub-cat'), preCat);
    } else if (setupStep === 'subject') {
        ncSwitchType('assunto');
        selectMatchingOption(document.getElementById('nc-ass-cat'), preCat);
        ncLoadSubcatsForAssunto();
        selectMatchingOption(document.getElementById('nc-ass-subcat'), preSub);
    } else if (setupStep === 'document') {
        ncSwitchType('documento');
    }
});

function setEditorMode(mode) {
    EditorState.mode = mode;
    const btnEdit = document.getElementById('btn-mode-edit');
    const btnPreview = document.getElementById('btn-mode-preview');
    const canvas = document.getElementById('editor-visual-canvas');
    if (!btnEdit || !btnPreview || !canvas) return;

    if (mode === 'preview') {
        btnEdit.className = "px-3 py-1 rounded text-xs font-semibold text-slate-600 dark:text-slate-300 hover:text-slate-900 dark:hover:text-white transition";
        btnPreview.className = "px-3 py-1 rounded text-xs font-semibold bg-slate-900 dark:bg-white text-white dark:text-slate-900 transition";
        canvas.classList.add('editor-preview-mode');
    } else {
        btnPreview.className = "px-3 py-1 rounded text-xs font-semibold text-slate-600 dark:text-slate-300 hover:text-slate-900 dark:hover:text-white transition";
        btnEdit.className = "px-3 py-1 rounded text-xs font-semibold bg-slate-900 dark:bg-white text-white dark:text-slate-900 transition";
        canvas.classList.remove('editor-preview-mode');
    }
}

function markEditorDirty() {
    EditorState.isDirty = true;
    const indicator = document.getElementById('save-status-indicator');
    if (indicator) {
        indicator.innerHTML = '<span class="w-2 h-2 rounded-full bg-amber-500 inline-block animate-pulse"></span><span class="text-amber-600 font-semibold">Alterações pendentes</span>';
    }
}

function saveEditorLayoutState() {
    const formGrid = document.getElementById('form-editor-grid');
    if (formGrid) {
        formGrid.submit();
    } else {
        const indicator = document.getElementById('save-status-indicator');
        if (indicator) {
            indicator.innerHTML = '<span class="w-2 h-2 rounded-full bg-emerald-500 inline-block"></span><span>Alterações salvas</span>';
        }
    }
}
