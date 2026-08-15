<?php

declare(strict_types=1);

namespace Antlr\Antlr4\Runtime;

/**
 * Vacuum all input from a string and then treat it like a buffer.
 *
 * Indexing is by Unicode code point, matching Java's `CodePointCharStream`, so
 * an astral-plane character occupies one position rather than the two a UTF-16
 * stream would use.
 *
 * The decoding is done **once**, up front, into an array of code points. The
 * lexer reads `LA()` several times per input character — it was the single
 * hottest call in the runtime — and decoding on each read made every one of
 * those a `mb_ord()` call. Text extraction needs the original bytes, so a byte
 * offset per code point is kept alongside, letting `getText()` be a plain
 * `substr()` instead of a scan.
 */
final class InputStream implements CharStream
{
    protected int $index = 0;

    protected int $size = 0;

    public string $name = '<empty>';

    public string $input;

    /**
     * The decoded input, one Unicode code point per element.
     *
     * @var array<int>
     */
    private array $codePoints = [];

    /**
     * Byte offset of each code point within {@see InputStream::$input}, with one
     * extra entry holding the total length so a slice's end is always known.
     * Empty when the input is pure ASCII, where the offset is the index itself.
     *
     * @var array<int>
     */
    private array $byteOffsets = [];

    /**
     * @param array<int> $codePoints
     * @param array<int> $byteOffsets
     */
    private function __construct(string $input, array $codePoints, array $byteOffsets)
    {
        $this->input = $input;
        $this->codePoints = $codePoints;
        $this->byteOffsets = $byteOffsets;
        $this->size = \count($codePoints);
    }

    public static function fromString(string $input): InputStream
    {
        if ($input === '') {
            return new self($input, [], []);
        }

        $length = \strlen($input);

        // Pure ASCII is the overwhelmingly common case and needs no decoding at
        // all: byte value is code point, and byte offset is index.
        if (\preg_match('/[\x80-\xFF]/', $input) === 0) {
            /** @var array<int> $bytes */
            $bytes = \unpack('C*', $input);

            return new self($input, \array_values($bytes), []);
        }

        // Converting the whole string at once keeps the decoding inside mbstring
        // rather than paying a PHP call per character.
        $utf32 = @\mb_convert_encoding($input, 'UTF-32BE', 'UTF-8');

        if ($utf32 === '') {
            return new self($input, [], []);
        }

        /** @var array<int> $unpacked */
        $unpacked = \unpack('N*', $utf32);
        $codePoints = \array_values($unpacked);

        // A code point's UTF-8 width is a function of its value, so the offsets
        // follow from the decoded points without re-walking the bytes.
        $byteOffsets = [];
        $offset = 0;

        foreach ($codePoints as $codePoint) {
            $byteOffsets[] = $offset;
            $offset += match (true) {
                $codePoint < 0x80 => 1,
                $codePoint < 0x800 => 2,
                $codePoint < 0x10000 => 3,
                default => 4,
            };
        }

        $byteOffsets[] = $length;

        return new self($input, $codePoints, $byteOffsets);
    }

    public static function fromPath(string $path): InputStream
    {
        $content = \file_get_contents($path);

        if ($content === false) {
            throw new \InvalidArgumentException(\sprintf('File not found at %s.', $path));
        }

        // `CharStreams.fromPath()` records the path as the stream's source name;
        // it surfaces through `Lexer::getSourceName()` and `Parser::getSourceName()`.
        $stream = self::fromString($content);
        $stream->name = $path;

        return $stream;
    }

    /**
     * The decoded code points backing this stream.
     *
     * Exposed so the lexer's inner loop can read characters without a method
     * call per access; treat it as read-only.
     *
     * @return array<int>
     */
    public function getCodePoints(): array
    {
        return $this->codePoints;
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

        return $this->codePoints[$pos];
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

        if ($start >= $this->size || $start > $stop) {
            return '';
        }

        if ($this->byteOffsets === []) {
            // ASCII: index and byte offset coincide.
            return \substr($this->input, $start, $stop - $start + 1);
        }

        $from = $this->byteOffsets[$start];

        return \substr($this->input, $from, $this->byteOffsets[$stop + 1] - $from);
    }

    public function getSourceName(): string
    {
        // Java falls back to `IntStream.UNKNOWN_SOURCE_NAME` rather than an empty
        // string, and the constant already existed here unused.
        return $this->name === '' || $this->name === '<empty>'
            ? IntStream::UNKNOWN_SOURCE_NAME
            : $this->name;
    }

    public function __toString(): string
    {
        return $this->input;
    }
}
