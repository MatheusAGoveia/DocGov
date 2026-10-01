// Executado pela suíte PHP contra a mesma base temporária, em um servidor próprio.
const { chromium } = require(process.env.DOCGOV_PLAYWRIGHT_PACKAGE || 'playwright');
const { spawn, execFileSync } = require('node:child_process');
const net = require('node:net');
const path = require('node:path');
const root = path.resolve(__dirname, '..');
if (!(process.env.DB_NAME || '').startsWith('docgov_nesting_test_')) throw new Error('Exige base temporária da suíte.');
const php = process.env.DOCGOV_PHP || 'php';
const fixtureScript = path.join(__dirname, 'nested_groups_browser_fixture.php');
const fixture = JSON.parse(execFileSync(php, [fixtureScript], { cwd: root, encoding: 'utf8' }));
const assert = (condition, message) => { if (!condition) throw new Error(message); };

(async () => {
  const port = await new Promise((resolve, reject) => {
    const socket = net.createServer(); socket.on('error', reject);
    socket.listen(0, '127.0.0.1', () => { const number = socket.address().port; socket.close(() => resolve(number)); });
  });
  const base = `http://127.0.0.1:${port}`;
  const server = spawn(php, ['-S', `127.0.0.1:${port}`, '-t', root], { cwd: root, windowsHide: true, stdio: ['ignore', 'pipe', 'pipe'] });
  let serverErrors = '';
  server.stdout.on('data', () => {}); server.stderr.on('data', chunk => { serverErrors += chunk; });
  let browser;
  try {
    for (let i = 0; i < 50; i++) {
      try {
        await new Promise((resolve, reject) => { const probe = net.connect(port, '127.0.0.1'); probe.once('connect', () => { probe.destroy(); resolve(); }); probe.once('error', reject); });
        break;
      } catch { await new Promise(resolve => setTimeout(resolve, 100)); }
    }
    browser = await chromium.launch({ headless: true, executablePath: process.env.DOCGOV_BROWSER_PATH || 'C:/Program Files/Google/Chrome/Application/chrome.exe' });
    const context = await browser.newContext({ viewport: { width: 1440, height: 900 } });
    await context.addCookies([{ name: 'PHPSESSID', value: fixture.session, url: base }]);
    const page = await context.newPage(); const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    const route = `/admin/index.php?tab=editar_grupo&id=${fixture.parent}&group_tab=groups`;
    const response = await page.goto(base + route, { waitUntil: 'load' });
    assert(response.status() === 200 && await page.locator('[data-group-nesting]').isVisible(), 'Tela de subgrupos não carregou: ' + response.status() + ' ' + page.url() + ' ' + (await page.locator('body').innerText()).slice(0, 350));
    await page.getByLabel('Equipe a incluir').selectOption(String(fixture.child));
    await page.getByRole('button', { name: 'Incluir subgrupo', exact: true }).click();
    await page.waitForURL('**/*msg=team_subgroup_added');
    assert(await page.locator(`[data-child-group="${fixture.child}"]`).isVisible(), 'Inclusão não apareceu na tela');
    assert(await page.locator('[data-group-nesting]').innerText().then(text => text.includes('Membros ativos via subgrupos (1)') && text.includes('Membro Folha')), 'Membro indireto ausente');
    const search = await page.request.get(base + `/api/search_principals.php?type=group&resource_type=category&resource_id=${fixture.category}&q=Browser Financeiro`);
    const payload = await search.json();
    assert(payload.success && payload.data[0].subtext.includes('1 membro ativo'), 'Pesquisa de permissões não conta membros indiretos');
    await page.goto(base + `/admin/index.php?tab=editar_usuario&id=${fixture.member}&user_tab=teams`, { waitUntil: 'load' });
    assert((await page.locator('body').innerText()).includes('Via subgrupos: Browser Contas a Pagar → Browser Financeiro'), 'Tela do usuário não explica herança');
    for (const [label, width, dark] of [['desktop-light', 1440, false], ['mobile-light', 390, false], ['mobile-dark', 390, true]]) {
      await page.setViewportSize({ width, height: 900 });
      await page.goto(base + route, { waitUntil: 'load' });
      await page.evaluate(value => { localStorage.setItem('theme', value ? 'dark' : 'light'); document.documentElement.classList.toggle('dark', value); document.documentElement.classList.toggle('light', !value); }, dark);
      const section = page.locator('[data-group-nesting]');
      assert(await section.isVisible(), label + ': conteúdo ausente');
      const box = await section.boundingBox();
      assert(box.x >= 0 && box.x + box.width <= width + 1, label + ': painel ultrapassa a tela');
      if (dark) await page.waitForFunction(() => getComputedStyle(document.querySelector('[data-group-nesting]')).backgroundColor === 'rgb(32, 36, 43)');
      await page.screenshot({ path: path.join(root, 'tmp', `nested-groups-${label}.png`), fullPage: true });
      console.log('PASS interface ' + label);
    }
    await page.goto(base + route, { waitUntil: 'load' });
    await page.locator(`[data-child-group="${fixture.child}"]`).getByRole('button', { name: 'Remover vínculo' }).click();
    const confirm = page.locator('.dg-dialog__confirm');
    await confirm.waitFor({ state: 'visible' }); await confirm.click();
    await page.waitForURL('**/*msg=team_subgroup_removed');
    assert(await page.locator(`[data-child-group="${fixture.child}"]`).count() === 0, 'Remoção não atualizou a tela');
    assert((await page.locator('[data-group-nesting]').innerText()).includes('Membros ativos via subgrupos (0)'), 'Remoção não atualizou membros indiretos');
    await page.goto(base + '/admin/index.php?tab=grupos', { waitUntil: 'load' });
    assert(await page.getByRole('columnheader', { name: 'Subgrupos' }).isVisible(), 'Listagem sem coluna de subgrupos');
    assert(errors.length === 0 && !/Fatal error|Uncaught|PHP Warning/.test(serverErrors), 'Erros de execução: ' + errors.join('; ') + serverErrors);
    console.log('PASS inclusão, pesquisa, diagnóstico, confirmação e remoção no navegador');
  } finally {
    if (browser) await browser.close();
    server.kill();
    await new Promise(resolve => { if (server.exitCode !== null) resolve(); else server.once('exit', resolve); });
    execFileSync(php, [fixtureScript, 'cleanup', fixture.session], { cwd: root, stdio: 'ignore' });
  }
})().catch(error => { console.error(error); process.exitCode = 1; });
