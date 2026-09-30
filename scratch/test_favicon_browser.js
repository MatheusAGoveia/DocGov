const { chromium } = require(process.env.DOCGOV_PLAYWRIGHT_PACKAGE || 'playwright');
const { execFileSync } = require('node:child_process');
const path = require('node:path');

const root = path.resolve(__dirname, '..');
const base = process.env.DOCGOV_TEST_BASE_URL || 'http://127.0.0.1:8000';
const chrome = process.env.DOCGOV_BROWSER_PATH || 'C:/Program Files/Google/Chrome/Application/chrome.exe';
const runPhp = code => execFileSync('php', ['-r', code], { cwd: root, encoding: 'utf8' }).trim();
const assert = (value, message) => { if (!value) throw new Error(message); };

const session = runPhp(`
require 'config/db.php';
$user = $pdo->query("SELECT id, name, username, email, role, active FROM users WHERE role = 'admin' AND active = TRUE ORDER BY id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!$user) throw new RuntimeException('Administrador ativo indisponível.');
$id = bin2hex(random_bytes(16)); session_id($id); session_start();
$_SESSION['user'] = ['id' => (int)$user['id'], 'nome' => $user['name'], 'login' => $user['username'], 'email' => $user['email'], 'role' => $user['role'], 'active' => true, 'inicial' => mb_substr($user['name'], 0, 1)];
$_SESSION['admin_logged'] = true; session_write_close(); echo $id;
`);

(async () => {
  const browser = await chromium.launch({ headless: true, executablePath: chrome });
  try {
    const check = async (page, route) => {
      const response = await page.goto(base + route, { waitUntil: 'load' });
      assert(response?.status() === 200, `${route}: HTTP ${response?.status()}`);
      const href = await page.locator('head link[rel="icon"]').getAttribute('href');
      assert(href?.includes('app_logo.php?v='), `${route}: favicon não aponta para a logo configurada`);
      const iconUrl = new URL(href, page.url());
      assert(iconUrl.pathname === '/app_logo.php', `${route}: caminho relativo incorreto (${iconUrl})`);
      const image = await page.evaluate(async url => {
        const response = await fetch(url);
        const blob = await response.blob();
        const bitmap = await createImageBitmap(blob);
        return { status: response.status, type: blob.type, width: bitmap.width, height: bitmap.height };
      }, iconUrl.href);
      assert(image.status === 200 && image.type === 'image/png' && image.width > 100 && image.height > 100, `${route}: imagem inválida ${JSON.stringify(image)}`);
    };

    const publicContext = await browser.newContext();
    await check(await publicContext.newPage(), '/login.php');
    await publicContext.close();

    const context = await browser.newContext();
    await context.addCookies([{ name: 'PHPSESSID', value: session, url: base }]);
    const page = await context.newPage();
    for (const route of [
      '/index.php', '/favoritos.php', '/ver_conteudo.php?id=257',
      '/minha_conta.php', '/notificacoes.php', '/admin/index.php?tab=documentos',
    ]) await check(page, route);
    console.log('PASS favicon: login, portal, favoritos, documento, conta, notificações e administração');
  } finally {
    await browser.close();
    runPhp(`session_id('${session}'); session_start(); session_destroy();`);
  }
})().catch(error => { console.error(error); process.exitCode = 1; });
