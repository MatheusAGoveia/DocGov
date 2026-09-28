(function () {
    'use strict';

    const typeByEditor = {
        richtext: 'text', file: 'file', code: 'code', video: 'video', link: 'link',
        flow: 'flow', orgchart: 'orgchart'
    };
    let config = { initialType: 'text', initialContent: {} };
    let activeType = 'text';
    let flowNodes = [];
    let orgNodes = [];
    let quillEditor = null;
    let articleMediaController = null;
    let pendingImageUploads = 0;

    const uid = prefix => `${prefix}-${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 7)}`;
    const text = value => String(value ?? '');
    const element = (tag, className, content) => {
        const node = document.createElement(tag);
        if (className) node.className = className;
        if (content !== undefined) node.textContent = content;
        return node;
    };

    function normalizeInitial() {
        const nodes = Array.isArray(config.initialContent?.nodes) ? config.initialContent.nodes : [];
        if (config.initialType === 'flow') {
            flowNodes = nodes.map((node, index) => ({
                id: text(node.id) || `step-${index + 1}`,
                type: ['start', 'process', 'decision', 'end'].includes(node.type) ? node.type : 'process',
                title: text(node.title), description: text(node.description), owner: text(node.owner)
            }));
        } else if (config.initialType === 'orgchart') {
            orgNodes = nodes.map((node, index) => ({
                id: text(node.id) || `node-${index + 1}`,
                parent_id: node.parent_id ? text(node.parent_id) : '',
                name: text(node.name), role: text(node.role), description: text(node.description)
            }));
        }
    }

    function editorTypeFromSelection() {
        const select = document.getElementById('document-section');
        const option = select?.selectedOptions?.[0];
        return typeByEditor[option?.dataset?.editorKind] || 'text';
    }

    function syncStructuredInput() {
        const input = document.getElementById('structured-content-input');
        if (!input) return;
        if (activeType === 'flow') input.value = JSON.stringify({ version: 1, nodes: flowNodes });
        else if (activeType === 'orgchart') input.value = JSON.stringify({ version: 1, nodes: orgNodes });
        else input.value = '{}';
    }

    function initFormattedTextEditor() {
        if (quillEditor || activeType !== 'text') return;
        const input = document.getElementById('text-content-input');
        const container = document.getElementById('quill-editor-container');
        const tableTools = document.getElementById('quill-table-tools');
        const mediaTools = document.getElementById('article-media-tools');
        const imageFileInput = document.getElementById('article-image-file');
        const mediaStatus = document.getElementById('article-media-status');
        if (!input || !container || typeof window.Quill !== 'function') {
            input?.setAttribute('data-editor-fallback', 'textarea');
            return;
        }
        try {
            if (!window.GovDocArticleMedia) throw new Error('Módulo de mídia do artigo não carregado.');
            window.GovDocArticleMedia.register();
            quillEditor = new window.Quill(container, {
                theme: 'snow',
                placeholder: input.placeholder || 'Comece a escrever o conteúdo...',
                modules: {
                    table: true,
                    uploader: {
                        mimetypes: ['image/jpeg', 'image/png', 'image/gif', 'image/webp'],
                        handler(range, files) {
                            Array.from(files || []).forEach(file => insertArticleImage(file, range?.index));
                        }
                    },
                    toolbar: [
                        [{ header: [1, 2, 3, false] }],
                        ['bold', 'italic', 'underline', 'strike'],
                        [{ list: 'ordered' }, { list: 'bullet' }],
                        [{ align: [] }],
                        ['blockquote', 'code-block'],
                        ['link', 'image', 'video'],
                        [{ color: [] }, { background: [] }],
                        ['clean']
                    ]
                }
            });
            if (input.value.trim()) {
                const editorHtml = input.value.replace(/src=(["'])document-media\.php\?id=(\d+)\1/g, 'src=$1../document-media.php?id=$2$1');
                quillEditor.clipboard.dangerouslyPasteHTML(window.GovDocArticleMedia?.normalizeLegacyHtml(editorHtml) || editorHtml, 'silent');
            }
            articleMediaController = window.GovDocArticleMedia.attach(quillEditor, document.getElementById('article-selected-media'), mediaStatus);
            input.classList.add('hidden');
            container.classList.remove('hidden');
            tableTools?.classList.remove('hidden');
            tableTools?.classList.add('flex');
            mediaTools?.classList.remove('hidden');
            mediaTools?.classList.add('flex');
            input.setAttribute('data-rich-text-editor', 'quill');

            function setMediaStatus(message, error = false) {
                if (!mediaStatus) return;
                mediaStatus.textContent = message;
                mediaStatus.classList.toggle('text-red-600', error);
            }

            async function insertArticleImage(file, preferredIndex = null) {
                if (!file || !['image/jpeg', 'image/png', 'image/gif', 'image/webp'].includes(file.type)) {
                    setMediaStatus('Escolha uma imagem JPEG, PNG, GIF ou WebP.', true);
                    return;
                }
                const subjectId = document.getElementById('select-assunto')?.value || '';
                const documentId = document.querySelector('#document-form input[name="id"]')?.value || '';
                if (!documentId && !subjectId) {
                    setMediaStatus('Selecione primeiro o assunto do documento.', true);
                    return;
                }
                const body = new FormData();
                body.append('csrf_token', document.querySelector('#document-form input[name="csrf_token"]')?.value || '');
                body.append('subject_id', subjectId);
                body.append('document_id', documentId);
                body.append('image', file);
                const insertionIndex = Number.isInteger(preferredIndex) ? preferredIndex : articleMediaController?.getInsertionIndex();
                pendingImageUploads++;
                setMediaStatus(`Enviando ${file.name}…`);
                try {
                    const response = await fetch('../document-media-upload.php', { method: 'POST', body, credentials: 'same-origin' });
                    const result = await response.json();
                    if (!response.ok || !result.ok) throw new Error(result.error || 'Falha no envio da imagem.');
                    const alt = document.getElementById('article-image-alt')?.value.trim() || file.name.replace(/\.[^.]+$/, '').replace(/[-_]+/g, ' ');
                    const caption = document.getElementById('article-image-caption')?.value.trim() || '';
                    articleMediaController?.insert({ kind: 'image', src: result.url, alt, caption, width: 80, layout: 'center' }, insertionIndex);
                    const captionInput = document.getElementById('article-image-caption');
                    if (captionInput) captionInput.value = '';
                } catch (error) {
                    setMediaStatus(error?.message || 'Não foi possível inserir a imagem.', true);
                } finally {
                    pendingImageUploads--;
                }
            }

            quillEditor.getModule('toolbar')?.addHandler('image', () => imageFileInput?.click());
            quillEditor.getModule('toolbar')?.addHandler('video', () => document.getElementById('article-video-url')?.focus());
            document.getElementById('article-insert-image')?.addEventListener('click', () => imageFileInput?.click());
            document.getElementById('article-insert-video')?.addEventListener('click', () => {
                const field = document.getElementById('article-video-url');
                const raw = field?.value.trim() || '';
                let url;
                try { url = new URL(raw); } catch { setMediaStatus('Informe uma URL válida de vídeo.', true); return; }
                if (url.protocol !== 'https:') { setMediaStatus('Use um endereço HTTPS do YouTube ou Vimeo.', true); return; }
                const host = url.hostname.toLowerCase().replace(/^www\./, '');
                let embed = '';
                let videoId = '';
                if (host === 'youtu.be') videoId = url.pathname.split('/')[1] || '';
                else if (['youtube.com', 'm.youtube.com', 'youtube-nocookie.com'].includes(host)) {
                    videoId = url.searchParams.get('v') || url.pathname.match(/^\/(?:embed|shorts|live)\/([^/]+)/)?.[1] || '';
                }
                if (/^[A-Za-z0-9_-]{11}$/.test(videoId)) embed = `https://www.youtube-nocookie.com/embed/${videoId}`;
                if (['vimeo.com', 'player.vimeo.com'].includes(host)) {
                    const vimeoId = url.pathname.match(/^\/(?:video\/)?(\d+)/)?.[1];
                    if (vimeoId) embed = `https://player.vimeo.com/video/${vimeoId}`;
                }
                if (!embed) { setMediaStatus('Este endereço não é um vídeo incorporável do YouTube ou Vimeo.', true); return; }
                articleMediaController?.insert({ kind: 'video', src: embed, alt: 'Vídeo incorporado', width: 100, layout: 'center' }, articleMediaController?.getInsertionIndex());
                field.value = '';
            });
            imageFileInput?.addEventListener('change', () => {
                const file = imageFileInput.files?.[0];
                if (file) insertArticleImage(file);
                imageFileInput.value = '';
            });

            const insertTable = document.getElementById('quill-insert-table');
            insertTable?.addEventListener('click', () => {
                const rows = Number.parseInt(document.getElementById('quill-table-rows')?.value || '3', 10);
                const columns = Number.parseInt(document.getElementById('quill-table-columns')?.value || '3', 10);
                const table = quillEditor?.getModule('table');
                if (!table || typeof table.insertTable !== 'function') return;
                quillEditor.focus();
                table.insertTable(Math.min(10, Math.max(2, rows)), Math.min(10, Math.max(2, columns)));
            });
        } catch (error) {
            container.classList.add('hidden');
            input.classList.remove('hidden');
            input.setAttribute('data-editor-fallback', 'textarea');
            console.warn('GovDoc: Quill indisponível; usando textarea segura.', error);
        }
    }

    function createInput(value, placeholder, onInput, className = '') {
        const input = element('input', `input-minimal w-full px-2.5 py-2 text-xs ${className}`.trim());
        input.type = 'text';
        input.value = value;
        input.placeholder = placeholder;
        input.addEventListener('input', event => onInput(event.target.value));
        return input;
    }

    function createTextarea(value, placeholder, onInput) {
        const area = element('textarea', 'input-minimal w-full px-2.5 py-2 text-xs');
        area.rows = 2;
        area.value = value;
        area.placeholder = placeholder;
        area.addEventListener('input', event => onInput(event.target.value));
        return area;
    }

    function actionButton(label, title, handler, danger = false) {
        const button = element('button', danger
            ? 'rounded px-2 py-1 text-[10px] font-semibold text-rose-600 hover:bg-rose-500/10'
            : 'rounded px-2 py-1 text-[10px] font-semibold text-slate-500 hover:bg-slate-100 dark:hover:bg-[#454956]', label);
        button.type = 'button';
        button.title = title;
        button.addEventListener('click', handler);
        return button;
    }

    function move(items, index, offset) {
        const target = index + offset;
        if (target < 0 || target >= items.length) return;
        [items[index], items[target]] = [items[target], items[index]];
    }

    function renderFlow() {
        const list = document.getElementById('flow-editor-list');
        const preview = document.getElementById('flow-editor-preview');
        if (!list || !preview) return;
        list.replaceChildren();
        preview.replaceChildren();
        if (!flowNodes.length) {
            list.append(element('div', 'rounded-md border border-dashed border-slate-300 p-6 text-center text-xs text-slate-400 dark:border-slate-600', 'Nenhuma etapa cadastrada.'));
        }
        flowNodes.forEach((node, index) => {
            const card = element('div', 'rounded-md border border-slate-200 bg-white p-3 dark:border-[#454956] dark:bg-[#353842]');
            const header = element('div', 'mb-3 flex items-center justify-between gap-3');
            header.append(element('strong', 'text-xs text-slate-800 dark:text-slate-100', `Etapa ${index + 1}`));
            const actions = element('div', 'flex items-center gap-1');
            actions.append(
                actionButton('↑', 'Mover para cima', () => { move(flowNodes, index, -1); renderFlow(); }),
                actionButton('↓', 'Mover para baixo', () => { move(flowNodes, index, 1); renderFlow(); }),
                actionButton('Remover', 'Remover etapa', () => { flowNodes.splice(index, 1); renderFlow(); }, true)
            );
            header.append(actions);
            const grid = element('div', 'grid gap-2 md:grid-cols-2');
            const type = element('select', 'input-minimal w-full px-2.5 py-2 text-xs');
            [['start', 'Início'], ['process', 'Etapa'], ['decision', 'Decisão'], ['end', 'Fim']].forEach(([value, label]) => {
                const option = element('option', '', label); option.value = value; option.selected = node.type === value; type.append(option);
            });
            type.addEventListener('change', event => { node.type = event.target.value; renderFlow(); });
            grid.append(
                type,
                createInput(node.title, 'Título da etapa *', value => { node.title = value; renderFlowPreview(); }),
                createInput(node.owner, 'Responsável', value => { node.owner = value; renderFlowPreview(); }),
                createTextarea(node.description, 'Descrição, regra ou resultado', value => { node.description = value; renderFlowPreview(); })
            );
            card.append(header, grid);
            list.append(card);
        });
        renderFlowPreview();
        syncStructuredInput();
    }

    function renderFlowPreview() {
        const preview = document.getElementById('flow-editor-preview');
        if (!preview) return;
        preview.replaceChildren();
        flowNodes.forEach((node, index) => {
            if (index > 0) preview.append(element('div', 'mx-auto h-5 w-px bg-slate-300 dark:bg-slate-600'));
            const shapeClass = node.type === 'decision'
                ? 'border-amber-300 bg-amber-50 dark:border-amber-700 dark:bg-amber-950/30'
                : node.type === 'start' || node.type === 'end'
                    ? 'rounded-full border-emerald-300 bg-emerald-50 dark:border-emerald-700 dark:bg-emerald-950/30'
                    : 'border-slate-200 bg-white dark:border-[#454956] dark:bg-[#353842]';
            const card = element('div', `rounded-md border p-3 text-center ${shapeClass}`);
            card.append(element('strong', 'block text-xs text-slate-800 dark:text-slate-100', node.title || `Etapa ${index + 1}`));
            if (node.owner) card.append(element('span', 'mt-1 block text-[10px] font-semibold text-slate-400', node.owner));
            if (node.description) card.append(element('p', 'mt-1 text-[11px] text-slate-500 dark:text-slate-300', node.description));
            preview.append(card);
        });
        syncStructuredInput();
    }

    function renderOrgChart() {
        const list = document.getElementById('orgchart-editor-list');
        if (!list) return;
        list.replaceChildren();
        if (!orgNodes.length) list.append(element('div', 'rounded-md border border-dashed border-slate-300 p-6 text-center text-xs text-slate-400 dark:border-slate-600', 'Nenhum nó cadastrado.'));
        orgNodes.forEach((node, index) => {
            const card = element('div', 'rounded-md border border-slate-200 bg-white p-3 dark:border-[#454956] dark:bg-[#353842]');
            const header = element('div', 'mb-3 flex items-center justify-between gap-3');
            header.append(element('strong', 'text-xs text-slate-800 dark:text-slate-100', `Nó ${index + 1}`));
            const actions = element('div', 'flex items-center gap-1');
            actions.append(
                actionButton('↑', 'Mover para cima', () => { move(orgNodes, index, -1); renderOrgChart(); }),
                actionButton('↓', 'Mover para baixo', () => { move(orgNodes, index, 1); renderOrgChart(); }),
                actionButton('Remover', 'Remover nó', () => {
                    orgNodes.splice(index, 1);
                    orgNodes.forEach(item => { if (item.parent_id === node.id) item.parent_id = ''; });
                    renderOrgChart();
                }, true)
            );
            header.append(actions);
            const grid = element('div', 'grid gap-2 md:grid-cols-2');
            const parent = element('select', 'input-minimal w-full px-2.5 py-2 text-xs');
            const rootOption = element('option', '', 'Sem superior (raiz)'); rootOption.value = ''; parent.append(rootOption);
            orgNodes.forEach(candidate => {
                if (candidate.id === node.id) return;
                const option = element('option', '', candidate.name || 'Nó sem nome');
                option.value = candidate.id; option.selected = node.parent_id === candidate.id; parent.append(option);
            });
            parent.addEventListener('change', event => { node.parent_id = event.target.value; renderOrgPreview(); syncStructuredInput(); });
            grid.append(
                createInput(node.name, 'Nome da unidade ou pessoa *', value => { node.name = value; renderOrgPreview(); }),
                createInput(node.role, 'Cargo ou função', value => { node.role = value; renderOrgPreview(); }),
                parent,
                createTextarea(node.description, 'Descrição opcional', value => { node.description = value; renderOrgPreview(); })
            );
            card.append(header, grid);
            list.append(card);
        });
        renderOrgPreview();
        syncStructuredInput();
    }

    function renderOrgPreview() {
        const preview = document.getElementById('orgchart-editor-preview');
        if (!preview) return;
        preview.replaceChildren();
        const byParent = new Map();
        orgNodes.forEach(node => {
            const parent = node.parent_id && orgNodes.some(candidate => candidate.id === node.parent_id) ? node.parent_id : '';
            if (!byParent.has(parent)) byParent.set(parent, []);
            byParent.get(parent).push(node);
        });
        const build = (parentId, ancestry = new Set()) => {
            const group = element('div', parentId ? 'govdoc-orgchart-children' : 'govdoc-orgchart-roots');
            (byParent.get(parentId) || []).forEach(node => {
                if (ancestry.has(node.id)) return;
                const branch = element('div', 'govdoc-orgchart-branch');
                const card = element('div', 'govdoc-orgchart-card');
                card.append(element('strong', '', node.name || 'Nó sem nome'));
                if (node.role) card.append(element('span', '', node.role));
                branch.append(card);
                const next = new Set(ancestry); next.add(node.id);
                const children = build(node.id, next);
                if (children.childElementCount) branch.append(children);
                group.append(branch);
            });
            return group;
        };
        preview.append(build(''));
        syncStructuredInput();
    }

    window.addFlowNode = function () {
        flowNodes.push({ id: uid('step'), type: flowNodes.length ? 'process' : 'start', title: '', description: '', owner: '' });
        renderFlow();
    };
    window.addOrgNode = function () {
        orgNodes.push({ id: uid('node'), parent_id: '', name: '', role: '', description: '' });
        renderOrgChart();
    };
    window.selectDocumentSection = function () {
        activeType = editorTypeFromSelection();
        const radio = document.querySelector(`input[name="tipo_conteudo"][value="${activeType}"]`);
        if (radio) radio.checked = true;
        if (typeof window.toggleFormContent === 'function') window.toggleFormContent(activeType);
        if (activeType === 'text') window.setTimeout(initFormattedTextEditor, 0);
        if (activeType === 'flow') renderFlow();
        if (activeType === 'orgchart') renderOrgChart();
        syncStructuredInput();
    };

    window.GovDocStructuredEditors = {
        init(options) {
            config = Object.assign(config, options || {});
            normalizeInitial();
            const form = document.getElementById('document-form');
            form?.addEventListener('submit', event => {
                syncStructuredInput();
                const input = document.getElementById('text-content-input');
                if (activeType === 'text' && quillEditor && input) {
                    if (pendingImageUploads > 0) {
                        event.preventDefault();
                        const status = document.getElementById('article-media-status');
                        if (status) status.textContent = 'Aguarde o envio da imagem antes de salvar.';
                        return;
                    }
                    const saved = quillEditor.root.cloneNode(true);
                    saved.querySelectorAll('.govdoc-media-handle, .govdoc-media-resize-handle, .govdoc-media-delete').forEach(handle => handle.remove());
                    saved.querySelectorAll('figure.govdoc-media img').forEach(image => image.removeAttribute('draggable'));
                    saved.querySelectorAll('figure.govdoc-media').forEach(figure => {
                        figure.classList.remove('is-selected');
                        figure.classList.remove('is-resizing');
                        figure.removeAttribute('draggable');
                        figure.removeAttribute('title');
                        figure.removeAttribute('tabindex');
                        figure.removeAttribute('role');
                        figure.removeAttribute('aria-label');
                    });
                    input.value = saved.innerHTML.replace(/src=(["'])\.\.\/document-media\.php\?id=(\d+)\1/g, 'src=$1document-media.php?id=$2$1');
                }
            }, { capture: true });
            window.selectDocumentSection();
        }
    };
})();
