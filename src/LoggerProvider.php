<?php

declare(strict_types=1);

namespace Antlr\Antlr4\Runtime;

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
            self::$logger = new StdoutMessageLogger();
        }

        return self::$logger;
    }
}
