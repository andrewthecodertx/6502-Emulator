<?php

declare(strict_types=1);

namespace Emulator;

class RAM
{
    /** @var array<int, int> */ private array $ram = [];

    public function __construct(
        private ?CPUMonitor $monitor = null
    ) {
    }

    public function readByte(int $addr): int
    {
        $addr = $addr & 0xFFFF;
        $data = $this->ram[$addr] ?? 0;

        if ($this->monitor !== null) {
            $this->monitor->logMemoryRead($addr, $data);
        }

        return $data;
    }

    public function writeByte(int $addr, int $value): void
    {
        $addr = $addr & 0xFFFF;
        $value = $value & 0xFF;

        $this->ram[$addr] = $value;

        if ($this->monitor !== null) {
            $this->monitor->logMemoryWrite($addr, $value);
        }
    }

    public function setMonitor(?CPUMonitor $monitor): void
    {
        $this->monitor = $monitor;
    }
}
