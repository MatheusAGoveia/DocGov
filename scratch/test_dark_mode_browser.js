const { chromium } = require(process.env.DOCGOV_PLAYWRIGHT_PACKAGE || 'playwright');
const { execFileSync } = require('node:child_process');
const path = require('node:path');

const root = path.resolve(__dirname, '..');
const base = process.env.DOCGOV_TEST_BASE_URL || 'http://127.0.0.1:8000';
const php = process.env.DOCGOV_PHP || 'php';
const chrome = process.env.DOCGOV_BROWSER_PATH || 'C:/Program Files/Google/Chrome/Application/chrome.exe';
const assert = (value, message) => { if (!value) throw new Error(message); };
const runPhp = code => execFileSync(php, ['-r', code], { cwd: root, encoding: 'utf8' }).trim();
const session = runPhp(`
require 'config/db.php';
$user = $pdo->query("SELECT id, name, username, email, role, active FROM users WHERE role = 'admin' AND active = TRUE ORDER BY id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!$user) throw new RuntimeException('Administrador de teste indisponível.');
$sessionId = bin2hex(random_bytes(16));
session_id($sessionId); session_start();
$_SESSION['user'] = ['id' => (int)$user['id'], 'nome' => $user['name'], 'login' => $user['username'], 'email' => $user['email'], 'role' => $user['role'], 'active' => true, 'inicial' => mb_substr($user['name'], 0, 1)];
$_SESSION['admin_logged'] = true; session_write_close();
echo $sessionId;
`);

(async () => {
  const browser = await chromium.launch({ headless: true, executablePath: chrome });
  const errors = [];
  try {
    const context = await browser.newContext({ viewport: { width: 1440, height: 900 }, deviceScaleFactor: 1 });
    await context.addCookies([{ name: 'PHPSESSID', value: session, url: base }]);
    const page = await context.newPage();
    // A troca visual é exercitada sem modificar a preferência real do usuário de teste.
    await page.route('**/api_user.php', route => {
      if (route.request().postData()?.includes('action=update_portal_theme')) {
        return route.fulfill({ status: 204, body: '' });
      }
      return route.continue();
    });
    page.on('pageerror', error => errors.push(error.message));
    const open = async (route, label, screenshot = false) => {
      const response = await page.goto(`${base}/${route}`, { waitUntil: 'domcontentloaded' });
      assert(response?.status() === 200, `${label}: HTTP ${response?.status()}`);
      await page.waitForLoadState('load');
      await page.waitForTimeout(250);
      const state = await page.evaluate(() => ({
        dark: document.documentElement.classList.contains('dark'),
        stored: localStorage.getItem('theme'),
        accent: document.documentElement.getAttribute('data-portal-theme'),
        body: getComputedStyle(document.body).backgroundColor,
        overflow: document.documentElement.scrollWidth > innerWidth + 2,
      }));
      assert(state.dark && state.stored === 'dark', `${label}: modo escuro não persistiu: ${JSON.stringify(state)}`);
      assert(!state.overflow, `${label}: rolagem horizontal inesperada`);
      if (screenshot) await page.screenshot({ path: path.join(root, 'tmp', `dark-mode-${label}.png`), fullPage: true });
      console.log(label, JSON.stringify(state));
      return state;
    };

    await page.goto(`${base}/index.php`, { waitUntil: 'domcontentloaded' });
    const menu = page.locator('.theme-dropdown-container').first();
    await menu.locator(':scope > button').click();
    await menu.locator('[data-appearance="dark"]').click();
    assert(await page.evaluate(() => localStorage.getItem('theme')) === 'dark', 'A escolha manual não foi salva.');
    assert(await page.locator('html.dark').count() === 1, 'A escolha não foi aplicada de imediato.');
    await open('index.php', 'inicio', true);
    await menu.locator(':scope > button').click();
    assert(await menu.locator('[data-appearance="dark"]').getAttribute('aria-pressed') === 'true', 'Seletor de aparência não reflete a escolha.');
    await page.screenshot({ path: path.join(root, 'tmp', 'dark-mode-dropdown.png') });
    await menu.locator('.theme-color-option-blue').click();
    assert(await page.evaluate(() => document.documentElement.classList.contains('dark') && localStorage.getItem('portal_theme') === 'blue'), 'Cor de destaque alterou a aparência ou não persistiu.');
    await open('favoritos.php', 'favoritos', true);
    await open('minha_conta.php?tab=perfil', 'perfil', true);
    await open('notificacoes.php', 'notificacoes', true);
    await open('admin/index.php?tab=visao_geral', 'admin', true);
    await page.evaluate(() => window.scrollTo(0, 700));
    await page.screenshot({ path: path.join(root, 'tmp', 'dark-mode-admin-scroll.png') });
    await page.evaluate(() => window.scrollTo(0, 0));
    await open('admin/index.php?tab=novo_documento&action=edit_doc&id=169', 'editor', true);
    assert(await page.locator('#quill-editor-container .ql-editor').count() === 1, 'Editor não carregou.');
    const editor = await page.locator('#quill-editor-container .ql-editor').evaluate(node => ({
      background: getComputedStyle(node.parentElement).backgroundColor,
      color: getComputedStyle(node).color,
    }));
    assert(editor.background !== 'rgb(255, 255, 255)', `Editor permaneceu branco: ${JSON.stringify(editor)}`);
    console.log('editor-cores', JSON.stringify(editor));
    console.log('editor-link', await page.locator('#quill-editor-container .ql-editor a').first().evaluate(node => ({ color: getComputedStyle(node).color, inline: node.getAttribute('style') })));
    await open('ver_conteudo.php?id=169', 'documento', true);
    console.log('document-link', await page.locator('.govdoc-rich-content a').first().evaluate(node => ({ color: getComputedStyle(node).color, inline: node.getAttribute('style') })));

    for (const width of [390, 768, 1920, 2560]) {
      await page.setViewportSize({ width, height: width === 390 ? 844 : 1000 });
      const state = await open('index.php', `inicio-${width}`, width === 390 || width === 2560);
      assert(state.accent === 'blue', `Cor não persistiu em ${width}px.`);
      if (width === 390) {
        await page.locator('.theme-dropdown-container > button').first().click();
        await page.screenshot({ path: path.join(root, 'tmp', 'dark-mode-dropdown-mobile.png') });
      }
    }

    await page.setViewportSize({ width: 390, height: 844 });
    for (const [route, label] of [
      ['favoritos.php', 'favoritos-mobile'],
      ['minha_conta.php?tab=perfil', 'perfil-mobile'],
      ['notificacoes.php', 'notificacoes-mobile'],
      ['admin/index.php?tab=visao_geral', 'admin-mobile'],
      ['admin/index.php?tab=novo_documento&action=edit_doc&id=169', 'editor-mobile'],
    ]) {
      await open(route, label);
      await page.screenshot({ path: path.join(root, 'tmp', `dark-mode-${label}.png`) });
    }

    await page.setViewportSize({ width: 1440, height: 900 });
    for (const accent of ['emerald', 'blue', 'indigo', 'violet', 'rose', 'amber', 'ocean', 'graphite']) {
      await page.evaluate(key => { document.documentElement.setAttribute('data-portal-theme', key); }, accent);
      const colors = await page.evaluate(() => {
        const styles = getComputedStyle(document.documentElement);
        const hex = name => styles.getPropertyValue(name).trim();
        const luminance = value => {
          const rgb = value.replace('#', '').match(/../g).map(part => parseInt(part, 16) / 255)
            .map(channel => channel <= 0.04045 ? channel / 12.92 : ((channel + 0.055) / 1.055) ** 2.4);
          return rgb[0] * 0.2126 + rgb[1] * 0.7152 + rgb[2] * 0.0722;
        };
        const contrast = (first, second) => {
          const values = [luminance(first), luminance(second)].sort((a, b) => b - a);
          return (values[0] + 0.05) / (values[1] + 0.05);
        };
        return {
          accent: hex('--accent'), foreground: hex('--accent-foreground'),
          buttonContrast: contrast(hex('--accent'), hex('--accent-foreground')),
          bodyContrast: contrast(hex('--text-main'), hex('--bg-canvas')),
          mutedContrast: contrast(hex('--text-secondary'), hex('--bg-surface-3')),
        };
      });
      assert(colors.accent && colors.foreground, `${accent}: tokens incompletos`);
      assert(colors.buttonContrast >= 4.5, `${accent}: contraste da ação insuficiente (${colors.buttonContrast.toFixed(2)})`);
      assert(colors.bodyContrast >= 4.5 && colors.mutedContrast >= 4.5, `${accent}: contraste do texto insuficiente`);
      console.log('accent', accent, JSON.stringify(colors));
    }

    const guest = await browser.newContext({ viewport: { width: 1440, height: 900 } });
    await guest.addInitScript(() => localStorage.setItem('theme', 'dark'));
    const login = await guest.newPage();
    login.on('pageerror', error => errors.push(error.message));
    const loginResponse = await login.goto(`${base}/login.php`, { waitUntil: 'domcontentloaded' });
    assert(loginResponse?.status() === 200 && await login.locator('html.dark').count() === 1, 'Login não respeita o modo escuro.');
    await login.screenshot({ path: path.join(root, 'tmp', 'dark-mode-login.png'), fullPage: true });
    await login.locator('.theme-dropdown-container > button').click();
    await login.locator('[data-appearance="light"]').click();
    assert(await login.locator('html.light').count() === 1, 'Login não alternou de volta para claro.');
    await guest.close();

    await page.goto(`${base}/index.php`, { waitUntil: 'domcontentloaded' });
    await menu.locator(':scope > button').click();
    await menu.locator('[data-appearance="light"]').click();
    assert(await page.locator('html.light').count() === 1 && await page.evaluate(() => localStorage.getItem('theme')) === 'light', 'Retorno ao modo claro falhou.');
    await page.reload({ waitUntil: 'domcontentloaded' });
    await page.waitForLoadState('load');
    await page.waitForTimeout(500); // Ícones de categoria usam lazy loading.
    assert(await page.locator('html.light').count() === 1, 'Modo claro não persistiu.');
    await page.screenshot({ path: path.join(root, 'tmp', 'dark-mode-light-regression.png') });
    assert(errors.length === 0, `Erros JavaScript: ${errors.join(' | ')}`);
    console.log('PASS dark-mode browser verification');
  } finally {
    await browser.close();
    runPhp(`session_id('${session}'); session_start(); session_destroy();`);
  }
})().catch(error => { console.error(error); process.exitCode = 1; });
