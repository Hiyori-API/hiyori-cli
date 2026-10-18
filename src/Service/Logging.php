<?php

namespace Hiyori\Service;

use Monolog\Handler\RotatingFileHandler;
use Monolog\Level;
use Monolog\Logger;

/**
 *
 */
class Logging
{
    /**
     * @var Logger
     */
    private Logger $logger;

    /**
     *
     */
    public function __construct()
    {
        $this->logger = new Logger('main');
        $this->logger
            ->pushHandler(new RotatingFileHandler(
                __DIR__ . '/../../storage/logs/daily',
                5,
                $this->assertLogLevel(),
            ));
    }

    /**
     * @return Level
     */
    private function assertLogLevel(): Level
    {
        // Dotenv yields strings; "false" is truthy, so cast properly.
        $appDebug = filter_var($_ENV['APP_DEBUG'] ?? false, FILTER_VALIDATE_BOOL);

        return $appDebug ? Level::Debug : Level::Info;
    }

    /**
     * @return Logger
     */
    public function getLogger(): Logger
    {
        return $this->logger;
    }
}
