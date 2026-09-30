const { chromium } = require(process.env.DOCGOV_PLAYWRIGHT_PACKAGE || 'playwright');
const { execFileSync } = require('node:child_process');
const path = require('node:path');
const root = path.resolve(__dirname, '..');
const php = process.env.DOCGOV_PHP || 'php';
const base = process.env.DOCGOV_TEST_BASE_URL || 'http://127.0.0.1:8000';
const chrome = process.env.DOCGOV_BROWSER_PATH || 'C:/Program Files/Google/Chrome/Application/chrome.exe';
const helper = path.join(__dirname, 'test_subject_visibility.php');
const runHelper = (...args) => execFileSync(php, [helper, ...args], { cwd: root, encoding: 'utf8' }).trim();
const assert = (value, message) => { if (!value) throw new Error(message); };
const fixture = JSON.parse(runHelper('--prepare'));

(async () => {
  let browser;
  try {
    browser = await chromium.launch({ headless: true, executablePath: chrome });
    const contexts = {};
    for (const role of ['global', 'reader', 'editor', 'manager', 'outsider']) {
      contexts[role] = await browser.newContext({ viewport: { width: 1440, height: 900 } });
      await contexts[role].addCookies([{ name: 'PHPSESSID', value: fixture.sessions[role].id, url: base }]);
    }
    const reader = contexts.reader;
    const expectStatus = async (context, route, status) => {
      const response = await context.request.get(base + route, { maxRedirects: 0 });
      assert(response.status() === status, `${route}: esperado ${status}, recebido ${response.status()}`);
      return response;
    };
    const json = async (context, route) => (await expectStatus(context, route, 200)).json();
    const page = await reader.newPage();
    const errors = []; page.on('pageerror', error => errors.push(error.message));
    const categoryRoute = `/index.php?cat=${fixture.category}`;
    const publicRoute = categoryRoute + `&subcat=${fixture.subcategories.public}`;
    await page.goto(base + categoryRoute, { waitUntil: 'load' });
    assert(await page.getByRole('link', { name: /Abrir subcategoria Orientações gerais teste/ }).count() === 1, 'Categoria pai não exibiu ramo público');
    assert(await page.getByRole('link', { name: /Abrir subcategoria Privada teste/ }).count() === 0, 'Irmão privado apareceu no portal');
    await page.goto(base + publicRoute, { waitUntil: 'load' });
    assert((await page.locator('main').innerText()).includes('Assunto public teste') && (await page.locator('main').innerText()).includes('Público'), 'Assunto público não abriu ou não exibiu visibilidade');
    assert(await page.getByRole('link', { name: /Abrir assunto Assunto sibling teste/ }).count() === 0, 'Assunto privado irmão apareceu no portal');
    await page.goto(base + publicRoute + `&assunto=${fixture.subjects.public}`, { waitUntil: 'load' });
    const subjectText = await page.locator('main').innerText();
    assert(subjectText.includes('Documento public teste') && !subjectText.includes('Documento draft teste') && !subjectText.includes('Documento review teste'), 'Listagem publicou conteúdo pendente');
    await expectStatus(reader, `/ver_conteudo.php?id=${fixture.documents.public}`, 200);
    for (const key of ['private', 'sibling', 'draft', 'review']) await expectStatus(reader, `/ver_conteudo.php?id=${fixture.documents[key]}`, 403);
    for (const route of ['/document-file.php', '/download.php']) {
      await expectStatus(reader, route + `?id=${fixture.documents.file}`, 200);
      await expectStatus(reader, route + `?id=${fixture.documents.private_file}`, 403);
    }
    await expectStatus(reader, `/category_image.php?id=${fixture.category}`, 200);
    await expectStatus(reader, `/subcategory_image.php?id=${fixture.subcategories.public}`, 200);
    await expectStatus(reader, `/subcategory_image.php?id=${fixture.subcategories.private}`, 403);
    const categories = await json(reader, '/api/categories.php');
    assert(categories.data.some(item => item.id === fixture.category), 'API omitiu categoria navegável');
    const subcategories = await json(reader, `/api/subcategories.php?category_id=${fixture.category}`);
    assert(subcategories.data.length === 1 && subcategories.data[0].id === fixture.subcategories.public, 'API expôs irmão privado ou omitiu visibilidade');
    const subjects = await json(reader, `/api/subjects.php?subcategory_id=${fixture.subcategories.public}`);
    assert(subjects.data.length === 1 && subjects.data[0].id === fixture.subjects.public && subjects.data[0].visibility === 'public', 'API expôs assunto privado irmão ou omitiu visibilidade');
    assert((await json(reader, `/api/subjects.php?subcategory_id=${fixture.subcategories.private}`)).data.length === 0, 'API expôs assunto privado');
    assert((await json(reader, '/api/documents.php?status=all')).data.length === 0, 'API administrativa foi liberada para leitor');
    const tree = await json(reader, '/api/tree.php');
    const node = tree.data.find(item => item.id === fixture.category);
    assert(node?.subcategories.length === 1 && node.subcategories[0].subjects.length === 1 && node.subcategories[0].subjects[0].id === fixture.subjects.public && node.subcategories[0].subjects[0].visibility === 'public', 'Árvore expôs irmãos privados');
    await page.goto(base + `/index.php?q=${fixture.token}`, { waitUntil: 'load' });
    const searchText = await page.locator('main').innerText();
    assert(searchText.includes('Documento public teste') && !searchText.includes('Documento private teste') && !searchText.includes('Documento draft teste'), 'Busca expôs conteúdo restrito');
    const favorite = await reader.request.post(base + '/api_user.php', { form: { action: 'toggle_favorito', target_type: 'document', target_id: fixture.documents.public, csrf_token: fixture.sessions.reader.csrf } });
    assert((await favorite.json()).success, 'Leitor não conseguiu favoritar conteúdo público');
    await page.goto(base + '/favoritos.php', { waitUntil: 'load' });
    assert((await page.locator('main').innerText()).includes('Documento public teste'), 'Favorito público não apareceu');
    console.log('PASS portal, busca, favoritos, imagens, downloads e APIs: leitura pública sem vazamento privado');

    const guest = await browser.newContext();
    await expectStatus(guest, `/ver_conteudo.php?id=${fixture.documents.public}`, 403);
    await expectStatus(guest, `/download.php?id=${fixture.documents.file}`, 403);
    await expectStatus(guest, '/api/subcategories.php', 401);
    await guest.close();
    const adminPage = await contexts.global.newPage();
    const editRoute = `/admin/index.php?tab=editar_estrutura&type=assunto&id=${fixture.subjects.public}&res_tab=overview`;
    await adminPage.goto(base + editRoute, { waitUntil: 'load' });
    const visibility = adminPage.locator('#resource-subject-visibility');
    assert(await visibility.inputValue() === 'public' && await visibility.isEnabled(), 'Administrador não pode configurar visibilidade');
    const editorPage = await contexts.editor.newPage();
    await editorPage.goto(base + editRoute, { waitUntil: 'load' });
    assert(await editorPage.locator('#resource-subject-visibility').isDisabled(), 'Editor recebeu controle de visibilidade');
    const editForm = { save_subject: '1', id: fixture.subjects.public, subcategory_id: fixture.subcategories.public, nome: 'Assunto public teste ' + fixture.token, descricao: 'Teste de visibilidade', status: 'ativo', visibility: 'private' };
    for (const role of ['editor', 'outsider']) {
      const response = await contexts[role].request.post(base + '/admin/index.php?tab=assuntos', { form: { ...editForm, csrf_token: fixture.sessions[role].csrf }, maxRedirects: 0 });
      assert(response.status() === 403, `${role}: backend permitiu mudar visibilidade`);
    }
    const invalid = await contexts.global.request.post(base + '/admin/index.php?tab=assuntos', { form: { ...editForm, visibility: 'invalid', csrf_token: fixture.sessions.global.csrf }, maxRedirects: 0 });
    assert(invalid.status() === 422, 'Backend aceitou visibilidade inválida');
    const editorMetadata = { ...editForm, csrf_token: fixture.sessions.editor.csrf }; delete editorMetadata.visibility;
    const edited = await contexts.editor.request.post(base + '/admin/index.php?tab=assuntos', { form: editorMetadata, maxRedirects: 0 });
    assert(edited.status() === 302, 'Editor perdeu capacidade de editar metadados');
    await expectStatus(reader, `/ver_conteudo.php?id=${fixture.documents.public}`, 200);
    await adminPage.goto(base + editRoute, { waitUntil: 'load' });
    await visibility.selectOption('private');
    await Promise.all([adminPage.waitForURL(url => url.searchParams.get('msg') === 'subject_saved'), visibility.locator('xpath=ancestor::form').getByRole('button', { name: 'Salvar identidade' }).click()]);
    assert(new URL(adminPage.url()).searchParams.get('tab') === 'editar_estrutura', 'Salvar não voltou ao editor do assunto');
    await expectStatus(reader, `/ver_conteudo.php?id=${fixture.documents.public}`, 403);
    await page.goto(base + '/favoritos.php', { waitUntil: 'load' });
    assert(!(await page.locator('main').innerText()).includes('Documento public teste'), 'Revogação manteve favorito visível');
    const manager = await contexts.manager.request.post(base + '/admin/index.php?tab=assuntos', { form: { ...editForm, visibility: 'public', csrf_token: fixture.sessions.manager.csrf }, maxRedirects: 0 });
    assert(manager.status() === 302, 'Administrador do recurso não conseguiu tornar pública');
    await expectStatus(reader, `/ver_conteudo.php?id=${fixture.documents.public}`, 200);
    console.log('PASS controles do painel, autorização de mudança, edição sem alteração da visibilidade e revogação imediata');

    for (const role of ['editor', 'reader']) {
      const response = await contexts[role].request.post(base + '/api/subjects.php', { form: { subcategory_id: fixture.subcategories.public, name: 'Publicação indevida ' + fixture.token, visibility: 'public', csrf_token: fixture.sessions[role].csrf } });
      assert(response.status() === 403, `${role}: API permitiu criar ramo público`);
    }
    const noCsrf = await contexts.manager.request.post(base + '/api/subjects.php', { form: { subcategory_id: fixture.subcategories.public, name: 'Teste sem CSRF', visibility: 'public' } });
    assert(noCsrf.status() === 419, 'API aceitou alteração sem CSRF');
    const created = await contexts.manager.request.post(base + '/api/subjects.php', { form: { subcategory_id: fixture.subcategories.public, name: 'Novo público teste ' + fixture.token, visibility: 'public', csrf_token: fixture.sessions.manager.csrf } });
    const createdData = await created.json();
    assert(createdData.success && createdData.visibility === 'public', 'Administrador do recurso não conseguiu criar ramo público pela API');
    const privateCreated = await contexts.editor.request.post(base + '/api/subjects.php', { form: { subcategory_id: fixture.subcategories.public, name: 'Novo privado teste ' + fixture.token, csrf_token: fixture.sessions.editor.csrf } });
    assert((await privateCreated.json()).visibility === 'private', 'Criação legada sem visibilidade deixou de ser privada');
    console.log('PASS API de criação: padrão privado, publicação por administrador e proteção CSRF');

    const invalidCreation = await contexts.manager.request.post(base + '/api/subjects.php', { form: { subcategory_id: fixture.subcategories.public, name: 'Visibilidade inválida', visibility: 'invalid', csrf_token: fixture.sessions.manager.csrf } });
    assert(invalidCreation.status() === 422, 'API aceitou visibilidade inválida');
    const managerPage = await contexts.manager.newPage();
    await managerPage.goto(base + '/admin/index.php?tab=assuntos', { waitUntil: 'load' });
    const createVisibility = managerPage.locator('#subject-visibility');
    const createForm = createVisibility.locator('xpath=ancestor::form');
    await createForm.locator('select[name="subcategory_id"]').selectOption(String(fixture.subcategories.public));
    assert(await createForm.evaluate(form => {
      const fields = [...form.querySelectorAll('input, select')].map(field => field.name);
      return fields.indexOf('subcategory_id') < fields.indexOf('visibility') && fields.indexOf('visibility') < fields.indexOf('nome');
    }), 'Formulário de assuntos não colocou visibilidade depois da subcategoria');
    assert(await createVisibility.isEnabled(), 'Administrador local perdeu controle na criação de assunto');
    await createVisibility.selectOption('public');
    const uiName = 'Assunto público criado pelo painel ' + fixture.token;
    await createForm.locator('input[name="nome"]').fill(uiName);
    await Promise.all([managerPage.waitForURL(url => url.searchParams.get('msg') === 'subject_saved'), createForm.getByRole('button', { name: 'Salvar Assunto' }).click()]);
    assert((await json(reader, '/api/subjects.php?subcategory_id=' + fixture.subcategories.public)).data.some(item => item.name === uiName && item.visibility === 'public'), 'Criação pelo painel não publicou o assunto');
    for (const [role, canPublish] of [['manager', true], ['editor', false]]) {
      const wizard = await contexts[role].newPage();
      await wizard.goto(base + '/admin/index.php?tab=novo_documento&setup=subject&cat_id=' + fixture.category + '&subcat_id=' + fixture.subcategories.public, { waitUntil: 'load' });
      const control = wizard.locator('#nc-subject-visibility');
      const form = control.locator('xpath=ancestor::form');
      await form.locator('select[name="_cat_aux"]').selectOption(String(fixture.category));
      await form.locator('select[name="subcategory_id"]').selectOption(String(fixture.subcategories.public));
      assert(await form.evaluate(form => {
        const fields = [...form.querySelectorAll('input, select')].map(field => field.name);
        return fields.indexOf('_cat_aux') < fields.indexOf('subcategory_id') && fields.indexOf('subcategory_id') < fields.indexOf('visibility') && fields.indexOf('visibility') < fields.indexOf('nome');
      }), 'Assistente não seguiu Categoria, Subcategoria, Visibilidade, Nome');
      assert(await control.isEnabled() === canPublish, role + ': controle do assistente não respeitou administração');
      await form.locator('select[name="_cat_aux"]').selectOption('');
      assert(await control.isDisabled() && await control.inputValue() === 'private', 'Trocar categoria manteve opção pública indevida');
      await wizard.close();
    }
    console.log('PASS formulários de criação e assistente: autorização por subcategoria e isolamento dos assuntos');

    for (const width of [390, 1440]) {
      await page.setViewportSize({ width, height: 900 });
      await page.goto(base + categoryRoute, { waitUntil: 'load' });
      await page.waitForFunction(() => document.documentElement.scrollWidth <= innerWidth + 2);
      await adminPage.setViewportSize({ width, height: 900 });
      await adminPage.goto(base + editRoute, { waitUntil: 'load' });
      assert(await visibility.isVisible(), `${width}px: controle de visibilidade oculto`);
      await adminPage.waitForFunction(() => document.documentElement.scrollWidth <= innerWidth + 2);
    }
    assert(errors.length === 0, 'Erros JavaScript: ' + errors.join(' | '));
    console.log('PASS layout móvel e desktop: portal e configuração de visibilidade');
  } finally {
    if (browser) await browser.close();
    for (const session of Object.values(fixture.sessions)) execFileSync(php, ['-r', `session_id('${session.id}'); session_start(); session_destroy();`], { cwd: root });
    runHelper('--cleanup', fixture.token);
  }
})().catch(error => { console.error(error); process.exitCode = 1; });
