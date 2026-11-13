// HTTP e cookies reais; somente banco temporário e LDAP simulado pelo teste pai.
const { spawn, execFileSync } = require('node:child_process');
const net = require('node:net');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const root = path.resolve(__dirname, '..');
const php = process.env.DOCGOV_PHP || 'php';
const ldapPort = Number(process.argv[2]);
const assert = (condition, message) => { if (!condition) throw new Error(message); };
assert(Number.isInteger(ldapPort) && ldapPort > 1023 && ldapPort < 65536, 'Porta LDAP inválida.');
const sessionDir = fs.mkdtempSync(path.join(os.tmpdir(), 'docgov-login-test-'));
const sessionOption = 'session.save_path="' + sessionDir.replace(/\\/g, '/') + '"';
const fixture = (...args) => execFileSync(php, ['-d', sessionOption, path.join(__dirname, 'login_domain_flow_fixture.php'), ...args], { cwd: root, encoding: 'utf8' }).trim();
const password = domain => '  Mock*' + domain + '  ';

(async () => {
    let server;
    try {
        fixture('prepare', String(ldapPort));
        const listener = net.createServer();
        await new Promise(resolve => listener.listen(0, '127.0.0.1', resolve));
        const port = listener.address().port;
        await new Promise(resolve => listener.close(resolve));
        let stderr = '';
        server = spawn(php, ['-d', sessionOption, '-S', '127.0.0.1:' + port, '-t', root], { cwd: root, windowsHide: true, stdio: ['ignore', 'ignore', 'pipe'] });
        server.stderr.on('data', data => { stderr += data.toString(); });
        const base = 'http://127.0.0.1:' + port;
        const deadline = Date.now() + 10000;
        for (;;) {
            try { await (await fetch(base + '/login.php')).arrayBuffer(); break; }
            catch (error) { if (Date.now() >= deadline) throw new Error('Servidor de teste indisponível: ' + stderr); await new Promise(resolve => setTimeout(resolve, 100)); }
        }
        const submit = async (domain, suppliedPassword, state = 'fresh', username = 'form.session') => {
            const oldId = fixture('session', state);
            const response = await fetch(base + '/login.php', { method: 'POST', redirect: 'manual',
                headers: { Cookie: 'PHPSESSID=' + oldId },
                body: new URLSearchParams({ ad_domain: domain, email: username, senha: suppliedPassword }) });
            const setCookie = response.headers.getSetCookie().find(value => value.startsWith('PHPSESSID='));
            const newId = setCookie ? setCookie.split(';')[0].slice('PHPSESSID='.length) : oldId;
            const html = await response.text();
            return { response, html, id: newId, oldId };
        };
        for (const state of ['fresh', 'stale']) {
            for (const domain of ['BETIM', 'SAUDE']) {
                const login = await submit(domain, password(domain), state);
                assert(login.response.status === 302 && login.response.headers.get('location') === 'index.php', state + '/' + domain + ': primeiro POST não concluiu o login: ' + login.html);
                const portal = await fetch(base + '/index.php', { redirect: 'manual', headers: { Cookie: 'PHPSESSID=' + login.id } });
                await portal.arrayBuffer();
                assert(portal.status === 200, state + '/' + domain + ': login válido voltou à tela de entrada no primeiro redirecionamento: ' + portal.headers.get('location'));
                const session = JSON.parse(fixture('inspect', login.id));
                assert(session.domain === domain && session.login === 'form.session' && session.last_activity_recent && !session.expired && login.id !== login.oldId, state + '/' + domain + ': sessão não renovada corretamente: ' + JSON.stringify(session));
                console.log('PASS primeiro envio: ' + domain + ', sessão ' + state);
            }
        }
        const wrong = await submit('SAUDE', password('BETIM'));
        assert(wrong.response.status === 200, 'Senha de BETIM foi aceita no domínio SAUDE.');
        const wrongHtml = wrong.html;
        assert(wrongHtml.includes('Usuário ou senha do Active Directory inválidos.') && /value="SAUDE" selected/.test(wrongHtml), 'Falha de senha perdeu a seleção SAUDE ou a mensagem correta.');
        const recovery = await submit('SAUDE', password('SAUDE'));
        assert(recovery.response.status === 302, 'Senha correta não entrou após uma senha incorreta.');
        for (const [domain, username] of [['BETIM', 'BETIM\\form.session'], ['SAUDE', 'form.session@saude.example.invalid']]) {
            const qualified = await submit(domain, password(domain), 'fresh', username);
            assert(qualified.response.status === 302, 'Login qualificado do mesmo domínio foi duplicado ou rejeitado: ' + domain);
        }
        for (const [domain, username] of [['SAUDE', 'BETIM\\form.session'], ['OUTRO', 'form.session'], ['', 'form.session']]) {
            const invalid = await submit(domain, password('BETIM'), 'fresh', username);
            assert(invalid.response.status === 200 && !JSON.parse(fixture('inspect', invalid.id)).domain, 'Domínio inválido ou divergente autenticou: ' + domain);
        }
        console.log('PASS seleção exclusiva: senha do outro domínio recusada, recuperação, login qualificado, domínio divergente/desconhecido/vazio.');
        const adminId = fixture('session', 'admin');
        const adminCookie = { Cookie: 'PHPSESSID=' + adminId };
        const healthUrl = base + '/admin/index.php?tab=servidores_ad&subtab=health';
        const healthPage = await fetch(healthUrl, { headers: adminCookie });
        const healthHtml = await healthPage.text();
        const csrf = healthHtml.match(/id="ad-csrf-token" value="([a-f0-9]+)"/);
        assert(healthPage.status === 200 && csrf, 'Painel de saúde não foi renderizado para o administrador de teste.');
        for (const manual of [false, true]) {
            const fields = { test_ad_health: '1', csrf_token: csrf[1] };
            if (manual) fields.validate_ad_accounts = '1';
            const result = await (await fetch(healthUrl, { method: 'POST', headers: adminCookie, body: new URLSearchParams(fields) })).json();
            assert(result.success && result.health.length === 2 && result.health.every(server => server.online), 'Painel mostrou servidor acessível como indisponível.');
            const betim = result.health.find(server => server.domain === 'BETIM');
            const saude = result.health.find(server => server.domain === 'SAUDE');
            assert(manual ? betim.status === 'healthy' && betim.directory_readable && saude.status === 'authentication_error'
                : betim.status === 'available' && saude.status === 'available' && betim.authenticated === null && saude.authenticated === null,
            'Painel misturou conectividade, autenticação e consulta ao diretório.');
        }
        console.log('PASS endpoint de saúde: atualização automática sem credenciais; validação manual diferencia conta rejeitada de servidor offline.');
        if (process.env.DOCGOV_PLAYWRIGHT_PACKAGE) {
            const { chromium } = require(process.env.DOCGOV_PLAYWRIGHT_PACKAGE);
            const browser = await chromium.launch({ headless: true, executablePath: process.env.DOCGOV_BROWSER_PATH || 'C:/Program Files/Google/Chrome/Application/chrome.exe' });
            try {
                const context = await browser.newContext({ viewport: { width: 1440, height: 900 } });
                await context.addCookies([{ name: 'PHPSESSID', value: adminId, url: base }]);
                const page = await context.newPage();
                const errors = [];
                page.on('pageerror', error => errors.push(error.message));
                await page.route('**/*', route => route.request().url().startsWith(base) || route.request().url().startsWith('https://cdn.tailwindcss.com') ? route.continue() : route.abort());
                await page.goto(healthUrl, { waitUntil: 'domcontentloaded' });
                await page.waitForFunction(() => document.querySelector('#health-check-results-grid')?.textContent.includes('Conexão ativa'));
                await page.getByRole('button', { name: 'Atualizar Saúde Agora' }).click();
                await page.waitForFunction(() => document.querySelector('#health-check-results-grid')?.textContent.includes('Conta técnica rejeitada'));
                assert((await page.locator('#kpi-online-count').innerText()) === '2/2 On', 'Interface contou falha de credencial como servidor offline.');
                assert((await page.locator('#health-check-results-grid').textContent()).includes('Operacional'), 'Servidor com conta válida não apareceu operacional.');
                await page.setViewportSize({ width: 390, height: 844 });
                assert(await page.locator('#btn-trigger-health-check').isVisible(), 'Controle de verificação oculto em celular.');
                assert(errors.length === 0, 'Erros JavaScript: ' + errors.join(' | '));
                console.log('PASS navegador: estados operacionais e conta rejeitada, KPI 2/2, celular e JavaScript sem erros.');
            } finally { await browser.close(); }
        }
        assert(!/PHP (?:Fatal error|Warning)/.test(stderr), 'Erro PHP no fluxo HTTP: ' + stderr);
    } finally {
        if (server && server.exitCode === null) {
            const ended = new Promise(resolve => server.once('exit', resolve));
            server.kill(); await ended;
        }
        for (const file of fs.readdirSync(sessionDir)) {
            assert(/^sess_[a-zA-Z0-9,-]+$/.test(file), 'Arquivo inesperado na pasta exclusiva de sessões.');
            fs.unlinkSync(path.join(sessionDir, file));
        }
        fs.rmdirSync(sessionDir);
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
