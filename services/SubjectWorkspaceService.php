<?php

declare(strict_types=1);

require_once __DIR__ . '/VideoEmbedService.php';

/**
 * Mantém a documentação estruturada de um processo no nível Assunto.
 * A autorização continua sendo responsabilidade do PermissionService.
 */
final class SubjectWorkspaceService
{
    public const SECTIONS = ['overview', 'description', 'flow', 'steps', 'video', 'evidence', 'faq', 'integrations'];

    public function __construct(private PDO $pdo)
    {
    }

    /** @return array<string, mixed> */
    public function get(int $subjectId): array
    {
        $defaults = $this->defaults($subjectId);
        if ($subjectId <= 0) {
            return $defaults;
        }

        $stmt = $this->pdo->prepare('SELECT * FROM subject_workspaces WHERE subject_id = :subject_id');
        $stmt->execute([':subject_id' => $subjectId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return $defaults;
        }

        foreach (['flow_steps', 'procedure_steps', 'evidences', 'faq_items', 'integrations'] as $jsonField) {
            $decoded = json_decode((string)($row[$jsonField] ?? '[]'), true);
            $row[$jsonField] = is_array($decoded) ? array_values($decoded) : [];
        }
        return array_replace($defaults, $row);
    }

    /** @return array<int, array<string, mixed>> */
    public function history(int $subjectId, int $limit = 80): array
    {
        $limit = max(1, min(200, $limit));
        $stmt = $this->pdo->prepare("\n            SELECT h.id, h.section, h.action, h.summary, h.created_at,\n                   u.name AS actor_name, u.username AS actor_username\n            FROM subject_workspace_history h\n            LEFT JOIN users u ON u.id = h.actor_id\n            WHERE h.subject_id = :subject_id\n            ORDER BY h.created_at DESC, h.id DESC\n            LIMIT {$limit}\n        ");
        $stmt->execute([':subject_id' => $subjectId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** @return array<string, bool> */
    public function completeness(array $workspace): array
    {
        return [
            'overview' => trim((string)$workspace['objective']) !== '' && trim((string)$workspace['owner_name']) !== '',
            'description' => trim((string)$workspace['description']) !== '',
            'flow' => !empty($workspace['flow_steps']),
            'steps' => !empty($workspace['procedure_steps']),
            'video' => trim((string)$workspace['video_url']) !== '' || (int)($workspace['video_document_id'] ?? 0) > 0,
            'evidence' => !empty($workspace['evidences']),
            'faq' => !empty($workspace['faq_items']),
            'integrations' => !empty($workspace['integrations']),
        ];
    }

    /** @param array<string, mixed> $input */
    public function saveSection(int $subjectId, int $actorId, string $section, array $input, bool $canApprove): array
    {
        $section = strtolower(trim($section));
        if ($subjectId <= 0 || $actorId <= 0 || !in_array($section, self::SECTIONS, true)) {
            throw new InvalidArgumentException('Seção de documentação inválida.');
        }
        $subjectStmt = $this->pdo->prepare('SELECT id FROM subjects WHERE id = :id');
        $subjectStmt->execute([':id' => $subjectId]);
        if (!$subjectStmt->fetchColumn()) {
            throw new RuntimeException('Assunto não encontrado.');
        }

        $before = $this->get($subjectId);
        $changes = $this->normaliseSection($section, $input, $canApprove, $subjectId, $before);
        $assignments = [];
        $params = [':subject_id' => $subjectId, ':actor_id' => $actorId];
        foreach ($changes as $column => $value) {
            $placeholder = ':' . $column;
            $assignments[] = $column . ' = ' . (in_array($column, ['flow_steps', 'procedure_steps', 'evidences', 'faq_items', 'integrations'], true)
                ? 'CAST(' . $placeholder . ' AS JSONB)'
                : $placeholder);
            $params[$placeholder] = is_array($value)
                ? json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
                : $value;
        }

        $this->pdo->beginTransaction();
        try {
            $ensure = $this->pdo->prepare("\n                INSERT INTO subject_workspaces (subject_id, created_by, updated_by)\n                VALUES (:subject_id, :actor_id, :actor_id)\n                ON CONFLICT (subject_id) DO NOTHING\n            ");
            $ensure->execute([':subject_id' => $subjectId, ':actor_id' => $actorId]);

            $sql = 'UPDATE subject_workspaces SET ' . implode(', ', $assignments) . ', updated_by = :actor_id WHERE subject_id = :subject_id';
            $update = $this->pdo->prepare($sql);
            $update->execute($params);

            $after = $this->get($subjectId);
            $history = $this->pdo->prepare("\n                INSERT INTO subject_workspace_history (subject_id, actor_id, section, action, summary, snapshot)\n                VALUES (:subject_id, :actor_id, :section, 'updated', :summary, CAST(:snapshot AS JSONB))\n            ");
            $history->execute([
                ':subject_id' => $subjectId,
                ':actor_id' => $actorId,
                ':section' => $section,
                ':summary' => $this->sectionLabel($section) . ' atualizada.',
                ':snapshot' => json_encode($after, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            ]);
            $this->pdo->commit();
            return $after;
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function sectionLabel(string $section): string
    {
        return match ($section) {
            'overview' => 'Visão geral',
            'description' => 'Descrição',
            'flow' => 'Fluxo do processo',
            'steps' => 'Passo a passo',
            'video' => 'Vídeo',
            'evidence' => 'Evidências',
            'faq' => 'Erros e FAQ',
            'integrations' => 'Integrações',
            default => 'Documentação',
        };
    }

    /** @return array<string, mixed> */
    private function normaliseSection(string $section, array $input, bool $canApprove, int $subjectId, array $before): array
    {
        return match ($section) {
            'overview' => $this->normaliseOverview($input, $canApprove, $before),
            'description' => [
                'description' => $this->text($input['description'] ?? '', 12000, 'Descrição'),
                'process_summary' => $this->text($input['process_summary'] ?? '', 6000, 'Resumo do processo'),
                'process_start' => $this->text($input['process_start'] ?? '', 2000, 'Início do processo'),
                'process_validation' => $this->text($input['process_validation'] ?? '', 2000, 'Validação'),
                'expected_result' => $this->text($input['expected_result'] ?? '', 2000, 'Resultado esperado'),
            ],
            'flow' => ['flow_steps' => $this->normaliseRows($input, 'flow', [
                'title' => [160, true], 'description' => [1500, false], 'owner' => [160, false], 'result' => [500, false],
            ], 30)],
            'steps' => ['procedure_steps' => $this->normaliseRows($input, 'procedure', [
                'title' => [160, true], 'description' => [3000, true], 'responsible' => [160, false], 'warning' => [600, false],
            ], 60)],
            'video' => $this->normaliseVideo($input, $subjectId),
            'evidence' => ['evidences' => $this->normaliseEvidences($input, $subjectId)],
            'faq' => ['faq_items' => $this->normaliseFaq($input)],
            'integrations' => ['integrations' => $this->normaliseIntegrations($input)],
            default => throw new InvalidArgumentException('Seção inválida.'),
        };
    }

    private function normaliseOverview(array $input, bool $canApprove, array $before): array
    {
        $status = strtolower(trim((string)($input['documentation_status'] ?? 'draft')));
        if (!in_array($status, ['draft', 'review', 'approved', 'deprecated'], true)) {
            throw new InvalidArgumentException('Status da documentação inválido.');
        }
        if (!$canApprove && (
            in_array($status, ['approved', 'deprecated'], true)
            || in_array((string)($before['documentation_status'] ?? ''), ['approved', 'deprecated'], true)
        )) {
            $status = (string)($before['documentation_status'] ?? 'draft');
        }
        $reviewDate = trim((string)($input['next_review_on'] ?? ''));
        if ($reviewDate !== '') {
            $parsedReviewDate = DateTimeImmutable::createFromFormat('!Y-m-d', $reviewDate);
            $dateErrors = DateTimeImmutable::getLastErrors();
            if ($parsedReviewDate === false
                || ($dateErrors !== false && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0))
                || $parsedReviewDate->format('Y-m-d') !== $reviewDate) {
                throw new InvalidArgumentException('Data da próxima revisão inválida.');
            }
        }
        return [
            'objective' => $this->text($input['objective'] ?? '', 600, 'Objetivo'),
            'owner_name' => $this->text($input['owner_name'] ?? '', 255, 'Responsável'),
            'audience' => $this->text($input['audience'] ?? '', 600, 'Público-alvo'),
            'documentation_status' => $status,
            'version_label' => $this->text($input['version_label'] ?? '1.0', 30, 'Versão'),
            'next_review_on' => $reviewDate !== '' ? $reviewDate : null,
        ];
    }

    private function normaliseVideo(array $input, int $subjectId): array
    {
        $url = trim((string)($input['video_url'] ?? ''));
        if ($url !== '' && VideoEmbedService::resolve($url)['kind'] === 'invalid') {
            throw new InvalidArgumentException('Informe uma URL de vídeo HTTP ou HTTPS válida.');
        }
        $videoDocumentId = (int)($input['video_document_id'] ?? 0);
        if ($videoDocumentId > 0) {
            $document = $this->pdo->prepare("SELECT id FROM documents WHERE id = :id AND subject_id = :subject_id AND content_type = 'video' AND status <> 'inactive'");
            $document->execute([':id' => $videoDocumentId, ':subject_id' => $subjectId]);
            if (!$document->fetchColumn()) {
                throw new InvalidArgumentException('O vídeo local selecionado não pertence a este assunto.');
            }
        }
        return [
            'video_title' => $this->text($input['video_title'] ?? '', 255, 'Título do vídeo'),
            'video_url' => mb_substr($url, 0, 2000),
            'video_document_id' => $videoDocumentId > 0 ? $videoDocumentId : null,
        ];
    }

    /** @param array<string, array{0:int,1:bool}> $fields */
    private function normaliseRows(array $input, string $prefix, array $fields, int $limit): array
    {
        $firstField = array_key_first($fields);
        $rowCount = count((array)($input[$prefix . '_' . $firstField] ?? []));
        if ($rowCount > $limit) {
            throw new InvalidArgumentException("O limite desta seção é {$limit} itens.");
        }
        $rows = [];
        for ($index = 0; $index < $rowCount; $index++) {
            $row = [];
            $hasContent = false;
            foreach ($fields as $field => [$maxLength, $required]) {
                $value = trim((string)(((array)($input[$prefix . '_' . $field] ?? []))[$index] ?? ''));
                if ($value !== '') $hasContent = true;
                $row[$field] = mb_substr($value, 0, $maxLength);
            }
            if ($hasContent) {
                foreach ($fields as $field => [$maxLength, $required]) {
                    if ($required && $row[$field] === '') {
                        throw new InvalidArgumentException('Preencha os campos obrigatórios dos itens adicionados.');
                    }
                }
                $rows[] = $row;
            }
        }
        return $rows;
    }

    private function normaliseEvidences(array $input, int $subjectId): array
    {
        $rows = $this->normaliseRows($input, 'evidence', [
            'title' => [160, true], 'kind' => [30, false], 'url' => [2000, false], 'document_id' => [20, false], 'description' => [1000, false],
        ], 60);
        $allowedKinds = ['document', 'link', 'image', 'file', 'record'];
        foreach ($rows as &$row) {
            $row['kind'] = in_array($row['kind'], $allowedKinds, true) ? $row['kind'] : 'record';
            $row['document_id'] = (int)$row['document_id'];
            if ($row['url'] !== '' && VideoEmbedService::normalizeExternalUrl($row['url']) === null) {
                throw new InvalidArgumentException('Uma das evidências possui URL inválida.');
            }
            if ($row['document_id'] > 0) {
                $document = $this->pdo->prepare('SELECT id FROM documents WHERE id = :id AND subject_id = :subject_id');
                $document->execute([':id' => $row['document_id'], ':subject_id' => $subjectId]);
                if (!$document->fetchColumn()) {
                    throw new InvalidArgumentException('Uma evidência referencia um documento fora deste assunto.');
                }
            }
        }
        unset($row);
        return $rows;
    }

    private function normaliseFaq(array $input): array
    {
        $rows = $this->normaliseRows($input, 'faq', [
            'kind' => [20, false], 'question' => [300, true], 'answer' => [3000, true],
        ], 80);
        foreach ($rows as &$row) {
            $row['kind'] = in_array($row['kind'], ['faq', 'error'], true) ? $row['kind'] : 'faq';
        }
        unset($row);
        return $rows;
    }

    private function normaliseIntegrations(array $input): array
    {
        $rows = $this->normaliseRows($input, 'integration', [
            'name' => [160, true], 'type' => [80, false], 'url' => [2000, false], 'description' => [1200, false], 'status' => [20, false],
        ], 50);
        foreach ($rows as &$row) {
            if ($row['url'] !== '' && VideoEmbedService::normalizeExternalUrl($row['url']) === null) {
                throw new InvalidArgumentException('Uma das integrações possui URL inválida.');
            }
            $row['status'] = in_array($row['status'], ['active', 'attention', 'inactive'], true) ? $row['status'] : 'active';
        }
        unset($row);
        return $rows;
    }

    private function text(mixed $value, int $maxLength, string $label): string
    {
        $text = trim((string)$value);
        if (mb_strlen($text) > $maxLength) {
            throw new InvalidArgumentException("{$label} excede o limite de {$maxLength} caracteres.");
        }
        return $text;
    }

    /** @return array<string, mixed> */
    private function defaults(int $subjectId): array
    {
        return [
            'subject_id' => $subjectId,
            'objective' => '', 'owner_name' => '', 'audience' => '',
            'documentation_status' => 'draft', 'version_label' => '1.0', 'next_review_on' => null,
            'description' => '', 'process_summary' => '', 'process_start' => '',
            'process_validation' => '', 'expected_result' => '',
            'video_title' => '', 'video_url' => '', 'video_document_id' => null,
            'flow_steps' => [], 'procedure_steps' => [], 'evidences' => [],
            'faq_items' => [], 'integrations' => [],
            'created_by' => null, 'updated_by' => null, 'created_at' => null, 'updated_at' => null,
        ];
    }
}
