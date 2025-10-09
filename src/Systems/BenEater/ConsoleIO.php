<?php

declare(strict_types=1);

namespace Emulator\Systems\BenEater;

/**
 * Simple console I/O utility for character-based terminal interaction.
 *
 * Provides character output with special handling for control characters
 * (newline, carriage return, backspace, bell) and non-blocking input buffering.
 */
class ConsoleIO
{
    public const CONSOLE_OUTPUT = 0xD000;
    public const CONSOLE_INPUT_STATUS = 0xD001;
    public const CONSOLE_INPUT_DATA = 0xD002;

    /** @var array<string> */ private array $inputBuffer = [];
    private bool $inputReady = false;

    /** Initializes console I/O with non-blocking input (CLI mode only). */
    public function __construct()
    {
        if (php_sapi_name() === 'cli') {
            stream_set_blocking(STDIN, false);
        }
    }

    /**
     * Writes a character to console output.
     *
     * Special characters: LF, CR, BS, BEL. Printable: 0x20-0x7E.
     *
     * @param int $character The character code to write (0-127)
     */
    public function writeCharacter(int $character): void
    {
        $char = chr($character & 0x7F);

        switch ($character) {
            case 0x0A:
                echo "\n";
                break;
            case 0x0D:
                echo "\r";
                break;
            case 0x08:
                echo "\b";
                break;
            case 0x07:
                echo "\a";
                break;
            default:
                if ($character >= 0x20 && $character <= 0x7E) {
                    echo $char;
                }
                break;
        }

        if (ob_get_level()) {
            ob_flush();
        }

        flush();
    }

    /**
     * Gets input status (bit 7 set if data ready).
     *
     * @return int 0x80 if input ready, 0x00 otherwise
     */
    public function getInputStatus(): int
    {
        $this->checkForInput();
        return $this->inputReady ? 0x80 : 0x00;
    }

    /**
     * Reads next character from input buffer.
     *
     * @return int The character code (0-255) or 0x00 if no input
     */
    public function readCharacter(): int
    {
        $this->checkForInput();

        if (!empty($this->inputBuffer)) {
            $char = array_shift($this->inputBuffer);
            $this->inputReady = !empty($this->inputBuffer);
            return ord($char);
        }

        return 0x00;
    }

    /** Polls STDIN for new input and updates buffer. */
    private function checkForInput(): void
    {
        if (php_sapi_name() === 'cli') {
            $input = fread(STDIN, 1024);
            if ($input !== false && $input !== '') {
                for ($i = 0; $i < strlen($input); $i++) {
                    $this->inputBuffer[] = $input[$i];
                }

                $this->inputReady = !empty($this->inputBuffer);
            }
        }
    }

    /**
     * Checks if input is available.
     *
     * @return bool True if input is ready
     */
    public function hasInput(): bool
    {
        $this->checkForInput();
        return $this->inputReady;
    }
}
