<?php

declare(strict_types=1);

namespace App\Stats\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:compute-stats', description: 'Pre-compute statistics (placeholder for materialized views)')]
final class ComputeStatsCommand extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('Stats computation placeholder — stats are computed on-the-fly for now.');

        return Command::SUCCESS;
    }
}
