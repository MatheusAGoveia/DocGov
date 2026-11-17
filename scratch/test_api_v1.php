<?php
declare(strict_types=1);

/**
 * Regressão HTTP da API de integração (api/v1) e da entrada de visitantes.
 *
 * Cria fixtures com sufixo exclusivo na base local de desenvolvimento, inicia
 * um servidor PHP embutido em porta livre e remove dados, arquivos, sessões e
 * o processo ao terminar (inclusive em falha):
 *   php scratch/test_api_v1.php
 */
require_once __DIR__ . '/_cli_only.php';
define('DOCGOV_SKIP_APP_RUNTIME', true);
require __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../services/ApiClientService.php';
require_once __DIR__ . '/../services/CsrfService.php';

if ($appEnvironment !== 'development' || !in_array($dbHost, ['127.0.0.1', 'localhost', '::1'], true)) {
    throw new RuntimeException('Este teste exige o banco local de desenvolvimento.');
}

const API_TEST_ROOT = __DIR__ . '/..';

$failures = 0;
function apiAssert(bool $condition, string $message, string $detail = ''): void {
    global $failures;
    if ($condition) {
        echo "  [OK] {$message}\n";
        return;
    }
    $failures++;
    echo "  [FALHA] {$message}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
}

/** @return array{status:int, headers:array<string,string>, body:string, json:mixed} */
function apiHttp(string $method, string $url, array $headers = [], ?string $body = null, ?string $cookieJar = null): array {
    $responseHeaders = [];
    $curl = curl_init($url);
    curl_setopt_array($curl, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_NOBODY => $method === 'HEAD',
        CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$responseHeaders): int {
            $parts = explode(':', $line, 2);
            if (count($parts) === 2) {
                $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
            }
            return strlen($line);
        },
    ]);
    if ($body !== null) {
        curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
    }
    if ($cookieJar !== null) {
        curl_setopt($curl, CURLOPT_COOKIEFILE, $cookieJar);
        curl_setopt($curl, CURLOPT_COOKIEJAR, $cookieJar);
    }
    $responseBody = curl_exec($curl);
    if ($responseBody === false) {
        throw new RuntimeException('Falha HTTP: ' . curl_error($curl));
    }
    $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    curl_close($curl);
    return ['status' => $status, 'headers' => $responseHeaders, 'body' => (string)$responseBody, 'json' => json_decode((string)$responseBody, true)];
}

/** Sessão PHP gravada no mesmo diretório do servidor de teste. @return array{id:string, csrf:string} */
function apiSession(string $sessionDir, PDO $pdo, int $userId, bool $adminLogged): array {
    $stmt = $pdo->prepare('SELECT id, name, username, email, role FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    ini_set('session.save_path', $sessionDir);
    session_id(bin2hex(random_bytes(16)));
    session_start();
    $_SESSION = [
        'user' => ['id' => (int)$user['id'], 'nome' => $user['name'], 'login' => $user['username'], 'email' => $user['email'], 'role' => $user['role'], 'active' => true, 'inicial' => 'T'],
        'admin_logged' => $adminLogged,
        'last_activity_at' => time(),
    ];
    $session = ['id' => session_id(), 'csrf' => CsrfService::token()];
    session_write_close();
    return $session;
}

function apiFreePort(): int {
    $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    if ($server === false) throw new RuntimeException("Porta livre indisponível: {$error}");
    $port = (int)substr(strrchr(stream_socket_get_name($server, false), ':'), 1);
    fclose($server);
    return $port;
}

function apiFixture(PDO $pdo, string $token, string $pngBytes): array {
    $actor = (int)$pdo->query("SELECT id FROM users WHERE role = 'admin' AND active = TRUE ORDER BY id LIMIT 1")->fetchColumn();
    if ($actor <= 0) throw new RuntimeException('Administrador de teste indisponível.');
    $pdo->beginTransaction();
    try {
        $category = $pdo->prepare('INSERT INTO categories (name, slug) VALUES (?, ?) RETURNING id');
        $category->execute(['API teste ' . $token, 'api-test-' . $token]);
        $categoryId = (int)$category->fetchColumn();
        $sub = $pdo->prepare('INSERT INTO subcategories (category_id, name, slug) VALUES (?, ?, ?) RETURNING id');
        $sub->execute([$categoryId, 'API sub ' . $token, 'api-sub-' . $token]);
        $subId = (int)$sub->fetchColumn();
        $subject = $pdo->prepare('INSERT INTO subjects (subcategory_id, name, slug, visibility) VALUES (?, ?, ?, ?) RETURNING id');
        $subject->execute([$subId, 'API público ' . $token, 'api-public-' . $token, 'public']);
        $publicSubject = (int)$subject->fetchColumn();
        $subject->execute([$subId, 'API privado ' . $token, 'api-private-' . $token, 'private']);
        $privateSubject = (int)$subject->fetchColumn();

        $user = $pdo->prepare("INSERT INTO users (name, username, email, role, active) VALUES (?, ?, ?, 'reader', ?) RETURNING id");
        $user->execute(['Leitor API ' . $token, 'api-reader-' . $token, 'api-reader-' . $token . '@example.invalid', 'true']);
        $reader = (int)$user->fetchColumn();
        $user->execute(['Leitor inativo API ' . $token, 'api-inactive-' . $token, 'api-inactive-' . $token . '@example.invalid', 'true']);
        $inactiveReader = (int)$user->fetchColumn();
        $grant = $pdo->prepare("INSERT INTO permissions (user_id, subject_id, permission_level, created_by) VALUES (?, ?, 'view', ?)");
        $grant->execute([$reader, $privateSubject, $actor]);
        $grant->execute([$inactiveReader, $privateSubject, $actor]);

        $doc = $pdo->prepare("INSERT INTO documents (subject_id, created_by, title, slug, description, content_type, section_key, status, text_content, published_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP) RETURNING id");
        $documents = [];
        foreach ([
            'public_text' => [$publicSubject, 'text', 'documents', 'published', '<p>Olá <script>alert(1)</script><b>público</b></p>'],
            'public_file' => [$publicSubject, 'file', 'attachments', 'published', null],
            'public_draft' => [$publicSubject, 'text', 'documents', 'draft', '<p>Rascunho</p>'],
            'private_text' => [$privateSubject, 'text', 'documents', 'published', '<p>Privado</p>'],
            'private_file' => [$privateSubject, 'file', 'attachments', 'published', null],
        ] as $key => [$subjectId, $type, $section, $status, $text]) {
            $doc->execute([$subjectId, $actor, 'Documento ' . $key . ' ' . $token, 'api-' . str_replace('_', '-', $key) . '-' . $token, 'Resumo ' . $key, $type, $section, $status, $text]);
            $documents[$key] = (int)$doc->fetchColumn();
        }
        $storedName = 'apitest_' . $token . '.png';
        if (file_put_contents(API_TEST_ROOT . '/storage/documents/' . $storedName, $pngBytes) === false) {
            throw new RuntimeException('Falha ao criar arquivo de fixture.');
        }
        $pdo->prepare("UPDATE documents SET stored_filename = ?, file_path = ?, original_filename = 'relatório anual.png', mime_type = 'image/png', file_extension = 'png', file_size = ? WHERE id IN (?, ?)")
            ->execute([$storedName, 'storage/documents/' . $storedName, strlen($pngBytes), $documents['public_file'], $documents['private_file']]);
        // Processo do assunto público em rascunho, nunca aprovado.
        $pdo->prepare("INSERT INTO subject_workspaces (subject_id, objective, documentation_status) VALUES (?, ?, 'draft')")
            ->execute([$publicSubject, 'Rascunho do processo ' . $token]);
        // Uma tag usada só em documento privado e outra em documento público.
        $tag = $pdo->prepare('INSERT INTO tags (name, normalized_name, created_by) VALUES (?, ?, ?) RETURNING id');
        $link = $pdo->prepare('INSERT INTO document_tags (document_id, tag_id) VALUES (?, ?)');
        foreach (['privada' => 'private_text', 'publica' => 'public_text'] as $label => $docKey) {
            $tag->execute(['Tag ' . $label . ' ' . $token, 'tag-' . $label . '-' . $token, $actor]);
            $link->execute([$documents[$docKey], (int)$tag->fetchColumn()]);
        }
        $pdo->commit();
    } catch (Throwable $error) {
        $pdo->rollBack();
        throw $error;
    }

    $service = new ApiClientService($pdo);
    $clients = [];
    $clients['public'] = $service->create(['name' => 'API teste público ' . $token], $actor);
    $clients['reader'] = $service->create(['name' => 'API teste leitor ' . $token, 'access_scope' => 'user', 'username' => 'api-reader-' . $token], $actor);
    $clients['revoked'] = $service->create(['name' => 'API teste revogada ' . $token], $actor);
    $service->revoke($clients['revoked']['client']['id'], $actor);
    $clients['expired'] = $service->create(['name' => 'API teste expirada ' . $token], $actor);
    $pdo->prepare("UPDATE api_clients SET expires_at = CURRENT_TIMESTAMP - INTERVAL '1 minute' WHERE id = ?")->execute([$clients['expired']['client']['id']]);
    $clients['inactive_user'] = $service->create(['name' => 'API teste conta inativa ' . $token, 'access_scope' => 'user', 'username' => 'api-inactive-' . $token], $actor);
    $pdo->prepare('UPDATE users SET active = FALSE WHERE id = ?')->execute([$inactiveReader]);
    $clients['limited'] = $service->create(['name' => 'API teste limite ' . $token], $actor);
    $pdo->prepare('UPDATE api_clients SET rate_limit_per_minute = 3 WHERE id = ?')->execute([$clients['limited']['client']['id']]);

    return compact('actor', 'categoryId', 'subId', 'publicSubject', 'privateSubject', 'reader', 'inactiveReader', 'documents', 'storedName', 'clients');
}

function apiCleanup(PDO $pdo, string $token): void {
    if (!preg_match('/^[a-f0-9]{16}$/', $token)) throw new RuntimeException('Token de fixture inválido.');
    $pdo->prepare('DELETE FROM api_clients WHERE name LIKE ?')->execute(['API teste % ' . $token]);
    $categoryIds = $pdo->prepare('SELECT id FROM categories WHERE slug = ?');
    $categoryIds->execute(['api-test-' . $token]);
    foreach ($categoryIds->fetchAll(PDO::FETCH_COLUMN) as $categoryId) {
        $pdo->prepare('DELETE FROM usage_audit_events WHERE resource_type = \'DOCUMENT\' AND resource_id IN (SELECT d.id FROM documents d JOIN subjects s ON s.id = d.subject_id JOIN subcategories sc ON sc.id = s.subcategory_id WHERE sc.category_id = ?)')->execute([$categoryId]);
        $pdo->prepare('DELETE FROM document_tags WHERE document_id IN (SELECT d.id FROM documents d JOIN subjects s ON s.id = d.subject_id JOIN subcategories sc ON sc.id = s.subcategory_id WHERE sc.category_id = ?)')->execute([$categoryId]);
        $pdo->prepare('DELETE FROM subject_workspace_history WHERE subject_id IN (SELECT s.id FROM subjects s JOIN subcategories sc ON sc.id = s.subcategory_id WHERE sc.category_id = ?)')->execute([$categoryId]);
        $pdo->prepare('DELETE FROM subject_workspaces WHERE subject_id IN (SELECT s.id FROM subjects s JOIN subcategories sc ON sc.id = s.subcategory_id WHERE sc.category_id = ?)')->execute([$categoryId]);
        $pdo->prepare('DELETE FROM documents WHERE subject_id IN (SELECT s.id FROM subjects s JOIN subcategories sc ON sc.id = s.subcategory_id WHERE sc.category_id = ?)')->execute([$categoryId]);
        $pdo->prepare('DELETE FROM permissions WHERE subject_id IN (SELECT s.id FROM subjects s JOIN subcategories sc ON sc.id = s.subcategory_id WHERE sc.category_id = ?)')->execute([$categoryId]);
        $pdo->prepare('DELETE FROM subjects WHERE subcategory_id IN (SELECT id FROM subcategories WHERE category_id = ?)')->execute([$categoryId]);
        $pdo->prepare('DELETE FROM subcategories WHERE category_id = ?')->execute([$categoryId]);
        $pdo->prepare('DELETE FROM categories WHERE id = ?')->execute([$categoryId]);
    }
    $pdo->prepare('DELETE FROM tags WHERE normalized_name IN (?, ?)')->execute(['tag-privada-' . $token, 'tag-publica-' . $token]);
    $pdo->prepare('DELETE FROM users WHERE username IN (?, ?)')->execute(['api-reader-' . $token, 'api-inactive-' . $token]);
    $file = API_TEST_ROOT . '/storage/documents/apitest_' . $token . '.png';
    if (is_file($file)) unlink($file);
}

$token = bin2hex(random_bytes(8));
$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aL1cAAAAASUVORK5CYII=', true);
$sessionDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'docgov-api-test-' . $token;
$cookieJar = $sessionDir . DIRECTORY_SEPARATOR . 'cookies.txt';
$serverLog = $sessionDir . DIRECTORY_SEPARATOR . 'server.log';
$server = null;
$previousGuestSetting = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'guest_access_enabled'")->fetchColumn();

try {
    mkdir($sessionDir, 0700, true);
    $f = apiFixture($pdo, $token, $png);
    // Sessões CLI precisam ser criadas antes de qualquer saída do teste.
    $adminSession = apiSession($sessionDir, $pdo, $f['actor'], true);
    $readerSession = apiSession($sessionDir, $pdo, $f['reader'], false);
    $port = apiFreePort();
    $base = "http://127.0.0.1:{$port}";
    // Aspas: caminhos curtos do Windows (MATHEU~1) contêm "~", operador no parser de INI.
    $server = proc_open([PHP_BINARY, '-d', 'session.save_path="' . $sessionDir . '"', '-d', 'display_errors=Off', '-d', 'log_errors=On', '-S', "127.0.0.1:{$port}", '-t', realpath(API_TEST_ROOT)],
        [0 => ['pipe', 'r'], 1 => ['file', $serverLog, 'a'], 2 => ['file', $serverLog, 'a']], $pipes);
    for ($attempt = 0; $attempt < 50; $attempt++) {
        $probe = @fsockopen('127.0.0.1', $port, $errno, $error, 0.2);
        if ($probe) { fclose($probe); break; }
        usleep(100000);
    }

    $bearer = static fn(string $key): array => ['Authorization: Bearer ' . $f['clients'][$key]['token']];
    $api = "{$base}/api/v1";

    echo "Autenticação\n";
    $response = apiHttp('GET', "{$api}/status.php");
    apiAssert($response['status'] === 401 && ($response['json']['code'] ?? '') === 'unauthorized' && str_contains($response['headers']['www-authenticate'] ?? '', 'Bearer'), 'sem chave responde 401 com WWW-Authenticate');
    $forged = substr($f['clients']['public']['token'], 0, -4) . '0000';
    apiAssert(apiHttp('GET', "{$api}/status.php", ['Authorization: Bearer ' . $forged])['status'] === 401, 'segredo alterado é recusado');
    apiAssert(apiHttp('GET', "{$api}/status.php?api_key=" . $f['clients']['public']['token'])['status'] === 401, 'chave na URL não é aceita');
    foreach (['revoked' => 'revogada', 'expired' => 'expirada', 'inactive_user' => 'vinculada a conta inativa'] as $key => $label) {
        apiAssert(apiHttp('GET', "{$api}/status.php", $bearer($key))['status'] === 401, "chave {$label} é recusada");
    }
    $response = apiHttp('GET', "{$api}/status.php", $bearer('public'));
    apiAssert($response['status'] === 200 && ($response['json']['data']['client']['access_scope'] ?? '') === 'public', 'chave pública autentica via Bearer');
    apiAssert(!isset($response['headers']['set-cookie']), 'API não cria sessão nem cookie');
    apiAssert(apiHttp('GET', "{$api}/status.php", ['X-API-Key: ' . $f['clients']['reader']['token']])['status'] === 200, 'cabeçalho X-API-Key também autentica');
    $stored = $pdo->prepare('SELECT token_hash, last_used_at FROM api_clients WHERE id = ?');
    $stored->execute([$f['clients']['public']['client']['id']]);
    $storedRow = $stored->fetch(PDO::FETCH_ASSOC);
    apiAssert($storedRow['token_hash'] === hash('sha256', $f['clients']['public']['token']) && !str_contains(json_encode($storedRow), $f['clients']['public']['token']), 'banco guarda só o hash do token');
    apiAssert($storedRow['last_used_at'] !== null, 'último uso é registrado');
    apiAssert(apiHttp('POST', "{$api}/documents.php", $bearer('public'), 'title=x')['status'] === 405, 'métodos de escrita respondem 405');
    apiAssert(apiHttp('GET', "{$api}/bootstrap.php", $bearer('public'))['status'] === 404, 'bootstrap interno não é acessível diretamente');

    echo "\nEscopo público (visitante)\n";
    $response = apiHttp('GET', "{$api}/tree.php", $bearer('public'));
    $treeJson = json_encode($response['json']);
    apiAssert($response['status'] === 200 && str_contains($treeJson, 'api-public-' . $token) && !str_contains($treeJson, 'api-private-' . $token), 'árvore pública mostra só o assunto público');
    $response = apiHttp('GET', "{$api}/documents.php?category_id={$f['categoryId']}&per_page=50", $bearer('public'));
    $ids = array_column($response['json']['data'] ?? [], 'id');
    sort($ids);
    $expectedPublic = [$f['documents']['public_text'], $f['documents']['public_file']];
    sort($expectedPublic);
    apiAssert($response['status'] === 200 && $ids === $expectedPublic, 'lista pública traz apenas publicados de assunto público', json_encode($ids));
    apiAssert(($response['json']['meta']['total'] ?? null) === 2, 'meta.total confere');
    apiAssert(apiHttp('GET', "{$api}/documents.php?subject_id={$f['privateSubject']}", $bearer('public'))['status'] === 404, 'filtro por assunto privado responde 404');
    foreach (['private_text', 'public_draft'] as $key) {
        apiAssert(apiHttp('GET', "{$api}/documents.php?id={$f['documents'][$key]}", $bearer('public'))['status'] === 404, "detalhe de {$key} responde 404");
    }
    $response = apiHttp('GET', "{$api}/documents.php?id={$f['documents']['public_text']}", $bearer('public'));
    $html = (string)($response['json']['data']['content']['html'] ?? '');
    apiAssert($response['status'] === 200 && ($response['json']['data']['content']['format'] ?? '') === 'html' && str_contains($html, '<b>público</b>') && !str_contains($html, '<script'), 'detalhe devolve HTML sanitizado');
    $response = apiHttp('GET', "{$api}/documents.php?id={$f['documents']['public_file']}", $bearer('public'));
    apiAssert(($response['json']['data']['links']['file'] ?? '') === 'document-file.php?id=' . $f['documents']['public_file'] && ($response['json']['data']['file']['size_bytes'] ?? 0) === strlen($png), 'detalhe de arquivo traz metadados e link de download');
    $response = apiHttp('GET', "{$api}/document-file.php?id={$f['documents']['public_file']}", $bearer('public'));
    apiAssert($response['status'] === 200 && $response['body'] === $png && str_starts_with($response['headers']['content-disposition'] ?? '', 'attachment;'), 'download público entrega o arquivo como anexo');
    apiAssert(apiHttp('GET', "{$api}/document-file.php?id={$f['documents']['private_file']}", $bearer('public'))['status'] === 404, 'download privado responde 404');
    apiAssert(apiHttp('GET', "{$api}/document-file.php?id={$f['documents']['public_text']}", $bearer('public'))['status'] === 404, 'documento sem arquivo responde 404');

    echo "\nEscopo de usuário\n";
    $response = apiHttp('GET', "{$api}/documents.php?category_id={$f['categoryId']}&per_page=50", $bearer('reader'));
    $ids = array_column($response['json']['data'] ?? [], 'id');
    apiAssert(in_array($f['documents']['private_text'], $ids, true) && in_array($f['documents']['public_text'], $ids, true) && !in_array($f['documents']['public_draft'], $ids, true), 'chave vinculada lê o privado concedido e nunca rascunhos', json_encode($ids));
    apiAssert(apiHttp('GET', "{$api}/document-file.php?id={$f['documents']['private_file']}", $bearer('reader'))['status'] === 200, 'chave vinculada baixa arquivo privado concedido');

    $pdo->prepare("UPDATE users SET role = 'admin' WHERE id = ?")->execute([$f['reader']]);
    apiAssert(apiHttp('GET', "{$api}/status.php", $bearer('reader'))['status'] === 401, 'chave perde acesso se a conta vinculada vira administradora global');
    $pdo->prepare("UPDATE users SET role = 'reader' WHERE id = ?")->execute([$f['reader']]);
    $adminUsername = (string)$pdo->query('SELECT username FROM users WHERE id = ' . (int)$f['actor'])->fetchColumn();
    try {
        (new ApiClientService($pdo))->create(['name' => 'API teste admin ' . $token, 'access_scope' => 'user', 'username' => $adminUsername], $f['actor']);
        apiAssert(false, 'criação de chave vinculada a administrador global é recusada');
    } catch (InvalidArgumentException) {
        apiAssert(true, 'criação de chave vinculada a administrador global é recusada');
    }

    echo "\nParâmetros e paginação\n";
    $response = apiHttp('GET', "{$api}/documents.php?category_id={$f['categoryId']}&per_page=1&page=2", $bearer('reader'));
    apiAssert(count($response['json']['data'] ?? []) === 1 && ($response['json']['meta']['total_pages'] ?? 0) === 4, 'paginação respeita per_page e total_pages', json_encode($response['json']['meta'] ?? []));
    apiAssert(apiHttp('GET', "{$api}/documents.php?per_page=500", $bearer('public'))['status'] === 422, 'per_page acima do limite responde 422');
    apiAssert(apiHttp('GET', "{$api}/documents.php?content_type=exe", $bearer('public'))['status'] === 422, 'content_type desconhecido responde 422');
    apiAssert(apiHttp('GET', "{$api}/documents.php?updated_since=ontem", $bearer('public'))['status'] === 422, 'updated_since inválido responde 422');
    $response = apiHttp('GET', "{$api}/documents.php?category_id={$f['categoryId']}&q=" . rawurlencode('public_text'), $bearer('public'));
    apiAssert(array_column($response['json']['data'] ?? [], 'id') === [$f['documents']['public_text']], 'busca textual filtra por título');
    $response = apiHttp('GET', "{$api}/documents.php?category_id={$f['categoryId']}&q=" . rawurlencode('%'), $bearer('public'));
    apiAssert(($response['json']['meta']['total'] ?? -1) === 0, 'curinga % na busca é tratado como texto');
    $response = apiHttp('GET', "{$api}/documents.php?category_id={$f['categoryId']}&updated_since=" . rawurlencode((new DateTimeImmutable('+1 day'))->format(DATE_ATOM)), $bearer('public'));
    apiAssert(($response['json']['meta']['total'] ?? -1) === 0, 'updated_since futuro não retorna documentos');

    echo "\nLimite de requisições\n";
    $statuses = [];
    for ($i = 0; $i < 4; $i++) {
        $statuses[] = apiHttp('GET', "{$api}/status.php", $bearer('limited'));
    }
    $last = end($statuses);
    apiAssert($statuses[2]['status'] === 200 && $last['status'] === 429 && (int)($last['headers']['retry-after'] ?? 0) >= 1, 'quarta chamada acima do limite responde 429 com Retry-After');

    echo "\nEntrada de visitantes no portal\n";
    $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value, updated_by) VALUES ('guest_access_enabled', 'true'::jsonb, ?) ON CONFLICT (setting_key) DO UPDATE SET setting_value = EXCLUDED.setting_value")->execute([$f['actor']]);
    $publicDoc = $f['documents']['public_text'];
    $response = apiHttp('GET', "{$base}/ver_conteudo.php?id={$publicDoc}");
    apiAssert($response['status'] === 200 && !isset($response['headers']['location']) && str_contains($response['body'], 'data-guest-notice') && str_contains($response['body'], 'Documento public_text'), 'link público abre diretamente sem conta, cookies ou redirecionamento');
    $response = apiHttp('HEAD', "{$base}/ver_conteudo.php?id={$publicDoc}");
    apiAssert($response['status'] === 200 && !isset($response['headers']['location']) && $response['body'] === '', 'HEAD do link público também dispensa login');
    foreach (['private_text', 'public_draft'] as $key) {
        $path = "ver_conteudo.php?id={$f['documents'][$key]}";
        $response = apiHttp('GET', "{$base}/{$path}");
        apiAssert($response['status'] === 302 && ($response['headers']['location'] ?? '') === 'login.php?next=' . rawurlencode($path)
            && !str_contains($response['body'], 'Documento ' . $key), "link de {$key} sem sessão exige login e preserva o destino");
    }
    foreach (['categories' => $f['categoryId'], 'subcategories' => $f['subId'], 'subjects' => $f['publicSubject']] as $table => $id) {
        $pdo->prepare("UPDATE {$table} SET active = FALSE WHERE id = ?")->execute([$id]);
        $response = apiHttp('GET', "{$base}/ver_conteudo.php?id={$publicDoc}");
        apiAssert($response['status'] === 302 && !str_contains($response['body'], 'Documento public_text'), "link público não libera conteúdo com {$table} inativo");
        $pdo->prepare("UPDATE {$table} SET active = TRUE WHERE id = ?")->execute([$id]);
    }
    $response = apiHttp('GET', "{$base}/ver_conteudo.php?id={$publicDoc}", [], null, $cookieJar);
    apiAssert($response['status'] === 200 && str_contains($response['body'], 'data-guest-notice'), 'link público inicia navegação como visitante automaticamente');
    $response = apiHttp('GET', "{$base}/index.php?q=" . rawurlencode($token), [], null, $cookieJar);
    apiAssert($response['status'] === 200 && str_contains($response['body'], 'Documento public_text') && !str_contains($response['body'], 'Documento private_text'), 'visitante que abriu link direto pode navegar no acervo público');
    apiAssert(apiHttp('GET', "{$base}/ver_conteudo.php?id={$f['documents']['private_text']}", [], null, $cookieJar)['status'] === 403, 'entrada automática não libera documento privado');
    $response = apiHttp('GET', "{$base}/ver_conteudo.php?id={$publicDoc}", ['Cookie: PHPSESSID=' . $readerSession['id']]);
    apiAssert($response['status'] === 200 && !str_contains($response['body'], 'data-guest-notice'), 'link público preserva a conta autenticada');
    apiAssert(apiHttp('GET', "{$base}/ver_conteudo.php?id={$f['documents']['private_text']}", ['Cookie: PHPSESSID=' . $readerSession['id']])['status'] === 200, 'conta autenticada mantém acesso privado concedido');
    $response = apiHttp('POST', "{$base}/login.php", ['Content-Type: application/x-www-form-urlencoded'], 'guest_access=1&next=' . rawurlencode("ver_conteudo.php?id={$publicDoc}"), $cookieJar);
    apiAssert($response['status'] === 302 && ($response['headers']['location'] ?? '') === "ver_conteudo.php?id={$publicDoc}", 'botão "Entrar sem conta" volta ao documento');
    $response = apiHttp('GET', "{$base}/ver_conteudo.php?id={$publicDoc}", [], null, $cookieJar);
    apiAssert($response['status'] === 200 && str_contains($response['body'], 'data-guest-notice') && !str_contains($response['body'], 'id="btn-fav"'), 'visitante lê documento público sem favoritos');
    apiAssert(apiHttp('GET', "{$base}/ver_conteudo.php?id={$f['documents']['private_text']}", [], null, $cookieJar)['status'] === 403, 'visitante não lê documento privado');
    apiAssert(apiHttp('GET', "{$base}/ver_conteudo.php?id={$f['documents']['public_draft']}", [], null, $cookieJar)['status'] === 403, 'visitante não lê rascunho');
    apiAssert(apiHttp('GET', "{$base}/download.php?id={$f['documents']['public_file']}", [], null, $cookieJar)['body'] === $png, 'visitante baixa arquivo público');
    apiAssert(apiHttp('GET', "{$base}/download.php?id={$f['documents']['private_file']}", [], null, $cookieJar)['status'] === 403, 'visitante não baixa arquivo privado');
    $response = apiHttp('GET', "{$base}/index.php?q=" . rawurlencode($token), [], null, $cookieJar);
    apiAssert($response['status'] === 200 && str_contains($response['body'], 'Documento public_text') && !str_contains($response['body'], 'Documento private_text'), 'busca do visitante mostra só documentos públicos');
    apiAssert(str_contains($response['body'], 'Tag publica ' . $token) && !str_contains($response['body'], 'Tag privada ' . $token), 'filtro de tags do visitante omite tags de conteúdo privado');
    $response = apiHttp('GET', "{$base}/index.php?cat=api-test-{$token}&subcat=api-sub-{$token}&assunto=api-public-{$token}", [], null, $cookieJar);
    apiAssert($response['status'] === 200 && str_contains($response['body'], 'Documento public_text') && !str_contains($response['body'], 'Rascunho do processo ' . $token), 'visitante não vê processo do assunto em rascunho');
    apiAssert(apiHttp('GET', "{$base}/minha_conta.php", [], null, $cookieJar)['status'] === 302, 'visitante não acessa Minha Conta');
    apiAssert(apiHttp('GET', "{$base}/admin/index.php", [], null, $cookieJar)['status'] === 302, 'visitante não acessa o painel');
    $response = apiHttp('POST', "{$base}/api_user.php", ['Content-Type: application/x-www-form-urlencoded'], 'action=toggle_favorito&doc_id=' . $publicDoc, $cookieJar);
    apiAssert($response['status'] === 401, 'visitante não grava favoritos');

    $pdo->prepare("UPDATE system_settings SET setting_value = 'false'::jsonb WHERE setting_key = 'guest_access_enabled'")->execute();
    foreach ([null, $cookieJar] as $directCookieJar) {
        $response = apiHttp('GET', "{$base}/ver_conteudo.php?id={$publicDoc}", [], null, $directCookieJar);
        apiAssert($response['status'] === 302 && ($response['headers']['location'] ?? '') === 'login.php?next=' . rawurlencode("ver_conteudo.php?id={$publicDoc}")
            && !str_contains($response['body'], 'Documento public_text'), 'desligar visitantes exige login também no link público direto');
    }
    $response = apiHttp('GET', "{$base}/index.php", [], null, $cookieJar);
    apiAssert($response['status'] === 302 && str_contains($response['headers']['location'] ?? '', 'login.php'), 'desligar visitantes encerra a navegação sem conta');
    apiAssert(apiHttp('GET', "{$base}/download.php?id={$f['documents']['public_file']}", [], null, $cookieJar)['status'] === 403, 'desligar visitantes bloqueia downloads anônimos');
    $loginPage = apiHttp('GET', "{$base}/login.php")['body'];
    apiAssert(!str_contains($loginPage, 'Entrar sem conta'), 'login oculta o botão quando visitantes estão desligados');
    apiAssert(apiHttp('POST', "{$base}/login.php", ['Content-Type: application/x-www-form-urlencoded'], 'guest_access=1')['status'] === 403, 'POST de visitante é recusado quando desligado');
    apiAssert(apiHttp('GET', "{$api}/documents.php?id={$publicDoc}", $bearer('public'))['status'] === 200, 'API pública continua independente da opção de visitantes');

    echo "\nPainel: Integrações (API)\n";
    $adminCookie = ['Cookie: PHPSESSID=' . $adminSession['id']];
    $response = apiHttp('GET', "{$base}/admin/index.php?tab=integracoes", $adminCookie);
    apiAssert($response['status'] === 200 && str_contains($response['body'], 'data-api-client-form') && str_contains($response['body'], 'API teste público ' . $token), 'Super Admin vê a aba e as chaves emitidas');
    apiAssert(!str_contains($response['body'], $f['clients']['public']['token']), 'lista nunca exibe o token completo');
    $settingsPage = apiHttp('GET', "{$base}/admin/index.php?tab=configuracoes", $adminCookie)['body'];
    apiAssert(str_contains($settingsPage, 'name="guest_access_enabled"'), 'Configurações exibem o interruptor de visitantes');
    $form = http_build_query(['api_client_action' => 'create', 'csrf_token' => $adminSession['csrf'], 'name' => 'API teste painel ' . $token, 'access_scope' => 'public', 'rate_limit_per_minute' => '60']);
    $response = apiHttp('POST', "{$base}/admin/index.php?tab=integracoes", array_merge($adminCookie, ['Content-Type: application/x-www-form-urlencoded']), $form);
    apiAssert($response['status'] === 302 && str_contains($response['headers']['location'] ?? '', 'msg=api_client_created'), 'criação redireciona após salvar');
    $page = apiHttp('GET', "{$base}/admin/index.php?tab=integracoes&msg=api_client_created", $adminCookie)['body'];
    preg_match('/value="(dgv_[a-f0-9]{8}_[a-f0-9]{48})"/', $page, $tokenMatch);
    apiAssert(isset($tokenMatch[1]) && str_contains($page, 'data-api-new-token'), 'token novo é exibido após a criação');
    $panelToken = $tokenMatch[1] ?? '';
    apiAssert(!str_contains(apiHttp('GET', "{$base}/admin/index.php?tab=integracoes", $adminCookie)['body'], $panelToken), 'token não reaparece em uma segunda visita');
    apiAssert(apiHttp('GET', "{$api}/status.php", ['Authorization: Bearer ' . $panelToken])['status'] === 200, 'chave criada pelo painel funciona na API');
    $panelClientId = (int)$pdo->query("SELECT id FROM api_clients WHERE name = " . $pdo->quote('API teste painel ' . $token))->fetchColumn();
    $badCsrf = http_build_query(['api_client_action' => 'revoke', 'csrf_token' => 'invalido', 'api_client_id' => $panelClientId]);
    apiAssert(apiHttp('POST', "{$base}/admin/index.php?tab=integracoes", array_merge($adminCookie, ['Content-Type: application/x-www-form-urlencoded']), $badCsrf)['status'] === 419, 'revogação sem CSRF válido é recusada');
    $revoke = http_build_query(['api_client_action' => 'revoke', 'csrf_token' => $adminSession['csrf'], 'api_client_id' => $panelClientId]);
    apiAssert(apiHttp('POST', "{$base}/admin/index.php?tab=integracoes", array_merge($adminCookie, ['Content-Type: application/x-www-form-urlencoded']), $revoke)['status'] === 302, 'Super Admin revoga a chave');
    apiAssert(apiHttp('GET', "{$api}/status.php", ['Authorization: Bearer ' . $panelToken])['status'] === 401, 'chave revogada perde o acesso imediatamente');
    $before = (int)$pdo->query('SELECT COUNT(*) FROM api_clients')->fetchColumn();
    $readerForm = http_build_query(['api_client_action' => 'create', 'csrf_token' => $readerSession['csrf'], 'name' => 'API teste intruso ' . $token, 'access_scope' => 'public']);
    apiHttp('POST', "{$base}/admin/index.php?tab=integracoes", ['Cookie: PHPSESSID=' . $readerSession['id'], 'Content-Type: application/x-www-form-urlencoded'], $readerForm);
    apiAssert((int)$pdo->query('SELECT COUNT(*) FROM api_clients')->fetchColumn() === $before, 'conta sem privilégio não cria chaves');
} finally {
    if ($previousGuestSetting === false) {
        $pdo->exec("DELETE FROM system_settings WHERE setting_key = 'guest_access_enabled'");
    } else {
        $pdo->prepare("UPDATE system_settings SET setting_value = CAST(? AS jsonb) WHERE setting_key = 'guest_access_enabled'")->execute([$previousGuestSetting]);
    }
    if (is_resource($server)) {
        proc_terminate($server);
        proc_close($server);
    }
    apiCleanup($pdo, $token);
    if (is_file($serverLog)) {
        $errors = array_filter(file($serverLog) ?: [], static fn(string $line): bool => (bool)preg_match('/PHP (Fatal|Warning|Notice|Deprecated)/', $line));
        if ($errors !== []) {
            echo "\nAvisos do servidor:\n" . implode('', array_slice($errors, 0, 10));
            $failures += count($errors);
        }
    }
    foreach (glob($sessionDir . DIRECTORY_SEPARATOR . '*') ?: [] as $file) unlink($file);
    if (is_dir($sessionDir)) rmdir($sessionDir);
}

echo $failures === 0 ? "\nPASS API v1 e visitantes.\n" : "\nFALHAS: {$failures}\n";
exit($failures === 0 ? 0 : 1);
