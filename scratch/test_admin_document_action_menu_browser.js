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
$user = $pdo->query("SELECT id, name, username, email, role, active FROM users WHERE role = 'admin' AND active = TRUE ORDER BY id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!$user) throw new RuntimeException('Administrador de teste indisponível.');
$sessionId = bin2hex(random_bytes(16)); session_id($sessionId); session_start();
$_SESSION['user'] = ['id' => (int)$user['id'], 'nome' => $user['name'], 'login' => $user['username'], 'email' => $user['email'], 'role' => $user['role'], 'active' => true, 'inicial' => mb_substr($user['name'], 0, 1)];
$_SESSION['admin_logged'] = true; session_write_close(); echo $sessionId;
`);
const document = JSON.parse(runPhp(`
require 'config/db.php';
$row = $pdo->query("SELECT id, title FROM documents WHERE status = 'published' ORDER BY length(title) DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!$row) throw new RuntimeException('Documento publicado de teste indisponível.');
echo json_encode($row, JSON_UNESCAPED_UNICODE);
`));

(async () => {
  const browser = await chromium.launch({ headless: true, executablePath: chrome });
  try {
    const context = await browser.newContext({ viewport: { width: 1440, height: 900 } });
    await context.addCookies([{ name: 'PHPSESSID', value: session, url: base }]);
    const page = await context.newPage();
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    const route = '/admin/index.php?tab=documentos&filter_status=all&search=' + encodeURIComponent(document.title);

    async function checkMenu(label, width, height, dark) {
      await page.setViewportSize({ width, height });
      await page.goto(base + route, { waitUntil: 'load' });
      if (dark) {
        await page.evaluate(() => localStorage.setItem('theme', 'dark'));
        await page.reload({ waitUntil: 'load' });
      }
      const rows = page.locator('#batch-form tbody tr');
      assert(await rows.count() === 1, `${label}: busca não produziu uma única linha`);
      const trigger = rows.locator('[data-action-menu-trigger]');
      await trigger.click();
      const menu = page.locator('#action-menu-' + document.id);
      assert(await menu.isVisible(), `${label}: menu não abriu`);
      const result = await menu.evaluate(element => {
        const rect = element.getBoundingClientRect();
        const actions = [...element.querySelectorAll('a, button')].map(action => {
          const box = action.getBoundingClientRect();
          const hit = document.elementFromPoint(box.left + box.width / 2, box.top + box.height / 2);
          return { text: action.textContent.trim(), visible: box.top >= 0 && box.bottom <= innerHeight && box.left >= 0 && box.right <= innerWidth && (hit === action || action.contains(hit)) };
        });
        return { inBody: element.parentElement === document.body, rect: { top: rect.top, bottom: rect.bottom, left: rect.left, right: rect.right }, actions, form: element.querySelector('button[name="document_trash_action"]').form?.id };
      });
      assert(result.inBody, `${label}: menu ainda está no contêiner da tabela`);
      assert(result.actions.length >= 3 && result.actions.every(action => action.visible), `${label}: ações recortadas: ${JSON.stringify(result)}`);
      assert(result.form === 'batch-form', `${label}: ação de lixeira perdeu o formulário`);
      assert(await trigger.getAttribute('aria-expanded') === 'true', `${label}: estado acessível incorreto`);
      await page.screenshot({ path: path.join(root, 'tmp', `admin-action-menu-${label}.png`) });
      await page.keyboard.press('Escape');
      assert(await menu.isHidden(), `${label}: Escape não fechou o menu`);
      await trigger.click();
      await trigger.click();
      assert(await menu.isHidden(), `${label}: segundo clique não fechou o menu`);
      console.log(`PASS ${label}: ${JSON.stringify(result.rect)}`);
    }

    await checkMenu('desktop-light', 1440, 900, false);
    await checkMenu('short-light', 1440, 480, false);
    await checkMenu('mobile-light', 390, 844, false);
    await checkMenu('desktop-dark', 1440, 900, true);
    await checkMenu('mobile-dark', 390, 844, true);
    assert(errors.length === 0, `Erros JavaScript: ${errors.join(' | ')}`);
  } finally {
    await browser.close();
    runPhp(`session_id('${session}'); session_start(); session_destroy();`);
  }
})().catch(error => { console.error(error); process.exitCode = 1; });
