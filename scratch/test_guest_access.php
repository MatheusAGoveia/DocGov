<?php
declare(strict_types=1);

/**
 * Regressão do acesso de visitantes (sem conta).
 *
 * Executa tudo em uma transação revertida na base local de desenvolvimento:
 *   php scratch/test_guest_access.php
 */
require_once __DIR__ . '/_cli_only.php';
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('DOCGOV_SKIP_APP_RUNTIME', true);
require __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../services/PermissionService.php';
require_once __DIR__ . '/../services/GuestAccessService.php';

if ($appEnvironment !== 'development' || !in_array($dbHost, ['127.0.0.1', 'localhost', '::1'], true)) {
    throw new RuntimeException('Este teste exige o banco local de desenvolvimento.');
}

function guestAssert(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException('FALHA: ' . $message);
    echo "  [OK] {$message}\n";
}

function guestFixture(PDO $pdo): array {
    $token = bin2hex(random_bytes(8));
    $actor = (int)$pdo->query("SELECT id FROM users WHERE role = 'admin' AND active = TRUE ORDER BY id LIMIT 1")->fetchColumn();
    if ($actor <= 0) throw new RuntimeException('Administrador de teste indisponível.');
    $insertCategory = $pdo->prepare('INSERT INTO categories (name, slug) VALUES (?, ?) RETURNING id');
    $insertCategory->execute(['Visitante teste ' . $token, 'guest-test-' . $token]);
    $category = (int)$insertCategory->fetchColumn();
    $insertCategory->execute(['Visitante privada teste ' . $token, 'guest-private-' . $token]);
    $privateCategory = (int)$insertCategory->fetchColumn();

    $insertSub = $pdo->prepare('INSERT INTO subcategories (category_id, name, slug) VALUES (?, ?, ?) RETURNING id');
    $insertSub->execute([$category, 'Sub pública ' . $token, 'guest-sub-public-' . $token]);
    $publicSub = (int)$insertSub->fetchColumn();
    $insertSub->execute([$category, 'Sub privada ' . $token, 'guest-sub-private-' . $token]);
    $privateSub = (int)$insertSub->fetchColumn();
    $insertSub->execute([$privateCategory, 'Sub isolada ' . $token, 'guest-sub-isolated-' . $token]);
    $isolatedSub = (int)$insertSub->fetchColumn();

    $insertSubject = $pdo->prepare('INSERT INTO subjects (subcategory_id, name, slug, visibility) VALUES (?, ?, ?, ?) RETURNING id');
    $insertSubject->execute([$publicSub, 'Assunto público ' . $token, 'guest-subject-public-' . $token, 'public']);
    $publicSubject = (int)$insertSubject->fetchColumn();
    $insertSubject->execute([$publicSub, 'Assunto irmão ' . $token, 'guest-subject-sibling-' . $token, 'private']);
    $siblingSubject = (int)$insertSubject->fetchColumn();
    $insertSubject->execute([$privateSub, 'Assunto privado ' . $token, 'guest-subject-private-' . $token, 'private']);
    $privateSubject = (int)$insertSubject->fetchColumn();
    $insertSubject->execute([$isolatedSub, 'Assunto isolado ' . $token, 'guest-subject-isolated-' . $token, 'private']);
    $isolatedSubject = (int)$insertSubject->fetchColumn();

    $insertDocument = $pdo->prepare("INSERT INTO documents (subject_id, created_by, title, slug, content_type, section_key, status, text_content) VALUES (?, ?, ?, ?, 'text', 'documents', ?, '<p>Teste</p>') RETURNING id");
    $documents = [];
    foreach ([
        'public' => [$publicSubject, 'published'],
        'draft' => [$publicSubject, 'draft'],
        'review' => [$publicSubject, 'review'],
        'inactive' => [$publicSubject, 'inactive'],
        'sibling' => [$siblingSubject, 'published'],
        'private' => [$privateSubject, 'published'],
        'isolated' => [$isolatedSubject, 'published'],
    ] as $key => [$subjectId, $status]) {
        $insertDocument->execute([$subjectId, $actor, 'Documento ' . $key . ' ' . $token, 'guest-doc-' . $key . '-' . $token, $status]);
        $documents[$key] = (int)$insertDocument->fetchColumn();
    }

    $insertUser = $pdo->prepare("INSERT INTO users (name, username, email, role, active) VALUES (?, ?, ?, 'reader', FALSE) RETURNING id");
    $insertUser->execute(['Inativo visitante ' . $token, 'guest-inactive-' . $token, 'guest-inactive-' . $token . '@example.invalid']);
    $inactiveUser = (int)$insertUser->fetchColumn();

    return compact('token', 'actor', 'category', 'privateCategory', 'publicSub', 'privateSub', 'isolatedSub',
        'publicSubject', 'siblingSubject', 'privateSubject', 'isolatedSubject', 'documents', 'inactiveUser');
}

echo "Acesso de visitantes (PermissionService)\n";
$pdo->beginTransaction();
try {
    $f = guestFixture($pdo);
    $pdo->exec("DELETE FROM system_settings WHERE setting_key = 'guest_access_enabled'");
    $guest = new PermissionService($pdo);

    guestAssert($guest->canViewDocument(0, $f['documents']['public']), 'visitante lê documento publicado de assunto público');
    guestAssert($guest->canViewDocument(null, $f['documents']['public']), 'visitante identificado como null também lê conteúdo público');
    foreach (['draft', 'review', 'inactive'] as $key) {
        guestAssert(!$guest->canViewDocument(0, $f['documents'][$key]), "visitante não lê documento em estado {$key}");
    }
    foreach (['sibling', 'private', 'isolated'] as $key) {
        guestAssert(!$guest->canViewDocument(0, $f['documents'][$key]), "visitante não lê documento de assunto privado ({$key})");
    }
    guestAssert($guest->canViewSubject(0, $f['publicSubject']), 'visitante abre o assunto público');
    guestAssert(!$guest->canViewSubject(0, $f['siblingSubject']), 'assunto privado irmão continua fechado');
    guestAssert($guest->canViewCategory(0, $f['category']), 'categoria pai fica navegável');
    guestAssert(!$guest->canView(0, 'category', $f['category']), 'categoria pai não recebe permissão herdável');
    guestAssert($guest->canViewSubcategory(0, $f['publicSub']), 'subcategoria do ramo público fica navegável');
    guestAssert(!$guest->canViewSubcategory(0, $f['privateSub']), 'subcategoria sem assunto público não aparece');
    guestAssert(!$guest->canViewCategory(0, $f['privateCategory']), 'categoria totalmente privada não aparece');

    $allowedSubjects = $guest->getAllowedSubjectIds(0);
    guestAssert(in_array($f['publicSubject'], $allowedSubjects, true)
        && !in_array($f['siblingSubject'], $allowedSubjects, true)
        && !in_array($f['privateSubject'], $allowedSubjects, true)
        && !in_array($f['isolatedSubject'], $allowedSubjects, true), 'árvore do visitante contém apenas assuntos públicos');

    $effective = $guest->getEffectiveDocumentPermission(0, $f['documents']['public']);
    guestAssert($effective['effective_level'] === 'view' && ($effective['sources'][0]['principal_type'] ?? '') === 'guest', 'fonte do acesso é explicada como visitante');

    guestAssert(!$guest->canEdit(0, 'subject', $f['publicSubject'])
        && !$guest->canEditDocument(0, $f['documents']['public'])
        && !$guest->canCreateDocument(0, $f['publicSubject'])
        && !$guest->canAdminSubject(0, $f['publicSubject'])
        && !$guest->canAccessAdminPanel(0)
        && !$guest->isGlobalAdmin(0), 'visitante não recebe escrita nem administração');

    guestAssert(!$guest->canViewDocument($f['inactiveUser'], $f['documents']['public']), 'conta inativa continua sem acesso');

    foreach (['categories' => $f['category'], 'subcategories' => $f['publicSub'], 'subjects' => $f['publicSubject']] as $table => $id) {
        $pdo->prepare("UPDATE {$table} SET active = FALSE WHERE id = ?")->execute([$id]);
        guestAssert(!(new PermissionService($pdo))->canViewDocument(0, $f['documents']['public']), "ancestral inativo ({$table}) fecha o acesso");
        $pdo->prepare("UPDATE {$table} SET active = TRUE WHERE id = ?")->execute([$id]);
    }

    $pdo->prepare("UPDATE subjects SET visibility = 'private' WHERE id = ?")->execute([$f['publicSubject']]);
    guestAssert(!(new PermissionService($pdo))->canViewDocument(0, $f['documents']['public']), 'tornar o assunto privado revoga o acesso imediatamente');
    $pdo->prepare("UPDATE subjects SET visibility = 'public' WHERE id = ?")->execute([$f['publicSubject']]);

    $disabledByArgument = new PermissionService($pdo, false);
    guestAssert(!$disabledByArgument->canViewDocument(0, $f['documents']['public']) && $disabledByArgument->getAllowedSubjectIds(0) === [], 'motor com leitura anônima desligada não libera nada');

    $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value, updated_by) VALUES ('guest_access_enabled', 'false'::jsonb, ?)")->execute([$f['actor']]);
    $disabledBySetting = new PermissionService($pdo);
    guestAssert(!$disabledBySetting->canViewDocument(0, $f['documents']['public']), 'configuração desligada bloqueia visitantes');
    guestAssert(!GuestAccessService::isEnabled(['guest_access_enabled' => false]), 'serviço de visitante respeita a configuração');
    $forcedForApi = new PermissionService($pdo, true);
    guestAssert($forcedForApi->canViewDocument(0, $f['documents']['public']), 'API pública pode ler mesmo com visitantes desligados');

    echo "\nRedirecionamento seguro após a entrada\n";
    guestAssert(GuestAccessService::safeNextPath('index.php?cat=rh&subcat=ferias') === 'index.php?cat=rh&subcat=ferias', 'aceita página do portal com parâmetros');
    guestAssert(GuestAccessService::safeNextPath('ver_conteudo.php?id=12') === 'ver_conteudo.php?id=12', 'aceita visualizador de documento');
    guestAssert(GuestAccessService::safeNextPath('favoritos.php', false) === 'favoritos.php', 'login com conta pode voltar aos favoritos');
    guestAssert(GuestAccessService::safeNextPath('favoritos.php') === 'index.php', 'visitante não é enviado a páginas de conta');
    guestAssert(GuestAccessService::safeNextPath('admin/index.php?tab=documentos', false) === 'index.php'
        && GuestAccessService::safeNextPath('admin/index.php') === 'index.php', 'entrada nunca termina direto no painel');
    foreach (['https://exemplo.invalid/', '//exemplo.invalid', '/\\exemplo.invalid', 'javascript:alert(1)', "index.php\r\nLocation: x", '../index.php', 'index.php/../../x', '', null] as $unsafe) {
        guestAssert(GuestAccessService::safeNextPath($unsafe) === 'index.php', 'recusa destino inseguro ' . json_encode($unsafe));
    }

    echo "\nPASS acesso de visitantes.\n";
} finally {
    if ($pdo->inTransaction()) $pdo->rollBack();
}
