<?php

declare(strict_types=1);

/**
 * Normaliza URLs de vídeo e libera incorporação apenas para provedores confiáveis.
 * URLs de mídia direta continuam sendo reproduzidas pelo elemento <video> nativo.
 */
final class VideoEmbedService
{
    /** @return array{kind: 'youtube'|'vimeo'|'direct'|'external'|'invalid', url?: string, embed_url?: string, provider?: string} */
    public static function resolve(string $url): array
    {
        $normalizedUrl = self::normalizeExternalUrl($url);
        if ($normalizedUrl === null) {
            return ['kind' => 'invalid'];
        }

        $parts = parse_url($normalizedUrl);
        $host = strtolower((string)($parts['host'] ?? ''));
        $host = preg_replace('/^www\./', '', $host) ?? $host;

        $youtubeId = self::extractYouTubeId($parts, $host);
        if ($youtubeId !== null) {
            return [
                'kind' => 'youtube',
                'provider' => 'YouTube',
                'url' => $normalizedUrl,
                'embed_url' => 'https://www.youtube-nocookie.com/embed/' . rawurlencode($youtubeId),
            ];
        }

        $vimeoId = self::extractVimeoId($parts, $host);
        if ($vimeoId !== null) {
            return [
                'kind' => 'vimeo',
                'provider' => 'Vimeo',
                'url' => $normalizedUrl,
                'embed_url' => 'https://player.vimeo.com/video/' . rawurlencode($vimeoId),
            ];
        }

        $path = strtolower((string)($parts['path'] ?? ''));
        if (preg_match('/\.(mp4|webm|ogv|ogg|m4v|mov)$/', $path) === 1) {
            return ['kind' => 'direct', 'provider' => 'Vídeo externo', 'url' => $normalizedUrl];
        }

        return ['kind' => 'external', 'url' => $normalizedUrl];
    }

    public static function normalizeExternalUrl(string $url): ?string
    {
        $url = trim($url);
        if ($url === '' || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        $parts = parse_url($url);
        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        $host = (string)($parts['host'] ?? '');
        if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
            return null;
        }

        return $url;
    }

    /** @param array<string, mixed> $parts */
    private static function extractYouTubeId(array $parts, string $host): ?string
    {
        $videoId = null;
        $path = trim((string)($parts['path'] ?? ''), '/');

        if ($host === 'youtu.be') {
            $videoId = explode('/', $path)[0] ?? null;
        } elseif (in_array($host, ['youtube.com', 'm.youtube.com', 'youtube-nocookie.com'], true)) {
            parse_str((string)($parts['query'] ?? ''), $query);
            $videoId = isset($query['v']) && is_string($query['v']) ? $query['v'] : null;

            if ($videoId === null) {
                $pathParts = explode('/', $path);
                if (in_array($pathParts[0] ?? '', ['embed', 'shorts', 'live'], true)) {
                    $videoId = $pathParts[1] ?? null;
                }
            }
        }

        $videoId = is_string($videoId) ? rawurldecode($videoId) : '';
        return preg_match('/^[A-Za-z0-9_-]{11}$/', $videoId) === 1 ? $videoId : null;
    }

    /** @param array<string, mixed> $parts */
    private static function extractVimeoId(array $parts, string $host): ?string
    {
        if ($host !== 'vimeo.com' && $host !== 'player.vimeo.com') {
            return null;
        }

        $path = trim((string)($parts['path'] ?? ''), '/');
        if (preg_match('/(?:video\/)?(\d+)/', $path, $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }
}
