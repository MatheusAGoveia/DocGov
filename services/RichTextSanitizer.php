<?php

declare(strict_types=1);

/** Sanitiza o HTML produzido pelo editor antes de persistir ou renderizar. */
final class RichTextSanitizer
{
    private const ALLOWED_TAGS = [
        'p', 'br', 'hr', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
        'strong', 'b', 'em', 'i', 'u', 's', 'blockquote', 'pre', 'code',
        'ul', 'ol', 'li', 'a', 'table', 'thead', 'tbody', 'tfoot', 'tr', 'th', 'td',
        'figure', 'figcaption', 'img', 'iframe', 'span', 'div', 'sup', 'sub',
    ];

    public static function sanitize(string $html, int $maxBytes = 2_097_152): string
    {
        $html = trim($html);
        if ($html === '') return '';
        if (strlen($html) > $maxBytes) {
            throw new InvalidArgumentException('O conteúdo formatado excede o limite de 2 MB.');
        }

        $dom = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $loaded = $dom->loadHTML(
            '<?xml encoding="utf-8" ?><div id="govdoc-rich-root">' . $html . '</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (!$loaded) return '';

        $root = $dom->getElementById('govdoc-rich-root');
        if (!$root) return '';
        self::cleanChildren($root);

        $result = '';
        foreach ($root->childNodes as $child) {
            $result .= $dom->saveHTML($child);
        }
        return trim($result);
    }

    private static function cleanChildren(DOMNode $parent): void
    {
        for ($node = $parent->firstChild; $node !== null;) {
            $next = $node->nextSibling;
            if ($node instanceof DOMComment) {
                $parent->removeChild($node);
            } elseif ($node instanceof DOMElement) {
                $tag = strtolower($node->tagName);
                if (!in_array($tag, self::ALLOWED_TAGS, true)) {
                    if (in_array($tag, ['script', 'style', 'iframe', 'object', 'embed', 'form', 'input', 'button'], true)) {
                        $parent->removeChild($node);
                    } else {
                        while ($node->firstChild) $parent->insertBefore($node->firstChild, $node);
                        $parent->removeChild($node);
                    }
                } elseif ($tag === 'iframe' && !self::safeVideoEmbed((string)$node->getAttribute('src'))) {
                    $parent->removeChild($node);
                } else {
                    self::cleanAttributes($node, $tag);
                    self::cleanChildren($node);
                }
            }
            $node = $next;
        }
    }

    private static function cleanAttributes(DOMElement $element, string $tag): void
    {
        $allowed = ['title', 'style', 'class'];
        if ($tag === 'a') $allowed = array_merge($allowed, ['href', 'target', 'rel']);
        if ($tag === 'img') $allowed = array_merge($allowed, ['src', 'alt', 'width', 'height']);
        if ($tag === 'iframe') $allowed = array_merge($allowed, ['src', 'allowfullscreen', 'frameborder', 'loading', 'referrerpolicy']);
        if (in_array($tag, ['th', 'td'], true)) $allowed = array_merge($allowed, ['colspan', 'rowspan']);
        if ($tag === 'li') $allowed[] = 'data-list';
        if (in_array($tag, ['div', 'pre', 'code'], true)) $allowed[] = 'data-language';

        foreach (iterator_to_array($element->attributes) as $attribute) {
            $name = strtolower($attribute->name);
            if (!in_array($name, $allowed, true)) {
                $element->removeAttribute($attribute->name);
                continue;
            }
            $value = trim($attribute->value);
            if (in_array($name, ['href', 'src'], true) && !self::safeUrl($value, $name === 'src')) {
                $element->removeAttribute($attribute->name);
            } elseif ($name === 'style') {
                $style = self::safeStyle($value, $tag);
                $style === '' ? $element->removeAttribute('style') : $element->setAttribute('style', $style);
            } elseif ($name === 'class') {
                $classes = self::safeClasses($value);
                $classes === '' ? $element->removeAttribute('class') : $element->setAttribute('class', $classes);
            } elseif ($name === 'data-list' && !in_array($value, ['ordered', 'bullet', 'checked', 'unchecked'], true)) {
                $element->removeAttribute($name);
            } elseif ($name === 'data-language' && !preg_match('/^[a-z0-9_-]{1,40}$/i', $value)) {
                $element->removeAttribute($name);
            } elseif ($name === 'target' && $value !== '_blank') {
                $element->removeAttribute('target');
            } elseif ($name === 'frameborder' && $value !== '0') {
                $element->removeAttribute('frameborder');
            } elseif (in_array($name, ['width', 'height', 'colspan', 'rowspan'], true) && !preg_match('/^\d{1,4}$/', $value)) {
                $element->removeAttribute($name);
            }
        }
        if ($tag === 'a' && $element->getAttribute('target') === '_blank') {
            $element->setAttribute('rel', 'noopener noreferrer');
        }
        if ($tag === 'iframe') {
            $element->setAttribute('loading', 'lazy');
            $element->setAttribute('referrerpolicy', 'strict-origin-when-cross-origin');
            $element->setAttribute('allowfullscreen', 'allowfullscreen');
        }
    }

    private static function safeUrl(string $url, bool $allowImageData): bool
    {
        if ($allowImageData && preg_match('~^(?:\.\./)?document-media\.php\?id=[1-9][0-9]*$~', $url)) return true;
        if ($url === '' || str_starts_with($url, '#') || str_starts_with($url, '/')) return true;
        if ($allowImageData && preg_match('#^data:image/(?:png|jpe?g|gif|webp);base64,[a-z0-9+/=\s]+$#i', $url)) return true;
        $scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
        return in_array($scheme, ['http', 'https', 'mailto', 'tel'], true);
    }

    private static function safeVideoEmbed(string $url): bool
    {
        return preg_match('~^https://www\.youtube-nocookie\.com/embed/[A-Za-z0-9_-]{11}$~', $url) === 1
            || preg_match('~^https://player\.vimeo\.com/video/[0-9]+$~', $url) === 1;
    }

    private static function safeStyle(string $style, string $tag): string
    {
        if (preg_match('/(?:url\s*\(|expression|javascript:|@import)/i', $style)) return '';
        $allowedProperties = [
            'color', 'background-color', 'text-align', 'font-weight', 'font-style',
            'text-decoration', 'font-size', 'line-height', 'margin-left', 'padding-left',
        ];
        $safe = [];
        foreach (explode(';', $style) as $declaration) {
            if (!str_contains($declaration, ':')) continue;
            [$property, $value] = array_map('trim', explode(':', $declaration, 2));
            $property = strtolower($property);
            if ($tag === 'figure' && $property === 'width' && preg_match('/^(?:[2-9][0-9]|100)%$/', $value)) {
                $safe[] = 'width: ' . $value;
                continue;
            }
            if (in_array($property, $allowedProperties, true) && preg_match('/^[#(),.%\-\w\s]+$/u', $value)) {
                $safe[] = $property . ': ' . mb_substr($value, 0, 120);
            }
        }
        return implode('; ', $safe);
    }

    private static function safeClasses(string $classes): string
    {
        $safe = [];
        foreach (preg_split('/\s+/', trim($classes)) ?: [] as $class) {
            if (preg_match('/^govdoc-media(?:--(?:block-left|center|block-right|wrap-left|wrap-right)|-frame)?$/', $class)) {
                $safe[] = $class;
                continue;
            }
            if (preg_match('/^ql-(?:align-(?:center|right|justify)|direction-rtl|indent-[1-8]|size-(?:small|large|huge)|font-(?:serif|monospace)|ui|video|code-block(?:-container)?)$/', $class)) {
                $safe[] = $class;
            }
        }
        return implode(' ', array_unique($safe));
    }
}
