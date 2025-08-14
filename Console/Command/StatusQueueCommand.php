<?php
declare(strict_types=1);
namespace Merlin\CacheWarmer\Console\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Merlin\CacheWarmer\Service\WarmService;

class StatusQueueCommand extends Command
{
    public function __construct(
        private readonly WarmService $warmService,
        string $name = null
    ) {
        parent::__construct($name);
    }

    protected function configure()
    {
        $this->setName('merlin:cache:queue:status')
            ->setDescription('Show queue status and remaining URLs')
            ->addOption('list', 'l', InputOption::VALUE_OPTIONAL, 'How many remaining URLs to list', 20);
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $limit  = (int)$input->getOption('list');
        $status = $this->warmService->getStatus($limit);
        $c      = $status['counts'] ?? [];

        $output->writeln(sprintf(
            "Pending: %d | Processing: %d | Done: %d | Failed: %d",
            $c['pending'] ?? 0,
            $c['processing'] ?? 0,
            $c['done'] ?? 0,
            $c['failed'] ?? 0
        ));

        if (!empty($status['remaining'])) {
            $output->writeln("");
            $output->writeln("<info>Top remaining URLs:</info>");
            foreach ($status['remaining'] as $u) {
                $output->writeln($u);
            }
        }

        return 0;
    }
}
