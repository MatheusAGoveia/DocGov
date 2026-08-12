<?php

/** Resolve a hierarquia documental sem depender de nomes globalmente únicos. */
final class HierarchyService
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @return array{id:int,subcategory_id:int,category_id:int,subject_name:string,subcategory_name:string,category_name:string}|null
     */
    public function resolveActiveSubject(string $subject, string $subcategory = '', string $category = ''): ?array
    {
        $subject = trim($subject);
        $subcategory = trim($subcategory);
        $category = trim($category);
        if ($subject === '') return null;

        $where = [
            '(s.id::text = :subject OR s.slug = :subject OR s.name = :subject)',
            's.active = TRUE',
            'sc.active = TRUE',
            'c.active = TRUE',
        ];
        $params = [':subject' => $subject];
        if ($subcategory !== '') {
            $where[] = '(sc.id::text = :subcategory OR sc.slug = :subcategory OR sc.name = :subcategory)';
            $params[':subcategory'] = $subcategory;
        }
        if ($category !== '') {
            $where[] = '(c.id::text = :category OR c.slug = :category OR c.name = :category)';
            $params[':category'] = $category;
        }

        $stmt = $this->pdo->prepare("\n            SELECT s.id, s.subcategory_id, sc.category_id,\n                   s.name AS subject_name, sc.name AS subcategory_name, c.name AS category_name\n            FROM subjects s\n            JOIN subcategories sc ON sc.id = s.subcategory_id\n            JOIN categories c ON c.id = sc.category_id\n            WHERE " . implode(' AND ', $where) . "\n            ORDER BY CASE WHEN s.id::text = :subject THEN 0 WHEN s.slug = :subject THEN 1 ELSE 2 END, s.id\n            LIMIT 2\n        ");
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if ($rows === []) return null;
        if (count($rows) > 1) {
            throw new InvalidArgumentException('O assunto informado existe em mais de um ramo. Selecione novamente a categoria, a subcategoria e o assunto.');
        }

        $row = $rows[0];
        return [
            'id' => (int)$row['id'],
            'subcategory_id' => (int)$row['subcategory_id'],
            'category_id' => (int)$row['category_id'],
            'subject_name' => (string)$row['subject_name'],
            'subcategory_name' => (string)$row['subcategory_name'],
            'category_name' => (string)$row['category_name'],
        ];
    }
}
