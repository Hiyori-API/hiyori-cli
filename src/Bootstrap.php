<?php

require_once __DIR__ . '/../vendor/autoload.php';

/**
 * Preflight
 */
$dotenv = new \Symfony\Component\Dotenv\Dotenv();
$dotenv->load(__DIR__.'/../.env');

/**
 * Create application
 */
$app = (new \Symfony\Component\Console\Application('Hiyori CLI', $_ENV['APP_VERSION']));
$app->setAutoExit(true);

/**
 * Register services
 */
$container = new \Symfony\Component\DependencyInjection\ContainerBuilder();

// Set parameters
$container->setParameter('rate_limit.max_requests', (int)($_ENV['RATE_LIMIT_MAX'] ?? 100));
$container->setParameter('rate_limit.time_window', (int)($_ENV['RATE_LIMIT_WINDOW'] ?? 60));

$container->autowire(\Hiyori\Service\Logging::class);
$container->autowire(\Hiyori\Service\Database::class);
$container->autowire(\Hiyori\Service\Serializer::class);
$container->autowire(\Hiyori\Service\ConsoleFactory::class)
    ->setFactory([\Hiyori\Service\ConsoleFactory::class, 'create']);
$container->autowire(\Hiyori\Service\SourceConfigurationFactory::class)
    ->setFactory([\Hiyori\Service\SourceConfigurationFactory::class, 'create']);
$container->autowire(\Hiyori\Service\StrategyConfigurationFactory::class)
    ->setFactory([\Hiyori\Service\StrategyConfigurationFactory::class, 'create']);
$container->autowire(\Hiyori\Service\Ingestion\Ingestion::class);
$container->autowire(\Hiyori\Service\Combiner\Combiner::class);
$container->autowire(\Hiyori\Service\RateLimiter::class)
    ->setArguments([
        '%rate_limit.max_requests%',
        '%rate_limit.time_window%',
    ]);
$container->autowire(\Hiyori\Service\MetricsCollector::class);
$container->autowire(\Hiyori\Service\HealthCheck::class)
    ->setArguments([
        '$mongoClient' => new \MongoDB\Client($_ENV['MONGODB_URL']),
        '$metrics' => new \Hiyori\Service\MetricsCollector(),
        '$rateLimiter' => new \Hiyori\Service\RateLimiter(
            (int)($_ENV['RATE_LIMIT_MAX'] ?? 100),
            (int)($_ENV['RATE_LIMIT_WINDOW'] ?? 60)
        ),
    ]);
$container->autowire(\Hiyori\Hiyori::class)
    ->setPublic(true);

$container->compile();
$hiyori = $container->get(\Hiyori\Hiyori::class);

/**
 * Register commands
 */
$app->addCommands([
    new \Hiyori\Commands\IngestionCommand($hiyori),
    new \Hiyori\Commands\CombinerCommand($hiyori),
    new \Hiyori\Commands\ReferencesCommand($hiyori),
    new \Hiyori\Commands\WeightageCommand($hiyori),
    new \Hiyori\Commands\SweeperCommand($hiyori),
    new \Hiyori\Commands\ExportCommand($hiyori),
    new \Hiyori\Commands\HealthCommand($hiyori),
    new \Hiyori\Commands\MetricsCommand($hiyori),
]);

/**
 * Run application
 */
$app->run();
