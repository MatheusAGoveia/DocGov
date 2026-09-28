<?php

/** Gera slugs únicos por assunto com proteção contra gravações concorrentes. */
final class DocumentSlugService
{
    private const MAX_SLUG_LENGTH = 255;
    private const ADVISORY_LOCK_NAMESPACE = 736423;

    public function __construct(private PDO $pdo)
    {
    }

    /**
     * Deve ser chamado dentro da mesma transação que gravará o documento.
     * O lock por assunto serializa a escolha do slug e elimina a janela entre
     * o SELECT de disponibilidade e o INSERT/UPDATE.
     */
    public function reserve(int $subjectId, string $title, ?int $excludeDocumentId = null): string
    {
        if ($subjectId <= 0 || trim($title) === '') {
            throw new InvalidArgumentException('Assunto e título são obrigatórios para gerar o endereço do documento.');
        }
        if (!$this->pdo->inTransaction()) {
            throw new LogicException('A reserva do slug precisa ocorrer dentro de uma transação.');
        }

        $lock = $this->pdo->prepare('SELECT pg_advisory_xact_lock(:namespace, :subject_id)');
        $lock->bindValue(':namespace', self::ADVISORY_LOCK_NAMESPACE, PDO::PARAM_INT);
        $lock->bindValue(':subject_id', $subjectId, PDO::PARAM_INT);
        $lock->execute();

        $excludeCurrentDocument = $excludeDocumentId !== null && $excludeDocumentId > 0;
        $slugStmt = $this->pdo->prepare('
            SELECT slug
            FROM documents
            WHERE subject_id = :subject_id
            ' . ($excludeCurrentDocument ? ' AND id <> :exclude_id' : '') . '
        ');
        $slugStmt->bindValue(':subject_id', $subjectId, PDO::PARAM_INT);
        if ($excludeCurrentDocument) {
            $slugStmt->bindValue(':exclude_id', $excludeDocumentId, PDO::PARAM_INT);
        }
        $slugStmt->execute();
        $usedSlugs = array_fill_keys(array_map('strval', $slugStmt->fetchAll(PDO::FETCH_COLUMN)), true);

        $baseSlug = $this->fit(slugify($title), '');
        if (!isset($usedSlugs[$baseSlug])) {
            return $baseSlug;
        }

        for ($suffixNumber = 2; $suffixNumber <= 1000000; $suffixNumber++) {
            $suffix = '-' . $suffixNumber;
            $candidate = $this->fit($baseSlug, $suffix);
            if (!isset($usedSlugs[$candidate])) {
                return $candidate;
            }
        }

        throw new RuntimeException('Não foi possível gerar um endereço único para o documento.');
    }

    private function fit(string $baseSlug, string $suffix): string
    {
        $baseSlug = trim($baseSlug, '-');
        if ($baseSlug === '') {
            $baseSlug = 'documento';
        }
        $maximumBaseLength = self::MAX_SLUG_LENGTH - strlen($suffix);
        $baseSlug = rtrim(mb_substr($baseSlug, 0, $maximumBaseLength), '-');
        if ($baseSlug === '') {
            $baseSlug = 'doc';
        }
        return $baseSlug . $suffix;
    }
}
