const { chromium } = require(process.env.DOCGOV_PLAYWRIGHT_PACKAGE || 'playwright');
const { execFileSync } = require('node:child_process');
const path = require('node:path');

const root = path.resolve(__dirname, '..');
const base = process.env.DOCGOV_TEST_BASE_URL || 'http://127.0.0.1:8000';
const chrome = process.env.DOCGOV_BROWSER_PATH || 'C:/Program Files/Google/Chrome/Application/chrome.exe';
const runPhp = code => execFileSync('php', ['-r', code], { cwd: root, encoding: 'utf8' }).trim();
const assert = (condition, message) => { if (!condition) throw new Error(message); };

const session = runPhp(`
require 'config/db.php';
$user = $pdo->query("SELECT id, name, username, email, role, active FROM users WHERE role = 'reader' AND active = TRUE ORDER BY id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!$user) throw new RuntimeException('Leitor ativo indisponível.');
$sessionId = bin2hex(random_bytes(16));
session_id($sessionId); session_start();
$_SESSION['user'] = ['id' => (int)$user['id'], 'nome' => $user['name'], 'login' => $user['username'], 'email' => $user['email'], 'role' => $user['role'], 'active' => true, 'inicial' => mb_substr($user['name'], 0, 1)];
session_write_close(); echo $sessionId;
`);

(async () => {
  const browser = await chromium.launch({ headless: true, executablePath: chrome });
  try {
    const context = await browser.newContext({ viewport: { width: 1440, height: 900 } });
    await context.addCookies([{ name: 'PHPSESSID', value: session, url: base }]);
    const page = await context.newPage();
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    const routes = [
      ['/index.php?cat=tecnologia-da-informacao', 'DocGov'],
      ['/index.php?cat=tecnologia-da-informacao&subcat=docgov', 'Comece aqui'],
      ['/index.php?cat=tecnologia-da-informacao&subcat=docgov&assunto=comece-aqui', 'Guia de entrada do DocGov'],
      ['/ver_conteudo.php?id=257', 'Roteiro recomendado'],
      ['/ver_conteudo.php?id=263', 'Configurar e diagnosticar Active Directory'],
      ['/ver_conteudo.php?id=271', 'Perguntas frequentes de suporte'],
      ['/ver_conteudo.php?id=273', 'Ciclo editorial do documento'],
      ['/ver_conteudo.php?id=268', 'OBTENHA_NO_COFRE_DE_SEGREDOS'],
    ];
    for (const [route, expected] of routes) {
      const response = await page.goto(base + route, { waitUntil: 'load' });
      assert(response?.status() === 200, `${route}: HTTP ${response?.status()}`);
      assert((await page.locator('body').innerText()).includes(expected), `${route}: texto não encontrado: ${expected}`);
    }

    for (const width of [390, 1440, 2560]) {
      await page.setViewportSize({ width, height: 900 });
      await page.goto(base + '/index.php?cat=tecnologia-da-informacao&subcat=docgov', { waitUntil: 'load' });
      assert(await page.getByRole('link', { name: /Abrir assunto Comece aqui/ }).count() === 1, `${width}px: assunto não encontrado`);
      const state = await page.evaluate(() => ({ width: innerWidth, scroll: document.documentElement.scrollWidth }));
      assert(state.scroll <= state.width + 2, `${width}px: rolagem horizontal ${JSON.stringify(state)}`);
      if (width !== 2560) await page.screenshot({ path: path.join(root, 'tmp', `docgov-catalog-${width}.png`), fullPage: true });
    }
    await page.setViewportSize({ width: 1440, height: 900 });
    await page.goto(base + '/index.php?cat=tecnologia-da-informacao&subcat=docgov&assunto=fluxo-editorial-e-publicacao&section=process-flow', { waitUntil: 'load' });
    assert((await page.locator('body').innerText()).includes('Aprovar e publicar'), 'Fluxo nativo não renderizado');
    await page.goto(base + '/ver_conteudo.php?id=258', { waitUntil: 'load' });
    await page.evaluate(() => localStorage.setItem('theme', 'dark'));
    await page.reload({ waitUntil: 'load' });
    await page.screenshot({ path: path.join(root, 'tmp', 'docgov-article-dark.png'), fullPage: true });
    assert(errors.length === 0, `Erros JavaScript: ${errors.join(' | ')}`);
    console.log('PASS DocGov catalog browser verification: hierarchy, articles, code, FAQ, flow, 390/1440/2560px');
  } finally {
    await browser.close();
    runPhp(`session_id('${session}'); session_start(); session_destroy();`);
  }
})().catch(error => { console.error(error); process.exitCode = 1; });
