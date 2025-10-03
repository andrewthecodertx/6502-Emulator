<?php

declare(strict_types=1);

namespace Emulator;

class IOMemory extends RAM
{
    private ConsoleIO $console;

    public function __construct()
    {
        parent::__construct();
        $this->console = new ConsoleIO();
    }

    public function readByte(int $addr): int
    {
        $addr = $addr & 0xFFFF;

        switch ($addr) {
            case ConsoleIO::CONSOLE_INPUT_STATUS:
                return $this->console->getInputStatus();

            case ConsoleIO::CONSOLE_INPUT_DATA:
                return $this->console->readCharacter();

            default:
                return parent::readByte($addr);
        }
    }

    public function writeByte(int $addr, int $value): void
    {
        $addr = $addr & 0xFFFF;

        switch ($addr) {
            case ConsoleIO::CONSOLE_OUTPUT:
                $this->console->writeCharacter($value);
                break;

            default:
                parent::writeByte($addr, $value);
                break;
        }
    }

    public function getConsole(): ConsoleIO
    {
        return $this->console;
    }
}
