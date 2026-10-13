/* Valida antes de enviar, mantendo os campos e os arquivos para correção. */
(() => {
    const mb = 1024 * 1024;
    const format = bytes => `${Number((bytes / mb).toFixed(2))} MB`;
    function validate(entries, limits) {
        let total = 4096;
        let batchBytes = 0;
        let batchCount = 0;
        for (const [name, value] of entries) {
            total += 1024 + new TextEncoder().encode(name).length;
            if (!(value instanceof File)) {
                total += new TextEncoder().encode(String(value)).length;
                continue;
            }
            total += value.size + new TextEncoder().encode(value.name).length;
            if (!value.size) continue;
            let limit = limits.file > 0 ? limits.file : Infinity;
            const batch = name === 'arquivo_file[]';
            const video = name === 'video_file' || (batch && /\.(mp4|webm|ogv|m4v|mov)$/i.test(value.name));
            if (video || name === 'arquivo_file' || batch) limit = Math.min(limit, (video ? 50 : 25) * mb);
            if (value.size > limit) return `O arquivo “${value.name}” excede o limite de ${format(limit)}. Reduza o arquivo e tente novamente.`;
            if (batch) { batchBytes += value.size; batchCount++; }
        }
        if (batchCount > 20) return 'Envie no máximo 20 arquivos por vez.';
        if (batchBytes > 500 * mb) return 'A fila excede o limite total de 500 MB. Envie em lotes menores.';
        if (limits.post > 0 && total > limits.post) return `O envio excede o limite total do servidor (${format(limits.post)}). Reduza os arquivos ou envie em lotes menores.`;
        return '';
    }
    window.DocGovUploads = { validate };
    const config = document.getElementById('upload-validation');
    const limits = JSON.parse(config.dataset.limits);
    document.addEventListener('submit', event => {
        const form = event.target;
        if (!(form instanceof HTMLFormElement) || form.enctype !== 'multipart/form-data') return;
        const error = validate(new FormData(form), limits);
        let notice = form.querySelector('[data-upload-error]');
        if (!error) { notice?.remove(); return; }
        event.preventDefault();
        event.stopImmediatePropagation();
        if (!notice) {
            notice = document.createElement('div');
            notice.dataset.uploadError = '1';
            notice.setAttribute('role', 'alert');
            notice.tabIndex = -1;
            notice.className = 'rounded-lg border border-red-300 bg-red-50 p-3 text-sm text-red-800 dark:border-red-800 dark:bg-red-950 dark:text-red-200';
            form.prepend(notice);
        }
        notice.textContent = error;
        notice.focus();
        notice.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }, true);
})();
