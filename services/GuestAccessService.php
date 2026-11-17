<?php

/**
 * Entrada de visitantes (sem conta).
 *
 * A autorização do conteúdo continua no PermissionService, que concede aos
 * visitantes somente leitura de documentos publicados em assuntos públicos.
 * Esta classe trata a configuração, o modo visitante da sessão (iniciado pelo
 * botão de entrada ou por um link público) e o destino seguro depois da entrada.
 */
final class GuestAccessService
{
    public const SETTING_KEY = 'guest_access_enabled';
    public const DEFAULT_ENABLED = true;

    private const SESSION_KEY = 'guest_access';
    private const MAX_NEXT_LENGTH = 2048;
    private const FALLBACK_PATH = 'index.php';

    /** Páginas que um visitante pode abrir após a entrada. */
    private const GUEST_DESTINATIONS = ['index.php', 'ver_conteudo.php'];

    /**
     * Páginas permitidas como retorno após o login com conta. O painel fica de
     * fora: a entrada sempre termina no acervo e o painel é uma escolha posterior.
     */
    private const ACCOUNT_DESTINATIONS = [
        'index.php',
        'ver_conteudo.php',
        'favoritos.php',
        'minha_conta.php',
        'notificacoes.php',
    ];

    public static function isEnabled(array $settings): bool
    {
        $value = $settings[self::SETTING_KEY] ?? self::DEFAULT_ENABLED;
        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? self::DEFAULT_ENABLED;
    }

    /** Lê somente a chave necessária, para uso fora do bootstrap web. */
    public static function readEnabled(PDO $pdo): bool
    {
        try {
            $stmt = $pdo->prepare('SELECT setting_value FROM system_settings WHERE setting_key = ?');
            $stmt->execute([self::SETTING_KEY]);
            $raw = $stmt->fetchColumn();
            if ($raw === false) {
                return self::DEFAULT_ENABLED;
            }
            return self::isEnabled([self::SETTING_KEY => json_decode((string)$raw, true, 4, JSON_THROW_ON_ERROR)]);
        } catch (Throwable $exception) {
            // Sem configuração legível, o portal mantém o padrão documentado.
            error_log('DocGov acesso de visitantes: ' . $exception->getMessage());
            return self::DEFAULT_ENABLED;
        }
    }

    /** Marca a sessão atual como visitante; exige sessão iniciada. */
    public static function start(): void
    {
        session_regenerate_id(true);
        unset($_SESSION['user'], $_SESSION['admin_logged']);
        $_SESSION[self::SESSION_KEY] = true;
    }

    public static function end(): void
    {
        unset($_SESSION[self::SESSION_KEY]);
    }

    /** Visitante ativo: entrou sem conta, não está logado e a opção segue habilitada. */
    public static function isActive(array $settings): bool
    {
        return empty($_SESSION['user'])
            && !empty($_SESSION[self::SESSION_KEY])
            && self::isEnabled($settings);
    }

    /**
     * Valida o destino relativo após a entrada. Aceita apenas páginas conhecidas
     * do portal, evitando redirecionamento aberto ou injeção de cabeçalho.
     */
    public static function safeNextPath(mixed $next, bool $guest = true): string
    {
        if (!is_string($next)) {
            return self::FALLBACK_PATH;
        }
        $next = trim($next);
        if ($next === '' || strlen($next) > self::MAX_NEXT_LENGTH || preg_match('/[\x00-\x20\x7F\\\\#]/', $next)) {
            return self::FALLBACK_PATH;
        }

        [$path, $query] = array_pad(explode('?', $next, 2), 2, null);
        $allowed = $guest ? self::GUEST_DESTINATIONS : self::ACCOUNT_DESTINATIONS;
        if (!in_array($path, $allowed, true)) {
            return self::FALLBACK_PATH;
        }

        return ($query === null || $query === '') ? $path : $path . '?' . $query;
    }

    /** Endereço relativo da página atual do portal, usado como retorno após o login. */
    public static function currentPortalPath(): string
    {
        $script = basename((string)($_SERVER['SCRIPT_NAME'] ?? self::FALLBACK_PATH));
        $query = (string)($_SERVER['QUERY_STRING'] ?? '');
        return $query !== '' ? $script . '?' . $query : $script;
    }

    public static function loginUrl(?string $next = null): string
    {
        $next = self::safeNextPath($next ?? self::currentPortalPath(), false);
        return $next === self::FALLBACK_PATH ? 'login.php' : 'login.php?next=' . rawurlencode($next);
    }
}
