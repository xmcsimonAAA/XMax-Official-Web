<?php

namespace app\portal\service;

use RuntimeException;

class ExternalHttpClient
{
    private $timeout;
    private $connectTimeout;
    private $retries;
    private $userAgent;
    private $lastRequestAt = 0.0;

    public function __construct(array $config)
    {
        $this->timeout = max(5, (int)($config['timeout'] ?? 25));
        $this->connectTimeout = max(3, (int)($config['connect_timeout'] ?? 10));
        $this->retries = max(1, (int)($config['retries'] ?? 3));
        $this->userAgent = trim((string)($config['user_agent'] ?? ''));

        if ($this->userAgent === '') {
            throw new RuntimeException('External content User-Agent is not configured.');
        }
    }

    public function get(string $url, array $headers = []): string
    {
        $lastError = 'Unknown HTTP error';

        for ($attempt = 1; $attempt <= $this->retries; $attempt++) {
            $this->respectRateLimit();
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 5,
                CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
                CURLOPT_TIMEOUT => $this->timeout,
                CURLOPT_USERAGENT => $this->userAgent,
                CURLOPT_HTTPHEADER => array_merge([
                    'Accept: text/html,application/json;q=0.9,*/*;q=0.8',
                    'Accept-Encoding: gzip, deflate',
                ], $headers),
                CURLOPT_ENCODING => '',
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
            ]);

            $body = curl_exec($ch);
            $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);
            $this->lastRequestAt = microtime(true);

            if ($body !== false && $status >= 200 && $status < 300) {
                return (string)$body;
            }

            $lastError = $curlError !== ''
                ? $curlError
                : 'HTTP ' . $status . ' returned for ' . $url;

            if ($status > 0 && $status !== 429 && $status < 500) {
                break;
            }

            if ($attempt < $this->retries) {
                usleep((int)(250000 * pow(2, $attempt - 1)));
            }
        }

        throw new RuntimeException($lastError);
    }

    public function getJson(string $url): array
    {
        $body = $this->get($url, ['Accept: application/json']);
        $data = json_decode($body, true);
        if (!is_array($data)) {
            throw new RuntimeException('Invalid JSON returned by ' . $url);
        }

        return $data;
    }

    private function respectRateLimit(): void
    {
        if ($this->lastRequestAt <= 0) {
            return;
        }

        $elapsed = microtime(true) - $this->lastRequestAt;
        if ($elapsed < 0.12) {
            usleep((int)((0.12 - $elapsed) * 1000000));
        }
    }
}
