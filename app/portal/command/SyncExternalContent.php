<?php

namespace app\portal\command;

use app\portal\service\ExternalContentSyncService;
use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;

class SyncExternalContent extends Command
{
    protected function configure()
    {
        $this->setName('portal:sync-external')
            ->setDescription('Synchronize SEC filings and XMax GlobeNewswire releases')
            ->addOption('source', 's', Option::VALUE_OPTIONAL, 'all, sec, or globenewswire', 'all')
            ->addOption('from-year', null, Option::VALUE_OPTIONAL, 'Earliest SEC filing year')
            ->addOption('backfill-all', null, Option::VALUE_NONE, 'Import all historical SEC filings')
            ->addOption('globe-pages', null, Option::VALUE_OPTIONAL, 'GlobeNewswire organization pages to scan')
            ->addOption('limit', 'l', Option::VALUE_OPTIONAL, 'Maximum records per source; zero means unlimited', 0);
    }

    protected function execute(Input $input, Output $output)
    {
        $config = (array)config('external_content');
        $source = strtolower((string)$input->getOption('source'));
        if (!in_array($source, ['all', 'sec', 'globenewswire'], true)) {
            $output->writeln('<error>--source must be all, sec, or globenewswire.</error>');
            return 1;
        }

        $fromYearOption = $input->getOption('from-year');
        $globePagesOption = $input->getOption('globe-pages');
        $options = [
            'from_year' => $fromYearOption === null
                ? (int)($config['sec']['backfill_from_year'] ?? 1994)
                : max(1994, (int)$fromYearOption),
            'backfill_all' => (bool)$input->getOption('backfill-all'),
            'globe_pages' => $globePagesOption === null
                ? max(1, (int)($config['globe']['pages_per_run'] ?? 1))
                : max(1, (int)$globePagesOption),
            'limit' => max(0, (int)$input->getOption('limit')),
        ];

        $sources = $source === 'all' ? ['sec', 'globenewswire'] : [$source];
        $service = new ExternalContentSyncService($config);
        $failed = false;

        foreach ($sources as $current) {
            $output->writeln('<info>Starting ' . $current . ' synchronization...</info>');
            try {
                $stats = $service->sync($current, $options, function (string $message) use ($output) {
                    $output->writeln($message);
                });
                $output->writeln('<comment>' . $current . ': ' . $this->formatStats($stats) . '</comment>');
                if ($stats['errors'] > 0) {
                    $failed = true;
                }
            } catch (\Throwable $e) {
                $failed = true;
                $output->writeln('<error>' . $current . ': ' . $e->getMessage() . '</error>');
            }
        }

        return $failed ? 1 : 0;
    }

    private function formatStats(array $stats): string
    {
        $parts = [];
        foreach (['found', 'created', 'updated', 'unchanged', 'manual', 'protected', 'errors'] as $key) {
            $parts[] = $key . '=' . (int)($stats[$key] ?? 0);
        }
        return implode(', ', $parts);
    }
}
