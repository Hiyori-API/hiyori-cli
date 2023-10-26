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
]);

/**
 * Run application
 */
$app->run();