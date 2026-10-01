// Diálogos compartilhados. O <dialog> mantém o foco e torna o fundo inerte.
(() => {
    'use strict';

    const queue = [];
    let active = null;
    let dialog;
    let elements;

    function createDialog() {
        dialog = document.createElement('dialog');
        dialog.className = 'dg-dialog';
        dialog.setAttribute('role', 'alertdialog');
        dialog.setAttribute('aria-modal', 'true');
        dialog.setAttribute('aria-labelledby', 'dg-dialog-title');
        dialog.innerHTML = `
            <form method="dialog" class="dg-dialog__form">
                <div class="dg-dialog__header">
                    <span class="dg-dialog__icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                            <circle cx="12" cy="12" r="9"/><path stroke-linecap="round" d="M12 7v6m0 4h.01"/>
                        </svg>
                    </span>
                    <div class="dg-dialog__heading">
                        <p class="dg-dialog__eyebrow"></p>
                        <h2 id="dg-dialog-title" class="dg-dialog__title"></h2>
                    </div>
                    <button type="button" class="dg-dialog__close" aria-label="Fechar diálogo">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                            <path stroke-linecap="round" d="m6 6 12 12M6 18 18 6"/>
                        </svg>
                    </button>
                </div>
                <div class="dg-dialog__body">
                    <p id="dg-dialog-message" class="dg-dialog__message"></p>
                    <div id="dg-dialog-details" class="dg-dialog__details" hidden>
                        <strong class="dg-dialog__details-label">Itens afetados</strong>
                        <p class="dg-dialog__details-text"></p>
                    </div>
                    <p id="dg-dialog-warning" class="dg-dialog__warning" hidden></p>
                    <div class="dg-dialog__field" hidden>
                        <label for="dg-dialog-input" class="dg-dialog__label"></label>
                        <code class="dg-dialog__expected" hidden></code>
                        <input id="dg-dialog-input" class="dg-dialog__input" type="text" autocomplete="off" spellcheck="false" aria-describedby="dg-dialog-help">
                        <p id="dg-dialog-help" class="dg-dialog__help" aria-live="polite"></p>
                    </div>
                </div>
                <div class="dg-dialog__footer">
                    <button type="button" class="dg-dialog__button dg-dialog__cancel">Cancelar</button>
                    <button type="submit" class="dg-dialog__button dg-dialog__confirm"></button>
                </div>
            </form>`;
        document.body.append(dialog);
        const find = name => dialog.querySelector('.dg-dialog__' + name);
        elements = Object.fromEntries([
            'form', 'eyebrow', 'title', 'message', 'details', 'details-text', 'warning',
            'field', 'label', 'expected', 'input', 'help', 'cancel', 'confirm', 'close',
        ].map(name => [name, find(name)]));
        elements.cancel.addEventListener('click', () => finish(false));
        elements.close.addEventListener('click', () => finish(false));
        dialog.addEventListener('cancel', event => {
            event.preventDefault();
            finish(false);
        });
        dialog.addEventListener('click', event => {
            if (event.target !== dialog) return;
            const bounds = dialog.getBoundingClientRect();
            if (event.clientX < bounds.left || event.clientX > bounds.right
                || event.clientY < bounds.top || event.clientY > bounds.bottom) finish(false);
        });
        elements.input.addEventListener('input', validateName);
        elements.form.addEventListener('submit', event => {
            event.preventDefault();
            if (!active || !validateName()) return;
            finish(true);
        });
    }

    function validateName() {
        if (!active) return false;
        const expected = active.options.requiredName;
        const value = elements.input.value.trim();
        const valid = expected === undefined || value === expected;
        elements.confirm.disabled = !valid;
        elements.input.setAttribute('aria-invalid', String(!valid && value !== ''));
        elements.help.classList.toggle('dg-dialog__help--error', !valid && value !== '');
        elements.help.textContent = expected === undefined ? '' : valid
            ? 'Nome confirmado. Você pode continuar.'
            : value ? 'O nome deve ser exatamente igual ao exibido acima.'
                : 'Digite o nome completo para liberar a exclusão.';
        return valid;
    }

    function finish(accepted) {
        if (!active) return;
        const request = active;
        const value = request.kind === 'prompt'
            ? (accepted ? elements.input.value.trim() : null) : accepted;
        dialog.close();
        document.body.style.overflow = request.previousOverflow;
        active = null;
        if (request.opener?.isConnected) request.opener.focus({ preventScroll: true });
        request.resolve(value);
        // Permite que a chamada anterior termine antes de abrir o próximo aviso.
        queueMicrotask(showNext);
    }

    function showNext() {
        if (active || !queue.length) return;
        active = queue.shift();
        const { kind, options } = active;

        // Compatibilidade com navegadores antigos sem HTMLDialogElement.
        if (typeof HTMLDialogElement === 'undefined' || !HTMLDialogElement.prototype.showModal) {
            const request = active;
            const message = [options.message, options.details, options.warning,
                options.requiredName === undefined ? '' : 'Digite exatamente: ' + options.requiredName,
            ].filter(Boolean).join('\n');
            let value;
            if (kind === 'alert') { window.alert(message); value = true; }
            else if (kind === 'prompt') {
                value = window.prompt(message);
                if (value !== null) value = value.trim();
                if (options.requiredName !== undefined && value !== options.requiredName) value = null;
            } else value = window.confirm(message);
            active = null;
            request.resolve(value);
            queueMicrotask(showNext);
            return;
        }

        if (!dialog) createDialog();
        active.opener = options.opener || document.activeElement;
        active.previousOverflow = document.body.style.overflow;
        dialog.dataset.tone = options.tone || 'info';
        elements.eyebrow.textContent = kind === 'alert' ? 'Aviso do sistema' : 'Confirmação de ação';
        elements.title.textContent = options.title || (kind === 'alert' ? 'Não foi possível concluir' : 'Confirmar ação');
        elements.message.textContent = options.message || '';
        elements['details-text'].textContent = options.details || '';
        elements.details.hidden = !options.details;
        elements.warning.textContent = options.warning || '';
        elements.warning.hidden = !options.warning;
        dialog.setAttribute('aria-describedby', ['dg-dialog-message',
            options.details ? 'dg-dialog-details' : '', options.warning ? 'dg-dialog-warning' : '',
        ].filter(Boolean).join(' '));
        elements.field.hidden = kind !== 'prompt';
        elements.label.textContent = options.inputLabel || 'Digite o nome abaixo para confirmar:';
        elements.expected.hidden = options.requiredName === undefined;
        elements.expected.textContent = options.requiredName || '';
        elements.input.value = '';
        elements.input.required = kind === 'prompt';
        elements.cancel.hidden = kind === 'alert';
        elements.confirm.textContent = options.confirmLabel || (kind === 'alert' ? 'Entendi' : 'Confirmar');
        validateName();
        document.body.style.overflow = 'hidden';
        dialog.showModal();
        (kind === 'prompt' ? elements.input : kind === 'alert' ? elements.confirm : elements.cancel).focus();
    }

    function open(kind, options) {
        return new Promise(resolve => {
            queue.push({ kind, options: typeof options === 'string' ? { message: options } : options, resolve });
            showNext();
        });
    }

    window.DocGovDialog = {
        confirm: options => open('confirm', options),
        alert: options => open('alert', options),
        prompt: options => open('prompt', options),
    };

    const approved = new WeakMap();
    const pending = new WeakSet();
    document.addEventListener('submit', async event => {
        const form = event.target;
        if (!(form instanceof HTMLFormElement)) return;
        const submitter = event.submitter;
        if (approved.has(form) && approved.get(form) === submitter) {
            approved.delete(form);
            return;
        }
        const source = submitter?.hasAttribute('data-confirm') ? submitter : form;
        const permanent = form.hasAttribute('data-delete-name');
        if (!permanent && !source.hasAttribute('data-confirm')) return;
        if (event.defaultPrevented) return;
        event.preventDefault();
        if (pending.has(form)) return;
        pending.add(form);
        try {
            let accepted;
            if (permanent) {
                const name = form.dataset.deleteName;
                const typed = await window.DocGovDialog.prompt({
                    title: 'Excluir permanentemente',
                    message: 'Você está prestes a excluir “' + name + '” e todos os itens abaixo dele.',
                    details: form.dataset.deleteSummary,
                    warning: 'Esta ação não pode ser desfeita. Os arquivos e vínculos também serão apagados.',
                    requiredName: name,
                    tone: 'danger',
                    confirmLabel: 'Excluir permanentemente',
                    opener: submitter,
                });
                accepted = typed !== null;
                const confirmation = form.querySelector('input[name="confirmation_name"]');
                if (!confirmation) return;
                confirmation.value = accepted ? typed : '';
            } else {
                accepted = await window.DocGovDialog.confirm({
                    message: source.dataset.confirm,
                    title: source.dataset.confirmTitle,
                    tone: source.dataset.confirmTone,
                    confirmLabel: source.dataset.confirmLabel,
                    opener: submitter,
                });
            }
            if (!accepted || !form.isConnected || (submitter && !submitter.isConnected)) return;
            // Preserva name/value do botão clicado, validação HTML e demais listeners.
            approved.set(form, submitter);
            try { form.requestSubmit(submitter || undefined); }
            finally { approved.delete(form); }
        } finally {
            pending.delete(form);
        }
    });
})();
