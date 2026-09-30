const { chromium } = require(process.env.DOCGOV_PLAYWRIGHT_PACKAGE || 'playwright');
const { execFileSync } = require('node:child_process');
const path = require('node:path');
const root = path.resolve(__dirname, '..');
const php = process.env.DOCGOV_PHP || 'php';
const helper = path.join(__dirname, 'test_ad_service_account.php');
const run = (...args) => execFileSync(php, [helper, ...args], { cwd: root, encoding: 'utf8' }).trim();
const assert = (value, message) => { if (!value) throw new Error(message); };
const base = process.env.DOCGOV_TEST_BASE_URL || 'http://127.0.0.1:8000';
const fixture = JSON.parse(run('--prepare'));
const account = fixture.key + '@example.invalid';
const password = '  Test*' + fixture.token + '  ';

(async () => {
  let browser;
  try {
    browser = await chromium.launch({ headless: true, executablePath: process.env.DOCGOV_BROWSER_PATH || 'C:/Program Files/Google/Chrome/Application/chrome.exe' });
    const contexts = {};
    for (const role of ['global', 'blocked']) {
      contexts[role] = await browser.newContext({ viewport: { width: 1440, height: 900 } });
      await contexts[role].addCookies([{ name: 'PHPSESSID', value: fixture.sessions[role].id, url: base }]);
    }
    const page = await contexts.global.newPage();
    const errors = []; page.on('pageerror', error => errors.push(error.message));
    const route = '/admin/index.php?tab=servidores_ad&domain=' + fixture.key;
    await page.goto(base + route, { waitUntil: 'load' });
    const form = page.locator('#form-domain-servers');
    const input = page.locator('#ad-service-bind-dn');
    const secret = page.locator('#ad-service-bind-password');
    assert(await input.count() === 1 && await secret.count() === 1, 'Conta técnica não possui um único par de campos por domínio');
    await input.fill(account); await secret.fill(password);
    await Promise.all([page.waitForURL(url => url.searchParams.get('msg') === 'ad_saved'), form.getByRole('button', { name: 'Salvar Configurações do Domínio' }).click()]);
    assert(new URL(page.url()).searchParams.get('domain') === fixture.key, 'Salvar perdeu o domínio selecionado');
    let state = JSON.parse(run('--inspect', fixture.key));
    assert(state.account === account && state.password_matches, 'O formulário não salvou conta e senha exatamente como informadas');
    assert(JSON.stringify(state.globals) === JSON.stringify(fixture.snapshot.globals) && state.other_domains_hash === fixture.snapshot.other_domains_hash, 'Salvar um domínio alterou configurações globais ou outro domínio');
    assert(await secret.inputValue() === '' && !(await page.content()).includes(password), 'A senha salva foi exposta no HTML');
    assert((await page.locator('#ad-service-account-status').innerText()).includes('Conta configurada'), 'Estado de configuração não foi exibido');
    await Promise.all([page.waitForURL(url => url.searchParams.get('msg') === 'ad_saved'), form.getByRole('button', { name: 'Salvar Configurações do Domínio' }).click()]);
    state = JSON.parse(run('--inspect', fixture.key));
    assert(state.password_matches, 'Salvar sem redigitar a senha apagou a credencial');
    console.log('PASS formulário AD: conta salva, senha preservada e ocultada, domínio selecionado e configurações independentes');

    const fields = { save_ad_settings: '1', ad_settings_scope: 'domain', ad_edit_domain: fixture.key, csrf_token: fixture.sessions.global.csrf, [`ad_domains[${fixture.key}][key]`]: fixture.key, [`ad_domains[${fixture.key}][service_bind_dn]`]: 'outra.conta@example.invalid', [`ad_domains[${fixture.key}][service_bind_password]`]: '' };
    await contexts.global.request.post(base + route, { form: fields, maxRedirects: 0 });
    state = JSON.parse(run('--inspect', fixture.key));
    assert(state.account === account && state.password_matches, 'Trocar conta sem nova senha modificou a configuração');
    const denied = await contexts.blocked.request.post(base + route, { form: { ...fields, csrf_token: fixture.sessions.blocked.csrf } });
    assert(denied.status() === 403, 'Editor de conteúdo ganhou permissão para configurar AD');
    const noCsrf = await contexts.global.request.post(base + route, { form: { ...fields, csrf_token: '' } });
    assert(noCsrf.status() === 419, 'Configuração de conta aceita sem CSRF');
    const testFields = { test_ad_connection: '1', test_domain_key: fixture.key, test_uri: 'ldap://127.0.0.1:2', test_bind_dn: account, test_bind_pass: '', test_base_dn: 'DC=test,DC=invalid', csrf_token: fixture.sessions.global.csrf };
    const foreignHost = await contexts.global.request.post(base + route, { form: testFields });
    assert(foreignHost.status() === 422 && !(await foreignHost.json()).success, 'Senha salva foi reutilizada em servidor não cadastrado');
    const blockedTest = await contexts.blocked.request.post(base + route, { form: { ...testFields, csrf_token: fixture.sessions.blocked.csrf } });
    assert(blockedTest.status() === 403, 'Teste de conta ignorou autorização');
    const testNoCsrf = await contexts.global.request.post(base + route, { form: { ...testFields, csrf_token: '' } });
    assert(testNoCsrf.status() === 419, 'Teste de conta ignorou CSRF');
    console.log('PASS conta AD: alteração exige nova senha, autorização, CSRF e proteção da senha salva');

    await page.goto(base + route, { waitUntil: 'load' });
    const responsePromise = page.waitForResponse(response => response.request().method() === 'POST' && response.url().includes('tab=servidores_ad'));
    await page.getByRole('button', { name: 'Autenticar & Testar Este Servidor' }).click();
    const response = await responsePromise;
    const payload = response.request().postData() || '';
    assert(payload.includes('test_domain_key') && payload.includes(fixture.key), 'Teste não informou o domínio da conta');
    const result = await response.json();
    assert(!result.success && result.error.includes('inacessível'), 'Teste não reutilizou a senha salva para chegar à validação de rede');
    assert(!JSON.stringify(result).includes(password), 'Resposta do teste expôs a senha');
    for (const width of [390, 1440]) {
      await page.setViewportSize({ width, height: 900 });
      assert(await input.isVisible() && await secret.isVisible(), `${width}px: controles da conta ocultos`);
      await page.waitForFunction(() => document.documentElement.scrollWidth <= innerWidth + 2);
    }
    assert(errors.length === 0, 'Erros JavaScript: ' + errors.join(' | '));
    console.log('PASS teste de servidor com conta salva e layout móvel/desktop');
  } finally {
    if (browser) await browser.close();
    for (const session of Object.values(fixture.sessions)) execFileSync(php, ['-r', `session_id('${session.id}'); session_start(); session_destroy();`], { cwd: root });
    run('--cleanup', fixture.key);
  }
})().catch(error => { console.error(error); process.exitCode = 1; });
