<?php

declare(strict_types=1);

namespace MahoCLI\Commands;

use Hirale_AsyncIndex_Model_Runner;
use Mage;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'hirale:asyncindex:events',
    description: 'List index events an indexer failed on, which nothing retries',
)]
class HiraleAsyncIndexEvents extends BaseMahoCommand
{
    #[\Override]
    protected function configure(): void
    {
        $this->addOption('limit', null, InputOption::VALUE_REQUIRED, 'How many events to show', '50');
        $this->addOption(
            'prune-done',
            null,
            InputOption::VALUE_NONE,
            'Also delete leftover rows marked done by full reindex runs from before this module deleted them',
        );
        $this->addOption(
            'prune-batch',
            null,
            InputOption::VALUE_REQUIRED,
            'Rows per prune transaction',
            (string) Hirale_AsyncIndex_Model_Runner::PRUNE_BATCH_SIZE,
        );
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->initMaho();

        $runner = Mage::getSingleton('hirale_asyncindex/runner');
        if (!$runner instanceof Hirale_AsyncIndex_Model_Runner) {
            $output->writeln('<error>Hirale AsyncIndex runner is unavailable.</error>');
            return Command::FAILURE;
        }

        if ($input->getOption('prune-done')) {
            $output->writeln(sprintf(
                '<info>Removed %d leftover completed event row(s).</info>',
                $runner->pruneCompletedEvents((int) $input->getOption('prune-batch')),
            ));
        }

        $total = $runner->countFailedEvents();
        if ($total === 0) {
            $output->writeln('No failed index events.');
            return Command::SUCCESS;
        }

        $limit = max(1, (int) $input->getOption('limit'));
        $events = $runner->listFailedEvents($limit);

        $table = new Table($output);
        $table->setHeaders(['event_id', 'indexer', 'entity', 'entity_pk', 'type', 'logged_at']);
        foreach ($events as $event) {
            $table->addRow([
                (string) ($event['event_id'] ?? ''),
                (string) ($event['indexer_code'] ?? ''),
                (string) ($event['entity'] ?? ''),
                (string) ($event['entity_pk'] ?? ''),
                (string) ($event['type'] ?? ''),
                (string) ($event['created_at'] ?? ''),
            ]);
        }
        $table->render();

        $output->writeln(sprintf(
            '<comment>%d failed event(s), showing %d.</comment>'
            . ' These are never retried: core marks the row and the drain only ever selects new ones.',
            $total,
            count($events),
        ));
        $output->writeln(
            'Reindex the affected indexer to clear them — see the README on why an automatic retry is not offered.',
        );

        return Command::SUCCESS;
    }
}
