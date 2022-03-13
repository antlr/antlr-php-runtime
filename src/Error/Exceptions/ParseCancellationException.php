<?php

declare(strict_types=1);

namespace Antlr\Antlr4\Runtime\Error\Exceptions;

class ParseCancellationException extends \Exception
{
    public static function from(\Throwable $exception): self
    {
        return new self($exception->getMessage(), $exception->getCode(), $exception);
    }
}
