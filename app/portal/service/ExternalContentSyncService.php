<?php

namespace app\portal\service;

use RuntimeException;

class ExternalContentSyncService
{
    private $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function sync(string $source, array $options, callable $progress = null): array
    {
        if (!in_array($source, ['sec', 'globenewswire'], true)) {
            throw new RuntimeException('Unknown external content source: ' . $source);
        }

        $lock = $this->acquireLock($source);
        try {
            $http = new ExternalHttpClient($this->config['http'] ?? []);
            $publisher = new ExternalPostService((int)($this->config['author_id'] ?? 1));

            if ($source === 'sec') {
                $service = new SecFilingSyncService($http, $publisher, $this->config['sec'] ?? []);
                return $service->sync(
                    (int)$options['from_year'],
                    (bool)$options['backfill_all'],
                    (int)$options['limit'],
                    $progress
                );
            }

            $service = new GlobeNewswireSyncService(
                $http,
                $publisher,
                new ExternalHtmlSanitizer(),
                $this->config['globe'] ?? []
            );
            return $service->sync((int)$options['globe_pages'], (int)$options['limit'], $progress);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function acquireLock(string $source)
    {
        $path = runtime_path() . 'external-content-' . $source . '.lock';
        $handle = fopen($path, 'c');
        if ($handle === false || !flock($handle, LOCK_EX | LOCK_NB)) {
            if (is_resource($handle)) {
                fclose($handle);
            }
            throw new RuntimeException('Another ' . $source . ' synchronization is already running.');
        }
        ftruncate($handle, 0);
        fwrite($handle, (string)getmypid());
        return $handle;
    }
}
