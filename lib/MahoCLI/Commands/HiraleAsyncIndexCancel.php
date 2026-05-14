<?php

declare(strict_types=1);

namespace MahoCLI\Commands;

use Hirale_AsyncIndex_Model_FullReindex;
use Mage;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'hirale:asyncindex:cancel',
    description: 'Request cooperative cancellation of a Hirale AsyncIndex full-reindex run',
)]
class HiraleAsyncIndexCancel extends BaseMahoCommand
{
    #[\Override]
    protected function configure(): void
    {
        $this->addArgument('run_id', InputArgument::REQUIRED, 'Run id to cancel');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->initMaho();

        $runId = (int) $input->getArgument('run_id');
        if ($runId <= 0) {
            $output->writeln('<error>run_id must be a positive integer.</error>');
            return Command::INVALID;
        }

        $fullReindex = Mage::getSingleton('hirale_asyncindex/fullReindex');
        if (!$fullReindex instanceof Hirale_AsyncIndex_Model_FullReindex) {
            $output->writeln('<error>Hirale AsyncIndex full reindex service is unavailable.</error>');
            return Command::FAILURE;
        }

        if (!$fullReindex->requestCancel($runId)) {
            $output->writeln(sprintf('<comment>Run %d is not in a cancelable state (not queued or running).</comment>', $runId));
            return Command::FAILURE;
        }

        $output->writeln(sprintf('<info>Cancel requested for run %d.</info> The worker will finish the current batch and then mark the run canceled.', $runId));
        return Command::SUCCESS;
    }
}
