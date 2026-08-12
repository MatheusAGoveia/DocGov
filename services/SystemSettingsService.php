<?php

final class SystemSettingsService {
    private PDO $pdo;
    private ?array $cache = null;

    private const DEFAULTS = [
        'portal_name' => 'DocGov',
        'organization_name' => 'Prefeitura Municipal',
        'portal_description' => 'Sistema de Gestão Documental',
        'portal_theme' => 'emerald',
        'system_logo_path' => '',
        'support_email' => '',
        'timezone' => 'America/Sao_Paulo',
        'session_timeout_minutes' => 120,
        'cors_enabled' => false,
        'cors_allowed_origins' => [],
        'cors_allowed_methods' => ['GET', 'POST', 'DELETE', 'OPTIONS'],
        'cors_allow_credentials' => false,
        'maintenance_enabled' => false,
        'maintenance_mode' => 'full',
        'maintenance_scope' => ['portal', 'admin', 'api', 'files'],
        'maintenance_start_at' => null,
        'maintenance_end_at' => null,
        'maintenance_reason' => 'Atualização planejada',
        'maintenance_reference' => '',
        'maintenance_responsible' => '',
        'maintenance_progress' => 0,
        'maintenance_announce_minutes' => 60,
        'maintenance_auto_refresh_seconds' => 30,
        'maintenance_title' => 'Sistema em manutenção',
        'maintenance_message' => 'Estamos realizando melhorias. O acesso será restabelecido em breve.',
    ];

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    public function all(bool $refresh = false): array {
        if ($this->cache !== null && !$refresh) {
            return $this->cache;
        }
        $settings = self::DEFAULTS;
        try {
            $rows = $this->pdo->query('SELECT setting_key, setting_value FROM system_settings')->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $row) {
                $settings[$row['setting_key']] = json_decode((string)$row['setting_value'], true, 512, JSON_THROW_ON_ERROR);
            }
        } catch (Throwable $exception) {
            // Permite que a aplicação continue usando padrões antes da migração.
            error_log('DocGov settings: ' . $exception->getMessage());
        }
        $this->cache = $settings;
        return $settings;
    }

    public function get(string $key, mixed $fallback = null): mixed {
        $settings = $this->all();
        return array_key_exists($key, $settings) ? $settings[$key] : $fallback;
    }

    public static function portalThemes(): array {
        return [
            'emerald' => ['label' => 'Verde Prefeitura', 'description' => 'Equilibrado e institucional.', 'accent' => '#0f8f6f'],
            'blue' => ['label' => 'Azul Institucional', 'description' => 'Formal e objetivo.', 'accent' => '#2563eb'],
            'indigo' => ['label' => 'Índigo', 'description' => 'Técnico e sóbrio.', 'accent' => '#4f46e5'],
            'violet' => ['label' => 'Violeta', 'description' => 'Distinto e contemporâneo.', 'accent' => '#7c3aed'],
            'rose' => ['label' => 'Rubi', 'description' => 'Marcante, com contraste alto.', 'accent' => '#be123c'],
            'amber' => ['label' => 'Âmbar', 'description' => 'Quente e acessível.', 'accent' => '#b45309'],
            'ocean' => ['label' => 'Oceano', 'description' => 'Calmo e confiável.', 'accent' => '#0e7490'],
            'graphite' => ['label' => 'Grafite', 'description' => 'Neutro e minimalista.', 'accent' => '#3f3f46'],
        ];
    }

    public static function normalizePortalTheme(mixed $theme): string {
        $theme = strtolower(trim((string)$theme));
        return array_key_exists($theme, self::portalThemes()) ? $theme : 'emerald';
    }

    public function saveMany(array $values, int $actorId): void {
        if ($actorId <= 0) {
            throw new InvalidArgumentException('Executor inválido para alterar configurações.');
        }
        $allowed = array_keys(self::DEFAULTS);
        $stmt = $this->pdo->prepare("INSERT INTO system_settings (setting_key, setting_value, updated_by, updated_at) VALUES (:key, CAST(:value AS JSONB), :actor, CURRENT_TIMESTAMP) ON CONFLICT (setting_key) DO UPDATE SET setting_value = EXCLUDED.setting_value, updated_by = EXCLUDED.updated_by, updated_at = CURRENT_TIMESTAMP");
        $started = !$this->pdo->inTransaction();
        if ($started) {
            $this->pdo->beginTransaction();
        }
        try {
            foreach ($values as $key => $value) {
                if (!in_array($key, $allowed, true)) {
                    throw new InvalidArgumentException("Configuração não permitida: {$key}");
                }
                $stmt->execute([
                    ':key' => $key,
                    ':value' => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                    ':actor' => $actorId,
                ]);
            }
            if ($started) {
                $this->pdo->commit();
            }
            $this->cache = null;
        } catch (Throwable $exception) {
            if ($started && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function maintenanceStatus(?DateTimeImmutable $now = null): array {
        $settings = $this->all();
        $timezone = new DateTimeZone((string)$settings['timezone']);
        $now = ($now ?? new DateTimeImmutable('now', $timezone))->setTimezone(new DateTimeZone('UTC'));
        $start = $this->parseUtcDate($settings['maintenance_start_at']);
        $end = $this->parseUtcDate($settings['maintenance_end_at']);
        $enabled = (bool)$settings['maintenance_enabled'];
        $active = $enabled && ($start === null || $now >= $start) && ($end === null || $now < $end);
        $scheduled = $enabled && $start !== null && $now < $start;
        $expired = $enabled && $end !== null && $now >= $end;
        return compact('enabled', 'active', 'scheduled', 'expired', 'start', 'end');
    }

    private function parseUtcDate(mixed $value): ?DateTimeImmutable {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }
        try {
            return (new DateTimeImmutable($value))->setTimezone(new DateTimeZone('UTC'));
        } catch (Throwable) {
            return null;
        }
    }
}
