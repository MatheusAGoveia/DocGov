const { chromium } = require(process.env.DOCGOV_PLAYWRIGHT_PACKAGE || 'playwright');
const { execFileSync } = require('node:child_process');
const path = require('node:path');

const root = path.resolve(__dirname, '..');
const base = process.env.DOCGOV_TEST_BASE_URL || 'http://127.0.0.1:8000';
const chrome = process.env.DOCGOV_BROWSER_PATH || 'C:/Program Files/Google/Chrome/Application/chrome.exe';
const php = process.env.DOCGOV_PHP || 'php';
const runPhp = code => execFileSync(php, ['-r', code], { cwd: root, encoding: 'utf8' }).trim();
const assert = (value, message) => { if (!value) throw new Error(message); };

const session = runPhp(`
require 'config/db.php';
$user = $pdo->query("SELECT id, name, username, email, role, active FROM users WHERE role = 'admin' AND avatar IS NOT NULL AND active = TRUE ORDER BY id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!$user) throw new RuntimeException('Usuário de teste com foto indisponível.');
$sessionId = bin2hex(random_bytes(16));
session_id($sessionId); session_start();
$_SESSION['user'] = ['id' => (int)$user['id'], 'nome' => $user['name'], 'login' => $user['username'], 'email' => $user['email'], 'role' => $user['role'], 'active' => true, 'inicial' => mb_substr($user['name'], 0, 1)];
$_SESSION['admin_logged'] = true; session_write_close();
echo $sessionId;
`);
const fallbackSession = runPhp(`
require 'config/db.php';
$user = $pdo->query("SELECT id, name, username, email, role, active FROM users WHERE avatar IS NULL AND active = TRUE ORDER BY id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!$user) throw new RuntimeException('Usuário de teste sem foto indisponível.');
$sessionId = bin2hex(random_bytes(16));
session_id($sessionId); session_start();
$_SESSION['user'] = ['id' => (int)$user['id'], 'nome' => $user['name'], 'login' => $user['username'], 'email' => $user['email'], 'role' => $user['role'], 'active' => true, 'inicial' => mb_substr($user['name'], 0, 1)];
session_write_close();
echo $sessionId;
`);

(async () => {
  const browser = await chromium.launch({ headless: true, executablePath: chrome });
  try {
    const context = await browser.newContext({ viewport: { width: 1440, height: 900 } });
    await context.addCookies([{ name: 'PHPSESSID', value: session, url: base }]);
    const page = await context.newPage();
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));

    for (const [route, selector] of [
      ['index.php', '.portal-profile-avatar img'],
      ['favoritos.php', '.portal-profile-avatar img'],
      ['admin/index.php?tab=visao_geral', '.admin-profile-avatar img'],
    ]) {
      const response = await page.goto(`${base}/${route}`, { waitUntil: 'load' });
      assert(response?.status() === 200, `${route}: HTTP ${response?.status()}`);
      const image = page.locator(selector).first();
      assert(await image.count() === 1, `${route}: a inicial não foi substituída pela foto`);
      const state = await image.evaluate(node => ({
        loaded: node.complete && node.naturalWidth > 0,
        size: node.parentElement.getBoundingClientRect().width,
        fit: getComputedStyle(node).objectFit,
      }));
      assert(state.loaded && state.size === 24 && state.fit === 'cover', `${route}: foto inválida ${JSON.stringify(state)}`);
    }

    for (const width of [390, 768, 2560]) {
      await page.setViewportSize({ width, height: 900 });
      await page.goto(`${base}/index.php`, { waitUntil: 'load' });
      const visible = await page.locator('.portal-profile-avatar img').first().isVisible();
      assert(visible, `Foto não aparece em ${width}px`);
      assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 2), `Rolagem horizontal em ${width}px`);
      if (width === 390 || width === 2560) {
        await page.locator('.portal-topbar').screenshot({ path: path.join(root, 'tmp', `profile-avatar-header-${width}.png`) });
      }
    }

    await page.setViewportSize({ width: 1440, height: 900 });
    await page.evaluate(() => localStorage.setItem('theme', 'dark'));
    await page.reload({ waitUntil: 'load' });
    assert(await page.locator('html.dark .portal-profile-avatar img').count() === 1, 'Foto não aparece no modo escuro');
    await page.locator('.portal-topbar').screenshot({ path: path.join(root, 'tmp', 'profile-avatar-header-dark.png') });

    const noPhotoContext = await browser.newContext();
    await noPhotoContext.addCookies([{ name: 'PHPSESSID', value: fallbackSession, url: base }]);
    const noPhotoPage = await noPhotoContext.newPage();
    await noPhotoPage.goto(`${base}/index.php`, { waitUntil: 'load' });
    assert(await noPhotoPage.locator('.portal-profile-avatar img').count() === 0, 'Usuário sem foto recebeu uma imagem');
    assert((await noPhotoPage.locator('.portal-profile-avatar').innerText()).trim().length > 0, 'Inicial de fallback ausente');
    await noPhotoContext.close();

    assert(errors.length === 0, `Erros JavaScript: ${errors.join(' | ')}`);
    console.log('PASS profile avatar browser verification');
  } finally {
    await browser.close();
    runPhp(`session_id('${session}'); session_start(); session_destroy();`);
    runPhp(`session_id('${fallbackSession}'); session_start(); session_destroy();`);
  }
})().catch(error => { console.error(error); process.exitCode = 1; });
