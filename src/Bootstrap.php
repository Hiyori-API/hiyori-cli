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

// Rate limits: a dashboard override (storage/state/ui.json → limits) wins over
// the RATE_LIMIT_* env vars, so the Management page's "Save" really applies to
// the next ingest process. Mirrors rateLimits() in public/index.php.
$rateMax = (int) ($_ENV['RATE_LIMIT_MAX'] ?? 100);
$rateWindow = (int) ($_ENV['RATE_LIMIT_WINDOW'] ?? 60);
$uiStateFile = dirname(__DIR__) . '/storage/state/ui.json';
if (is_file($uiStateFile)) {
    $uiState = json_decode((string) file_get_contents($uiStateFile), true);
    $override = is_array($uiState) ? ($uiState['limits'] ?? null) : null;
    if (is_array($override) && (int) ($override['max'] ?? 0) > 0 && (int) ($override['window'] ?? 0) > 0) {
        $rateMax = (int) $override['max'];
        $rateWindow = (int) $override['window'];
    }
}

// Set parameters
$container->setParameter('rate_limit.max_requests', $rateMax);
$container->setParameter('rate_limit.time_window', $rateWindow);

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
        '$mongoClient' => new \MongoDB\Client($_ENV['MONGODB_URL']),
        '$rateLimiter' => new \Hiyori\Service\RateLimiter(
            $rateMax,
            $rateWindow,
        ),
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
    new \Hiyori\Commands\RejectionsCommand($hiyori),
    new \Hiyori\Commands\MlLlmJudgeCommand($hiyori),
]);

/**
 * Run application
 */
$app->run();
