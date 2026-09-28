const fs = require('fs');
const path = require('path');
const { chromium } = require('playwright');

const baseUrl = (process.env.DOCGOV_TEST_BASE_URL || 'http://127.0.0.1:8765').replace(/\/$/, '');
const sessionId = process.env.DOCGOV_TEST_SESSION_ID || '';
const evidencePath = process.env.DOCGOV_TEST_SCREENSHOT
  || path.join(__dirname, 'release-browser-dashboard.png');

function assert(condition, message) {
  if (!condition) {
    throw new Error(message);
  }
}

function browserExecutable() {
  const candidates = [
    process.env.DOCGOV_BROWSER_PATH,
    'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe',
    'C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe',
  ].filter(Boolean);
  return candidates.find((candidate) => fs.existsSync(candidate));
}

async function collectPageEvidence(page, route, expectedText) {
  const response = await page.goto(`${baseUrl}/${route}`, { waitUntil: 'domcontentloaded' });
  assert(response && response.status() < 400, `${route} retornou HTTP ${response ? response.status() : 'sem resposta'}.`);
  const bodyText = (await page.locator('body').innerText()).trim();
  assert(bodyText.length > 80, `${route} não apresentou conteúdo significativo.`);
  if (expectedText) {
    assert(bodyText.toLocaleLowerCase('pt-BR').includes(expectedText.toLocaleLowerCase('pt-BR')), `${route} não exibiu “${expectedText}”.`);
  }
  const overlayCount = await page.locator('[data-nextjs-dialog], .vite-error-overlay, #webpack-dev-server-client-overlay').count();
  assert(overlayCount === 0, `${route} exibiu uma sobreposição de erro.`);
  const accessibility = await page.evaluate(() => {
    const isExposed = (element) => {
      const style = getComputedStyle(element);
      return element.getClientRects().length > 0
        && style.display !== 'none'
        && style.visibility !== 'hidden'
        && !element.closest('[hidden], [aria-hidden="true"]');
    };
    const visibleFields = Array.from(document.querySelectorAll('input, select, textarea')).filter((field) => field.type !== 'hidden' && isExposed(field));
    const unlabeledFields = visibleFields.filter((field) => {
      if (field.getAttribute('aria-label') || field.getAttribute('aria-labelledby')) return false;
      if (field.id && document.querySelector(`label[for="${CSS.escape(field.id)}"]`)) return false;
      return !field.closest('label');
    }).map((field) => ({ tag: field.tagName.toLowerCase(), type: field.type || '', id: field.id || '', name: field.name || '' }));
    const hasAccessibleName = (element) => Boolean(
      (element.getAttribute('aria-label') || '').trim()
      || (element.getAttribute('title') || '').trim()
      || (element.textContent || '').trim()
      || (element.querySelector('img')?.getAttribute('alt') || '').trim()
    );
    return {
      imagesWithoutAlt: document.querySelectorAll('img:not([alt])').length,
      unlabeledFields,
      unnamedButtons: Array.from(document.querySelectorAll('button')).filter((element) => isExposed(element) && !hasAccessibleName(element)).map((element) => ({ id: element.id || '', className: element.className || '' })),
      unnamedLinks: Array.from(document.querySelectorAll('a[href]')).filter((element) => isExposed(element) && !hasAccessibleName(element)).map((element) => ({ href: element.getAttribute('href') || '', className: element.className || '' })),
      htmlLanguage: document.documentElement.lang || '',
      headingsLevelOne: document.querySelectorAll('h1').length,
    };
  });
  return { route, status: response.status(), title: await page.title(), bodyLength: bodyText.length, accessibility };
}

(async () => {
  const executablePath = browserExecutable();
  assert(executablePath, 'Chrome ou Edge não foi encontrado para o teste.');
  assert(sessionId, 'DOCGOV_TEST_SESSION_ID não foi informado.');

  const browser = await chromium.launch({ headless: true, executablePath });
  const consoleErrors = [];
  const failedResponses = [];
  const pageResults = [];

  try {
    const anonymousContext = await browser.newContext();
    const anonymousPage = await anonymousContext.newPage();
    anonymousPage.on('console', (message) => {
      if (message.type() === 'error') consoleErrors.push({ scope: 'anonymous', text: message.text() });
    });
    anonymousPage.on('response', (response) => {
      if (response.status() >= 400) failedResponses.push({ scope: 'anonymous', status: response.status(), url: response.url() });
    });

    await anonymousPage.goto(`${baseUrl}/index.php`, { waitUntil: 'domcontentloaded' });
    assert(new URL(anonymousPage.url()).pathname.endsWith('/login.php'), 'Acesso sem sessão não foi redirecionado ao login.');
    const loginText = (await anonymousPage.locator('body').innerText()).trim();
    assert(loginText.length > 80, 'Tela de login sem conteúdo significativo.');
    assert(await anonymousPage.locator('input[name="email"]').count() === 1, 'Campo de usuário não encontrado no login.');
    assert(await anonymousPage.locator('input[name="senha"][type="password"]').count() === 1, 'Campo de senha não encontrado no login.');
    assert(await anonymousPage.locator('select[name="ad_domain"]').count() === 1, 'Seleção controlada de domínio não encontrada.');
    assert((await anonymousPage.locator('input[name="email"]').inputValue()) === '', 'Login veio preenchido com um usuário salvo pelo sistema.');
    assert((await anonymousPage.locator('input[name="senha"]').inputValue()) === '', 'Senha veio preenchida pelo sistema.');
    const publicAccessLinks = await anonymousPage.getByRole('link', { name: /acesso p[úu]blico/i }).count();
    assert(publicAccessLinks === 0, 'A tela ainda oferece um acesso público inexistente.');
    pageResults.push(await collectPageEvidence(anonymousPage, 'login.php', ''));
    await anonymousContext.close();

    const authenticatedContext = await browser.newContext();
    await authenticatedContext.addCookies([{
      name: 'PHPSESSID',
      value: sessionId,
      url: baseUrl,
      httpOnly: true,
      sameSite: 'Lax',
    }]);
    const page = await authenticatedContext.newPage();
    page.on('console', (message) => {
      if (message.type() === 'error') consoleErrors.push({ scope: page.url(), text: message.text() });
    });
    page.on('response', (response) => {
      if (response.status() >= 400) failedResponses.push({ scope: page.url(), status: response.status(), url: response.url() });
    });

    pageResults.push(await collectPageEvidence(page, 'index.php', 'Categorias'));
    const documentLink = page.locator('a[href*="ver_conteudo.php?id="]').first();
    if (await documentLink.count()) {
      const href = await documentLink.getAttribute('href');
      pageResults.push(await collectPageEvidence(page, href, ''));
    }
    pageResults.push(await collectPageEvidence(page, 'admin/index.php?tab=visao_geral', 'Visão Geral'));
    pageResults.push(await collectPageEvidence(page, 'admin/index.php?tab=documentos', 'Documentos'));
    pageResults.push(await collectPageEvidence(page, 'admin/index.php?tab=novo_documento', 'conteúdo'));
    pageResults.push(await collectPageEvidence(page, 'admin/index.php?tab=usuarios', 'Usuários'));
    pageResults.push(await collectPageEvidence(page, 'admin/index.php?tab=configuracoes', 'Configurações'));

    const externalHosts = await page.evaluate(() => Array.from(new Set(
      performance.getEntriesByType('resource')
        .map((entry) => new URL(entry.name).host)
        .filter((host) => host && host !== location.host)
    )).sort());
    await page.screenshot({ path: evidencePath, fullPage: true });
    await authenticatedContext.close();

    assert(consoleErrors.length === 0, `Erros no console: ${JSON.stringify(consoleErrors)}`);
    assert(failedResponses.length === 0, `Respostas HTTP com falha: ${JSON.stringify(failedResponses)}`);

    console.log(JSON.stringify({
      ok: true,
      executablePath,
      pages: pageResults,
      consoleErrors,
      failedResponses,
      externalHosts,
      screenshot: evidencePath,
    }, null, 2));
  } finally {
    await browser.close();
  }
})().catch((error) => {
  console.error(error.stack || error.message || String(error));
  process.exit(1);
});
