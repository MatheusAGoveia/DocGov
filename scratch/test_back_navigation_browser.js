const { chromium } = require(process.env.DOCGOV_PLAYWRIGHT_PACKAGE || 'playwright');

const baseUrl = process.env.DOCGOV_TEST_BASE_URL || 'http://localhost:8000';
const sessionId = process.env.DOCGOV_TEST_SESSION_ID;
const chromePath = process.env.DOCGOV_BROWSER_PATH || 'C:/Program Files/Google/Chrome/Application/chrome.exe';

function assert(condition, message) {
  if (!condition) throw new Error(message);
}

(async () => {
  assert(sessionId, 'Sessão temporária de teste não informada.');
  const browser = await chromium.launch({ headless: true, executablePath: chromePath });
  try {
    const context = await browser.newContext();
    await context.addCookies([{ name: 'PHPSESSID', value: sessionId, url: baseUrl }]);
    const cases = [
      ['/index.php?cat=tecnologia-da-informacao', '/index.php', 'categorias'],
      ['/index.php?cat=tecnologia-da-informacao&subcat=seguranca-digital', '/index.php?cat=tecnologia-da-informacao', 'categoria'],
      ['/index.php?cat=tecnologia-da-informacao&subcat=seguranca-digital&assunto=orientacoes-essenciais', '/index.php?cat=tecnologia-da-informacao&subcat=seguranca-digital', 'subcategoria'],
      ['/index.php?q=seguranca', '/index.php', 'categorias'],
      ['/ver_conteudo.php?id=185', '/index.php?cat=tecnologia-da-informacao&subcat=seguranca-digital&assunto=orientacoes-essenciais', 'assunto'],
      ['/favoritos.php', '/index.php', 'acervo'],
      ['/minha_conta.php', '/index.php', 'acervo'],
      ['/notificacoes.php', '/index.php', 'acervo'],
      ['/admin/index.php?tab=visao_geral', '/index.php', 'acervo'],
      ['/admin/index.php?tab=novo_documento', '/admin/index.php?tab=documentos', 'documentos'],
      ['/admin/index.php?tab=detalhes_documento&id=185', '/admin/index.php?tab=documentos', 'documentos'],
      ['/admin/index.php?tab=editar_estrutura&type=categoria&id=79', '/admin/index.php?tab=editar_estrutura', 'árvore'],
    ];

    for (const [route, fallback, label] of cases) {
      const page = await context.newPage();
      const response = await page.goto(new URL(route, baseUrl).href, { waitUntil: 'domcontentloaded' });
      assert(response && response.status() < 400, `${route}: HTTP ${response ? response.status() : 'sem resposta'}`);
      assert(!page.url().includes('/login.php'), `${route}: sessão não reconhecida`);
      const back = page.locator('nav[aria-label="Navegação de retorno"] a');
      assert(await back.count() === 1, `${route}: seta ausente ou duplicada`);
      assert(await back.isVisible(), `${route}: seta não visível`);
      const href = await back.getAttribute('href');
      const target = new URL(href, page.url());
      const expected = new URL(fallback, baseUrl);
      assert(target.pathname === expected.pathname && target.search === expected.search, `${route}: destino ${target.pathname}${target.search} não corresponde a ${fallback}`);
      assert((await back.innerText()).toLocaleLowerCase('pt-BR').includes(label), `${route}: rótulo não identifica o destino`);
      await page.close();
      console.log(`[OK] ${route} -> ${fallback}`);
    }

    const page = await context.newPage();
    await page.goto(new URL('/index.php?cat=tecnologia-da-informacao&subcat=seguranca-digital', baseUrl).href, { waitUntil: 'domcontentloaded' });
    await page.locator('nav[aria-label="Navegação de retorno"] a').click();
    await page.waitForURL('**/index.php?cat=tecnologia-da-informacao');
    console.log('[OK] Clique na seta retorna ao nível anterior.');
    await page.close();

    const historyPage = await context.newPage();
    await historyPage.setViewportSize({ width: 390, height: 844 });
    const subjectUrl = '/index.php?cat=tecnologia-da-informacao&subcat=seguranca-digital&assunto=orientacoes-essenciais&section=videos';
    await historyPage.goto(new URL(subjectUrl, baseUrl).href, { waitUntil: 'domcontentloaded' });
    await historyPage.locator('a[href="ver_conteudo.php?id=185"]').first().click();
    await historyPage.waitForURL('**/ver_conteudo.php?id=185');
    const mobileBack = historyPage.locator('nav[aria-label="Navegação de retorno"] a');
    assert(await mobileBack.isVisible(), 'Seta não aparece na largura de celular.');
    await historyPage.screenshot({ path: 'tmp/back-navigation-mobile.png' });
    await mobileBack.click();
    await historyPage.waitForURL(`**${subjectUrl}`);
    console.log('[OK] Retorno pelo histórico preserva a aba de vídeos no celular.');
    await historyPage.close();

    const adminMobile = await context.newPage();
    await adminMobile.setViewportSize({ width: 390, height: 844 });
    await adminMobile.goto(new URL('/admin/index.php?tab=novo_documento', baseUrl).href, { waitUntil: 'domcontentloaded' });
    assert(await adminMobile.locator('nav[aria-label="Navegação de retorno"] a').isVisible(), 'Seta administrativa não aparece no celular.');
    await adminMobile.screenshot({ path: 'tmp/back-navigation-admin-mobile.png' });
    console.log('[OK] Seta administrativa visível no celular.');
    await adminMobile.close();
  } finally {
    await browser.close();
  }
})().catch((error) => {
  console.error(error);
  process.exitCode = 1;
});
