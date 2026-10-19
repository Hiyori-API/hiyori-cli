<?php

require_once __DIR__ . '/../vendor/autoload.php';

/**
 * Preflight
 */
$dotenv = new \Symfony\Component\Dotenv\Dotenv();
$dotenv->load(__DIR__ . '/../.env');

/**
 * Create application
 */
$app = (new \Symfony\Component\Console\Application('Hiyori CLI', $_ENV['APP_VERSION']));
$app->setAutoExit(true);

/**
 * Register services
 */
$container = new \Symfony\Component\DependencyInjection\ContainerBuilder();

// Rate limits resolve through RateLimiter::effectiveLimits(), the single owner
// of the precedence rule (RATE_LIMIT_* env vars, then a dashboard override in
// storage/state/ui.json), so the Management page's "Save" applies to the next
// ingest process and cannot diverge from the service. The override file itself
// belongs to Hiyori\Service\SettingsStore, which is where the limiter reads
// it from — nobody parses ui.json on their own any more.
$limits = \Hiyori\Service\RateLimiter::effectiveLimits();

// Set parameters
$container->setParameter('rate_limit.max_requests', $limits['max']);
$container->setParameter('rate_limit.time_window', $limits['window']);

// Register Serializer as a service using a static method
class SerializerFactory
{
    public static function create(): \JMS\Serializer\Serializer
    {
        return \JMS\Serializer\SerializerBuilder::create()->build();
    }
}

$container->register(\JMS\Serializer\Serializer::class)
    ->setFactory([SerializerFactory::class, 'create'])
    ->setPublic(true);

$container->autowire(\Hiyori\Service\Logging::class);
$container->autowire(\Hiyori\Service\Database::class)
    // The migrator is injected with a setter below to avoid a constructor cycle.
    ->setArgument('$migrator', null);
$container->autowire(\Hiyori\Service\Migrator::class)
    ->setPublic(true);
$container->getDefinition(\Hiyori\Service\Database::class)->addMethodCall(
    'setMigrator',
    [new \Symfony\Component\DependencyInjection\Reference(\Hiyori\Service\Migrator::class)],
);
$container->autowire(\Hiyori\Service\ConsoleFactory::class)
    ->setFactory([\Hiyori\Service\ConsoleFactory::class, 'create']);
$container->autowire(\Hiyori\Service\SourceConfigurationFactory::class)
    ->setFactory([\Hiyori\Service\SourceConfigurationFactory::class, 'create']);
$container->autowire(\Hiyori\Service\StrategyConfigurationFactory::class)
    ->setFactory([\Hiyori\Service\StrategyConfigurationFactory::class, 'create']);
$container->autowire(\Hiyori\Service\RateLimiter::class)
    ->setArguments([
        '%rate_limit.max_requests%',
        '%rate_limit.time_window%',
    ]);
$container->autowire(\Hiyori\Service\MetricsCollector::class);
$container->autowire(\Hiyori\Service\HealthCheck::class)
    ->setArguments([
        // Same budget as Database and the dashboard; without it the driver
        // default is 30s and `hiyori health --watch` appears to hang.
        '$mongoClient' => new \MongoDB\Client($_ENV['MONGODB_URL'], [
            'serverSelectionTimeoutMS' => 3000,
            'connectTimeoutMS' => 3000,
        ]),
        // Share the container's RateLimiter so circuit-breaker state is not
        // split between two instances: the ingester opened a breaker the health
        // check could never observe.
        '$rateLimiter' => new \Symfony\Component\DependencyInjection\Reference(\Hiyori\Service\RateLimiter::class),
    ]);
$container->autowire(\Hiyori\Service\Ingestion\Ingestion::class);
$container->autowire(\Hiyori\Service\Combiner\Combiner::class);
$container->autowire(\Hiyori\Hiyori::class)
    ->setPublic(true);

$container->compile();
$hiyori = $container->get(\Hiyori\Hiyori::class);

/**
 * Register commands
 */
$app->addCommands([
    new \Hiyori\Commands\IngestionCommand($hiyori),
    new \Hiyori\Commands\IngestAllCommand($hiyori),
    new \Hiyori\Commands\MigrateCommand($hiyori),
    new \Hiyori\Commands\SetupCommand($hiyori),
    new \Hiyori\Commands\PublishCommand(),
    new \Hiyori\Commands\ReleaseCommand(),
    new \Hiyori\Commands\ScheduleCommand(),
    new \Hiyori\Commands\CombinerCommand($hiyori),
    new \Hiyori\Commands\ReferencesCommand($hiyori),
    new \Hiyori\Commands\WeightageCommand($hiyori),
    new \Hiyori\Commands\SweeperCommand($hiyori),
    new \Hiyori\Commands\RepairReferencesCommand($hiyori),
    // The release chain, in one place: stage order, preconditions and a
    // single run record, instead of three divergent subsets in CI and scripts.
    new \Hiyori\Commands\PipelineCommand($hiyori, $app),
    new \Hiyori\Commands\ExportCommand($hiyori),
    new \Hiyori\Commands\HealthCommand($hiyori),
    new \Hiyori\Commands\MetricsCommand($hiyori),
    new \Hiyori\Commands\DashboardCommand(),
    new \Hiyori\Commands\BackupCommand(),
    new \Hiyori\Commands\ValidateCommand($hiyori),
    new \Hiyori\Commands\RestoreCommand(),
    new \Hiyori\Commands\HiyoriIdCommand($hiyori),
    new \Hiyori\Commands\MlExportPairsCommand($hiyori),
    new \Hiyori\Commands\MlTrainCommand(),
    new \Hiyori\Commands\BenchmarkCommand(),
    new \Hiyori\Commands\LabelsCommand($hiyori),
    new \Hiyori\Commands\CombineDupesCommand($hiyori),
    new \Hiyori\Commands\CombineDedupeCommand($hiyori),
    new \Hiyori\Commands\CombineConsensusCommand($hiyori),
    new \Hiyori\Commands\CombineFsCalibrateCommand(),
    new \Hiyori\Commands\RejectionsCommand($hiyori),
    new \Hiyori\Commands\MlLlmJudgeCommand($hiyori),
]);

/**
 * Run application
 */
$app->run();
