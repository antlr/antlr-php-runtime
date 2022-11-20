<?php

declare(strict_types=1);

namespace Antlr\Antlr4\Runtime;

use Monolog\Formatter\LineFormatter;
use Monolog\Handler\StreamHandler;
use Monolog\Logger as MonologLogger;
use Psr\Log\LoggerInterface as PsrLogger;

final class LoggerProvider
{
    private static ?PsrLogger $logger = null;

    public static function setLogger(PsrLogger $logger): void
    {
        self::$logger = $logger;
    }

    public static function getLogger(): PsrLogger
    {
        if (self::$logger === null) {
            self::$logger = self::getDefaultLogger();
        }

        return self::$logger;
    }

    private static function getDefaultLogger(): PsrLogger
    {
        $logger = new MonologLogger('name');
        $handler = new StreamHandler('php://stdout');
        $handler->setFormatter(new LineFormatter('%message%'));
        $logger->pushHandler($handler);

        return $logger;
    }
}
