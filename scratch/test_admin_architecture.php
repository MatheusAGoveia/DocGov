<?php
declare(strict_types=1);

require_once __DIR__ . '/_cli_only.php';
define('DOCGOV_SKIP_APP_RUNTIME', true);

$inspectIndex = array_search('--inspect', $argv, true);
$fileIndex = array_search('--file', $argv, true);
if ($fileIndex !== false) $inspectIndex = $fileIndex;
if ($inspectIndex !== false) {
    $manifest = json_decode(file_get_contents($argv[$inspectIndex + 1] ?? ''), true, 32, JSON_THROW_ON_ERROR);
    if (!preg_match('/^docgov_admin_architecture_test_[a-f0-9]{12}$/D', $manifest['database'] ?? '')) {
        throw new RuntimeException('Manifesto de teste inválido.');
    }
    putenv('DB_NAME=' . $manifest['database']);
}
require __DIR__ . '/../config/db.php';
if ($appEnvironment !== 'development' || !in_array($dbHost, ['127.0.0.1', 'localhost', '::1'], true)) {
    throw new RuntimeException('Esta suíte exige PostgreSQL local de desenvolvimento.');
}
if ($inspectIndex !== false) {
    if ($fileIndex !== false) {
        $stmt = $pdo->prepare('SELECT id, file_path, original_filename FROM documents WHERE id = ?');
        $stmt->execute([(int)($argv[$fileIndex + 2] ?? 0)]);
        echo json_encode($stmt->fetch(), JSON_THROW_ON_ERROR);
        exit;
    }
    echo json_encode([
        'documents' => $pdo->query('SELECT id, title, status, subject_id, text_content FROM documents ORDER BY id')->fetchAll(),
        'members' => $pdo->query('SELECT user_id, group_id FROM user_groups ORDER BY user_id, group_id')->fetchAll(),
        'groups' => $pdo->query('SELECT id, name, active FROM groups ORDER BY id')->fetchAll(),
        'tags' => $pdo->query('SELECT name, active FROM tags ORDER BY id')->fetchAll(),
        'permissions' => (int)$pdo->query('SELECT COUNT(*) FROM permissions')->fetchColumn(),
        'users' => (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn(),
        'settings' => $pdo->query('SELECT setting_key, setting_value FROM system_settings ORDER BY setting_key')->fetchAll(),
    ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    exit;
}

$serverPdo = $pdo;
$oldDatabase = getenv('DB_NAME');
$database = 'docgov_admin_architecture_test_' . bin2hex(random_bytes(6));
$artifactDirectory = sys_get_temp_dir() . '/docgov-admin-architecture-' . bin2hex(random_bytes(6));
$created = false;
$exitCode = 1;
mkdir($artifactDirectory, 0700);
mkdir($artifactDirectory . '/sessions', 0700);
$manifestPath = $artifactDirectory . '/fixture.json';
$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$insert = static function (PDO $db, string $sql, array $params): int {
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return (int)$stmt->fetchColumn();
};

try {
    $serverPdo->exec('CREATE DATABASE ' . $database);
    $created = true;
    putenv('DB_NAME=' . $database);
    $pdo = new PDO(sprintf('pgsql:host=%s;port=%s;dbname=%s', $dbHost, $dbPort, $database), $dbUser, (string)$dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec(file_get_contents(__DIR__ . '/../database/schema.sql'));
    $pdo->exec('CREATE TABLE ad_auth_logs (id SERIAL PRIMARY KEY, domain_key VARCHAR(50), username VARCHAR(100), server_uri VARCHAR(255), server_name VARCHAR(100), status VARCHAR(50), status_message TEXT, latency_ms INT, user_ip VARCHAR(50), created_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP)');
    $pdo->exec('ALTER TABLE users ADD COLUMN department VARCHAR(150), ADD COLUMN job_title VARCHAR(150), ADD COLUMN phone VARCHAR(50)');
    $users = [];
    foreach (['admin' => 'admin', 'manager' => 'reader', 'delegate' => 'reader', 'reader' => 'reader'] as $key => $role) {
        $id = $insert($pdo, 'INSERT INTO users (name, username, email, role, auth_source, active) VALUES (?, ?, ?, ?, ?, TRUE) RETURNING id',
            ['Arquitetura ' . $key, 'architecture.' . $key, 'architecture.' . $key . '@example.invalid', $role, 'ad']);
        $users[$key] = ['id' => $id, 'role' => $role];
    }
    $categoryId = $insert($pdo, 'INSERT INTO categories (name, slug) VALUES (?, ?) RETURNING id', ['Categoria Arquitetura', 'architecture-category']);
    $subcategoryId = $insert($pdo, 'INSERT INTO subcategories (category_id, name, slug) VALUES (?, ?, ?) RETURNING id', [$categoryId, 'Subcategoria Arquitetura', 'architecture-subcategory']);
    $subjectId = $insert($pdo, 'INSERT INTO subjects (subcategory_id, name, slug) VALUES (?, ?, ?) RETURNING id', [$subcategoryId, 'Assunto Arquitetura', 'architecture-subject']);
    $groupId = $insert($pdo, 'INSERT INTO groups (name) VALUES (?) RETURNING id', ['Equipe Arquitetura']);
    $pdo->prepare("INSERT INTO permissions (user_id, category_id, permission_level) VALUES (?, ?, 'admin')")->execute([$users['manager']['id'], $categoryId]);
    $delegateGroup = $insert($pdo, 'INSERT INTO groups (name) VALUES (?) RETURNING id', ['Delegação Arquitetura']);
    $pdo->prepare('INSERT INTO user_groups (user_id, group_id) VALUES (?, ?)')->execute([$users['delegate']['id'], $delegateGroup]);
    $pdo->prepare("INSERT INTO group_system_capabilities (group_id, capability, granted_by) VALUES (?, 'system.directory.manage', ?)")->execute([$delegateGroup, $users['admin']['id']]);
    $documentIds = [];
    foreach (['draft', 'review', 'published'] as $status) {
        $documentIds[$status] = $insert($pdo, "INSERT INTO documents (subject_id, created_by, title, slug, content_type, section_key, status, text_content) VALUES (?, ?, ?, ?, 'text', 'documents', ?, ?) RETURNING id",
            [$subjectId, $users['admin']['id'], 'Documento ' . $status, 'architecture-' . $status, $status, '<p>Conteúdo de teste.</p>']);
    }
    $tagName = 'Acentuação "segura" </script><script>window.unexpectedCode=true</script>';
    $tagId = $insert($pdo, 'INSERT INTO tags (name, normalized_name) VALUES (?, ?) RETURNING id', [$tagName, 'architecture-tag']);
    $pdo->prepare('INSERT INTO document_tags (document_id, tag_id) VALUES (?, ?)')->execute([$documentIds['draft'], $tagId]);
    $settings = [
        'maintenance_enabled' => false, 'ad_auth_enabled' => false,
        'ad_super_admin_users' => ['architecture.admin'], 'ad_default_domain' => 'BETIM',
        'ad_domains' => ['BETIM' => ['key' => 'BETIM', 'name' => 'Domínio de teste', 'uri' => 'ldap://127.0.0.1:1', 'base_dn' => 'DC=example,DC=invalid', 'dns_domain' => 'example.invalid', 'netbios_domain' => 'BETIM', 'ca_certificate' => '', 'service_bind_dn' => '', 'service_bind_password' => '', 'is_primary' => true]],
    ];
    foreach ($settings as $key => $value) {
        $pdo->prepare('INSERT INTO system_settings (setting_key, setting_value) VALUES (?, CAST(? AS JSONB))')->execute([$key, json_encode($value, JSON_THROW_ON_ERROR)]);
    }
    ini_set('session.save_path', $artifactDirectory . '/sessions');
    $csrfToken = bin2hex(random_bytes(32));
    foreach ($users as $key => &$user) {
        $user['session'] = bin2hex(random_bytes(16));
        session_id($user['session']);
        session_start();
        $_SESSION = ['user' => ['id' => $user['id'], 'nome' => 'Arquitetura ' . $key, 'login' => 'architecture.' . $key, 'email' => 'architecture.' . $key . '@example.invalid', 'role' => $user['role'], 'active' => true, 'inicial' => 'A'], 'admin_logged' => true, 'last_activity_at' => time(), 'csrf_token' => $csrfToken];
        session_write_close();
    }
    unset($user);
    $manifest = compact('database', 'artifactDirectory', 'users', 'csrfToken', 'categoryId', 'subcategoryId', 'subjectId', 'groupId', 'documentIds', 'tagId', 'tagName');
    $manifest['sessionDirectory'] = $artifactDirectory . '/sessions';
    $manifest['sourceHash'] = hash_file('sha256', __DIR__ . '/../admin/index.php');
    file_put_contents($manifestPath, json_encode($manifest, JSON_THROW_ON_ERROR));
    putenv('DOCGOV_ADMIN_FIXTURE_FILE=' . $manifestPath);
    putenv('DOCGOV_PHP=' . PHP_BINARY);
    $nodeArgs = ['node', __DIR__ . '/test_admin_architecture.js'];
    foreach (['--record', '--compare'] as $option) {
        $index = array_search($option, $argv, true);
        if ($index !== false) {
            $assert(isset($argv[$index + 1]), 'Informe o caminho para ' . $option);
            $nodeArgs[] = $option;
            $nodeArgs[] = $argv[$index + 1];
        }
    }
    foreach (['--browser', '--fail-node', '--fail-after-upload'] as $option) if (in_array($option, $argv, true)) $nodeArgs[] = $option;
    $process = proc_open($nodeArgs, [0 => ['pipe', 'r'], 1 => ['file', $artifactDirectory . '/node.out', 'w'], 2 => ['file', $artifactDirectory . '/node.err', 'w']], $pipes);
    $assert(is_resource($process), 'Não foi possível iniciar a suíte Node.');
    fclose($pipes[0]);
    $code = proc_close($process);
    echo file_get_contents($artifactDirectory . '/node.out');
    $assert($code === 0, 'Suíte HTTP/navegador: ' . file_get_contents($artifactDirectory . '/node.err'));
    if (in_array('--regression', $argv, true)) {
        // A regressão de exclusão exige dois administradores já existentes.
        $insert($pdo, "INSERT INTO users (name, username, email, role, auth_source) VALUES (?, ?, ?, 'admin', 'ad') RETURNING id",
            ['Segundo administrador de teste', 'architecture.admin.second', 'architecture.admin.second@example.invalid']);
        $pdo->exec("UPDATE system_settings SET setting_value = '[\"architecture.admin\",\"architecture.admin.second\"]'::jsonb WHERE setting_key = 'ad_super_admin_users'");
        foreach (['test_permissions.php', 'test_permission_api.php', 'test_hierarchy_resolution.php', 'test_document_approval_workflow.php', 'test_duplicate_document_creation.php', 'test_subject_workspace.php', 'test_group_access_admin.php', 'test_system_access_groups.php', 'test_system_settings_admin.php', 'test_structure_permanent_deletion.php', 'test_tags.php', 'test_access_integrity.php'] as $test) {
            $process = proc_open([PHP_BINARY, __DIR__ . '/' . $test], [0 => ['pipe', 'r'], 1 => ['file', $artifactDirectory . '/regression.out', 'w'], 2 => ['file', $artifactDirectory . '/regression.err', 'w']], $pipes);
            $assert(is_resource($process), 'Não foi possível iniciar ' . $test);
            fclose($pipes[0]);
            $code = proc_close($process);
            $assert($code === 0, $test . ': ' . file_get_contents($artifactDirectory . '/regression.out') . file_get_contents($artifactDirectory . '/regression.err'));
            echo '[OK] ' . $test . PHP_EOL;
        }
    }
    $exitCode = 0;
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . PHP_EOL);
} finally {
    // Recupera uploads próprios mesmo quando o Node falha antes de inspecioná-los.
    if ($created && isset($users['admin']['id']) && $pdo instanceof PDO) {
        $storageRoot = realpath(__DIR__ . '/../storage/documents');
        $owned = $pdo->prepare("SELECT file_path, original_filename FROM documents WHERE created_by = ? AND original_filename LIKE 'architecture-test-%'");
        $owned->execute([$users['admin']['id']]);
        foreach ($owned->fetchAll() as $file) {
            if (!preg_match('/^architecture-test-[a-f0-9]{16}\\.txt$/D', (string)$file['original_filename'])) continue;
            $stored = realpath(__DIR__ . '/../' . (string)$file['file_path']);
            if ($storageRoot !== false && $stored !== false && str_starts_with($stored, $storageRoot . DIRECTORY_SEPARATOR) && is_file($stored)) {
                if (!unlink($stored)) {
                    fwrite(STDERR, 'Não foi possível remover o upload da fixture.' . PHP_EOL);
                    $exitCode = 1;
                }
            }
        }
        unset($owned);
    }
    $pdo = null;
    if ($created) {
        $serverPdo->prepare('SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = ? AND pid <> pg_backend_pid()')->execute([$database]);
        $serverPdo->exec('DROP DATABASE ' . $database);
    }
    putenv($oldDatabase === false ? 'DB_NAME' : 'DB_NAME=' . $oldDatabase);
    putenv('DOCGOV_ADMIN_FIXTURE_FILE');
    // Somente a pasta exclusiva criada por esta execução, sem seguir links.
    $remove = static function (string $path) use (&$remove): void {
        if (is_dir($path) && !is_link($path)) {
            foreach (new DirectoryIterator($path) as $entry) if (!$entry->isDot()) $remove($entry->getPathname());
            rmdir($path);
        } elseif (file_exists($path) || is_link($path)) unlink($path);
    };
    $remove($artifactDirectory);
    echo '[OK] Base, sessões e artefatos temporários removidos.' . PHP_EOL;
}
exit($exitCode);
