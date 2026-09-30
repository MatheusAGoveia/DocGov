const { chromium } = require(process.env.DOCGOV_PLAYWRIGHT_PACKAGE || 'playwright');
const { execFileSync } = require('node:child_process');

const base = process.env.DOCGOV_TEST_BASE_URL || 'http://127.0.0.1:8000';
const chrome = process.env.DOCGOV_BROWSER_PATH || 'C:/Program Files/Google/Chrome/Application/chrome.exe';
const runPhp = code => execFileSync('php', ['-r', code], { cwd: require('node:path').resolve(__dirname, '..'), encoding: 'utf8' }).trim();
const assert = (value, message) => { if (!value) throw new Error(message); };

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
$row = $pdo->query("SELECT id, title, status FROM documents WHERE status IN ('review', 'draft', 'published') ORDER BY CASE status WHEN 'review' THEN 0 WHEN 'draft' THEN 1 ELSE 2 END, length(title) DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!$row) throw new RuntimeException('Documento de teste indisponível.');
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
    const listUrl = base + '/admin/index.php?tab=documentos&filter_status=' + document.status + '&search=' + encodeURIComponent(document.title);
    const detailUrl = new RegExp(`tab=detalhes_documento&id=${document.id}(?:&|$)`);
    const openList = async () => {
      const response = await page.goto(listUrl, { waitUntil: 'load' });
      assert(response?.status() === 200, `Lista retornou HTTP ${response?.status()}`);
      const rows = page.locator('#batch-form tbody tr');
      assert(await rows.count() === 1, `A busca deveria mostrar só um documento: ${await rows.count()}`);
      return rows.first();
    };
    const assertDetails = label => assert(detailUrl.test(page.url()), `${label}: não abriu os detalhes (${page.url()})`);

    let row = await openList();
    await row.locator('td').nth(2).click();
    assertDetails('Clique na localização');

    row = await openList();
    await row.locator('td').nth(5).click();
    assertDetails('Clique no status');

    row = await openList();
    const checkbox = row.locator('input.batch-checkbox');
    await checkbox.click();
    assert(await checkbox.isChecked() && page.url() === listUrl, 'Checkbox alterou a seleção ou navegou indevidamente');

    await row.locator('[data-action-menu-trigger]').click();
    assert(await page.locator('#action-menu-' + document.id).isVisible() && page.url() === listUrl, 'Menu de ações não permaneceu independente');

    row = await openList();
    await row.locator('td').nth(1).locator('a').click();
    assertDetails('Clique no título');

    row = await openList();
    await row.focus();
    await page.keyboard.press('Enter');
    await page.waitForURL(detailUrl);
    assertDetails('Enter na linha');

    row = await openList();
    await row.focus();
    await page.keyboard.press('Space');
    await page.waitForURL(detailUrl);
    assertDetails('Espaço na linha');

    await page.setViewportSize({ width: 390, height: 844 });
    row = await openList();
    await row.locator('td').nth(2).click();
    assertDetails('Clique móvel na localização');

    assert(errors.length === 0, `Erros JavaScript: ${errors.join(' | ')}`);
    console.log('PASS linha clicável: células, título, teclado, mobile; checkbox e menu independentes');
  } finally {
    await browser.close();
    runPhp(`session_id('${session}'); session_start(); session_destroy();`);
  }
})().catch(error => { console.error(error); process.exitCode = 1; });
