// Todos os POSTs administrativos são interceptados: os testes não excluem dados.
const { chromium } = require(process.env.DOCGOV_PLAYWRIGHT_PACKAGE || 'playwright');
const { execFileSync } = require('node:child_process');
const path = require('node:path');
const fs = require('node:fs');

const root = path.resolve(__dirname, '..');
const base = process.env.DOCGOV_TEST_BASE_URL || 'http://127.0.0.1:8000';
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

(async () => {
    const browser = await chromium.launch({ headless: true, executablePath: process.env.DOCGOV_BROWSER_PATH || 'C:/Program Files/Google/Chrome/Application/chrome.exe' });
    try {
        fs.mkdirSync(path.join(root, 'tmp'), { recursive: true });
        const context = await browser.newContext({ viewport: { width: 1440, height: 900 } });
        await context.addCookies([{ name: 'PHPSESSID', value: session, url: base }]);
        const page = await context.newPage();
        const errors = [];
        const nativeDialogs = [];
        const posts = [];
        page.on('pageerror', error => errors.push(error.message));
        page.on('dialog', async dialog => { nativeDialogs.push(dialog.type()); await dialog.dismiss(); });
        await page.route('**/admin/index.php**', async route => {
            if (route.request().method() !== 'POST') return route.continue();
            posts.push(new URLSearchParams(route.request().postData() || ''));
            await route.fulfill({ contentType: 'text/html', body: '<p>Envio interceptado pelo teste.</p>' });
        });
        const modal = page.locator('.dg-dialog');
        const confirmButton = modal.locator('.dg-dialog__confirm');
        const cancelButton = modal.locator('.dg-dialog__cancel');

        async function go(route) {
            await page.goto(base + route, { waitUntil: 'networkidle' });
            assert(await page.evaluate(() => !!window.DocGovDialog), 'Componente não foi carregado');
        }
        async function assertLayout(label) {
            const layout = await modal.evaluate(element => {
                const rect = element.getBoundingClientRect();
                const style = getComputedStyle(element);
                const buttons = [...element.querySelectorAll('.dg-dialog__button')].filter(button => !button.hidden);
                return {
                    contained: rect.left >= 0 && rect.right <= innerWidth && rect.top >= 0 && rect.bottom <= innerHeight,
                    overflow: element.scrollWidth > element.clientWidth,
                    modal: element.matches(':modal'), background: style.backgroundColor,
                    buttonHeights: buttons.map(button => button.getBoundingClientRect().height),
                    radius: style.borderRadius,
                };
            });
            assert(layout.contained && !layout.overflow && layout.modal, `${label}: layout inválido ${JSON.stringify(layout)}`);
            assert(layout.buttonHeights.every(height => height >= 43.9), `${label}: botões pequenos ${JSON.stringify(layout)}`);
            assert(layout.radius === '16px', `${label}: CSS não aplicado`);
            await page.screenshot({ path: path.join(root, 'tmp', `dialogs-${label}.png`) });
            console.log(`PASS ${label}: ${JSON.stringify(layout)}`);
        }

        for (const scenario of [
            { label: 'subcategory-desktop-light', width: 1440, height: 900, dark: false },
            { label: 'subcategory-mobile-dark', width: 390, height: 844, dark: true },
        ]) {
            await page.setViewportSize(scenario);
            await go('/admin/index.php?tab=subcategorias');
            await page.evaluate(dark => {
                localStorage.setItem('theme', dark ? 'dark' : 'light');
                document.documentElement.classList.toggle('dark', dark);
            }, scenario.dark);
            const form = page.locator('form[data-confirm]').filter({ has: page.locator('input[name="structure_type"][value="subcategory"]') }).first();
            assert(await form.count(), 'Formulário real de subcategoria indisponível');
            const trigger = form.locator('button[type="submit"]');
            const expectedAction = await trigger.getAttribute('value');
            await trigger.click();
            assert(await modal.isVisible(), 'Confirmação de subcategoria não abriu');
            assert(await cancelButton.evaluate(button => button === document.activeElement), 'Foco inicial não está em Cancelar');
            await page.keyboard.press('Tab');
            assert(await confirmButton.evaluate(button => button === document.activeElement), 'Tab não alcançou a ação principal');
            await page.keyboard.press('Shift+Tab');
            assert(await cancelButton.evaluate(button => button === document.activeElement), 'Shift+Tab não voltou a Cancelar');
            await assertLayout(scenario.label);
            await page.keyboard.press('Escape');
            assert(await modal.isHidden() && posts.length === 0, 'Escape enviou formulário');
            assert(await trigger.evaluate(button => button === document.activeElement), 'Foco não voltou ao botão');
            await trigger.click();
            await cancelButton.click();
            assert(posts.length === 0, 'Cancelar enviou formulário');
            await trigger.click();
            await Promise.all([page.waitForNavigation(), confirmButton.click()]);
            const posted = posts.pop();
            assert(posted?.get('structure_action') === expectedAction && posted.get('structure_type') === 'subcategory', 'Nome/valor do botão de estrutura perdido');
            assert(posted.get('csrf_token') && posted.get('structure_id'), 'Campos de segurança/contexto perdidos');
        }

        await go('/admin/index.php?tab=editar_estrutura&view=discarded');
        const permanentForm = page.locator('form[data-delete-name]').first();
        if (await permanentForm.count()) {
            await permanentForm.locator('button[type="submit"]').click();
            assert(await confirmButton.isDisabled(), 'Exclusão real liberada sem digitar o nome');
            await cancelButton.click();
            console.log('PASS integração do formulário real de exclusão permanente');
        }
        // Fixture verifica nome com aspas/HTML, sem criar recursos no banco.
        const name = `Subcategoria "D'água" & <em>Orientações</em>`;
        await page.evaluate(name => {
            const form = document.createElement('form');
            form.id = 'dialog-test-permanent'; form.method = 'post'; form.action = 'index.php';
            form.dataset.deleteName = name;
            form.dataset.deleteSummary = '2 assuntos e 5 documentos';
            form.innerHTML = '<input name="confirmation_name" type="hidden"><input name="csrf_token" type="hidden" value="fixture"><button name="structure_delete_action" value="permanent_delete">Excluir fixture</button>';
            document.body.append(form);
        }, name);
        await page.locator('#dialog-test-permanent button').click();
        const input = modal.locator('input');
        assert(await input.evaluate(element => element === document.activeElement), 'Campo de nome não recebeu foco');
        assert(await confirmButton.isDisabled(), 'Exclusão liberada com nome vazio');
        await input.fill('nome incorreto');
        assert(await confirmButton.isDisabled() && await input.getAttribute('aria-invalid') === 'true', 'Nome incorreto foi aceito');
        assert(await modal.locator('.dg-dialog__expected').innerText() === name, 'Nome foi interpretado como HTML');
        assert(await modal.locator('.dg-dialog__expected em').count() === 0, 'HTML injetado no diálogo');
        await assertLayout('permanent-mobile-dark');
        await input.fill(' ' + name + ' ');
        assert(await confirmButton.isEnabled(), 'Nome correto não liberou a exclusão');
        await Promise.all([page.waitForNavigation(), page.keyboard.press('Enter')]);
        const permanentPost = posts.pop();
        assert(permanentPost?.get('confirmation_name') === name && permanentPost.get('structure_delete_action') === 'permanent_delete', 'Confirmação digitada ou botão perdidos');
        console.log('PASS exclusão permanente: nome exato, escape de HTML, Enter e payload');

        // Menus reposicionados no body precisam manter o botão associado ao formulário.
        await go('/admin/index.php?tab=documentos&filter_status=all');
        const menuTrigger = page.locator('[data-action-menu-trigger]').first();
        if (await menuTrigger.count()) {
            const documentId = (await menuTrigger.getAttribute('aria-controls')).replace('action-menu-', '');
            await menuTrigger.click();
            await page.locator('#action-menu-' + documentId + ' button[name="document_trash_action"]').click();
            assert(await modal.isVisible(), 'Confirmação no menu de documentos não abriu');
            await Promise.all([page.waitForNavigation(), confirmButton.click()]);
            const documentPost = posts.pop();
            assert(documentPost?.get('document_trash_action') === 'trash' && documentPost.get('document_id') === documentId, 'Menu perdeu botão/ID do documento');
            console.log('PASS lixeira de documento com botão fora do formulário');
        }
        await go('/admin/index.php?tab=documentos&filter_status=all');
        const selection = page.locator('.batch-checkbox').first();
        if (await selection.count()) {
            await selection.check();
            const batchButton = page.locator('[data-batch-action="trash"]');
            await batchButton.click();
            await Promise.all([page.waitForNavigation(), confirmButton.click()]);
            assert(posts.pop()?.get('batch_action') === 'trash', 'Ação de lote não preservada');
            console.log('PASS confirmação de ação em lote');
        }

        for (const route of ['/index.php', '/favoritos.php']) {
            await go(route);
            await page.evaluate(() => { window.dialogResult = null; window.DocGovDialog.alert({ title: 'Não foi possível atualizar o favorito', message: 'Tente novamente em instantes.' }).then(result => window.dialogResult = result); });
            assert(await cancelButton.isHidden(), 'Aviso simples tem Cancelar');
            assert(await confirmButton.evaluate(element => element === document.activeElement), 'Aviso não recebeu foco');
            await assertLayout(route.includes('favoritos') ? 'favorite-alert-mobile' : 'portal-alert-mobile');
            await confirmButton.click();
            assert(await page.evaluate(() => window.dialogResult === true), 'Aviso não resolveu');
        }
        await page.evaluate(() => {
            window.dialogQueue = [];
            window.DocGovDialog.confirm('Primeira confirmação').then(value => window.dialogQueue.push(value));
            window.DocGovDialog.alert('Segundo aviso').then(value => window.dialogQueue.push(value));
        });
        await cancelButton.click();
        assert(await modal.locator('.dg-dialog__message').innerText() === 'Segundo aviso', 'Fila de diálogos não avançou');
        await confirmButton.click();
        assert(await page.evaluate(() => JSON.stringify(window.dialogQueue) === '[false,true]'), 'Retornos da fila incorretos');
        assert(await page.evaluate(() => document.body.style.overflow !== 'hidden'), 'Rolagem permaneceu bloqueada');
        assert(nativeDialogs.length === 0, `Diálogos nativos encontrados: ${nativeDialogs}`);
        assert(errors.length === 0, `Erros JavaScript: ${errors.join(' | ')}`);
        console.log('PASS fila, foco, rolagem e ausência de diálogos nativos/erros JavaScript');
    } finally {
        await browser.close();
        runPhp(`session_id('${session}'); session_start(); session_destroy();`);
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
