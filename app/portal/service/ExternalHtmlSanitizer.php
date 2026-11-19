<?php

namespace app\portal\service;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use RuntimeException;

class ExternalHtmlSanitizer
{
    private const ALLOWED_TAGS = [
        'p', 'br', 'hr', 'strong', 'b', 'em', 'i', 'u', 'sup', 'sub',
        'h2', 'h3', 'h4', 'h5', 'h6', 'ul', 'ol', 'li', 'blockquote',
        'table', 'thead', 'tbody', 'tfoot', 'tr', 'th', 'td',
        'a', 'img', 'figure', 'figcaption', 'div', 'span',
    ];

    private const DROP_WITH_CONTENT = [
        'script', 'style', 'iframe', 'object', 'embed', 'form', 'button',
        'input', 'textarea', 'select', 'option', 'svg', 'canvas', 'noscript',
    ];

    private const ALLOWED_ATTRIBUTES = [
        'a' => ['href', 'title'],
        'img' => ['src', 'alt', 'title', 'width', 'height'],
        'th' => ['colspan', 'rowspan'],
        'td' => ['colspan', 'rowspan'],
    ];

    public function extractArticleBody(string $html, string $baseUrl): string
    {
        $document = $this->loadDocument($html);
        $xpath = new DOMXPath($document);
        $container = $xpath->query('//*[@id="main-body-container" and contains(concat(" ", normalize-space(@class), " "), " article-body ")]')->item(0);

        if (!$container instanceof DOMElement) {
            throw new RuntimeException('GlobeNewswire article body was not found.');
        }

        $this->sanitizeChildren($container, $baseUrl);
        $content = '';
        foreach ($container->childNodes as $child) {
            $content .= $document->saveHTML($child);
        }

        $content = trim($content);
        if ($content === '' || mb_strlen(strip_tags($content)) < 100) {
            throw new RuntimeException('GlobeNewswire article body is unexpectedly empty.');
        }

        return $content;
    }

    private function loadDocument(string $html): DOMDocument
    {
        $document = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (!$loaded) {
            throw new RuntimeException('Unable to parse external HTML.');
        }

        return $document;
    }

    private function sanitizeChildren(DOMNode $parent, string $baseUrl): void
    {
        $children = [];
        foreach ($parent->childNodes as $child) {
            $children[] = $child;
        }

        foreach ($children as $child) {
            if (!$child instanceof DOMElement) {
                continue;
            }

            $tag = strtolower($child->tagName);
            if (in_array($tag, self::DROP_WITH_CONTENT, true)) {
                $parent->removeChild($child);
                continue;
            }

            if (!in_array($tag, self::ALLOWED_TAGS, true)) {
                $this->sanitizeChildren($child, $baseUrl);
                while ($child->firstChild) {
                    $parent->insertBefore($child->firstChild, $child);
                }
                $parent->removeChild($child);
                continue;
            }

            $this->sanitizeAttributes($child, $tag, $baseUrl);
            $this->sanitizeChildren($child, $baseUrl);
        }
    }

    private function sanitizeAttributes(DOMElement $element, string $tag, string $baseUrl): void
    {
        $allowed = self::ALLOWED_ATTRIBUTES[$tag] ?? [];
        $names = [];
        foreach ($element->attributes as $attribute) {
            $names[] = $attribute->name;
        }

        foreach ($names as $name) {
            if (!in_array(strtolower($name), $allowed, true)) {
                $element->removeAttribute($name);
            }
        }

        if ($tag === 'a' && $element->hasAttribute('href')) {
            $href = $this->normalizeUrl($element->getAttribute('href'), $baseUrl, true);
            if ($href === '') {
                $element->removeAttribute('href');
            } else {
                $element->setAttribute('href', $href);
                $element->setAttribute('target', '_blank');
                $element->setAttribute('rel', 'noopener noreferrer');
            }
        }

        if ($tag === 'img' && $element->hasAttribute('src')) {
            $src = $this->normalizeUrl($element->getAttribute('src'), $baseUrl, false);
            if ($src === '') {
                $element->parentNode->removeChild($element);
            } else {
                $element->setAttribute('src', $src);
                $element->setAttribute('loading', 'lazy');
                $element->setAttribute('referrerpolicy', 'no-referrer-when-downgrade');
            }
        }
    }

    private function normalizeUrl(string $url, string $baseUrl, bool $allowMailto): string
    {
        $url = trim(html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($url === '') {
            return '';
        }

        if (strpos($url, '//') === 0) {
            $url = 'https:' . $url;
        } elseif (strpos($url, '/') === 0) {
            $parts = parse_url($baseUrl);
            $url = ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? 'www.globenewswire.com') . $url;
        }

        $scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
        $allowed = $allowMailto ? ['http', 'https', 'mailto'] : ['http', 'https'];
        return in_array($scheme, $allowed, true) ? $url : '';
    }
}
