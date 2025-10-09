<?php

declare(strict_types=1);

namespace Emulator\Systems\BenEater;

class ConsoleIO
{
    public const CONSOLE_OUTPUT = 0xD000;
    public const CONSOLE_INPUT_STATUS = 0xD001;
    public const CONSOLE_INPUT_DATA = 0xD002;

    /** @var array<string> */ private array $inputBuffer = [];
    private bool $inputReady = false;

    public function __construct()
    {
        if (php_sapi_name() === 'cli') {
            stream_set_blocking(STDIN, false);
        }
    }

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

    public function getInputStatus(): int
    {
        $this->checkForInput();
        return $this->inputReady ? 0x80 : 0x00;
    }

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

    public function hasInput(): bool
    {
        $this->checkForInput();
        return $this->inputReady;
    }
}
