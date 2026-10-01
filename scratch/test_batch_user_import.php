<?php
declare(strict_types=1);
require_once __DIR__ . '/_cli_only.php';
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('DOCGOV_SKIP_APP_RUNTIME', true);
require __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../services/ActiveDirectoryAuthService.php';
require_once __DIR__ . '/../services/BatchUserImportService.php';
require_once __DIR__ . '/../services/CsrfService.php';
if ($appEnvironment !== 'development' || !in_array($dbHost, ['127.0.0.1', 'localhost', '::1'], true)) throw new RuntimeException('Somente banco local de desenvolvimento.');

function batchAssert(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
function batchInsert(PDO $pdo, string $sql, array $params): int { $stmt = $pdo->prepare($sql); $stmt->execute($params); return (int)$stmt->fetchColumn(); }
function batchConfig(): array {
    return ['enabled' => true, 'default_domain' => 'BATCHTEST', 'super_admin_users' => [], 'domains' => ['BATCHTEST' => ['key' => 'BATCHTEST', 'enabled' => true, 'dns_domain' => 'example.invalid', 'netbios_domain' => 'BATCHTEST']]];
}
function batchUser(PDO $pdo, string $token, string $label, bool $active = true, string $name = ''): int {
    return batchInsert($pdo, 'INSERT INTO users (name, username, email, role, active) VALUES (?, ?, ?, ?, ?) RETURNING id', [$name ?: 'Teste lote ' . $label, "batch.{$token}.{$label}", "batch.{$token}.{$label}@example.invalid", $label === 'admin' ? 'admin' : 'reader', $active ? 'true' : 'false']);
}
function batchCleanup(PDO $pdo, string $token): void {
    batchAssert(preg_match('/^[a-f0-9]{12}$/', $token) === 1, 'Token de teste inválido.');
    $stmt = $pdo->prepare('SELECT id FROM users WHERE username LIKE ?'); $stmt->execute(["batch.{$token}.%"]); $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
    foreach ($ids as $id) $pdo->prepare('DELETE FROM usage_audit_events WHERE user_id = ?')->execute([$id]);
    $pdo->prepare('DELETE FROM groups WHERE name = ?')->execute(['Teste importação lote ' . $token]);
    $pdo->prepare('DELETE FROM groups WHERE name = ?')->execute(['Teste delegação lote ' . $token]);
    $pdo->prepare('DELETE FROM users WHERE username LIKE ?')->execute(["batch.{$token}.%"]);
}
$mode = $argv[1] ?? '--test';
if ($mode === '--cleanup') { batchCleanup($pdo, (string)$argv[2]); echo "Fixtures removidas.\n"; exit; }
if ($mode === '--prepare') {
    $token = bin2hex(random_bytes(6));
    $adminId = batchUser($pdo, $token, 'admin');
    $readerId = batchUser($pdo, $token, 'reader');
    $memberId = batchUser($pdo, $token, 'member');
    $groupId = batchInsert($pdo, 'INSERT INTO groups (name) VALUES (?) RETURNING id', ['Teste importação lote ' . $token]);
    $sessions = [];
    foreach (['admin' => $adminId, 'reader' => $readerId] as $role => $id) {
        session_id(bin2hex(random_bytes(16))); session_start();
        $_SESSION = ['user' => ['id' => $id, 'nome' => 'Teste lote', 'login' => "batch.{$token}.{$role}", 'role' => $role === 'admin' ? 'admin' : 'reader', 'active' => true], 'admin_logged' => true];
        $sessions[$role] = ['id' => session_id(), 'csrf' => CsrfService::token()]; session_write_close();
    }
    echo json_encode(['token' => $token, 'groupId' => $groupId, 'memberId' => $memberId, 'username' => "batch.{$token}.member", 'sessions' => $sessions], JSON_THROW_ON_ERROR); exit;
}
if ($mode === '--inspect') {
    $token = (string)$argv[2]; batchAssert(preg_match('/^[a-f0-9]{12}$/', $token) === 1, 'Token inválido.');
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM user_groups ug JOIN groups g ON g.id = ug.group_id WHERE g.name = ?'); $stmt->execute(['Teste importação lote ' . $token]);
    echo json_encode(['members' => (int)$stmt->fetchColumn()]); exit;
}

final class TestBatchDirectory implements DirectoryImportGateway {
    public int $calls = 0;
    public array $entries = [];
    public function __construct(private ActiveDirectoryAuthService $real) {}
    public function lookupDirectoryUsers(array $identifiers, string $domainKey): array {
        $this->calls++;
        return array_map(fn(string $value): array => $this->entries[$value] ?? ['success' => false, 'code' => 'not_found', 'message' => 'Não encontrado no AD.'], $identifiers);
    }
    public function provisionDirectoryUser(array $entry, string $domainKey): array {
        $result = $this->real->provisionDirectoryUser($entry, $domainKey);
        if (($entry['force_failure'] ?? false) === true) throw new RuntimeException('Falha simulada após cadastro.');
        return $result;
    }
}
function batchEntry(string $username, string $name, ?string $email = null): array {
    return ['success' => true, 'entry' => ['samaccountname' => [$username], 'displayname' => [$name], 'mail' => [$email ?? $username . '@example.invalid'], 'objectguid' => [substr(hash('sha256', $username, true), 0, 16)], 'useraccountcontrol' => ['512']]];
}

if ($mode === '--concurrent-worker') {
    $token = (string)($argv[2] ?? ''); batchAssert(preg_match('/^[a-f0-9]{12}$/', $token) === 1, 'Token inválido.');
    $stmt = $pdo->prepare('SELECT id FROM users WHERE username = ?'); $stmt->execute(["batch.{$token}.admin"]); $actor = (int)$stmt->fetchColumn();
    $stmt = $pdo->prepare('SELECT id FROM groups WHERE name = ?'); $stmt->execute(['Teste importação lote ' . $token]); $group = (int)$stmt->fetchColumn();
    batchAssert($actor > 0 && $group > 0, 'Fixtures de concorrência ausentes.');
    $config = batchConfig(); $directory = new TestBatchDirectory(new ActiveDirectoryAuthService($pdo, $config));
    $name = 'Pessoa Concorrente ' . $token;
    $directory->entries[$name] = batchEntry("batch.{$token}.concurrent", $name);
    echo json_encode((new BatchUserImportService($pdo, $directory, $config))->process([$name], 'BATCHTEST', $actor, $group)['results'][0], JSON_THROW_ON_ERROR);
    exit;
}

$token = bin2hex(random_bytes(6));
try {
    $admin = batchUser($pdo, $token, 'admin'); $reader = batchUser($pdo, $token, 'reader');
    $member = batchUser($pdo, $token, 'member'); $inactive = batchUser($pdo, $token, 'inactive', false);
    $duplicateA = batchUser($pdo, $token, 'same1', true, 'Nome Duplicado ' . $token); $duplicateB = batchUser($pdo, $token, 'same2', true, 'Nome Duplicado ' . $token);
    $group = batchInsert($pdo, 'INSERT INTO groups (name) VALUES (?) RETURNING id', ['Teste importação lote ' . $token]);
    $config = batchConfig();
    $config['super_admin_users'] = ["batch.{$token}.new"];
    $directory = new TestBatchDirectory(new ActiveDirectoryAuthService($pdo, $config));
    $service = new BatchUserImportService($pdo, $directory, $config);
    $directory->entries['Pessoa Nova'] = batchEntry("batch.{$token}.new", 'Pessoa Nova');
    $directory->entries['Nome Ambíguo'] = ['success' => false, 'code' => 'ambiguous', 'message' => 'Use o login.', 'candidates' => ['BATCHTEST\\um', 'BATCHTEST\\dois']];
    $directory->entries['AD Inativo'] = ['success' => false, 'code' => 'inactive', 'message' => 'Conta inativa no AD.'];
    $directory->entries['AD Indisponível'] = ['success' => false, 'code' => 'unavailable', 'message' => 'AD indisponível.'];
    $directory->entries['E-mail Conflitante'] = batchEntry("batch.{$token}.conflict", 'Conflito', "batch.{$token}.member@example.invalid");
    $directory->entries['Falha Atômica'] = batchEntry("batch.{$token}.rollback", 'Rollback'); $directory->entries['Falha Atômica']['entry']['force_failure'] = true;
    $list = ["batch.{$token}.member", 'Pessoa Nova', 'pessoa nova', 'Não Existe', 'Nome Ambíguo', "batch.{$token}.inactive", 'AD Inativo', 'AD Indisponível', 'Nome Duplicado ' . $token, 'E-mail Conflitante', 'Falha Atômica', 'OUTRO\\user', ''];
    $result = $service->process($list, 'BATCHTEST', $admin, $group)['results'];
    batchAssert(array_column($result, 'status') === ['added', 'added', 'duplicate', 'not_found', 'ambiguous', 'inactive', 'inactive', 'unavailable', 'ambiguous', 'conflict', 'error', 'invalid', 'invalid'], 'Resultados incorretos: ' . json_encode(array_column($result, 'status')));
    batchAssert($result[1]['created'] && count($result[4]['candidates']) === 2, 'Cadastro automático ou sugestões ausentes.');
    $new = $pdo->query("SELECT * FROM users WHERE username = 'batch.{$token}.new'")->fetch();
    batchAssert($new['role'] === 'reader' && $new['last_login_at'] === null && $new['password_hash'] === null, 'Importação promoveu papel ou simulou login.');
    batchAssert((int)$pdo->query("SELECT COUNT(*) FROM users WHERE username = 'batch.{$token}.rollback'")->fetchColumn() === 0, 'Cadastro parcial persistiu após falha.');
    $beforeCalls = $directory->calls;
    $repeat = $service->process(['Pessoa Nova', "batch.{$token}.member"], 'BATCHTEST', $admin, $group)['results'];
    batchAssert(array_column($repeat, 'status') === ['already_member', 'already_member'] && $directory->calls === $beforeCalls, 'Repetição criou vínculos ou consultou AD sem necessidade.');
    batchAssert((int)$pdo->query("SELECT COUNT(*) FROM user_groups WHERE group_id = {$group}")->fetchColumn() === 2, 'Membros duplicados ou inativos na equipe.');
    echo "PASS lote misto: novos, existentes, repetidos, ausentes, ambíguos, inativos, domínio incorreto, conflito, falha parcial e idempotência.\n";

    $pdo->prepare("UPDATE users SET role = 'editor', active = FALSE WHERE id = ?")->execute([$new['id']]);
    $preserved = $service->process(['Pessoa Nova'], 'BATCHTEST', $admin)['results'][0];
    $state = $pdo->query("SELECT role, active FROM users WHERE id = " . (int)$new['id'])->fetch();
    batchAssert($preserved['status'] === 'inactive' && $state['role'] === 'editor' && !$state['active'], 'Importação alterou papel ou reativou usuário.');
    foreach ([$reader, $inactive] as $actor) {
        foreach ([null, $group] as $target) {
            $blocked = false; try { $service->process(['Pessoa Nova'], 'BATCHTEST', $actor, $target); } catch (RuntimeException $error) { $blocked = $error->getCode() === 403; }
            batchAssert($blocked, 'Usuário não autorizado conseguiu importar.');
        }
    }
    $delegatedGroup = batchInsert($pdo, 'INSERT INTO groups (name) VALUES (?) RETURNING id', ['Teste delegação lote ' . $token]);
    $pdo->prepare('INSERT INTO user_groups (user_id, group_id) VALUES (?, ?)')->execute([$reader, $delegatedGroup]);
    $pdo->prepare('INSERT INTO group_system_capabilities (group_id, capability, granted_by) VALUES (?, ?, ?)')->execute([$delegatedGroup, SystemAccessService::DIRECTORY_MANAGE, $admin]);
    batchAssert($service->process(['Não Existe'], 'BATCHTEST', $reader)['results'][0]['status'] === 'not_found', 'Diretório delegado foi bloqueado.');
    $blocked = false; try { $service->process(['Não Existe'], 'BATCHTEST', $reader, $group); } catch (RuntimeException $error) { $blocked = $error->getCode() === 403; }
    batchAssert($blocked, 'Diretório delegado permitiu gerenciar equipes.');
    foreach ([[], array_fill(0, 21, 'Pessoa'), [['nome' => 'Pessoa']], ['Pessoa' => 'Nome']] as $badList) {
        $blocked = false; try { $service->process($badList, 'BATCHTEST', $admin); } catch (InvalidArgumentException) { $blocked = true; }
        batchAssert($blocked, 'Lista malformada ou sem limite foi aceita.');
    }
    echo "PASS segurança: permissões, delegação, limites, papéis e bloqueios locais preservados.\n";

    $names = [];
    for ($index = 0; $index < 500; $index++) {
        $name = 'Pessoa Hospital ' . $index;
        $names[] = $name; $directory->entries[$name] = batchEntry("batch.{$token}.hospital.{$index}", $name);
    }
    $calls = $directory->calls;
    foreach (array_chunk($names, BatchUserImportService::CHUNK_SIZE) as $chunk) {
        $result = $service->process($chunk, 'BATCHTEST', $admin, $group)['results'];
        batchAssert(count(array_filter($result, fn(array $row): bool => $row['status'] === 'added' && $row['created'])) === count($chunk), 'Lote de hospital não foi importado integralmente.');
    }
    batchAssert($directory->calls - $calls === 25, 'Não consultou um lote por etapa.');
    batchAssert((int)$pdo->query("SELECT COUNT(*) FROM user_groups WHERE group_id = {$group}")->fetchColumn() === 502, 'O lote de 500 perdeu pessoas.');
    foreach (array_chunk($names, BatchUserImportService::CHUNK_SIZE) as $chunk) {
        $result = $service->process($chunk, 'BATCHTEST', $admin, $group)['results'];
        batchAssert(count(array_filter($result, fn(array $row): bool => $row['status'] === 'already_member')) === count($chunk), 'Repetição de 500 não foi idempotente.');
    }
    $audit = $pdo->prepare("SELECT COUNT(*) FROM usage_audit_events WHERE user_id = ? AND metadata->>'action' = 'team_member_added'"); $audit->execute([$admin]);
    batchAssert((int)$audit->fetchColumn() === 502, 'Auditoria perdeu ou duplicou inclusões.');
    $notifications = $pdo->query("SELECT COUNT(*) FROM notifications n JOIN user_groups ug ON ug.user_id = n.user_id WHERE ug.group_id = {$group} AND n.type = 'team_membership_added'")->fetchColumn();
    batchAssert((int)$notifications === 502, 'Notificações ausentes ou duplicadas.');
    echo "PASS 500 usuários: cadastro + vínculo, repetição sem duplicar, auditoria e notificações.\n";
} finally { batchCleanup($pdo, $token); }
