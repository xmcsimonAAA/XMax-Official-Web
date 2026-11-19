<?php
// Offline checks only: no application bootstrap, network requests, database, or publishing.
require dirname(__DIR__) . '/vendor/autoload.php';

use app\portal\service\ExternalHtmlSanitizer;
use app\portal\service\ExternalHttpClient;
use app\portal\service\ExternalPostService;
use app\portal\service\GlobeNewswireSyncService;
use app\portal\service\SecFilingSyncService;

function check($condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

class OfflineHttp extends ExternalHttpClient
{
    public $responses = [];
    public $requests = [];

    public function __construct() {}

    public function get(string $url, array $headers = []): string
    {
        $this->requests[] = $url;
        if (!array_key_exists($url, $this->responses)) {
            throw new RuntimeException('Unexpected fixture request: ' . $url);
        }
        return $this->responses[$url];
    }

    public function getJson(string $url): array
    {
        return json_decode($this->get($url), true, 512, JSON_THROW_ON_ERROR);
    }
}

class OfflinePublisher extends ExternalPostService
{
    public $items = [];
    public $errors = [];

    public function __construct() {}

    public function publish(array $item): string
    {
        $this->items[] = $item;
        return 'created';
    }

    public function ensureSecYearCategory(int $year, int $parentId): int
    {
        return $year;
    }

    public function recordError(string $source, string $externalId, string $url, string $title, string $message): void
    {
        $this->errors[] = [$source, $externalId, $message];
    }
}

class OfflineCache
{
    public $values = [];

    public function get(string $key, $default = null)
    {
        return $this->values[$key] ?? $default;
    }

    public function set(string $key, $value, $ttl = null): bool
    {
        $this->values[$key] = $value;
        return true;
    }
}

function callPrivate($object, string $name, array $args = [])
{
    $method = new ReflectionMethod($object, $name);
    $method->setAccessible(true);
    return $method->invokeArgs($object, $args);
}

$stockClass = new ReflectionClass(\api\portal\controller\TimeSeriesController::class);
$stock = $stockClass->newInstanceWithoutConstructor();
$cache = new OfflineCache();
\think\Container::getInstance()->instance('cache', $cache);
$cached = ['timeSeries' => [['datetime' => '2026-11-18 16:00:00', 'close' => '1.00']], 'quote' => ['price' => '1.00']];
$cache->values['last_full_data'] = $cached;
check(callPrivate($stock, 'getLastCompleteData', [false]) === $cached, 'Persistent stock cache fallback changed.');
check(callPrivate($stock, 'checkRequestLimit')['allowed'] === false, 'Blank API keys must not be used.');
$key = $stockClass->getProperty('apiKey2');
$key->setAccessible(true);
$key->setValue($stock, 'offline-test-key');
$limit = callPrivate($stock, 'checkRequestLimit');
check($limit['allowed'] && $limit['prefix'] === 'key2', 'Configured backup API key was not selected.');
check($stockClass->hasMethod('forceRefresh'), 'Server stock repair logic was lost.');
echo "PASS preserved stock cache and environment-key handling\n";

$http = new OfflineHttp();
$publisher = new OfflinePublisher();
$recent = ['accessionNumber' => ['0001473334-26-000003', '0001473334-26-000002', '0001473334-26-000001'], 'filingDate' => ['2026-11-18', '2026-11-17', '2026-11-16'], 'form' => ['8-K', '10-Q', '4']];
$older = ['accessionNumber' => ['0001473334-24-000001'], 'filingDate' => ['2024-01-10'], 'form' => ['S-8']];
$http->responses['https://data.sec.gov/submissions/CIK0001473334.json'] = json_encode(['filings' => ['recent' => $recent, 'files' => [['name' => 'older.json', 'filingFrom' => '2024-01-01', 'filingTo' => '2024-12-31']]]]);
$http->responses['https://data.sec.gov/submissions/older.json'] = json_encode($older);
$sec = new SecFilingSyncService($http, $publisher, ['cik' => '1473334', 'category_parent_id' => 3]);
$stats = $sec->sync(1994, true, 0);
check($stats['created'] === 4 && $stats['errors'] === 0, 'SEC all-form backfill failed.');
check(array_column($publisher->items, 'category_id') === [2026, 2026, 2026, 2024], 'SEC years or sorting changed.');
foreach ($publisher->items as $item) {
    check($item['content'] === '' && $item['post_source'] === $item['source_url'], 'SEC entries must remain links only.');
    check(strpos($item['source_url'], 'https://www.sec.gov/Archives/edgar/data/1473334/') === 0, 'SEC filing URL is invalid.');
}
$limited = $sec->sync(2025, false, 2);
check($limited['found'] === 2 && $limited['errors'] === 0, 'SEC limit or date filter failed.');
echo "PASS SEC all filing types, historical files, years, links, and limits\n";

$url = 'https://www.globenewswire.com/news-release/2026/11/18/9999999/0/en/Offline-Test.html';
$bodyText = 'Offline fixture text verifies that a full news release body, including the final paragraph, is retained. ';
$html = '<html><head><meta name="author" content="XMax Inc."><meta property="og:title" content="Offline Release"><meta property="og:url" content="' . $url . '"><meta property="og:article:published_time" content="2026-11-18T14:30:00Z"></head><body><div id="main-body-container" class="article-body"><p onclick="bad()">' . str_repeat($bodyText, 3) . '</p><script>unsafeMarker()</script><iframe src="https://example.com"></iframe><img src="/image.png" onerror="bad()"><img src="javascript:bad()"><a href="javascript:bad()">Unsafe link</a><table><tr><td>Cell</td></tr></table><p>END-OF-RELEASE</p></div></body></html>';
$sanitizer = new ExternalHtmlSanitizer();
$content = $sanitizer->extractArticleBody($html, $url);
check(strpos($content, 'END-OF-RELEASE') !== false && strpos($content, '<table>') !== false, 'News content was truncated.');
check(!preg_match('/<script|<iframe|onclick|onerror|javascript:|unsafeMarker/i', $content), 'Unsafe external HTML survived.');
check(strpos($content, 'https://www.globenewswire.com/image.png') !== false, 'Relative news image URL was not normalized.');
$threw = false;
try {
    $sanitizer->extractArticleBody('<p>No body container</p>', $url);
} catch (RuntimeException $e) {
    $threw = true;
}
check($threw, 'Missing release body must fail explicitly.');

$http = new OfflineHttp();
$publisher = new OfflinePublisher();
$listing = '<div class="recentNewsH"><li class="row"><a class="sourceLink">XMax Inc.</a><div class="mainLink"><a href="' . $url . '">Offline Release</a></div><div class="date-source"><span>November 18, 2026 09:30 ET</span></div></li><li class="row"><a class="sourceLink">Other Company</a><div class="mainLink"><a href="' . $url . '">Exclude this</a></div></li></div>';
$http->responses['https://www.globenewswire.com/offline-list?page=1'] = $listing;
$http->responses['https://www.globenewswire.com/offline-list?page=2'] = $listing;
$http->responses[$url] = $html;
$globe = new GlobeNewswireSyncService($http, $publisher, $sanitizer, ['organization_name' => 'XMax Inc.', 'organization_url' => 'https://www.globenewswire.com/offline-list?page=%d', 'category_id' => 2]);
$stats = $globe->sync(2, 0);
check($stats['found'] === 1 && $stats['created'] === 1 && $stats['errors'] === 0, 'Globe listing filtering or deduplication failed.');
check(strpos($publisher->items[0]['content'], 'END-OF-RELEASE') !== false, 'Globe full article was not passed to the publisher.');
$http->responses[$url] = str_replace('content="XMax Inc."', 'content="Other Company"', $html);
$stats = $globe->sync(1, 0);
check($stats['errors'] === 1 && count($publisher->items) === 1, 'Wrong release author must not be published.');
echo "PASS Globe full body, company filtering, deduplication, and HTML sanitizing\n";
echo "Offline smoke tests passed. No network, database, migration, or real publishing was used.\n";
