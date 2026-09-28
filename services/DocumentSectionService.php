<?php

declare(strict_types=1);

/** Catálogo extensível que transforma documentos publicados em seções do assunto. */
final class DocumentSectionService
{
    private const CONTENT_TYPE_BY_EDITOR = [
        'richtext' => 'text',
        'file' => 'file',
        'code' => 'code',
        'video' => 'video',
        'link' => 'link',
        'flow' => 'flow',
        'orgchart' => 'orgchart',
    ];

    public function __construct(private PDO $pdo)
    {
    }

    /** @return array<int,array<string,mixed>> */
    public function activeSections(): array
    {
        $stmt = $this->pdo->query("\n            SELECT section_key, label, description, editor_kind, sort_order\n            FROM document_sections\n            WHERE active = TRUE\n            ORDER BY sort_order, label, section_key\n        ");
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** @return array<string,mixed> */
    public function requireActive(string $sectionKey): array
    {
        $sectionKey = strtolower(trim($sectionKey));
        $stmt = $this->pdo->prepare("\n            SELECT section_key, label, description, editor_kind, sort_order\n            FROM document_sections\n            WHERE section_key = :section_key AND active = TRUE\n        ");
        $stmt->execute([':section_key' => $sectionKey]);
        $section = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$section) {
            throw new InvalidArgumentException('Selecione uma seção de conteúdo válida.');
        }
        return $section;
    }

    /** @return array{section:array<string,mixed>,content_type:string} */
    public function resolveSelection(string $sectionKey): array
    {
        $section = $this->requireActive($sectionKey);
        $editorKind = (string)$section['editor_kind'];
        $contentType = self::CONTENT_TYPE_BY_EDITOR[$editorKind] ?? null;
        if ($contentType === null) {
            throw new RuntimeException('A seção selecionada não possui um editor compatível.');
        }
        return ['section' => $section, 'content_type' => $contentType];
    }

    public function defaultSectionForContentType(string $contentType): string
    {
        return match ($contentType) {
            'text' => 'documents',
            'file' => 'attachments',
            'code' => 'source-code',
            'video' => 'videos',
            'link' => 'links',
            'flow' => 'process-flow',
            'orgchart' => 'organization-chart',
            default => 'documents',
        };
    }

    /**
     * Só devolve seções que possuem ao menos um documento publicado no assunto.
     * A consulta não conhece chaves específicas e, portanto, inclui tipos futuros.
     *
     * @return array<int,array<string,mixed>>
     */
    public function publishedSectionsForSubject(int $subjectId): array
    {
        if ($subjectId <= 0) return [];
        $stmt = $this->pdo->prepare("\n            SELECT ds.section_key, ds.label, ds.description, ds.editor_kind, ds.sort_order,\n                   COUNT(d.id)::int AS document_count\n            FROM document_sections ds\n            JOIN documents d ON d.section_key = ds.section_key\n            WHERE d.subject_id = :subject_id\n              AND d.status = 'published'\n              AND ds.active = TRUE\n            GROUP BY ds.section_key, ds.label, ds.description, ds.editor_kind, ds.sort_order\n            HAVING COUNT(d.id) > 0\n            ORDER BY ds.sort_order, ds.label, ds.section_key\n        ");
        $stmt->execute([':subject_id' => $subjectId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Abas do editor: um rascunho já precisa ser encontrado pelo gestor.
     * Documentos na lixeira não criam abas; o catálogo mantém a ordem e
     * permite novos tipos sem condicionais na interface.
     *
     * @return array<int,array<string,mixed>>
     */
    public function existingSectionsForSubject(int $subjectId): array
    {
        if ($subjectId <= 0) return [];
        $stmt = $this->pdo->prepare("\n            SELECT ds.section_key, ds.label, ds.description, ds.editor_kind, ds.sort_order,\n                   COUNT(d.id)::int AS document_count,\n                   COUNT(d.id) FILTER (WHERE d.status = 'published')::int AS published_count\n            FROM document_sections ds\n            JOIN documents d ON d.section_key = ds.section_key\n            WHERE d.subject_id = :subject_id\n              AND d.status <> 'inactive'\n              AND ds.active = TRUE\n            GROUP BY ds.section_key, ds.label, ds.description, ds.editor_kind, ds.sort_order\n            ORDER BY ds.sort_order, ds.label, ds.section_key\n        ");
        $stmt->execute([':subject_id' => $subjectId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}
