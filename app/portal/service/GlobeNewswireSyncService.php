<?php

namespace app\portal\service;

use DateTime;
use DateTimeZone;
use DOMDocument;
use DOMElement;
use DOMXPath;
use RuntimeException;

class GlobeNewswireSyncService
{
    private $http;
    private $publisher;
    private $sanitizer;
    private $config;

    public function __construct(
        ExternalHttpClient $http,
        ExternalPostService $publisher,
        ExternalHtmlSanitizer $sanitizer,
        array $config
    ) {
        $this->http = $http;
        $this->publisher = $publisher;
        $this->sanitizer = $sanitizer;
        $this->config = $config;
    }

    public function sync(int $pages, int $limit, callable $progress = null): array
    {
        $pages = max(1, $pages);
        $summaries = [];
        for ($page = 1; $page <= $pages; $page++) {
            $url = sprintf((string)$this->config['organization_url'], $page);
            foreach ($this->parseListing($this->http->get($url), $url) as $summary) {
                $summaries[$summary['external_id']] = $summary;
            }
        }

        uasort($summaries, function (array $left, array $right) {
            return $right['published_time'] <=> $left['published_time'];
        });
        if ($limit > 0) {
            $summaries = array_slice($summaries, 0, $limit, true);
        }

        $stats = $this->emptyStats();
        foreach ($summaries as $summary) {
            try {
                $item = $this->fetchArticle($summary);
                $status = $this->publisher->publish($item);
                $this->count($stats, $status);
                if ($progress) {
                    $progress('GlobeNewswire ' . $summary['external_id'] . ': ' . $status);
                }
            } catch (\Throwable $e) {
                $stats['errors']++;
                $this->publisher->recordError(
                    'globenewswire',
                    $summary['external_id'],
                    $summary['source_url'],
                    $summary['title'],
                    $e->getMessage()
                );
                if ($progress) {
                    $progress('GlobeNewswire ' . $summary['external_id'] . ': error - ' . $e->getMessage());
                }
            }
        }

        $stats['found'] = count($summaries);
        return $stats;
    }

    private function parseListing(string $html, string $baseUrl): array
    {
        $document = $this->loadDocument($html);
        $xpath = new DOMXPath($document);
        $nodes = $xpath->query('//div[contains(concat(" ", normalize-space(@class), " "), " recentNewsH ")]//li[contains(concat(" ", normalize-space(@class), " "), " row ")]');
        $items = [];

        foreach ($nodes as $node) {
            $sourceNode = $xpath->query('.//a[contains(concat(" ", normalize-space(@class), " "), " sourceLink ")]', $node)->item(0);
            if (!$sourceNode || trim($sourceNode->textContent) !== (string)$this->config['organization_name']) {
                continue;
            }

            $link = $xpath->query('.//div[contains(concat(" ", normalize-space(@class), " "), " mainLink ")]/a', $node)->item(0);
            if (!$link instanceof DOMElement) {
                continue;
            }
            $url = $this->absoluteUrl($link->getAttribute('href'), $baseUrl);
            if (!$this->isGlobeNewswireUrl($url)
                || !preg_match('~/news-release/\d{4}/\d{2}/\d{2}/(\d+)/~', $url, $match)) {
                continue;
            }

            $dateNode = $xpath->query('.//div[contains(concat(" ", normalize-space(@class), " "), " date-source ")]/span[1]', $node)->item(0);
            $excerptNode = $xpath->query('.//div[contains(concat(" ", normalize-space(@class), " "), " newsTxt ")]//p', $node)->item(0);
            $imageNode = $xpath->query('.//div[contains(concat(" ", normalize-space(@class), " "), " newsImg ")]//img', $node)->item(0);

            $items[] = [
                'external_id' => $match[1],
                'source_url' => $url,
                'title' => trim($link->textContent),
                'excerpt' => $excerptNode ? trim($excerptNode->textContent) : '',
                'thumbnail' => $imageNode instanceof DOMElement ? $this->absoluteUrl($imageNode->getAttribute('src'), $baseUrl) : '',
                'published_time' => $dateNode ? $this->parseListingDate(trim($dateNode->textContent)) : 0,
            ];
        }

        if (!$items) {
            throw new RuntimeException('No XMax Inc. releases were found on the GlobeNewswire organization page.');
        }
        return $items;
    }

    private function fetchArticle(array $summary): array
    {
        $html = $this->http->get($summary['source_url']);
        $document = $this->loadDocument($html);
        $xpath = new DOMXPath($document);

        $author = $this->meta($xpath, 'name', 'author');
        if (strcasecmp(trim($author), (string)$this->config['organization_name']) !== 0) {
            throw new RuntimeException('Release author is not XMax Inc.');
        }

        $title = $this->meta($xpath, 'property', 'og:title');
        if ($title === '') {
            $title = $this->meta($xpath, 'name', 'title');
        }
        $canonical = $this->meta($xpath, 'property', 'og:url');
        if ($canonical === '' || !$this->isGlobeNewswireUrl($canonical)) {
            $canonical = $summary['source_url'];
        }
        $published = $this->meta($xpath, 'property', 'og:article:published_time');
        $publishedTime = $published !== '' ? strtotime($published) : (int)$summary['published_time'];
        if ($publishedTime <= 0) {
            throw new RuntimeException('Release publication date was not found.');
        }

        $thumbnail = $this->meta($xpath, 'property', 'og:image');
        if ($thumbnail === '') {
            $thumbnail = $summary['thumbnail'];
        }

        return [
            'source' => 'globenewswire',
            'external_id' => $summary['external_id'],
            'source_url' => $canonical,
            'post_source' => 'GlobeNewswire',
            'title' => html_entity_decode($title !== '' ? $title : $summary['title'], ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            'keywords' => $this->meta($xpath, 'name', 'keywords'),
            'excerpt' => html_entity_decode($this->meta($xpath, 'name', 'description') ?: $summary['excerpt'], ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            'content' => $this->sanitizer->extractArticleBody($html, $canonical),
            'thumbnail' => $thumbnail,
            'published_time' => $publishedTime,
            'category_id' => (int)$this->config['category_id'],
        ];
    }

    private function loadDocument(string $html): DOMDocument
    {
        $document = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (!$loaded) {
            throw new RuntimeException('Unable to parse GlobeNewswire HTML.');
        }
        return $document;
    }

    private function meta(DOMXPath $xpath, string $attribute, string $value): string
    {
        $nodes = $xpath->query('//meta[translate(@' . $attribute . ', "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz")="' . strtolower($value) . '"]/@content');
        return $nodes->length > 0 ? trim($nodes->item(0)->nodeValue) : '';
    }

    private function parseListingDate(string $value): int
    {
        $value = preg_replace('/\s+ET$/i', '', preg_replace('/\s+/', ' ', trim($value)));
        $date = DateTime::createFromFormat('F j, Y H:i', $value, new DateTimeZone('America/New_York'));
        return $date ? $date->getTimestamp() : 0;
    }

    private function absoluteUrl(string $url, string $baseUrl): string
    {
        $url = trim(html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if (strpos($url, '//') === 0) {
            return 'https:' . $url;
        }
        if (strpos($url, '/') === 0) {
            $parts = parse_url($baseUrl);
            return ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? 'www.globenewswire.com') . $url;
        }
        return $url;
    }

    private function isGlobeNewswireUrl(string $url): bool
    {
        $scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
        $host = strtolower((string)parse_url($url, PHP_URL_HOST));
        return $scheme === 'https' && ($host === 'www.globenewswire.com' || $host === 'globenewswire.com');
    }

    private function emptyStats(): array
    {
        return ['found' => 0, 'created' => 0, 'updated' => 0, 'unchanged' => 0, 'manual' => 0, 'protected' => 0, 'errors' => 0];
    }

    private function count(array &$stats, string $status): void
    {
        if (array_key_exists($status, $stats)) {
            $stats[$status]++;
        }
    }
}
