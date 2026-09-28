<?php

declare(strict_types=1);

/** Valida estruturas de fluxo e organograma antes de armazená-las em JSONB. */
final class StructuredContentService
{
    /** @return array<string,mixed> */
    public static function normalize(string $contentType, mixed $payload): array
    {
        if (is_string($payload)) {
            try {
                $payload = json_decode($payload, true, 64, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                throw new InvalidArgumentException('A estrutura enviada é inválida. Atualize a página e tente novamente.');
            }
        }
        if (!is_array($payload)) $payload = [];
        return match ($contentType) {
            'flow' => self::normalizeFlow($payload),
            'orgchart' => self::normalizeOrgChart($payload),
            default => [],
        };
    }

    /** @return array{version:int,nodes:array<int,array<string,mixed>>} */
    private static function normalizeFlow(array $payload): array
    {
        $source = array_values((array)($payload['nodes'] ?? []));
        if ($source === []) throw new InvalidArgumentException('Adicione ao menos uma etapa ao fluxo do processo.');
        if (count($source) > 100) throw new InvalidArgumentException('O fluxo aceita no máximo 100 etapas.');
        $nodes = [];
        foreach ($source as $index => $row) {
            if (!is_array($row)) continue;
            $title = self::text($row['title'] ?? '', 160);
            if ($title === '') throw new InvalidArgumentException('Todas as etapas do fluxo precisam de um título.');
            $nodes[] = [
                'id' => self::id($row['id'] ?? '', 'step-' . ($index + 1)),
                'type' => in_array(($row['type'] ?? ''), ['start', 'process', 'decision', 'end'], true) ? $row['type'] : 'process',
                'title' => $title,
                'description' => self::text($row['description'] ?? '', 1500),
                'owner' => self::text($row['owner'] ?? '', 160),
            ];
        }
        return ['version' => 1, 'nodes' => $nodes];
    }

    /** @return array{version:int,nodes:array<int,array<string,mixed>>} */
    private static function normalizeOrgChart(array $payload): array
    {
        $source = array_values((array)($payload['nodes'] ?? []));
        if ($source === []) throw new InvalidArgumentException('Adicione ao menos um nível ao organograma.');
        if (count($source) > 200) throw new InvalidArgumentException('O organograma aceita no máximo 200 nós.');
        $nodes = [];
        $ids = [];
        foreach ($source as $index => $row) {
            if (!is_array($row)) continue;
            $name = self::text($row['name'] ?? '', 160);
            if ($name === '') throw new InvalidArgumentException('Todos os nós do organograma precisam de um nome.');
            $id = self::id($row['id'] ?? '', 'node-' . ($index + 1));
            if (isset($ids[$id])) $id .= '-' . ($index + 1);
            $ids[$id] = true;
            $nodes[] = [
                'id' => $id,
                'parent_id' => self::id($row['parent_id'] ?? '', ''),
                'name' => $name,
                'role' => self::text($row['role'] ?? '', 160),
                'description' => self::text($row['description'] ?? '', 800),
            ];
        }
        foreach ($nodes as &$node) {
            if ($node['parent_id'] === '' || !isset($ids[$node['parent_id']])) $node['parent_id'] = null;
            if ($node['parent_id'] === $node['id']) throw new InvalidArgumentException('Um nó do organograma não pode ser subordinado a ele mesmo.');
        }
        unset($node);
        self::assertAcyclic($nodes);
        return ['version' => 1, 'nodes' => $nodes];
    }

    private static function assertAcyclic(array $nodes): void
    {
        $parents = [];
        foreach ($nodes as $node) $parents[(string)$node['id']] = $node['parent_id'];
        foreach (array_keys($parents) as $id) {
            $seen = [];
            $current = $id;
            while ($current !== null && $current !== '') {
                if (isset($seen[$current])) throw new InvalidArgumentException('O organograma possui uma referência circular.');
                $seen[$current] = true;
                $current = $parents[$current] ?? null;
            }
        }
    }

    private static function id(mixed $value, string $fallback): string
    {
        $id = strtolower(trim((string)$value));
        return preg_match('/^[a-z0-9][a-z0-9_-]{0,79}$/', $id) ? $id : $fallback;
    }

    private static function text(mixed $value, int $max): string
    {
        return mb_substr(trim(strip_tags((string)$value)), 0, $max);
    }
}
