<?php

declare(strict_types=1);

namespace Emulator\Systems\BenEater;

use Emulator\Core\CPUMonitor;

/**
 * Simple random access memory implementation.
 *
 * Provides 64KB addressable RAM with optional monitoring support for
 * debugging. Uninitialized addresses return 0.
 */
class RAM
{
    /** @var array<int, int> */ private array $ram = [];

    /**
     * Creates a new RAM instance.
     *
     * @param CPUMonitor|null $monitor Optional monitor for logging memory access
     */
    public function __construct(
        private ?CPUMonitor $monitor = null
    ) {
    }

    /**
     * Reads a byte from RAM at the specified address.
     *
     * Uninitialized addresses return 0.
     *
     * @param int $addr The memory address (will be masked to 16-bit)
     * @return int The byte value (0-255)
     */
    public function readByte(int $addr): int
    {
        $addr = $addr & 0xFFFF;
        $data = $this->ram[$addr] ?? 0;

        if ($this->monitor !== null) {
            $this->monitor->logMemoryRead($addr, $data);
        }

        return $data;
    }

    /**
     * Writes a byte to RAM at the specified address.
     *
     * @param int $addr The memory address (will be masked to 16-bit)
     * @param int $value The byte value to write (will be masked to 8-bit)
     */
    public function writeByte(int $addr, int $value): void
    {
        $addr = $addr & 0xFFFF;
        $value = $value & 0xFF;

        $this->ram[$addr] = $value;

        if ($this->monitor !== null) {
            $this->monitor->logMemoryWrite($addr, $value);
        }
    }

    /**
     * Sets or clears the CPU monitor for memory access logging.
     *
     * @param CPUMonitor|null $monitor The monitor instance or null to disable
     */
    public function setMonitor(?CPUMonitor $monitor): void
    {
        $this->monitor = $monitor;
    }
}
