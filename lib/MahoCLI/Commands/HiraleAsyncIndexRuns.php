<?php

declare(strict_types=1);

namespace MahoCLI\Commands;

use Hirale_AsyncIndex_Model_FullReindex;
use Mage;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'hirale:asyncindex:runs',
    description: 'List active Hirale AsyncIndex full-reindex runs',
)]
class HiraleAsyncIndexRuns extends BaseMahoCommand
{
    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->initMaho();

        $fullReindex = Mage::getSingleton('hirale_asyncindex/fullReindex');
        if (!$fullReindex instanceof Hirale_AsyncIndex_Model_FullReindex) {
            $output->writeln('<error>Hirale AsyncIndex full reindex service is unavailable.</error>');
            return Command::FAILURE;
        }

        $runs = $fullReindex->listActiveRuns();
        if ($runs === []) {
            $output->writeln('No active runs.');
            return Command::SUCCESS;
        }

        $table = new Table($output);
        $table->setHeaders(['run_id', 'indexer', 'mode', 'status', 'progress', 'cancel_req', 'reason', 'started_at']);
        foreach ($runs as $run) {
            $total = (int) ($run['total'] ?? 0);
            $processed = (int) ($run['processed'] ?? 0);
            $progress = $total > 0 ? sprintf('%d/%d (%d%%)', $processed, $total, (int) round($processed / $total * 100)) : (string) $processed;
            $table->addRow([
                (string) $run['run_id'],
                (string) ($run['indexer_code'] ?? ''),
                (string) ($run['mode'] ?? ''),
                (string) ($run['status'] ?? ''),
                $progress,
                ((int) ($run['cancel_requested'] ?? 0) === 1) ? 'yes' : '',
                (string) ($run['reason'] ?? ''),
                (string) ($run['started_at'] ?? ''),
            ]);
        }
        $table->render();

        return Command::SUCCESS;
    }
}
