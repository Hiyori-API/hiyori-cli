<?php

declare(strict_types=1);

namespace Hiyori\Commands;

use Hiyori\Hiyori;
use Hiyori\Service\Ml\LlmJudge;
use Hiyori\Service\Ml\RejectionStore;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Adjudicate pending rejection-buffer pairs with the LLM — the tie-break for
 * the ambiguous band (70–85). The combiner's borderline rejections
 * (`combine --rejection-buffer`) are re-judged by the LLM; a `match` verdict
 * flips the pair to `approve`, otherwise it stays `reject`. Decided pairs are
 * then promoted into training labels with `php hiyori rejections promote`.
 *
 * Env-gated and a no-op without `LLM_API_KEY`:
 *   LLM_PROVIDER           openai (default) | anthropic
 *   LLM_MODEL              default gpt-4o-mini / claude-3-5-haiku-20241022
 *   LLM_MAX_PAIRS_PER_RUN  default 2000
 *   LLM_BUDGET_USD         default 5.00
 *
 *   php hiyori ml:llm-judge [--limit N] [--dry-run] [--json]
 */
#[AsCommand(
    name: 'ml:llm-judge',
    description: 'Adjudicate pending rejection-buffer pairs with the LLM (ambiguous-band tie-break)',
)]
final class MlLlmJudgeCommand extends Command
{
    /** @var array<string, array{table: string, shorthand: string}> */
    private static ?array $sources = null;

    public function __construct(
        private Hiyori $hiyori,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('limit', null, InputOption::VALUE_OPTIONAL, 'Max pending pairs to adjudicate (0 = all)', '0')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'List the pairs without calling the LLM')
            ->addOption('json', 'j', InputOption::VALUE_NONE, 'Output as JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $db = $this->hiyori->getDatabase();

        $judge = LlmJudge::fromEnv($db);
        if ($judge === null) {
            $io->note('No LLM configured (set LLM_PROVIDER + LLM_API_KEY). Nothing to do.');

            return Command::SUCCESS;
        }

        $store = RejectionStore::create($db);
        $store->ensureIndexes();

        $limit = (int) $input->getOption('limit');
        $dryRun = (bool) $input->getOption('dry-run');
        $pending = $store->list(null, $limit > 0 ? $limit : 50);

        if ($pending === []) {
            $io->note('Rejection buffer is empty — nothing to adjudicate.');
            $this->recordSpend($judge);

            return Command::SUCCESS;
        }

        $io->text(\sprintf('Adjudicating %d pending pair(s) with %s/%s%s', count($pending), $judge->provider(), $judge->model(), $dryRun ? ' (dry-run)' : ''));

        $adjudicated = 0;
        $skipped = 0;
        $results = [];
        foreach ($pending as $row) {
            $a = $this->fetchEntry($db, (string) $row['base_source'], (string) $row['base_id']);
            $b = $this->fetchEntry($db, (string) $row['candidate_source'], (string) $row['candidate_id']);
            if ($a === null || $b === null) {
                $skipped++;
                $results[] = ['base' => $row['base_source'] . ':' . $row['base_id'], 'candidate' => $row['candidate_source'] . ':' . $row['candidate_id'], 'status' => 'no-data'];
                continue;
            }

            if ($dryRun) {
                $results[] = ['base' => $row['base_source'] . ':' . $row['base_id'], 'candidate' => $row['candidate_source'] . ':' . $row['candidate_id'], 'status' => 'would-judge'];
                continue;
            }

            $decision = $judge->decide($a, $b);
            if ($decision === null) {
                continue;
            }

            $store->upsert([
                'base_source' => (string) $row['base_source'],
                'base_id' => (string) $row['base_id'],
                'candidate_source' => (string) $row['candidate_source'],
                'candidate_id' => (string) $row['candidate_id'],
                'decision' => $decision['match'] ? RejectionStore::APPROVE : RejectionStore::REJECT,
                'source' => 'llm',
                'notes' => $decision['reason'],
                'score' => $decision['confidence'],
            ]);
            $adjudicated++;
            $results[] = [
                'base' => $row['base_source'] . ':' . $row['base_id'],
                'candidate' => $row['candidate_source'] . ':' . $row['candidate_id'],
                'status' => $decision['match'] ? 'approve' : 'reject',
                'confidence' => $decision['confidence'],
            ];
        }

        $spend = $this->recordSpend($judge);

        if ($input->getOption('json')) {
            $output->writeln((string) json_encode([
                'adjudicated' => $adjudicated,
                'skipped' => $skipped,
                'spend' => $spend,
                'results' => $results,
            ], JSON_PRETTY_PRINT));

            return Command::SUCCESS;
        }

        $io->success(\sprintf(
            'Adjudicated %d pair(s), skipped %d. Spend: %d tokens, ~$%.4f.',
            $adjudicated,
            $skipped,
            $spend['tokens'],
            $spend['cost_usd'],
        ));
        $io->text('Promote decided pairs into training labels: php hiyori rejections promote');

        return Command::SUCCESS;
    }

    /**
     * @return array{tokens: int, cost_usd: float, judged: int, skipped_budget: int}
     */
    private function recordSpend(LlmJudge $judge): array
    {
        $spend = $judge->spend();
        $telemetry = new \Hiyori\Service\Telemetry();
        $telemetry->update('llm-latest', array_merge($spend, [
            'model' => $judge->model(),
            'provider' => $judge->provider(),
            'completed_at' => date('c'),
        ]));
        $telemetry->append('llm-judge', array_merge($spend, [
            'model' => $judge->model(),
            'provider' => $judge->provider(),
            'completed_at' => date('c'),
        ]));

        return $spend;
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function fetchEntry(\Hiyori\Service\Database $db, string $shorthand, string $id): ?array
    {
        if (self::$sources === null) {
            self::$sources = [];
            /** @var array<string, array{table: string, shorthand: string}> $configs */
            $configs = require dirname(__DIR__) . '/Config/Sources.php';
            foreach ($configs as $config) {
                self::$sources[$config['shorthand']] = $config;
            }
        }
        $source = self::$sources[$shorthand] ?? null;
        if ($source === null || $id === '') {
            return null;
        }
        $doc = $db->getCollection($source['table'])->findOne(
            ['reference_ids.' . $shorthand => $id],
            ['projection' => ['title' => 1, 'synonyms' => 1, 'type' => 1, 'episodes' => 1, 'chapters' => 1, 'status' => 1, 'year' => 1, 'season' => 1, 'tags' => 1]],
        );
        if ($doc === null) {
            return null;
        }

        return json_decode(json_encode($doc), true);
    }
}
