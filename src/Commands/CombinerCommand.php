<?php

namespace Hiyori\Commands;

use Hiyori\Hiyori;
use Hiyori\Service\Combiner\Strategy\MetadataFuzzyStrategy;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\LockableTrait;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'combine',
    description: 'Combine metadata from all sources into one',
    aliases: ['c', 'combiner'],
    hidden: false,
)]
final class CombinerCommand extends Command
{
    use LockableTrait;

    public function __construct(
        private Hiyori $hiyori
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('base', InputArgument::REQUIRED, 'Source dataset to use as a base')
            ->addOption('strategy', 's', InputOption::VALUE_OPTIONAL, 'Defaults to metadata_fuzzy (benchmark winner; tuned weights)')
            ->addOption('threshold', 't', InputOption::VALUE_OPTIONAL, 'metadata_fuzzy score threshold (0-100, default 70)')
            ->addOption('weights', 'w', InputOption::VALUE_OPTIONAL, 'metadata_fuzzy field weights as JSON, e.g. {"title":25,"tags":20}')
            ->addOption('into', null, InputOption::VALUE_OPTIONAL, 'Collection to merge into (e.g. organized_manga). Defaults to organized.', 'organized');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$this->lock()) {
            $output->writeln('The command is already running in another process.');

            return Command::SUCCESS;
        }

        $base = $input->getArgument('base');
        $strategy = $input->getOption('strategy') ?? MetadataFuzzyStrategy::class;

        $config = ['collection' => (string) $input->getOption('into')];
        if ($input->getOption('threshold') !== null) {
            $config['threshold'] = (float) $input->getOption('threshold');
        }
        if ($input->getOption('weights') !== null) {
            $decoded = json_decode((string) $input->getOption('weights'), true);
            if (!is_array($decoded)) {
                $output->writeln('<error>--weights must be valid JSON, e.g. {"title":25,"tags":20}</error>');

                return Command::FAILURE;
            }
            $config['weights'] = $decoded;
        }

        $this->hiyori->combine($base, $strategy, $config);

        $this->release();

        return Command::SUCCESS;
    }
}
