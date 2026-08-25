<?php

declare(strict_types=1);


/**
 * Everything the run writes to the terminal.
 *
 * Colour is decided once, from whether the stream is a terminal, so piping the
 * output somewhere gives plain text without any caller having to think about it.
 */
final class Console
{
    private const COLORS = [
        'green' => '0;32',
        'yellow' => '0;33',
        'red' => '0;31',
        'dim' => '2',
        'bold' => '1',
    ];

    /** @var resource */
    private $stream;

    /**
     * @param resource $stream
     */
    public function __construct($stream, private readonly bool $useColor)
    {
        $this->stream = $stream;
    }

    /**
     * @param resource $stream
     */
    public static function forStream($stream): self
    {
        return new self($stream, stream_isatty($stream));
    }

    /**
     * Colour is not this class's business alone: VarDumper writes its own escape
     * codes, and it has to be told the same answer.
     */
    public function usesColor(): bool
    {
        return $this->useColor;
    }

    /**
     * @param 'green'|'yellow'|'red'|'dim'|'bold' $color
     */
    public function paint(string $text, string $color): string
    {
        if (!$this->useColor) {
            return $text;
        }

        return "\033[" . self::COLORS[$color] . 'm' . $text . "\033[0m";
    }

    public function line(string $text = ''): void
    {
        fwrite($this->stream, $text . PHP_EOL);
    }

    public function title(string $text): void
    {
        $this->line($this->paint($text, 'bold'));
    }

    public function heading(string $text): void
    {
        $this->line();
        $this->line($this->paint($text, 'bold'));
    }

    public function ok(string $text): void
    {
        $this->line('  ' . $this->paint('✓', 'green') . ' ' . $text);
    }

    public function warn(string $text): void
    {
        $this->line('  ' . $this->paint('!', 'yellow') . ' ' . $this->paint($text, 'yellow'));
    }

    public function info(string $text): void
    {
        $this->line('  ' . $this->paint($text, 'dim'));
    }

    /**
     * A problem worth naming where it was found, ahead of the failure the run
     * ends with.
     */
    public function error(string $text): void
    {
        $this->line('  ' . $this->paint('✗', 'red') . ' ' . $this->paint($text, 'red'));
    }

    public function failure(SmokeTestFailure $failure): void
    {
        $this->line();
        $this->line('  ' . $this->paint('✗', 'red') . ' ' . $this->paint($failure->getMessage(), 'red'));

        foreach ($failure->details() as $detail) {
            $this->line('    ' . $this->paint($detail, 'dim'));
        }

        $this->line();
        $this->line($this->paint('FAIL', 'red'));
    }

    public function success(): void
    {
        $this->line();
        $this->line($this->paint('PASS', 'green'));
    }
}
