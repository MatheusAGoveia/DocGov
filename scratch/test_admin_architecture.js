'use strict';

// HTTP real em base, sessões e servidor exclusivos preparados pelo driver PHP.
const { spawn, execFileSync } = require('node:child_process');
const fs = require('node:fs');
const path = require('node:path');
const net = require('node:net');
const assert = require('node:assert/strict');
const root = path.resolve(__dirname, '..');
const manifestPath = process.env.DOCGOV_ADMIN_FIXTURE_FILE;
assert(manifestPath, 'Execute por php scratch/test_admin_architecture.php.');
const fixture = JSON.parse(fs.readFileSync(manifestPath, 'utf8'));
assert(/^docgov_admin_architecture_test_[a-f0-9]{12}$/.test(fixture.database));
assert.equal(process.env.DB_NAME, fixture.database);
const php = process.env.DOCGOV_PHP || 'php';
const results = [];
const inspect = () => JSON.parse(execFileSync(php, [path.join(__dirname, 'test_admin_architecture.php'), '--inspect', manifestPath], { cwd: root, encoding: 'utf8', windowsHide: true }));
const option = name => {
    const index = process.argv.indexOf(name);
    return index < 0 ? null : process.argv[index + 1];
};
const fields = html => [...html.matchAll(/<(?:input|textarea|select)\b[^>]*\bname=["']([^"']+)["']/gi)].map(m => m[1]).sort();
const valueOf = (html, name) => {
    const input = [...html.matchAll(/<input\b[^>]*>/gi)].map(m => m[0]).find(tag => new RegExp(`\\bname=["']${name}["']`).test(tag));
    return input?.match(/\bvalue="([^"]*)"/)?.[1] ?? null;
};
const summary = (response, html) => {
    const type = response.headers.get('content-type')?.split(';')[0] || '';
    const item = { status: response.status, location: response.headers.get('location'), type };
    assert(!/<b>(?:Warning|Fatal error|Parse error|Deprecated)<\/b>|PHP (?:Warning|Fatal error)/.test(html), 'Erro técnico na resposta: ' + html.slice(0, 300));
    if (type === 'application/json') item.json = JSON.parse(html);
    else item.fields = fields(html);
    return item;
};

async function runHTTP(base) {
    const request = async (name, route, { user = 'admin', body, status } = {}) => {
        const headers = user ? { Cookie: 'PHPSESSID=' + fixture.users[user].session } : {};
        const params = body === undefined ? undefined : new URLSearchParams(body);
        const response = await fetch(base + route, { method: body === undefined ? 'GET' : 'POST', redirect: 'manual', headers, body: params });
        const html = await response.text();
        const item = { name, ...summary(response, html) };
        if (status !== undefined) assert.equal(response.status, status, `${name}: ${html.slice(0, 300)}`);
        results.push(item);
        return { response, html, item };
    };
    await request('sem sessão', '/admin/index.php', { user: null, status: 302 });
    await request('leitor sem painel', '/admin/index.php', { user: 'reader', status: 302 });
    await request('gestor sem configurações', '/admin/index.php?tab=configuracoes', { user: 'manager', status: 403 });
    await request('delegação de diretório', '/admin/index.php?tab=usuarios', { user: 'delegate', status: 200 });
    await request('delegado sem equipes', '/admin/index.php?tab=grupos', { user: 'delegate', status: 403 });
    for (const tab of ['visao_geral', 'documentos', 'novo_documento', 'categorias', 'subcategorias', 'assuntos', 'editar_estrutura', 'grupos', 'usuarios', 'tags', 'configuracoes', 'servidores_ad', 'lixeira']) {
        await request('página ' + tab, '/admin/index.php?tab=' + tab, { status: 200 });
    }
    await request('página membros', '/admin/index.php?tab=editar_grupo&id=' + fixture.groupId + '&group_tab=users', { status: 200 });
    const csrfActions = ['structure_delete_action', 'structure_action', 'subject_workspace_action', 'save_subject_workspace', 'test_ad_connection', 'replicate_ad_users', 'save_system_settings', 'test_ad_health', 'get_user_details', 'delete_ad_domain', 'create_ad_domain', 'save_ad_settings', 'tag_admin_action', 'create_user', 'save_direct_user_permission', 'remove_direct_user_permission', 'group_action', 'save_doc', 'workflow_quick_action', 'document_trash_action', 'batch_action', 'save_category', 'save_subcategory', 'save_subject'];
    for (const action of csrfActions) {
        await request('CSRF ' + action, '/admin/index.php?tab=novo_documento', { body: { [action]: 'invalid', csrf_token: 'invalid', subject_id: String(fixture.subjectId) } });
    }
    await request('dois indicadores AD', '/admin/index.php', { body: { test_ad_connection: '1', replicate_ad_users: '1', csrf_token: 'invalid' }, status: 419 });
    await request('dois indicadores sem término', '/admin/index.php', { body: { structure_action: 'invalid', tag_admin_action: 'invalid', csrf_token: 'invalid' }, status: 419 });
    await request('lote antes de salvar e CSRF', '/admin/index.php', { body: { save_doc: '1', batch_upload: '1', csrf_token: 'invalid' }, status: 419 });
    await request('lote não aceita edição', '/admin/index.php', { body: { save_doc: '1', batch_upload: '1', id: String(fixture.documentIds.draft), csrf_token: fixture.csrfToken }, status: 422 });
    const draft = { save_doc: '1', csrf_token: fixture.csrfToken, titulo: 'Rascunho via arquitetura', descricao: 'Descrição de teste', categoria: String(fixture.categoryId), subcategoria: String(fixture.subcategoryId), assunto: String(fixture.subjectId), tipo_conteudo: 'text', conteudo_html: '<p>Conteúdo temporário.</p>', workflow_action: 'save_draft' };
    await request('salvar rascunho', '/admin/index.php?tab=novo_documento', { body: draft, status: 302 });
    const invalid = await request('recuperação após validação', '/admin/index.php?tab=novo_documento', { body: { ...draft, titulo: 'Título de recuperação', assunto: '0', tipo_conteudo: 'code', section_key: 'source-code', codigo_fonte: 'print("ação")', linguagem_codigo: 'python', 'new_tags[]': 'Nova tag de teste' } });
    invalid.item.recoveredTitle = valueOf(invalid.html, 'titulo');
    assert.equal(invalid.item.recoveredTitle, 'Título de recuperação');
    assert(invalid.html.includes('print(&quot;ação&quot;)') || invalid.html.includes('print("ação")'), 'Conteúdo perdido após validação');
    await request('incluir membro', '/admin/index.php?tab=editar_grupo&id=' + fixture.groupId + '&group_tab=users', { body: { group_action: 'add_user', group_id: String(fixture.groupId), user_id: String(fixture.users.reader.id), csrf_token: fixture.csrfToken }, status: 302 });
    const state = inspect();
    assert.equal(state.documents.filter(d => d.title === draft.titulo).length, 1);
    assert(state.members.some(m => m.group_id === fixture.groupId && m.user_id === fixture.users.reader.id));
    results.push({ name: 'efeitos persistidos', state });
    // Depois da extração, todos os includes internos devem recusar GET/POST direto.
    const plan = fs.readFileSync(path.join(root, 'docs/superpowers/plans/2026-11-13-organizacao-modulos-administrativos.md'), 'utf8');
    const handlers = [...plan.matchAll(/^\| \d+ \| `([^`]+\.php)` \|/gm)].map(m => 'admin/modules/' + m[1]);
    if (fs.existsSync(path.join(root, 'admin/dispatch.php'))) {
        const before = JSON.stringify(inspect());
        for (const target of ['admin/dispatch.php', ...handlers, ...(fs.existsSync(path.join(root, 'admin/partials/client-config.php')) ? ['admin/partials/client-config.php'] : [])]) {
            for (const method of ['GET', 'POST']) {
                const response = await fetch(base + '/' + target, { method, body: method === 'POST' ? new URLSearchParams({ save_doc: '1', group_action: 'delete_group', group_id: fixture.groupId, csrf_token: fixture.csrfToken }) : undefined });
                assert.equal(response.status, 404, method + ' direto: ' + target);
                await response.arrayBuffer();
            }
        }
        assert.equal(JSON.stringify(inspect()), before, 'Acesso direto alterou o banco');
        console.log('[OK] Includes internos recusaram acesso direto, sem efeitos persistidos.');
    }
    const record = option('--record');
    const compare = option('--compare');
    const snapshot = { sourceHash: fixture.sourceHash, results };
    if (record) fs.writeFileSync(path.resolve(record), JSON.stringify(snapshot, null, 2));
    if (compare) assert.deepEqual(results, JSON.parse(fs.readFileSync(path.resolve(compare), 'utf8')).results, 'A caracterização HTTP mudou.');
    console.log('[OK] ' + results.length + ' verificações HTTP/estado' + (compare ? ', sem diferenças da caracterização anterior.' : '.'));
    // Verifica o destino físico do upload com um arquivo próprio, removido ao final.
    const form = new FormData();
    for (const [key, value] of Object.entries({ ...draft, batch_upload: '1', 'batch_titles[]': 'Arquivo da suíte' })) form.set(key, value);
    const filename = 'architecture-test-' + require('node:crypto').randomBytes(8).toString('hex') + '.txt';
    const content = 'Arquivo temporário da regressão arquitetural.\n';
    form.set('arquivo_file[]', new Blob([content], { type: 'text/plain' }), filename);
    const upload = await fetch(base + '/admin/index.php', { method: 'POST', redirect: 'manual', headers: { Cookie: 'PHPSESSID=' + fixture.users.admin.session }, body: form });
    const uploaded = await upload.json();
    assert.equal(upload.status, 201, JSON.stringify(uploaded));
    if (process.argv.includes('--fail-after-upload')) throw new Error('Falha provocada após criar upload de fixture.');
    const file = JSON.parse(execFileSync(php, [path.join(__dirname, 'test_admin_architecture.php'), '--file', manifestPath, String(uploaded.documents[0].id)], { cwd: root, encoding: 'utf8', windowsHide: true }));
    const stored = path.resolve(root, file.file_path);
    assert(stored.startsWith(path.join(root, 'storage', 'documents') + path.sep));
    assert.equal(file.original_filename, filename);
    try { assert.equal(fs.readFileSync(stored, 'utf8'), content); }
    finally { fs.unlinkSync(stored); }
    console.log('[OK] Upload em lote manteve o destino físico e o conteúdo; arquivo de fixture removido.');
}

async function runBrowser(base) {
    const { chromium } = require(process.env.DOCGOV_PLAYWRIGHT_PACKAGE || 'playwright');
    const browser = await chromium.launch({ headless: true, executablePath: process.env.DOCGOV_BROWSER_PATH || 'C:/Program Files/Google/Chrome/Application/chrome.exe' });
    try {
        for (const [width, height, dark] of [[1440, 900, false], [390, 844, true]]) {
            const context = await browser.newContext({ viewport: { width, height } });
            await context.addCookies([{ name: 'PHPSESSID', value: fixture.users.admin.session, url: base }]);
            await context.addInitScript(isDark => localStorage.setItem('theme', isDark ? 'dark' : 'light'), dark);
            const page = await context.newPage();
            const errors = [];
            page.on('pageerror', e => errors.push(e.message));
            page.on('response', r => { if (r.url().startsWith(base + '/assets/') && r.status() >= 400) errors.push('Asset: ' + r.url()); });
            await page.goto(base + '/admin/index.php?tab=documentos&filter_status=all', { waitUntil: 'networkidle' });
            await page.locator('.batch-checkbox').first().check();
            assert((await page.locator('#batch-selection-count').innerText()).includes('1 documento selecionado'));
            assert.equal(await page.evaluate(() => typeof toggleActionMenu), 'function');
            const menu = page.locator('[data-action-menu-trigger]').first();
            await menu.click();
            assert.equal(await menu.getAttribute('aria-expanded'), 'true');
            await page.keyboard.press('Escape');
            assert.equal(await menu.getAttribute('aria-expanded'), 'false');
            await page.locator('#filter-cat').selectOption({ label: 'Categoria Arquitetura' });
            await page.locator('#filter-subcat').selectOption({ label: 'Subcategoria Arquitetura' });
            assert((await page.locator('#filter-assunto option').allTextContents()).includes('Assunto Arquitetura'));
            await page.goto(base + '/admin/index.php?tab=novo_documento&action=edit_doc&id=' + fixture.documentIds.draft, { waitUntil: 'networkidle' });
            const config = await page.evaluate(() => ({ config: window.DocGovAdminConfig, injected: window.unexpectedCode === true }));
            assert(config.config, 'Configuração do painel ausente');
            assert.equal(config.injected, false);
            assert(config.config.tags.catalog.some(t => t.name === fixture.tagName));
            assert.equal(await page.locator('#docgov-admin-config').count(), 1);
            const expected = ['navigation', 'document-list', 'structure', 'document-editor'];
            for (const file of expected) assert.equal(await page.locator('script[src*="/assets/admin/' + file + '.js"]').count(), 1);
            const types = await page.evaluate(() => ['toggleSubmenu', 'toggleMobileSidebar', 'toggleMainSidebar', 'openDocumentRow', 'closeActionMenus', 'toggleActionMenu', 'toggleSelectAll', 'updateBatchToolbar', 'onFilterCategoryChange', 'onFilterSubcategoryChange', 'setDocumentHierarchyHelper', 'onCategoryChange', 'onSubcategoryChange', 'toggleFormContent', 'updateCodePreview', 'toggleVideoSource', 'updateVideoFilePreview', 'getVideoEmbedUrl', 'updateVideoUrlPreview', 'updateFilePreview', 'initDocumentTags', 'initBatchFileUpload', 'setEditorMode', 'markEditorDirty', 'saveEditorLayoutState', 'ncSwitchType', 'ncLoadSubcatsForAssunto', 'toggleTreeNode', 'expandAllTreeNodes', 'collapseAllTreeNodes', 'filterTreeNodes'].map(name => typeof window[name]));
            assert(types.every(t => t === 'function'));
            await page.locator('#document-tag-input').fill('Tag de interface');
            await page.locator('#document-tag-add').click();
            assert.equal(await page.locator('input[name="new_tags[]"]').count(), 1, 'Listener de tag duplicado');
            const hierarchy = await page.evaluate(() => {
                // Nomes repetidos não podem substituir a seleção por ID.
                const select = document.getElementById('select-cat');
                select.add(new Option('Categoria Arquitetura', '99999'));
                window.DocGovAdminConfig.hierarchy.documentSubcategories['99999'] = [{ id: 99998, nome: 'Subcategoria Arquitetura' }];
                window.DocGovAdminConfig.hierarchy.documentSubjects['99998'] = [{ id: 99997, nome: 'Assunto Arquitetura' }];
                select.value = '99999'; onCategoryChange();
                document.getElementById('select-subcat').value = '99998'; onSubcategoryChange();
                return document.getElementById('select-assunto').options[1].value;
            });
            assert.equal(hierarchy, '99997');
            await page.evaluate(() => {
                document.getElementById('code-source-input').value = 'print("ação")';
                document.querySelector('input[name="tipo_conteudo"][value="code"]').checked = true;
                toggleFormContent('code'); updateCodePreview();
            });
            assert((await page.locator('#code-preview').innerText()).includes('ação'));
            await page.locator('#file-input').setInputFiles({ name: 'preview.txt', mimeType: 'text/plain', buffer: Buffer.from('preview') });
            assert((await page.locator('#file-preview-name').textContent()).includes('preview.txt'));
            const video = await page.evaluate(() => getVideoEmbedUrl('https://www.youtube.com/watch?v=dQw4w9WgXcQ'));
            assert(video);
            await page.evaluate(() => { ncSwitchType('assunto'); ncSwitchType('documento'); setEditorMode('preview'); setEditorMode('edit'); });
            for (const setup of ['subject', 'document']) {
                await page.goto(base + '/admin/index.php?tab=novo_documento&setup=' + setup + '&cat_id=' + fixture.categoryId + '&subcat_id=' + fixture.subcategoryId + '&subject_id=' + fixture.subjectId, { waitUntil: 'networkidle' });
                assert(await page.locator('#nc-panel-' + (setup === 'subject' ? 'assunto' : 'documento')).isVisible());
            }
            await page.goto(base + '/admin/index.php?tab=editar_estrutura', { waitUntil: 'networkidle' });
            await page.evaluate(() => { expandAllTreeNodes(); collapseAllTreeNodes(); filterTreeNodes('Arquitetura'); filterTreeNodes(''); });
            await page.goto(base + '/admin/index.php?tab=editar_grupo&id=' + fixture.groupId + '&group_tab=users', { waitUntil: 'networkidle' });
            if (await page.locator('[data-team-member-option]').count()) {
                await page.evaluate(() => filterTeamMemberOptions('architecture'));
                await page.locator('[data-team-member-option]').first().click();
                assert(await page.locator('#team-member-user-id').inputValue());
            }
            assert.deepEqual(errors, [], 'Erros de navegador');
            await context.close();
        }
        console.log('[OK] Navegador em 1440×900/claro e 390×844/escuro.');
    } finally {
        await browser.close();
    }
}

(async () => {
    let server;
    try {
        const listener = net.createServer();
        await new Promise(resolve => listener.listen(0, '127.0.0.1', resolve));
        const port = listener.address().port;
        await new Promise(resolve => listener.close(resolve));
        let stderr = '';
        server = spawn(php, ['-d', 'session.save_path="' + fixture.sessionDirectory.replace(/\\/g, '/') + '"', '-d', 'display_errors=1', '-S', '127.0.0.1:' + port, '-t', root], { cwd: root, windowsHide: true, stdio: ['ignore', 'ignore', 'pipe'] });
        server.stderr.on('data', data => { stderr += data.toString(); });
        const base = 'http://127.0.0.1:' + port;
        const deadline = Date.now() + 10000;
        for (;;) {
            try { await (await fetch(base + '/login.php')).arrayBuffer(); break; }
            catch (error) { if (Date.now() > deadline) throw new Error('Servidor indisponível: ' + stderr); await new Promise(resolve => setTimeout(resolve, 100)); }
        }
        if (process.argv.includes('--fail-node')) throw new Error('Falha provocada para verificar a limpeza.');
        await runHTTP(base);
        if (process.argv.includes('--browser')) await runBrowser(base);
    } finally {
        if (server && server.exitCode === null) {
            const exited = new Promise(resolve => server.once('exit', resolve));
            server.kill();
            await exited;
        }
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
