<?php
declare(strict_types=1);
namespace Merlin\CacheWarmer\Console\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\ProgressBar;
use Merlin\CacheWarmer\Service\EnqueueService;

class BuildQueueCommand extends Command
{
    public function __construct(private readonly EnqueueService $enqueueService, string $name = null)
    {
        parent::__construct($name);
    }

    protected function configure()
    {
        $this->setName('merlin:cache:queue:build')
            ->setDescription('Build the cache warm queue from a sitemap, file, or list of URLs (console-only).')
            ->addOption('sitemap', null, InputOption::VALUE_OPTIONAL, 'Path or URL to sitemap.xml')
            ->addOption('file', null, InputOption::VALUE_OPTIONAL, 'Text file containing URLs, one per line')
            ->addOption('add', null, InputOption::VALUE_OPTIONAL, 'Comma-separated URLs to add directly')
            ->addOption('store-id', null, InputOption::VALUE_OPTIONAL, 'Store ID', 0)
            ->addOption('priority', 'p', InputOption::VALUE_OPTIONAL, 'Priority (higher processed first)', 0)
            ->addOption('not-before', null, InputOption::VALUE_OPTIONAL, 'Delay processing by N seconds from now', 0)
            ->addOption('dedupe', null, InputOption::VALUE_NONE, 'Skip enqueuing duplicates (default)')
            ->addOption('allow-duplicates', null, InputOption::VALUE_NONE, 'Do not dedupe; allow duplicates')
            ->addOption('clear', 'c', InputOption::VALUE_NONE, 'Clear pending/processing items before enqueueing');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $storeId = (int)$input->getOption('store-id');
        $priority = (int)$input->getOption('priority');
        $notBeforeSeconds = (int)$input->getOption('not-before');
        $notBeforeTs = $notBeforeSeconds > 0 ? time() + $notBeforeSeconds : null;
        $dedupe = !$input->getOption('allow-duplicates');

        if ($input->getOption('clear')) {
            $deleted = $this->enqueueService->clearPending();
            $output->writeln("<info>Cleared {$deleted} pending/processing items.</info>");
        }

        $urls = [];
        $sitemap = (string)$input->getOption('sitemap');
        $file = (string)$input->getOption('file');
        $add = (string)$input->getOption('add');

        if ($sitemap) {
            $output->writeln("<info>Parsing sitemap: {$sitemap}</info>");
            $xml = @file_get_contents($sitemap);
            if ($xml === false) {
                $output->writeln("<error>Could not read sitemap: {$sitemap}</error>");
                return 1;
            }
            $sx = @simplexml_load_string($xml);
            if ($sx === false) {
                $output->writeln("<error>Invalid XML in sitemap.</error>");
                return 1;
            }
            if (isset($sx->sitemap)) {
                foreach ($sx->sitemap as $sm) {
                    if (isset($sm->loc)) {
                        $child = (string)$sm->loc;
                        $childXml = @file_get_contents($child);
                        if ($childXml !== false) {
                            $csx = @simplexml_load_string($childXml);
                            if ($csx && isset($csx->url)) {
                                foreach ($csx->url as $u) {
                                    if (isset($u->loc)) $urls[] = (string)$u->loc;
                                }
                            }
                        }
                    }
                }
            }
            if (isset($sx->url)) {
                foreach ($sx->url as $u) {
                    if (isset($u->loc)) $urls[] = (string)$u->loc;
                }
            }
        }

        if ($file) {
            if (!is_readable($file)) {
                $output->writeln("<error>File not readable: {$file}</error>");
                return 1;
            }
            foreach (file($file) as $line) {
                $line = trim($line);
                if ($line !== '') $urls[] = $line;
            }
        }

        if ($add) {
            foreach (explode(',', $add) as $u) {
                $u = trim($u);
                if ($u !== '') $urls[] = $u;
            }
        }

        $urls = array_values(array_unique($urls));
        if (empty($urls)) {
            $output->writeln("<comment>No URLs to enqueue. Use --sitemap, --file or --add.</comment>");
            return 0;
        }

        $progress = new ProgressBar($output, count($urls));
        $progress->start();
        $chunkSize = 200;
        $inserted = 0;
        for ($i = 0; $i < count($urls); $i += $chunkSize) {
            $batch = array_slice($urls, $i, $chunkSize);
            $inserted += $this->enqueueService->addUrls($batch, $storeId, $priority, $notBeforeTs, $dedupe);
            $progress->advance(count($batch));
        }
        $progress->finish();
        $output->writeln("");
        $output->writeln("<info>Enqueued {$inserted} URL(s).</info>");
        return 0;
    }
}
