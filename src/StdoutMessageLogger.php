<?php

declare(strict_types=1);

namespace Antlr\Antlr4\Runtime;

use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface as Logger;

final class StdoutMessageLogger extends AbstractLogger implements Logger
{
    /**
     * @param mixed        $level
     * @param array<mixed> $context
     */
    public function log($level, \Stringable|string $message, array $context = []): void
    {
        \fwrite(\STDOUT, self::formatMessage($message, $context) . \PHP_EOL);
    }

    /**
     * @param array<string, mixed> $context
     */
    private static function formatMessage(\Stringable|string $message, array $context): string
    {
        $replace = [];
        foreach ($context as $key => $val) {
            $replace['{' . $key . '}'] = $val;
        }

        return \strtr((string) $message, $replace);
    }
}
