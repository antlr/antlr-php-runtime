<?php

declare(strict_types=1);

namespace Antlr\Antlr4\Runtime;

use Antlr\Antlr4\Runtime\Utils\StringUtils;

/**
 * Vacuum all input from a string and then treat it like a buffer.
 */
final class InputStream implements CharStream
{
    protected int $index = 0;

    protected int $size = 0;

    public string $name = '<empty>';

    public string $input;

    /** @var array<string> */
    public array $characters = [];

    /**
     * @param array<string> $characters
     */
    private function __construct(string $input, array $characters)
    {
        $this->input = $input;
        $this->characters = $characters;
        $this->size = \count($this->characters);
    }

    public static function fromString(string $input): InputStream
    {
        $chars = \preg_split('//u', $input, -1, \PREG_SPLIT_NO_EMPTY);

        return new self($input, $chars === false ? [] : $chars);
    }

    public static function fromPath(string $path): InputStream
    {
        $content = \file_get_contents($path);

        if ($content === false) {
            throw new \InvalidArgumentException(\sprintf('File not found at %s.', $path));
        }

        return self::fromString($content);
    }

    public function getIndex(): int
    {
        return $this->index;
    }

    public function getLength(): int
    {
        return $this->size;
    }

    public function consume(): void
    {
        if ($this->index >= $this->size) {
            // assert this.LA(1) == Token.EOF
            throw new \LogicException('Cannot consume EOF.');
        }

        $this->index++;
    }

    public function LA(int $offset): int
    {
        if ($offset === 0) {
            return 0;// undefined
        }

        if ($offset < 0) {
            // e.g., translate LA(-1) to use offset=0
            $offset++;
        }

        $pos = $this->index + $offset - 1;

        if ($pos < 0 || $pos >= $this->size) {
            // invalid
            return Token::EOF;
        }

        return StringUtils::codePoint($this->characters[$pos]);
    }

    public function LT(int $offset): int
    {
        return $this->LA($offset);
    }

    /**
     * Mark/release do nothing; we have entire buffer
     */
    public function mark(): int
    {
        return -1;
    }

    public function release(int $marker): void
    {
        // Override as needed
    }

    /**
     * {@see self::consume()} ahead until `$p === $this->index`; Can't just set
     * `$p = $this->index` as we must update line and column. If we seek
     * backwards, just set `$p`.
     */
    public function seek(int $index): void
    {
        if ($index <= $this->index) {
            $this->index = $index; // just jump; don't update stream state (line, ...)

            return;
        }

        // seek forward
        $this->index = \min($index, $this->size);
    }

    public function getText(int $start, int $stop): string
    {
        if ($stop >= $this->size) {
            $stop = $this->size - 1;
        }

        if ($start >= $this->size) {
            return '';
        }

        return \implode(\array_slice($this->characters, $start, $stop - $start + 1));
    }

    public function getSourceName(): string
    {
        return '';
    }

    public function __toString(): string
    {
        return $this->input;
    }
}
