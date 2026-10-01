(() => {
    'use strict';
    const MAX_ENTRIES = 1000;
    const CHUNK_SIZE = 20;
    const successStatuses = new Set(['imported', 'existing', 'added', 'already_member', 'duplicate']);
    const retryStatuses = new Set(['pending', 'error', 'unavailable']);
    const labels = { pending: 'Pendente', imported: 'Importado', existing: 'Já cadastrado', added: 'Adicionado à equipe', already_member: 'Já é membro', duplicate: 'Repetido', not_found: 'Não encontrado no AD', ambiguous: 'Nome ambíguo', inactive: 'Conta inativa', invalid: 'Entrada inválida', conflict: 'Conflito de cadastro', unavailable: 'AD indisponível', error: 'Falha nesta entrada' };

    // CSV de uma coluna, inclusive campos entre aspas; nunca escolhe uma coluna silenciosamente.
    function parseList(text) {
        text = text.replace(/^\uFEFF/, '');
        const lines = [];
        let value = '', quoted = false, closed = false;
        for (let index = 0; index < text.length; index++) {
            const char = text[index];
            if (quoted) {
                if (char === '"' && text[index + 1] === '"') { value += '"'; index++; }
                else if (char === '"') { quoted = false; closed = true; }
                else if (char === '\r' || char === '\n') { throw new Error('Cada nome ou login deve ocupar uma única linha.'); }
                else { value += char; }
            } else if (char === '"' && value.trim() === '' && !closed) {
                quoted = true;
            } else if (char === '\r' || char === '\n') {
                if (char === '\r' && text[index + 1] === '\n') index++;
                if (value.trim()) lines.push(value.trim());
                value = ''; closed = false;
            } else if (char === ',' || char === ';' || char === '\t') {
                throw new Error('Use uma única coluna com um nome ou login por linha. Remova as colunas extras do CSV.');
            } else if (closed && char.trim()) {
                throw new Error('Confira as aspas e as colunas do CSV.');
            } else { value += char; }
        }
        if (quoted) throw new Error('O CSV contém aspas sem fechamento.');
        if (value.trim()) lines.push(value.trim());
        if (lines.length && /^(nome|nome completo|login|username|usu[aá]rio)$/i.test(lines[0])) lines.shift();
        if (lines.length > MAX_ENTRIES) throw new Error(`A lista contém ${lines.length} entradas. Envie até ${MAX_ENTRIES} por vez.`);
        if (lines.some(line => [...line].length > 255 || /[\x00-\x1f\x7f]/.test(line))) throw new Error('Cada entrada deve ter até 255 caracteres e não pode conter caracteres de controle.');
        return lines;
    }

    document.querySelectorAll('[data-batch-user-import]').forEach(root => {
        const get = name => root.querySelector(`[data-batch-${name}]`);
        let rows = [], running = false, stop = false, reportDomain = '';
        const message = text => { get('message').textContent = text; };
        const setBusy = busy => {
            running = busy;
            ['submit', 'list', 'file', 'domain'].forEach(name => { get(name).disabled = busy; });
            get('retry').disabled = busy;
            get('stop').hidden = !busy;
            get('stop').disabled = false;
            root.setAttribute('aria-busy', String(busy));
        };
        const render = () => {
            const counts = {};
            rows.forEach(row => { counts[row.status] = (counts[row.status] || 0) + 1; });
            get('summary').replaceChildren();
            for (const [status, count] of Object.entries(counts)) {
                const item = document.createElement('span');
                item.textContent = `${labels[status] || status}: ${count}`;
                get('summary').append(item);
            }
            const created = rows.filter(row => row.created).length;
            if (created) { const item = document.createElement('span'); item.textContent = `Cadastros novos: ${created}`; get('summary').append(item); }
            const filter = get('filter').value;
            const fragment = document.createDocumentFragment();
            rows.filter(row => filter === 'all' || (filter === 'issues' && !successStatuses.has(row.status)) || (filter === 'success' && successStatuses.has(row.status)) || row.status === filter).forEach(row => {
                const tr = document.createElement('tr');
                const detail = [row.message, row.username ? `Login: ${row.username}` : '', ...(row.candidates || [])].filter(Boolean).join(' ');
                [row.line, row.input, labels[row.status] || row.status, detail].forEach((value, index) => {
                    const td = document.createElement('td');
                    td.textContent = String(value);
                    if (index === 2) td.dataset.status = row.status;
                    tr.append(td);
                });
                fragment.append(tr);
            });
            get('results').replaceChildren(fragment);
            get('report').hidden = false;
            get('copy').hidden = !counts.not_found;
            get('retry').hidden = running || !rows.some(row => retryStatuses.has(row.status));
            const done = rows.filter(row => row.status !== 'pending').length;
            get('progress').max = rows.length || 1;
            get('progress').value = done;
            get('progress-text').textContent = `${done} de ${rows.length} entradas processadas${reportDomain ? ` · ${reportDomain}` : ''}.`;
        };

        get('list').addEventListener('input', () => {
            try { const count = parseList(get('list').value).length; get('count').textContent = `${count} entrada(s) na lista. Repetições serão ignoradas.`; }
            catch (error) { get('count').textContent = error.message; }
        });
        get('file').addEventListener('change', async () => {
            const file = get('file').files[0];
            if (!file) return;
            try {
                if (!/\.(txt|csv)$/i.test(file.name) || file.size > 1024 * 1024) throw new Error('Selecione um arquivo TXT ou CSV de até 1 MB.');
                const buffer = await file.arrayBuffer();
                const text = new TextDecoder('utf-8', { fatal: true }).decode(buffer);
                parseList(text);
                get('list').value = text;
                get('list').dispatchEvent(new Event('input'));
                message(`Lista carregada: ${file.name}. Confira o domínio e os nomes antes de importar.`);
            } catch (error) { message(error instanceof TypeError ? 'Salve o arquivo com codificação UTF-8 e tente novamente.' : error.message); }
            get('file').value = '';
        });

        const process = async indexes => {
            if (running || !indexes.length) return;
            stop = false;
            setBusy(true);
            get('progress-wrap').hidden = false;
            message('Consultando e processando a lista. Você pode parar após a etapa atual.');
            render();
            let interrupted = false;
            try {
                for (let offset = 0; offset < indexes.length && !stop; offset += CHUNK_SIZE) {
                    const chunk = indexes.slice(offset, offset + CHUNK_SIZE);
                    const body = new URLSearchParams({ mode: root.dataset.mode, domain: reportDomain, entries: JSON.stringify(chunk.map(index => rows[index].input)) });
                    if (root.dataset.mode === 'group') body.set('group_id', root.dataset.groupId);
                    try {
                        const response = await fetch(root.dataset.endpoint, { method: 'POST', credentials: 'same-origin', headers: { 'X-CSRF-Token': root.dataset.csrf, Accept: 'application/json' }, body });
                        const payload = await response.json();
                        if (!response.ok || !payload.success) throw new Error(payload.error || 'Não foi possível processar esta etapa.');
                        if (!Array.isArray(payload.results) || payload.results.length !== chunk.length) throw new Error('O servidor retornou uma resposta incompleta.');
                        payload.results.forEach((result, position) => { const index = chunk[position]; rows[index] = { ...result, input: rows[index].input, line: rows[index].line }; });
                    } catch (error) {
                        chunk.forEach(index => { rows[index].status = 'error'; rows[index].message = 'Não foi possível confirmar esta etapa. Reprocessar não duplica cadastros ou vínculos.'; });
                        message(`${error.message} As etapas seguintes continuam pendentes. Baixe o relatório antes de atualizar a página.`);
                        interrupted = true;
                        break;
                    }
                    render();
                }
            } finally {
                setBusy(false);
                render();
                if (!interrupted) message(stop ? 'Importação interrompida após a etapa atual. Os resultados concluídos foram preservados; você pode processar as pendências.' : 'Lista processada. Confira os resultados e os nomes não encontrados. Baixe o relatório antes de atualizar a página.');
            }
        };
        get('form').addEventListener('submit', event => {
            event.preventDefault();
            try {
                const entries = parseList(get('list').value);
                if (!entries.length) throw new Error('Informe pelo menos um nome ou login.');
                if (!get('domain').value) throw new Error('Selecione um domínio habilitado.');
                const seen = new Set();
                rows = entries.map((input, index) => {
                    const key = input.toLocaleLowerCase('pt-BR');
                    const duplicate = seen.has(key);
                    seen.add(key);
                    return { input, line: index + 1, status: duplicate ? 'duplicate' : 'pending', created: false, message: duplicate ? 'Entrada repetida na lista; considerada apenas uma vez.' : 'Aguardando processamento.' };
                });
                reportDomain = get('domain').value;
                process(rows.map((row, index) => row.status === 'pending' ? index : -1).filter(index => index >= 0));
            } catch (error) { message(error.message); }
        });
        get('stop').addEventListener('click', () => { stop = true; get('stop').disabled = true; message('A etapa atual será concluída antes de parar.'); });
        get('retry').addEventListener('click', () => process(rows.map((row, index) => retryStatuses.has(row.status) ? index : -1).filter(index => index >= 0)));
        get('filter').addEventListener('change', render);
        get('refresh').addEventListener('click', event => { if (running) { event.preventDefault(); message('Espere a etapa atual terminar para atualizar os membros ou o diretório.'); } });
        get('copy').addEventListener('click', async () => {
            try { await navigator.clipboard.writeText(rows.filter(row => row.status === 'not_found').map(row => row.input).join('\n')); message('Nomes não encontrados copiados.'); }
            catch { message('Não foi possível copiar. Baixe o relatório CSV para consultar os nomes não encontrados.'); }
        });
        get('export').addEventListener('click', () => {
            const cell = value => {
                let text = String(value ?? '');
                if (/^[\s]*[=+\-@]/.test(text)) text = "'" + text;
                return '"' + text.replace(/"/g, '""') + '"';
            };
            const data = [['Linha', 'Entrada', 'Domínio', 'Resultado', 'Detalhes', 'Login', 'Cadastro novo'], ...rows.map(row => [row.line, row.input, reportDomain, labels[row.status] || row.status, [row.message, ...(row.candidates || [])].join(' '), row.username || '', row.created ? 'Sim' : 'Não'])];
            const blob = new Blob(['\uFEFF' + data.map(row => row.map(cell).join(';')).join('\r\n')], { type: 'text/csv;charset=utf-8' });
            const url = URL.createObjectURL(blob);
            const anchor = document.createElement('a');
            anchor.href = url; anchor.download = 'resultado-importacao-usuarios.csv'; anchor.click();
            setTimeout(() => URL.revokeObjectURL(url), 1000);
        });
        window.addEventListener('beforeunload', event => { if (running) { event.preventDefault(); event.returnValue = ''; } });
    });
})();
