<?php
declare(strict_types=1);

namespace Merlin\CacheWarmer\Console\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\ProgressBar;
use Merlin\CacheWarmer\Service\WarmService;

class StartQueueCommand extends Command
{
    public function __construct(
        private readonly WarmService $warmService,
        string $name = null
    ) {
        parent::__construct($name);
    }

    protected function configure()
    {
        $this->setName('merlin:cache:queue:start') // explicit for Magento interceptors
            ->setDescription('Process (warm) URLs from the queue with a progress bar and timer.')
            ->addOption('limit', 'l', InputOption::VALUE_OPTIONAL, 'Maximum URLs to process this run (0 = no limit)', 0)
            ->addOption('sleep', null, InputOption::VALUE_OPTIONAL, 'Sleep between requests in milliseconds', 0)
            ->addOption('timeout', 't', InputOption::VALUE_OPTIONAL, 'HTTP timeout seconds', 15)
            ->addOption('header', null, InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY, 'Additional request header(s), format Key: Value')
            ->addOption('user-agent', null, InputOption::VALUE_OPTIONAL, 'Override User-Agent header');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $limit   = (int)$input->getOption('limit');
        $sleep   = (int)$input->getOption('sleep');
        $timeout = (int)$input->getOption('timeout');
        $ua      = (string)$input->getOption('user-agent') ?: 'MerlinCacheWarmer/1.0';

        $headers = [];
        foreach ((array)$input->getOption('header') as $h) {
            if (strpos($h, ':') !== false) {
                [$k, $v] = array_map('trim', explode(':', $h, 2));
                if ($k !== '') $headers[$k] = $v;
            }
        }

        // Estimate total for the progress bar (don’t hard-depend on DB here)
        $status = $this->warmService->getStatus(0);
        $estimatedTotal = (int)($status['counts']['pending'] ?? 0);
        $total = $limit > 0 ? $limit : ($estimatedTotal > 0 ? $estimatedTotal : 1);

        // Start timer
        $startedAt = microtime(true);

        // Progress bar with live elapsed timer via %message%
        $progress = new ProgressBar($output, $total);
        $progress->setFormat('%current%/%max% [%bar%] %percent:3s%% | Elapsed: %message%');
        $progress->setMessage($this->formatDuration(0.0)); // initial 00:00:00.000
        $progress->start();

        $processed = $success = $failed = 0;

        while (true) {
            // Process one at a time so we can tick smoothly
            $result = $this->warmService->process(1, $sleep, $timeout, $headers, $ua);

            if (($result['processed'] ?? 0) === 0) {
                break; // nothing eligible right now
            }

            $processed += $result['processed'];
            $success   += $result['success'];
            $failed    += $result['failed'];

            // Extend the bar if we under-estimated and no --limit set
            if ($limit === 0 && $processed > $progress->getMaxSteps()) {
                $progress->setMaxSteps($processed);
            }

            // Update elapsed time message
            $progress->setMessage($this->formatDuration(microtime(true) - $startedAt));
            $progress->advance($result['processed']);

            if ($limit > 0 && $processed >= $limit) {
                break;
            }
        }

        $progress->finish();
        $output->writeln('');

        $totalElapsed = $this->formatDuration(microtime(true) - $startedAt);
        $output->writeln(sprintf(
            "<info>Processed: %d | Success: %d | Failed: %d | Total time: %s</info>",
            $processed, $success, $failed, $totalElapsed
        ));

        return 0;
    }

    private function formatDuration(float $seconds): string
    {
        $ms = (int) round(($seconds - floor($seconds)) * 1000);
        $s  = (int) floor($seconds) % 60;
        $m  = (int) floor($seconds / 60) % 60;
        $h  = (int) floor($seconds / 3600);
        return sprintf('%02d:%02d:%02d.%03d', $h, $m, $s, $ms);
    }
}
