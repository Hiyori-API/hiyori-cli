<?php

require_once __DIR__ . '/../vendor/autoload.php';


$app = new \Symfony\Component\Console\Application('Hiyori', '1.0.0');

$app->add(new \Hiyori\Commands\AnimeIndexerCommand());

$app->run();