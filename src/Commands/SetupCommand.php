<?php

namespace Hiyori\Commands;

use Hiyori\Hiyori;
use MongoDB\Client;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'setup',
    description: 'Setup checklist: .env, MongoDB connection, run migrations',
)]
final class SetupCommand extends Command
{
    public function __construct(
        private Hiyori $hiyori
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $ok = true;

        // 1) .env exists
        $env = dirname(__DIR__, 2) . '/.env';
        if (!is_file($env)) {
            $io->error('.env missing — run: cp .env.example .env and fill in values');
            $ok = false;
        } else {
            $io->writeln('  ✓ .env present');
        }

        // 2) MongoDB reachable
        try {
            $client = new Client($_ENV['MONGODB_URL']);
            $client->selectDatabase('admin')->command(['ping' => 1]);
            $io->writeln('  ✓ MongoDB reachable (' . ($_ENV['MONGODB_URL'] ?? 'mongodb://localhost:27017') . ')');
        } catch (\Throwable $e) {
            $io->error('MongoDB unreachable: ' . $e->getMessage());
            $ok = false;
        }

        // 3) migrations
        /** @var \Hiyori\Service\Migrator $migrator */
        $migrator = $this->hiyori->getMigrator();
        $pending = $migrator->pending();
        if ($pending === []) {
            $io->writeln('  ✓ migrations up to date');
        } else {
            $io->writeln(sprintf('  … applying %d pending migration(s)', count($pending)));
            foreach ($migrator->run() as $v => $name) {
                $io->writeln(sprintf('    ✓ v%s %s', $v, $name));
            }
        }

        $io->writeln('');

        if (!$ok) {
            $io->error('Setup incomplete — fix the items above and re-run: php hiyori setup');

            return Command::FAILURE;
        }

        $io->success('Setup complete. Next steps:');
        $io->writeln('  1. php hiyori ingest anilist');
        $io->writeln('  2. php hiyori combine anilist');
        $io->writeln('  3. php hiyori dashboard:serve   (or: php hiyori dashboard:serve --install)');
        $io->writeln('  4. php hiyori schedule --install  (optional ingest scheduler)');

        return Command::SUCCESS;
    }
}
