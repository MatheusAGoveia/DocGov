(function () {
    'use strict';

    const layouts = ['center', 'wrap-left', 'wrap-right'];
    const layoutLabels = { center: 'Centro', 'wrap-left': 'Texto à direita', 'wrap-right': 'Texto à esquerda' };
    let registered = false;

    function normalize(value) {
        const media = value && typeof value === 'object' ? value : {};
        const legacyLayout = media.layout === 'block-left' ? 'wrap-left' : media.layout === 'block-right' ? 'wrap-right' : media.layout;
        const layout = layouts.includes(legacyLayout) ? legacyLayout : 'center';
        const maxWidth = layout.startsWith('wrap-') ? 50 : 100;
        return {
            kind: media.kind === 'video' ? 'video' : 'image',
            src: String(media.src || ''),
            alt: String(media.alt || '').slice(0, 200),
            caption: String(media.caption || '').slice(0, 300),
            layout,
            width: Math.min(maxWidth, Math.max(25, Number.parseInt(media.width, 10) || 80))
        };
    }

    function register() {
        if (registered || typeof window.Quill !== 'function') return;
        const BlockEmbed = window.Quill.import('blots/block/embed');
        class ArticleMediaBlot extends BlockEmbed {
            static blotName = 'articleMedia';
            static tagName = 'FIGURE';
            static className = 'govdoc-media';

            static create(raw) {
                const value = normalize(raw);
                const node = super.create();
                node.classList.add(`govdoc-media--${value.layout}`);
                node.style.width = `${value.width}%`;
                node.setAttribute('draggable', 'true');
                node.setAttribute('title', 'Clique para ajustar; arraste para mover no texto');
                node.setAttribute('tabindex', '0');
                node.setAttribute('role', 'group');
                node.setAttribute('aria-label', value.kind === 'video' ? 'Vídeo no artigo; pressione Enter para ajustar' : 'Imagem no artigo; pressione Enter para ajustar');
                const frameContainer = document.createElement('div');
                frameContainer.className = 'govdoc-media-frame';
                if (value.kind === 'video') {
                    const frame = document.createElement('iframe');
                    frame.className = 'ql-video';
                    frame.src = value.src;
                    frame.title = value.alt || 'Vídeo do documento';
                    frame.setAttribute('frameborder', '0');
                    frame.setAttribute('allowfullscreen', 'allowfullscreen');
                    frame.setAttribute('loading', 'lazy');
                    frame.setAttribute('referrerpolicy', 'strict-origin-when-cross-origin');
                    frameContainer.append(frame);
                } else {
                    const image = document.createElement('img');
                    image.src = value.src;
                    image.alt = value.alt;
                    image.draggable = true;
                    frameContainer.append(image);
                }
                const handle = document.createElement('span');
                handle.className = 'govdoc-media-handle';
                handle.textContent = '⠿';
                handle.setAttribute('draggable', 'true');
                handle.setAttribute('contenteditable', 'false');
                handle.setAttribute('aria-hidden', 'true');
                handle.setAttribute('title', 'Arrastar bloco de mídia');
                frameContainer.append(handle);
                const deleteButton = document.createElement('button');
                deleteButton.type = 'button';
                deleteButton.className = 'govdoc-media-delete';
                deleteButton.setAttribute('contenteditable', 'false');
                deleteButton.setAttribute('draggable', 'false');
                deleteButton.setAttribute('aria-label', value.kind === 'video' ? 'Excluir vídeo do artigo' : 'Excluir imagem do artigo');
                deleteButton.setAttribute('title', value.kind === 'video' ? 'Excluir vídeo' : 'Excluir imagem');
                deleteButton.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 7h16M10 4h4M6 7l1 13h10l1-13M10 11v6M14 11v6"/></svg>';
                frameContainer.append(deleteButton);
                for (const corner of ['nw', 'ne', 'sw', 'se']) {
                    const resizeHandle = document.createElement('span');
                    resizeHandle.className = `govdoc-media-resize-handle govdoc-media-resize-handle--${corner}`;
                    resizeHandle.dataset.resizeCorner = corner;
                    resizeHandle.setAttribute('contenteditable', 'false');
                    resizeHandle.setAttribute('aria-hidden', 'true');
                    resizeHandle.setAttribute('title', 'Arraste o canto para redimensionar proporcionalmente');
                    frameContainer.append(resizeHandle);
                }
                node.append(frameContainer);
                if (value.caption) {
                    const caption = document.createElement('figcaption');
                    caption.textContent = value.caption;
                    node.append(caption);
                }
                return node;
            }

            static value(node) {
                const image = node.querySelector('img');
                const frame = node.querySelector('iframe');
                const layout = [...layouts, 'block-left', 'block-right'].find(item => node.classList.contains(`govdoc-media--${item}`)) || 'center';
                return normalize({
                    kind: frame ? 'video' : 'image',
                    src: image?.getAttribute('src') || frame?.getAttribute('src') || '',
                    alt: image?.getAttribute('alt') || frame?.getAttribute('title') || '',
                    caption: node.querySelector('figcaption')?.textContent || '',
                    layout,
                    width: node.style.width
                });
            }
        }
        window.Quill.register(ArticleMediaBlot, true);
        registered = true;
    }

    // Documentos anteriores usavam imagem e legenda em parágrafos separados.
    // A conversão ocorre somente no editor, sem reescrever o banco ao abrir.
    function normalizeLegacyHtml(html) {
        const wrapper = document.createElement('div');
        wrapper.innerHTML = html;
        for (const media of Array.from(wrapper.querySelectorAll('img, iframe.ql-video'))) {
            if (media.closest('figure.govdoc-media')) continue;
            const paragraph = media.parentElement;
            const standalone = paragraph === wrapper;
            if (!standalone && (!paragraph || paragraph.tagName !== 'P' || paragraph.parentElement !== wrapper)) continue;
            const onlyMedia = standalone || (paragraph.children.length === 1 && !paragraph.textContent.trim());
            const target = standalone ? media : paragraph;
            const next = target.nextElementSibling;
            const figure = document.createElement('figure');
            figure.className = 'govdoc-media govdoc-media--center';
            figure.style.width = media.tagName === 'IMG' ? '80%' : '100%';
            if (standalone) {
                media.replaceWith(figure);
            } else {
                const before = paragraph.cloneNode(false);
                const after = paragraph.cloneNode(false);
                while (paragraph.firstChild && paragraph.firstChild !== media) before.append(paragraph.firstChild);
                media.remove();
                while (paragraph.firstChild) after.append(paragraph.firstChild);
                const blocks = [];
                if (before.textContent.trim() || before.querySelector('img, iframe, a, code')) blocks.push(before);
                blocks.push(figure);
                if (after.textContent.trim() || after.querySelector('img, iframe, a, code')) blocks.push(after);
                paragraph.replaceWith(...blocks);
            }
            figure.append(media);
            if (onlyMedia && next?.tagName === 'P' && next.children.length === 1 && next.firstElementChild?.tagName === 'EM') {
                const caption = document.createElement('figcaption');
                caption.textContent = next.textContent.trim();
                figure.append(caption);
                next.remove();
            }
        }
        return wrapper.innerHTML;
    }

    function attach(quill, panel, status) {
        const root = quill.root;
        const marker = document.createElement('div');
        marker.className = 'govdoc-media-drop-marker';
        marker.setAttribute('aria-hidden', 'true');
        quill.container.append(marker);
        const dropZones = document.createElement('div');
        dropZones.className = 'govdoc-media-drop-zones';
        dropZones.setAttribute('aria-hidden', 'true');
        for (const layout of ['wrap-left', 'center', 'wrap-right']) {
            const zone = document.createElement('div');
            zone.className = 'govdoc-media-drop-zone';
            zone.dataset.zoneLayout = layout;
            const label = document.createElement('span');
            label.textContent = layoutLabels[layout];
            zone.append(label);
            dropZones.append(zone);
        }
        quill.container.append(dropZones);
        const undoBar = document.createElement('div');
        undoBar.className = 'govdoc-media-undo';
        undoBar.setAttribute('role', 'status');
        undoBar.setAttribute('aria-live', 'polite');
        const undoMessage = document.createElement('span');
        const undoButton = document.createElement('button');
        undoButton.type = 'button';
        undoButton.textContent = 'Desfazer';
        undoBar.append(undoMessage, undoButton);
        quill.container.append(undoBar);
        let selected = null;
        let dragged = null;
        let resizing = null;
        let pendingRemoval = null;
        let undoTimer = null;
        let lastSelectionIndex = Math.max(0, quill.getLength() - 1);

        const getBlot = node => node ? window.Quill.find(node) : null;
        const mediaValue = node => {
            const blot = getBlot(node);
            return blot?.statics?.blotName === 'articleMedia' ? blot.statics.value(node) : null;
        };
        const announce = message => { if (status) status.textContent = message; };

        function clearSelection() {
            selected?.classList.remove('is-selected');
            selected = null;
            panel?.classList.add('hidden');
        }

        function select(node) {
            if (!node || !root.contains(node) || !mediaValue(node)) { clearSelection(); return; }
            if (selected && selected !== node) selected.classList.remove('is-selected');
            selected = node;
            selected.classList.add('is-selected');
            panel?.classList.remove('hidden');
            const value = mediaValue(node);
            const width = panel?.querySelector('[data-media-width]');
            if (width) { width.max = value.layout.startsWith('wrap-') ? '50' : '100'; width.value = String(value.width); }
            const output = panel?.querySelector('[data-media-width-output]');
            if (output) output.textContent = `${value.width}%`;
            const alt = panel?.querySelector('[data-media-alt]');
            if (alt) { alt.value = value.alt; alt.closest('[data-media-alt-wrap]')?.classList.toggle('hidden', value.kind !== 'image'); }
            const caption = panel?.querySelector('[data-media-caption]');
            if (caption) caption.value = value.caption;
            const kind = panel?.querySelector('[data-media-kind]');
            if (kind) kind.textContent = value.kind === 'video' ? 'Vídeo selecionado' : 'Imagem selecionada';
            const remove = panel?.querySelector('[data-media-remove]');
            if (remove) {
                const label = value.kind === 'video' ? 'Excluir vídeo' : 'Excluir imagem';
                remove.setAttribute('aria-label', label);
                const text = remove.querySelector('[data-media-remove-label]');
                if (text) text.textContent = label;
            }
            panel?.querySelectorAll('[data-media-layout]').forEach(button => {
                button.setAttribute('aria-pressed', button.dataset.mediaLayout === value.layout ? 'true' : 'false');
            });
        }

        function selectedPosition() {
            const blot = getBlot(selected);
            if (!blot || blot.statics.blotName !== 'articleMedia') return null;
            return { index: quill.getIndex(blot), value: mediaValue(selected) };
        }

        function clearUndo() {
            if (undoTimer) window.clearTimeout(undoTimer);
            undoTimer = null;
            pendingRemoval = null;
            undoBar.classList.remove('is-visible');
        }

        function removeSelected() {
            const position = selectedPosition();
            if (!position) return;
            clearUndo();
            quill.getModule('history')?.cutoff();
            quill.deleteText(position.index, 1, 'user');
            quill.getModule('history')?.cutoff();
            clearSelection();
            quill.setSelection(Math.min(position.index, quill.getLength() - 1), 0, 'silent');
            pendingRemoval = position;
            const label = position.value.kind === 'video' ? 'Vídeo' : 'Imagem';
            undoMessage.textContent = `${label} removido do artigo. Salve o documento para confirmar.`;
            undoBar.classList.add('is-visible');
            undoTimer = window.setTimeout(clearUndo, 10000);
            announce(`${label} removido. Você pode desfazer antes de salvar.`);
        }

        undoButton.addEventListener('click', () => {
            if (!pendingRemoval) return;
            const { index, value } = pendingRemoval;
            clearUndo();
            quill.getModule('history')?.cutoff();
            quill.insertEmbed(Math.min(index, Math.max(0, quill.getLength() - 1)), 'articleMedia', value, 'user');
            quill.getModule('history')?.cutoff();
            const restored = quill.getLeaf(index)[0]?.domNode?.closest?.('figure.govdoc-media');
            if (restored) select(restored);
            announce('Exclusão desfeita. O bloco voltou ao artigo.');
        });

        function replaceSelected(patch) {
            const position = selectedPosition();
            if (!position) return;
            const value = normalize({ ...position.value, ...patch });
            quill.deleteText(position.index, 1, 'user');
            quill.insertEmbed(position.index, 'articleMedia', value, 'user');
            select(quill.getLeaf(position.index)[0]?.domNode?.closest?.('figure.govdoc-media') || root.querySelectorAll('figure.govdoc-media')[0]);
        }

        function showWidth(figure, percent) {
            figure.style.width = `${percent}%`;
            const width = panel?.querySelector('[data-media-width]');
            if (width) width.value = String(percent);
            const output = panel?.querySelector('[data-media-width-output]');
            if (output) output.textContent = `${percent}%`;
        }

        function insert(raw, preferredIndex) {
            const value = normalize(raw);
            const index = Number.isInteger(preferredIndex) ? preferredIndex : lastSelectionIndex;
            const safeIndex = Math.min(Math.max(index, 0), Math.max(0, quill.getLength() - 1));
            quill.insertEmbed(safeIndex, 'articleMedia', value, 'user');
            const blot = quill.getLeaf(safeIndex)[0];
            const node = blot?.domNode?.closest?.('figure.govdoc-media');
            if (node) select(node);
            quill.setSelection(Math.min(safeIndex + 1, quill.getLength() - 1), 0, 'silent');
            announce(`${value.kind === 'video' ? 'Vídeo inserido' : 'Imagem inserida'} no ponto do cursor. Arraste o bloco para reposicioná-lo.`);
        }

        function moveTo(target, requestedLayout = null) {
            const position = selectedPosition();
            // Um bloco ocupa também a quebra de linha vizinha no Quill: soltar
            // imediatamente antes/depois dele é a mesma posição visual.
            if (!position || !Number.isInteger(target)) return;
            const layout = layouts.includes(requestedLayout) ? requestedLayout : position.value.layout;
            if (Math.abs(target - position.index) <= 1) {
                if (layout !== position.value.layout) {
                    replaceSelected({ layout });
                    announce(`Posição alterada: ${layoutLabels[layout]}. Salve o documento para confirmar.`);
                }
                return;
            }
            const value = normalize({ ...position.value, layout });
            quill.deleteText(position.index, 1, 'user');
            const adjusted = Math.min(Math.max(0, target > position.index ? target - 1 : target), Math.max(0, quill.getLength() - 1));
            quill.insertEmbed(adjusted, 'articleMedia', value, 'user');
            const node = quill.getLeaf(adjusted)[0]?.domNode?.closest?.('figure.govdoc-media');
            if (node) select(node);
            announce(`Bloco movido para ${layoutLabels[layout].toLowerCase()}. Salve o documento para confirmar.`);
        }

        function layoutFromX(clientX) {
            const bounds = root.getBoundingClientRect();
            const relativeX = (clientX - bounds.left) / Math.max(bounds.width, 1);
            return relativeX < 1 / 3 ? 'wrap-left' : relativeX > 2 / 3 ? 'wrap-right' : 'center';
        }

        function positionDuringDrag(clientX, clientY) {
            const bounds = dragged?.getBoundingClientRect();
            if (bounds && clientY >= bounds.top && clientY <= bounds.bottom) {
                const blot = getBlot(dragged);
                if (blot) return { index: quill.getIndex(blot), withinMedia: true };
            }
            return dropTarget(clientX, clientY);
        }

        function clearDragPreview() {
            dragged = null;
            marker.classList.remove('is-visible');
            dropZones.classList.remove('is-visible');
            delete dropZones.dataset.activeLayout;
        }

        function dropTarget(clientX, clientY) {
            const caret = document.caretRangeFromPoint?.(clientX, clientY);
            if (caret && root.contains(caret.startContainer) && !caret.startContainer.parentElement?.closest?.('figure.govdoc-media')) {
                const blot = window.Quill.find(caret.startContainer, true);
                if (blot?.statics?.blotName === 'text') {
                    const index = quill.getIndex(blot) + caret.startOffset;
                    const bounds = quill.getBounds(index);
                    if (bounds) return { index, caret: bounds };
                }
            }
            const blocks = Array.from(root.children).filter(node => node.nodeType === 1);
            for (const block of blocks) {
                const bounds = block.getBoundingClientRect();
                if (clientY < bounds.top + bounds.height / 2) {
                    const blot = getBlot(block);
                    if (blot) return { index: quill.getIndex(blot), top: bounds.top };
                }
            }
            const last = blocks[blocks.length - 1];
            return { index: Math.max(0, quill.getLength() - 1), top: last?.getBoundingClientRect().bottom || root.getBoundingClientRect().top };
        }

        quill.on('selection-change', range => {
            if (range) lastSelectionIndex = range.index;
        });
        quill.on('text-change', () => {
            if (pendingRemoval) clearUndo();
        });
        root.addEventListener('click', event => {
            const deleteButton = event.target.closest?.('.govdoc-media-delete');
            if (deleteButton) {
                event.preventDefault();
                event.stopPropagation();
                select(deleteButton.closest('figure.govdoc-media'));
                removeSelected();
                return;
            }
            const figure = event.target.closest?.('figure.govdoc-media');
            if (figure) { event.preventDefault(); select(figure); }
            else if (!panel?.contains(event.target)) clearSelection();
        });
        root.addEventListener('keydown', event => {
            if (selected && ['Delete', 'Backspace'].includes(event.key) && (event.target === root || event.target === selected)) {
                event.preventDefault();
                event.stopImmediatePropagation();
                removeSelected();
                return;
            }
            if (!['Enter', ' '].includes(event.key) || !event.target.matches?.('figure.govdoc-media')) return;
            event.preventDefault();
            select(event.target);
            panel?.querySelector('[data-media-width]')?.focus();
        }, true);
        root.addEventListener('pointerdown', event => {
            const handle = event.target.closest?.('.govdoc-media-resize-handle');
            const figure = handle?.closest('figure.govdoc-media');
            if (!figure || event.button !== 0) return;
            event.preventDefault();
            event.stopPropagation();
            select(figure);
            const value = mediaValue(figure);
            const styles = window.getComputedStyle(root);
            const contentWidth = root.clientWidth - Number.parseFloat(styles.paddingLeft) - Number.parseFloat(styles.paddingRight);
            if (!value || contentWidth <= 0) return;
            const side = handle.dataset.resizeCorner?.endsWith('w') ? -1 : 1;
            const centerFactor = value.layout === 'center' ? 2 : 1;
            resizing = { handle, figure, pointerId: event.pointerId, startX: event.clientX, startWidth: value.width, contentWidth, maxWidth: value.layout.startsWith('wrap-') ? 50 : 100, currentWidth: value.width, side, centerFactor };
            figure.classList.add('is-resizing');
            handle.setPointerCapture(event.pointerId);
        });
        root.addEventListener('pointermove', event => {
            if (!resizing || event.pointerId !== resizing.pointerId) return;
            event.preventDefault();
            const deltaPercent = (event.clientX - resizing.startX) * resizing.side * resizing.centerFactor / resizing.contentWidth * 100;
            const percent = Math.min(resizing.maxWidth, Math.max(25, Math.round(resizing.startWidth + deltaPercent)));
            resizing.currentWidth = percent;
            showWidth(resizing.figure, percent);
        });
        root.addEventListener('pointerup', event => {
            if (!resizing || event.pointerId !== resizing.pointerId) return;
            event.preventDefault();
            const { handle, figure, startWidth, currentWidth } = resizing;
            resizing = null;
            if (handle.hasPointerCapture(event.pointerId)) handle.releasePointerCapture(event.pointerId);
            figure.classList.remove('is-resizing');
            if (currentWidth !== startWidth) {
                replaceSelected({ width: currentWidth });
                announce(`Mídia redimensionada para ${currentWidth}%. Salve o documento para confirmar.`);
            }
        });
        root.addEventListener('pointercancel', event => {
            if (!resizing || event.pointerId !== resizing.pointerId) return;
            const { figure, startWidth } = resizing;
            resizing = null;
            figure.classList.remove('is-resizing');
            showWidth(figure, startWidth);
        });
        root.addEventListener('dragstart', event => {
            if (event.target.closest?.('.govdoc-media-delete')) {
                event.preventDefault();
                event.stopImmediatePropagation();
                return;
            }
            if (event.target.closest?.('.govdoc-media-resize-handle')) {
                event.preventDefault();
                event.stopImmediatePropagation();
                return;
            }
            const figure = event.target.closest?.('figure.govdoc-media');
            if (!figure) return;
            // O Quill cancela qualquer dragstart nativo; interceptamos somente
            // nossos blocos antes do listener interno dele.
            event.stopImmediatePropagation();
            dragged = figure;
            select(figure);
            dropZones.classList.add('is-visible');
            event.dataTransfer.effectAllowed = 'move';
            event.dataTransfer.setData('text/plain', 'govdoc-article-media');
        }, true);
        root.addEventListener('dragover', event => {
            if (!dragged) return;
            event.preventDefault();
            event.dataTransfer.dropEffect = 'move';
            const target = positionDuringDrag(event.clientX, event.clientY);
            dropZones.dataset.activeLayout = layoutFromX(event.clientX);
            if (target.withinMedia) {
                marker.classList.remove('is-visible');
                return;
            }
            if (target.caret) {
                marker.classList.add('is-caret');
                marker.style.top = `${target.caret.top}px`;
                marker.style.left = `${target.caret.left}px`;
                marker.style.height = `${Math.max(16, target.caret.height)}px`;
            } else {
                marker.classList.remove('is-caret');
                marker.style.left = '';
                marker.style.height = '';
                const containerTop = quill.container.getBoundingClientRect().top;
                marker.style.top = `${target.top - containerTop}px`;
            }
            marker.classList.add('is-visible');
        }, true);
        root.addEventListener('drop', event => {
            if (!dragged) return;
            event.preventDefault();
            const target = positionDuringDrag(event.clientX, event.clientY);
            moveTo(target.index, layoutFromX(event.clientX));
            clearDragPreview();
        }, true);
        root.addEventListener('dragend', clearDragPreview);

        panel?.querySelectorAll('[data-media-layout]').forEach(button => button.addEventListener('click', () => {
            replaceSelected({ layout: button.dataset.mediaLayout });
        }));
        const width = panel?.querySelector('[data-media-width]');
        width?.addEventListener('input', () => {
            if (selected) selected.style.width = `${width.value}%`;
            const output = panel.querySelector('[data-media-width-output]');
            if (output) output.textContent = `${width.value}%`;
        });
        width?.addEventListener('change', () => replaceSelected({ width: Number(width.value) }));
        const alt = panel?.querySelector('[data-media-alt]');
        alt?.addEventListener('input', () => { if (selected) selected.querySelector('img')?.setAttribute('alt', alt.value); });
        alt?.addEventListener('change', () => replaceSelected({ alt: alt.value }));
        const caption = panel?.querySelector('[data-media-caption]');
        caption?.addEventListener('input', () => {
            if (!selected) return;
            let label = selected.querySelector('figcaption');
            if (!label && caption.value) { label = document.createElement('figcaption'); selected.append(label); }
            if (label) label.textContent = caption.value;
        });
        caption?.addEventListener('change', () => replaceSelected({ caption: caption.value }));
        panel?.querySelector('[data-media-remove]')?.addEventListener('click', removeSelected);
        panel?.querySelectorAll('[data-media-move]').forEach(button => button.addEventListener('click', () => {
            const sibling = button.dataset.mediaMove === 'up' ? selected?.previousElementSibling : selected?.nextElementSibling;
            const blot = getBlot(sibling);
            if (!blot) return;
            const target = button.dataset.mediaMove === 'up' ? quill.getIndex(blot) : quill.getIndex(blot) + blot.length();
            moveTo(target);
        }));

        return {
            insert,
            getInsertionIndex() { return quill.getSelection()?.index ?? lastSelectionIndex; },
            selectedValue() { return selectedPosition(); }
        };
    }

    window.GovDocArticleMedia = { register, normalizeLegacyHtml, attach };
})();
