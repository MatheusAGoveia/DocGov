const { chromium } = require(process.env.DOCGOV_PLAYWRIGHT_PACKAGE || 'playwright');
const { execFileSync } = require('node:child_process');
const path = require('node:path');
const root = path.resolve(__dirname, '..');
const helper = path.join(__dirname, 'test_batch_user_import.php');
const run = (...args) => execFileSync(process.env.DOCGOV_PHP || 'php', [helper, ...args], { cwd: root, encoding: 'utf8' }).trim();
const assert = (condition, message) => { if (!condition) throw new Error(message); };
const base = process.env.DOCGOV_TEST_BASE_URL || 'http://127.0.0.1:8000';
const fixture = JSON.parse(run('--prepare'));

(async () => {
    let browser;
    try {
        browser = await chromium.launch({ headless: true, executablePath: process.env.DOCGOV_BROWSER_PATH || 'C:/Program Files/Google/Chrome/Application/chrome.exe' });
        const context = await browser.newContext({ viewport: { width: 1440, height: 1000 }, acceptDownloads: true });
        await context.addCookies([{ name: 'PHPSESSID', value: fixture.sessions.admin.id, url: base }]);
        const page = await context.newPage();
        const errors = []; page.on('pageerror', error => errors.push(error.message));
        await page.goto(`${base}/admin/index.php?tab=editar_grupo&id=${fixture.groupId}&group_tab=users`, { waitUntil: 'load' });
        const panel = page.locator('[data-batch-user-import]');
        assert(await panel.count() === 1, 'Painel em lote não aparece nos membros.');
        await panel.locator('summary').click();
        const domain = await panel.locator('[data-batch-domain]').inputValue();
        assert(domain, 'Domínio habilitado ausente.');
        await panel.locator('[data-batch-list]').fill(fixture.username);
        await panel.locator('[data-batch-submit]').click();
        await page.waitForFunction(() => document.querySelector('[data-batch-message]').textContent.startsWith('Lista processada.'));
        assert((await panel.locator('[data-batch-results]').innerText()).includes('Adicionado à equipe'), 'Inclusão real pela API falhou.');
        assert(JSON.parse(run('--inspect', fixture.token)).members === 1, 'Vínculo real não persistido.');
        await panel.locator('[data-batch-submit]').click();
        await page.waitForFunction(() => document.querySelector('[data-batch-message]').textContent.startsWith('Lista processada.'));
        assert((await panel.locator('[data-batch-results]').innerText()).includes('Já é membro'), 'Repetição real não exibiu membro existente.');
        assert(JSON.parse(run('--inspect', fixture.token)).members === 1, 'Repetição real duplicou vínculo.');
        console.log('PASS navegador + API real: adicionar usuário existente ao grupo e repetir sem duplicar.');

        const form = { mode: 'group', domain, group_id: String(fixture.groupId), entries: JSON.stringify([fixture.username]) };
        const api = `${base}/admin/import-users.php`;
        const anonymous = await browser.newContext();
        assert((await anonymous.request.post(api, { form })).status() === 401, 'API anônima não bloqueada.');
        assert((await context.request.get(api)).status() === 405, 'GET não bloqueado.');
        assert((await context.request.post(api, { form })).status() === 419, 'CSRF não bloqueado.');
        const reader = await browser.newContext();
        await reader.addCookies([{ name: 'PHPSESSID', value: fixture.sessions.reader.id, url: base }]);
        assert((await reader.request.post(api, { form, headers: { 'X-CSRF-Token': fixture.sessions.reader.csrf } })).status() === 403, 'Reader alterou grupo.');
        assert((await reader.request.post(api, { form: { ...form, mode: 'directory' }, headers: { 'X-CSRF-Token': fixture.sessions.reader.csrf } })).status() === 403, 'Reader importou diretório.');
        assert((await context.request.post(api, { form: { ...form, entries: JSON.stringify(Array(21).fill('Pessoa')) }, headers: { 'X-CSRF-Token': fixture.sessions.admin.csrf } })).status() === 422, 'API ignorou limite por etapa.');
        assert((await context.request.post(api, { form: { ...form, entries: 'null' }, headers: { 'X-CSRF-Token': fixture.sessions.admin.csrf } })).status() === 422, 'API aceitou JSON malformado.');
        console.log('PASS API: autenticação, método, CSRF, autorização, JSON e limite.');

        await page.goto(`${base}/admin/index.php?tab=usuarios`, { waitUntil: 'load' });
        await panel.locator('summary').click();
        let calls = 0;
        let failNext = false;
        let slowNext = false;
        const mock = async route => {
            const form = new URLSearchParams(route.request().postData());
            const entries = JSON.parse(form.get('entries'));
            calls++;
            assert(entries.length <= 20, 'Cliente enviou etapa grande demais.');
            if (failNext) { failNext = false; await route.abort('failed'); return; }
            if (slowNext) { slowNext = false; await new Promise(resolve => setTimeout(resolve, 400)); }
            await route.fulfill({ contentType: 'application/json', body: JSON.stringify({ success: true, results: entries.map((input, index) => {
                const status = input === 'Não Existe' || input.startsWith('=') || input.startsWith('<img') ? 'not_found' : input === 'Nome Igual' ? 'ambiguous' : input === 'Inativo' ? 'inactive' : 'imported';
                return { input, status, created: status === 'imported', username: status === 'imported' ? `mock.${index}` : '', message: status === 'imported' ? 'Importado.' : 'Confira esta entrada.', candidates: status === 'ambiguous' ? ['BETIM\\um', 'BETIM\\dois'] : [] };
            }) }) });
        };
        await page.route('**/admin/import-users.php', mock);
        const people = Array.from({ length: 500 }, (_, index) => `Pessoa Hospital ${index}`);
        await panel.locator('[data-batch-list]').fill(people.concat(['Pessoa Hospital 0', 'Não Existe', 'Nome Igual', 'Inativo', '=1+1', '<img src=x onerror="window.batchXss=1">']).join('\n'));
        await panel.locator('[data-batch-submit]').click();
        await page.waitForFunction(() => document.querySelector('[data-batch-message]').textContent.startsWith('Lista processada.'));
        assert(calls === 26, 'Cliente não dividiu lista de 500 em etapas.');
        assert(await panel.locator('[data-batch-results] tr').count() === 506, 'Resultado da lista perdeu linhas.');
        const summary = await panel.locator('[data-batch-summary]').innerText();
        assert(summary.includes('Importado: 500') && summary.includes('Repetido: 1') && summary.includes('Não encontrado no AD: 3') && summary.includes('Nome ambíguo: 1'), 'Resumo não diferencia todos os resultados.');
        assert(await panel.locator('[data-batch-results] img').count() === 0 && !(await page.evaluate(() => window.batchXss)), 'Resultado permitiu HTML na lista.');
        await panel.locator('[data-batch-filter]').selectOption('not_found');
        assert(await panel.locator('[data-batch-results] tr').count() === 3, 'Filtro de não encontrados incorreto.');
        const downloadPromise = page.waitForEvent('download'); await panel.locator('[data-batch-export]').click();
        const download = await downloadPromise;
        const stream = await download.createReadStream(); const buffers = []; for await (const chunk of stream) buffers.push(chunk);
        const csv = Buffer.concat(buffers).toString('utf8');
        assert(csv.includes("'\u003d1+1") && csv.split('\r\n').length === 507, 'CSV perdeu linhas ou permitiu fórmula de planilha.');
        await panel.locator('[data-batch-filter]').selectOption('all');
        await panel.screenshot({ path: path.join(root, 'scratch', 'batch-user-import-desktop.png') });
        console.log('PASS UI 500: progresso em etapas, resumo, filtros, homônimos, repetidos, exportação e proteção de HTML/CSV.');

        await panel.locator('[data-batch-file]').setInputFiles({ name: 'pessoas.csv', mimeType: 'text/csv', buffer: Buffer.from('\uFEFFNome\r\n"Ana Maria"\r\njoao.silva\r\n') });
        await page.waitForFunction(() => document.querySelector('[data-batch-count]').textContent.startsWith('2 entrada'));
        await panel.locator('[data-batch-file]').setInputFiles({ name: 'colunas.csv', mimeType: 'text/csv', buffer: Buffer.from('nome,email\nPessoa,email@example.invalid') });
        await page.waitForFunction(() => document.querySelector('[data-batch-message]').textContent.includes('uma única coluna'));
        await panel.locator('[data-batch-list]').fill(Array(1001).fill('Pessoa').join('\n'));
        const before = calls; await panel.locator('[data-batch-submit]').click();
        assert(calls === before && (await panel.locator('[data-batch-message]').innerText()).includes('até 1000'), 'Limite da lista não foi validado.');

        await panel.locator('[data-batch-list]').fill(people.slice(0, 45).join('\n'));
        slowNext = true;
        await panel.locator('[data-batch-submit]').click();
        await panel.locator('[data-batch-stop]').click();
        await page.waitForFunction(() => document.querySelector('[data-batch-message]').textContent.startsWith('Importação interrompida'));
        assert((await panel.locator('[data-batch-summary]').innerText()).includes('Pendente: 25'), 'Parar perdeu as pendências.');
        await panel.locator('[data-batch-retry]').click();
        await page.waitForFunction(() => document.querySelector('[data-batch-message]').textContent.startsWith('Lista processada.'));
        assert((await panel.locator('[data-batch-summary]').innerText()).includes('Importado: 45'), 'Retomar perdeu os resultados anteriores.');

        failNext = true;
        await panel.locator('[data-batch-submit]').click();
        await page.waitForFunction(() => document.querySelector('[data-batch-message]').textContent.includes('etapas seguintes continuam pendentes'));
        assert((await panel.locator('[data-batch-summary]').innerText()).includes('Pendente: 25'), 'Falha de rede executou etapas seguintes.');
        await panel.locator('[data-batch-retry]').click();
        await page.waitForFunction(() => document.querySelector('[data-batch-message]').textContent.startsWith('Lista processada.'));
        assert((await panel.locator('[data-batch-summary]').innerText()).includes('Importado: 45'), 'Reprocessar falha de rede não concluiu.');
        console.log('PASS UI: arquivos, limite de 1000, parar e continuar, falha de rede e reprocessamento.');

        await page.setViewportSize({ width: 390, height: 844 });
        await page.evaluate(() => {
            const sidebar = document.getElementById('sidebar-menu');
            if (sidebar && !sidebar.classList.contains('-translate-x-full')) window.toggleMobileSidebar();
        });
        await page.waitForFunction(() => document.getElementById('sidebar-menu').getBoundingClientRect().right <= 1);
        await panel.scrollIntoViewIfNeeded();
        assert(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth), 'Painel causa rolagem horizontal no celular.');
        await page.screenshot({ path: path.join(root, 'scratch', 'batch-user-import-mobile.png'), fullPage: false });
        await page.evaluate(() => document.documentElement.classList.add('dark'));
        await panel.screenshot({ path: path.join(root, 'scratch', 'batch-user-import-dark.png') });
        assert(errors.length === 0, 'Erros JavaScript: ' + errors.join(', '));
        console.log('PASS visual: desktop, celular de 390px, tema escuro e sem erros JavaScript.');
    } finally {
        if (browser) await browser.close();
        run('--cleanup', fixture.token);
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
