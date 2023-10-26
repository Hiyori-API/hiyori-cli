<?php

namespace Hiyori\Service;

use Monolog\Handler\RotatingFileHandler;
use Monolog\Level;
use Monolog\Logger;

class Logging
{
    private Logger $logger;
    public function __construct()
    {
        $this->logger = new Logger('main');
        $this->logger
            ->pushHandler(new RotatingFileHandler(
                __DIR__.'/../../storage/logs/daily',
                5,
                $this->assertLogLevel(),
            ));
    }

    private function assertLogLevel(): Level
    {
        $appDebug = $_ENV['APP_DEBUG'] ?? false;

        return $appDebug ? Level::Debug : Level::Info;
    }

}
