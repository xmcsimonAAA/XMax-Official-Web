<?php

namespace app\portal\service;

use RuntimeException;

class SecFilingSyncService
{
    private $http;
    private $publisher;
    private $config;

    public function __construct(ExternalHttpClient $http, ExternalPostService $publisher, array $config)
    {
        $this->http = $http;
        $this->publisher = $publisher;
        $this->config = $config;
    }

    public function sync(int $fromYear, bool $backfillAll, int $limit, callable $progress = null): array
    {
        $cik = str_pad(preg_replace('/\D+/', '', (string)$this->config['cik']), 10, '0', STR_PAD_LEFT);
        $submissionUrl = 'https://data.sec.gov/submissions/CIK' . $cik . '.json';
        $submission = $this->http->getJson($submissionUrl);
        $sets = [];

        if (!empty($submission['filings']['recent']) && is_array($submission['filings']['recent'])) {
            $sets[] = $submission['filings']['recent'];
        }

        if ($backfillAll || $this->needsSupplementalFiles($submission, $fromYear)) {
            foreach (($submission['filings']['files'] ?? []) as $file) {
                if (empty($file['name']) || (!$backfillAll && !$this->fileCanContainYear($file, $fromYear))) {
                    continue;
                }
                $sets[] = $this->http->getJson('https://data.sec.gov/submissions/' . rawurlencode($file['name']));
            }
        }

        $items = [];
        foreach ($sets as $columns) {
            foreach ($this->rowsFromColumns($columns) as $row) {
                $year = (int)substr((string)($row['filingDate'] ?? ''), 0, 4);
                if (!$backfillAll && $year < $fromYear) {
                    continue;
                }
                if (empty($row['accessionNumber']) || empty($row['filingDate']) || empty($row['form'])) {
                    continue;
                }
                $items[$row['accessionNumber']] = $row;
            }
        }

        uasort($items, function (array $left, array $right) {
            return strcmp((string)$right['filingDate'], (string)$left['filingDate']);
        });
        if ($limit > 0) {
            $items = array_slice($items, 0, $limit, true);
        }

        $stats = $this->emptyStats();
        foreach ($items as $row) {
            $externalId = (string)$row['accessionNumber'];
            $sourceUrl = $this->filingUrl($cik, $row);
            $title = $this->filingTitle($row);
            try {
                $year = (int)substr((string)$row['filingDate'], 0, 4);
                $categoryId = $this->publisher->ensureSecYearCategory(
                    $year,
                    (int)$this->config['category_parent_id']
                );
                $status = $this->publisher->publish([
                    'source' => 'sec',
                    'external_id' => $externalId,
                    'source_url' => $sourceUrl,
                    'post_source' => $sourceUrl,
                    'title' => $title,
                    'keywords' => 'XMax,SEC,' . (string)$row['form'],
                    'excerpt' => $this->filingExcerpt($row),
                    'content' => '',
                    'thumbnail' => '',
                    'published_time' => strtotime((string)$row['filingDate'] . ' 12:00:00 UTC'),
                    'category_id' => $categoryId,
                ]);
                $this->count($stats, $status);
                if ($progress) {
                    $progress('SEC ' . $externalId . ': ' . $status);
                }
            } catch (\Throwable $e) {
                $stats['errors']++;
                $this->publisher->recordError('sec', $externalId, $sourceUrl, $title, $e->getMessage());
                if ($progress) {
                    $progress('SEC ' . $externalId . ': error - ' . $e->getMessage());
                }
            }
        }

        $stats['found'] = count($items);
        return $stats;
    }

    private function rowsFromColumns(array $columns): array
    {
        $rows = [];
        $count = count($columns['accessionNumber'] ?? []);
        for ($index = 0; $index < $count; $index++) {
            $row = [];
            foreach ($columns as $name => $values) {
                if (is_array($values)) {
                    $row[$name] = $values[$index] ?? '';
                }
            }
            $rows[] = $row;
        }
        return $rows;
    }

    private function filingUrl(string $cik, array $row): string
    {
        $cikNumber = ltrim($cik, '0');
        $accession = (string)$row['accessionNumber'];
        $directory = str_replace('-', '', $accession);
        return 'https://www.sec.gov/Archives/edgar/data/' . $cikNumber . '/' . $directory . '/' . $accession . '-index.htm';
    }

    private function filingTitle(array $row): string
    {
        $form = trim((string)$row['form']);
        $description = trim((string)($row['primaryDocDescription'] ?? ''));
        if ($description === '') {
            $description = trim((string)($row['primaryDocument'] ?? ''));
        }
        return $description === '' ? $form : $form . ' - ' . $description;
    }

    private function filingExcerpt(array $row): string
    {
        $parts = [
            'Filed ' . (string)$row['filingDate'],
            'Form ' . (string)$row['form'],
            'Accession ' . (string)$row['accessionNumber'],
        ];
        if (!empty($row['reportDate'])) {
            $parts[] = 'Report date ' . (string)$row['reportDate'];
        }
        return implode(' | ', $parts);
    }

    private function needsSupplementalFiles(array $submission, int $fromYear): bool
    {
        $dates = $submission['filings']['recent']['filingDate'] ?? [];
        if (!$dates) {
            return true;
        }
        $oldest = min($dates);
        return $fromYear < (int)substr((string)$oldest, 0, 4);
    }

    private function fileCanContainYear(array $file, int $year): bool
    {
        $from = (int)substr((string)($file['filingFrom'] ?? ''), 0, 4);
        $to = (int)substr((string)($file['filingTo'] ?? ''), 0, 4);
        return $from === 0 || $to === 0 || ($year >= $from && $year <= $to) || $to >= $year;
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
