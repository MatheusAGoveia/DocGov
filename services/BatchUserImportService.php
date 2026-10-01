<?php

require_once __DIR__ . '/DirectoryImportGateway.php';
require_once __DIR__ . '/PermissionService.php';
require_once __DIR__ . '/SystemAccessService.php';
require_once __DIR__ . '/UsageAuditService.php';
require_once __DIR__ . '/NotificationService.php';

/** Importação com autorização no servidor e transação independente por pessoa. */
final class BatchUserImportService
{
    public const CHUNK_SIZE = 20;
    public const MAX_ENTRIES = 1000;

    public function __construct(private PDO $pdo, private DirectoryImportGateway $directory, private array $config) {}

    public function process(array $identifiers, string $domainKey, int $actorId, ?int $groupId = null): array
    {
        $permissions = new PermissionService($this->pdo);
        $access = new SystemAccessService($this->pdo, $permissions);
        if (($groupId !== null && (!$permissions->isGlobalAdmin($actorId) || !$access->hasCapability($actorId, SystemAccessService::DIRECTORY_MANAGE))) ||
            ($groupId === null && !$access->hasCapability($actorId, SystemAccessService::DIRECTORY_MANAGE))) {
            throw new RuntimeException('Você não possui permissão para esta importação.', 403);
        }
        $domainKey = strtoupper(trim($domainKey));
        $domain = $this->config['domains'][$domainKey] ?? null;
        if (!$domain || ($groupId !== null && $groupId <= 0)) {
            throw new InvalidArgumentException('Selecione um domínio e uma equipe válidos.');
        }
        if (!array_is_list($identifiers) || count($identifiers) < 1 || count($identifiers) > self::CHUNK_SIZE) {
            throw new InvalidArgumentException('Envie de 1 a ' . self::CHUNK_SIZE . ' entradas por etapa.');
        }
        foreach ($identifiers as $identifier) {
            if (!is_string($identifier) || strlen($identifier) > 1020) {
                throw new InvalidArgumentException('A lista deve conter apenas nomes ou logins com até 255 caracteres.');
            }
        }
        if ($groupId !== null) {
            $this->loadGroup($groupId);
        }

        $results = [];
        $pending = [];
        $seen = [];
        foreach ($identifiers as $index => $input) {
            $value = trim($input);
            $key = mb_strtolower($value, 'UTF-8');
            $results[$index] = ['input' => $input, 'status' => 'invalid', 'message' => 'Informe um nome completo ou login válido.', 'created' => false];
            if ($value === '' || mb_strlen($value) > 255 || !mb_check_encoding($value, 'UTF-8') || preg_match('/[\x00-\x1f\x7f]/', $value)) {
                continue;
            }
            if (isset($seen[$key])) {
                $results[$index]['status'] = 'duplicate';
                $results[$index]['message'] = 'Entrada repetida nesta lista; considerada apenas uma vez.';
                continue;
            }
            $seen[$key] = true;
            if ($groupId !== null) {
                try {
                    $user = $this->findLocalUser($value, $domainKey, $domain);
                    if ($user) {
                        $results[$index] = $this->persist($input, $actorId, $groupId, $user, null, $domainKey);
                        continue;
                    }
                } catch (DomainException $error) {
                    $results[$index]['status'] = 'ambiguous';
                    $results[$index]['message'] = $error->getMessage();
                    continue;
                } catch (InvalidArgumentException $error) {
                    $results[$index]['message'] = $error->getMessage();
                    continue;
                }
            }
            $pending[$index] = $value;
        }
        if ($pending !== []) {
            try {
                $lookups = $this->directory->lookupDirectoryUsers(array_values($pending), $domainKey);
            } catch (Throwable $error) {
                error_log('DocGov importação em lote: falha na consulta ao diretório: ' . $error->getMessage());
                $lookups = [];
            }
            foreach (array_keys($pending) as $offset => $index) {
                $lookup = $lookups[$offset] ?? ['success' => false, 'code' => 'unavailable', 'message' => 'A consulta ao AD falhou; esta entrada ainda não foi verificada.'];
                if (empty($lookup['success'])) {
                    $code = (string)($lookup['code'] ?? 'unavailable');
                    $results[$index]['status'] = in_array($code, ['not_found', 'ambiguous', 'inactive', 'invalid', 'unavailable'], true) ? $code : 'error';
                    $results[$index]['message'] = (string)$lookup['message'];
                    $results[$index]['candidates'] = $lookup['candidates'] ?? [];
                } else {
                    $results[$index] = $this->persist($identifiers[$index], $actorId, $groupId, null, $lookup['entry'], $domainKey);
                }
            }
        }
        return ['success' => true, 'results' => array_values($results)];
    }

    private function loadGroup(int $groupId, bool $lock = false): array
    {
        $stmt = $this->pdo->prepare('SELECT id, name, active FROM groups WHERE id = ?' . ($lock ? ' FOR SHARE' : ''));
        $stmt->execute([$groupId]);
        $group = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$group) {
            throw new InvalidArgumentException('Equipe não encontrada.');
        }
        return $group;
    }

    private function findLocalUser(string $value, string $domainKey, array $domain): ?array
    {
        $qualified = str_contains($value, '\\') || str_contains($value, '@');
        if ($qualified) {
            if (str_contains($value, '\\') && str_contains($value, '@')) {
                throw new InvalidArgumentException('Use DOMÍNIO\\login ou login@domínio.');
            }
            $separator = str_contains($value, '\\') ? '\\' : '@';
            $parts = explode($separator, $value);
            if (count($parts) !== 2) {
                throw new InvalidArgumentException('Login corporativo inválido.');
            }
            [$hint, $value] = $separator === '\\' ? $parts : [$parts[1], $parts[0]];
            $aliases = array_map('strtolower', [$domainKey, (string)($domain['netbios_domain'] ?? ''), (string)($domain['dns_domain'] ?? '')]);
            if ($hint === '' || !in_array(strtolower($hint), $aliases, true) || !preg_match('/^[a-z0-9._-]{1,100}$/i', $value)) {
                throw new InvalidArgumentException('O login deve pertencer ao domínio selecionado.');
            }
        }
        $stmt = $this->pdo->prepare("SELECT * FROM users WHERE LOWER(username) = LOWER(?) AND (auth_source <> 'ad' OR ad_domain = ?) LIMIT 2");
        $stmt->execute([$value, $domainKey]);
        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!$qualified && $users === []) {
            $stmt = $this->pdo->prepare("SELECT * FROM users WHERE LOWER(name) = LOWER(?) AND (auth_source <> 'ad' OR ad_domain = ?) ORDER BY id LIMIT 2");
            $stmt->execute([$value, $domainKey]);
            $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        if (count($users) > 1) {
            throw new DomainException('Mais de um cadastro corresponde a este nome. Informe o login corporativo.');
        }
        return $users[0] ?? null;
    }

    private function persist(string $input, int $actorId, ?int $groupId, ?array $user, ?array $entry, string $domainKey): array
    {
        $result = ['input' => $input, 'status' => 'error', 'message' => 'Não foi possível concluir esta entrada. Tente novamente.', 'created' => false];
        try {
            $this->pdo->beginTransaction();
            $group = $groupId !== null ? $this->loadGroup($groupId, true) : null;
            $created = false;
            if ($entry !== null) {
                $provisioned = $this->directory->provisionDirectoryUser($entry, $domainKey);
                $user = $provisioned['user'];
                $created = $provisioned['created'];
            }
            $stmt = $this->pdo->prepare('SELECT id, name, username, active FROM users WHERE id = ? FOR SHARE');
            $stmt->execute([(int)$user['id']]);
            $current = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$current || !in_array($current['active'], [true, 1, '1', 't', 'true'], true)) {
                $this->pdo->rollBack();
                return array_replace($result, ['status' => 'inactive', 'message' => 'O cadastro está inativo no sistema. Solicite uma revisão ao administrador.']);
            }
            if ($groupId !== null) {
                $stmt = $this->pdo->prepare('INSERT INTO user_groups (user_id, group_id) VALUES (?, ?) ON CONFLICT (user_id, group_id) DO NOTHING');
                $stmt->execute([$current['id'], $groupId]);
                $added = $stmt->rowCount() === 1;
                $status = $added ? 'added' : 'already_member';
                $message = $added ? ($created ? 'Cadastrado automaticamente pelo AD e adicionado à equipe.' : 'Adicionado à equipe.') : 'Já pertence à equipe; vínculo preservado.';
            } else {
                $status = $created ? 'imported' : 'existing';
                $message = $created ? 'Importado do AD com sucesso.' : 'Já cadastrado no sistema; cadastro preservado.';
            }
            $this->pdo->commit();
            $result = array_replace($result, ['status' => $status, 'message' => $message, 'created' => $created, 'name' => $current['name'], 'username' => $current['username']]);
            $audit = new UsageAuditService($this->pdo);
            if ($created) {
                $audit->log('admin_action', $actorId, 'ADMIN', null, ['action' => 'user_imported_from_ad', 'source' => 'batch', 'target_user_id' => (int)$current['id'], 'domain' => $domainKey]);
            }
            if ($groupId !== null && $added) {
                $audit->log('admin_action', $actorId, 'ADMIN', null, ['action' => 'team_member_added', 'source' => 'batch', 'team_id' => $groupId, 'target_user_id' => (int)$current['id']]);
                try {
                    $active = in_array($group['active'], [true, 1, '1', 't', 'true'], true);
                    (new NotificationService($this->pdo))->create((int)$current['id'], 'team_membership_added', 'Você foi adicionado a uma equipe', 'Você agora faz parte da equipe “' . $group['name'] . '”. ' . ($active ? 'Os acessos associados já estão disponíveis.' : 'A equipe está inativa e ainda não concede acesso.'));
                } catch (Throwable $error) {
                    error_log('DocGov importação em lote: falha de notificação: ' . $error->getMessage());
                }
            }
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            error_log('DocGov importação em lote: falha ao salvar entrada: ' . $error->getMessage());
            if ($error instanceof DomainException || ($error instanceof PDOException && $error->getCode() === '23505')) {
                $result['status'] = 'conflict';
                $result['message'] = 'O login ou e-mail já pertence a outro cadastro. Solicite a revisão ao administrador.';
            }
        }
        return $result;
    }
}
