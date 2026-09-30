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
if (!$user) throw new RuntimeException('Administrador de teste indisponível.');
$sessionId = bin2hex(random_bytes(16)); session_id($sessionId); session_start();
$_SESSION['user'] = ['id' => (int)$user['id'], 'nome' => $user['name'], 'login' => $user['username'], 'email' => $user['email'], 'role' => $user['role'], 'active' => true, 'inicial' => mb_substr($user['name'], 0, 1)];
$_SESSION['admin_logged'] = true; session_write_close(); echo $sessionId;
`);
const fixture = JSON.parse(runPhp(`
require 'config/db.php';
$subjectId = (int)$pdo->query("SELECT id FROM subjects WHERE subcategory_id = 107 ORDER BY id LIMIT 1")->fetchColumn();
$actorId = (int)$pdo->query("SELECT id FROM users WHERE role = 'admin' AND active = TRUE ORDER BY id LIMIT 1")->fetchColumn();
if (!$subjectId || !$actorId) throw new RuntimeException('Base de teste indisponível.');
$token = bin2hex(random_bytes(6));
$stmt = $pdo->prepare('INSERT INTO documents (subject_id, created_by, title, slug, description, content_type, section_key, status, text_content) VALUES (:subject, :actor, :title, :slug, :description, :type, :section, :status, :content) RETURNING id');
$pdo->beginTransaction();
$ids = [];
foreach (['draft' => 'Rascunho', 'review' => 'Em revisão'] as $status => $label) {
  $stmt->execute([':subject' => $subjectId, ':actor' => $actorId, ':title' => 'Teste temporário filtro ' . $label . ' ' . $token, ':slug' => 'teste-filtro-' . $token . '-' . $status, ':description' => 'Fixture temporária para verificar a listagem.', ':type' => 'text', ':section' => 'documents', ':status' => $status, ':content' => '<p>Teste temporário.</p>']);
  $ids[$status] = (int)$stmt->fetchColumn();
}
$pdo->commit(); echo json_encode($ids);
`));

(async () => {
  const browser = await chromium.launch({ headless: true, executablePath: chrome });
  try {
    const context = await browser.newContext({ viewport: { width: 1440, height: 900 } });
    await context.addCookies([{ name: 'PHPSESSID', value: session, url: base }]);
    const page = await context.newPage();
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    const open = async route => {
      const response = await page.goto(base + route, { waitUntil: 'load' });
      assert(response?.status() === 200, `${route}: HTTP ${response?.status()}`);
    };
    const rows = () => page.locator('#batch-form tbody tr');
    const statuses = async () => rows().locator('td:nth-child(6)').allInnerTexts();
    const assertStatuses = async expected => {
      const list = await statuses();
      assert(list.length > 0, `Nenhuma linha exibida: ${(await page.locator('body').innerText()).slice(0, 700)}`);
      assert(list.every(value => expected.includes(value.split('\n')[0].trim())), `Status inesperado: ${JSON.stringify(list)}`);
    };

    await open('/admin/index.php?tab=documentos');
    assert(await page.locator('#document-filter-form').count() === 1, 'Formulário GET de filtros ausente');
    assert(await page.locator('#batch-form').count() === 1, 'Formulário POST de ações ausente');
    assert(await page.locator('#document-filter-form select[name="filter_status"]').inputValue() === 'pending', 'Visão inicial não é Pendentes');
    await assertStatuses(['Rascunho', 'Em revisão']);
    assert(await page.locator('[data-batch-action="publish"]').isDisabled(), 'Aprovar deveria iniciar desabilitado');
    await page.screenshot({ path: path.join(root, 'tmp', 'admin-documents-pending.png') });

    await page.locator('#document-filter-form select[name="filter_status"]').selectOption('published');
    await page.locator('#document-filter-form button[type="submit"]').click();
    assert(new URL(page.url()).searchParams.get('filter_status') === 'published', 'Filtro não foi enviado como GET');
    await assertStatuses(['Publicado']);
    await page.screenshot({ path: path.join(root, 'tmp', 'admin-documents-published.png') });

    await open('/admin/index.php?tab=documentos&filter_status=all');
    const allText = await page.locator('#batch-form').innerText();
    assert(allText.includes('Teste temporário filtro') && allText.includes('Publicado'), 'Todos não exibiu documentos de status diferentes');
    const firstPageIds = await rows().locator('input.batch-checkbox').evaluateAll(nodes => nodes.map(node => node.value));
    await Promise.all([
      page.waitForURL(url => url.searchParams.get('page') === '2', { waitUntil: 'load' }),
      page.getByRole('link', { name: 'Próxima' }).click(),
    ]);
    assert(new URL(page.url()).searchParams.get('filter_status') === 'all', 'Paginação perdeu o filtro Todos');
    const secondPageIds = await rows().locator('input.batch-checkbox').evaluateAll(nodes => nodes.map(node => node.value));
    assert(firstPageIds.length === 10 && secondPageIds.length === 10 && firstPageIds[0] !== secondPageIds[0], `Paginação não avançou: ${JSON.stringify({ firstPageIds, secondPageIds, url: page.url() })}`);

    await open('/admin/index.php?tab=documentos&filter_status=review');
    await assertStatuses(['Em revisão']);
    await open('/admin/index.php?tab=documentos&filter_status=draft');
    await assertStatuses(['Rascunho']);
    await open('/admin/index.php?tab=documentos&filter_status=inactive');
    assert((await page.locator('body').innerText()).includes('Nenhum documento nesta visualização'), 'Filtro Inativo não foi aplicado');

    await open('/admin/index.php?tab=documentos&filter_status=all');
    await page.locator('#document-filter-form input[name="search"]').fill('LDAP');
    await page.locator('#document-filter-form button[type="submit"]').click();
    assert(new URL(page.url()).searchParams.get('search') === 'LDAP', 'Busca não foi enviada como GET');
    assert((await page.locator('#batch-form').innerText()).includes('Active Directory'), 'Busca não encontrou o documento esperado');

    await open('/admin/index.php?tab=documentos&filter_status=all');
    await page.locator('#filter-cat').selectOption({ label: 'Tecnologia da Informação' });
    await page.locator('#filter-subcat').selectOption({ label: 'DocGov' });
    await page.locator('#document-filter-form button[type="submit"]').click();
    assert((await page.locator('#batch-form').innerText()).includes('19 documentos encontrados'), `Filtros de hierarquia não limitaram a DocGov: ${(await page.locator('#batch-form').innerText()).slice(-400)}`);

    await page.setViewportSize({ width: 390, height: 844 });
    await open('/admin/index.php?tab=documentos&filter_status=published');
    assert(await page.locator('#document-filter-form select[name="filter_status"]').isVisible(), 'Filtro de status não aparece no celular');
    const mobileSize = await page.evaluate(() => ({ viewport: innerWidth, content: document.documentElement.scrollWidth }));
    assert(mobileSize.content <= mobileSize.viewport + 2, `Rolagem horizontal na página móvel: ${JSON.stringify(mobileSize)}`);
    await page.screenshot({ path: path.join(root, 'tmp', 'admin-documents-mobile.png') });

    await page.setViewportSize({ width: 1440, height: 900 });
    await page.evaluate(() => localStorage.setItem('theme', 'dark'));
    await page.reload({ waitUntil: 'load' });
    await page.screenshot({ path: path.join(root, 'tmp', 'admin-documents-dark.png') });
    assert(errors.length === 0, `Erros JavaScript: ${errors.join(' | ')}`);
    console.log('PASS filtros de documentos: pendentes, todos, status, busca, hierarquia e paginação');
  } finally {
    await browser.close();
    runPhp(`require 'config/db.php'; $stmt=$pdo->prepare("DELETE FROM documents WHERE id IN (?, ?) AND title LIKE 'Teste temporário filtro %'"); $stmt->execute([${fixture.draft}, ${fixture.review}]); if ($stmt->rowCount() !== 2) throw new RuntimeException('Fixture não foi totalmente removida.');`);
    runPhp(`session_id('${session}'); session_start(); session_destroy();`);
  }
})().catch(error => { console.error(error); process.exitCode = 1; });
