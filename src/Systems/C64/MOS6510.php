<?php

namespace Emulator\Systems\C64;

use Emulator\Core\CPU;
use Emulator\Core\BusInterface;

/**
 * MOS 6510 CPU - Modified 6502 with 6-bit I/O port
 *
 * The 6510 is nearly identical to the 6502, but includes a built-in 6-bit I/O port
 * at addresses $0000 (direction) and $0001 (data). The C64 uses this port for:
 * - Bits 0-2: Memory banking control (LORAM, HIRAM, CHAREN)
 * - Bit 3: Cassette write line
 * - Bit 4: Cassette switch sense
 * - Bit 5: Cassette motor control
 *
 * NOTE: The actual I/O port data is handled by the C64Bus, this class just
 * extends the base CPU to be aware of the 6510-specific behavior.
 */
class MOS6510 extends CPU
{
    /** @param BusInterface $bus The system bus (typically C64Bus) */
    public function __construct(BusInterface $bus)
    {
        parent::__construct($bus);
    }

    public function getCpuPortData(): int
    {
        return $this->getBus()->read(0x0001);
    }

    public function getCpuPortDirection(): int
    {
        return $this->getBus()->read(0x0000);
    }

    public function setCpuPortData(int $value): void
    {
        $this->getBus()->write(0x0001, $value & 0xFF);
    }

    public function setCpuPortDirection(int $value): void
    {
        $this->getBus()->write(0x0000, $value & 0xFF);
    }

    public function isBasicRomEnabled(): bool
    {
        $port = $this->getCpuPortData();
        $loram = ($port & 0x01) !== 0;
        $hiram = ($port & 0x02) !== 0;
        return $loram && $hiram;
    }

    public function isKernalRomEnabled(): bool
    {
        $port = $this->getCpuPortData();
        $hiram = ($port & 0x02) !== 0;
        return $hiram;
    }

    public function isIOEnabled(): bool
    {
        $port = $this->getCpuPortData();
        $charen = ($port & 0x04) !== 0;
        $loram = ($port & 0x01) !== 0;
        $hiram = ($port & 0x02) !== 0;
        return $charen && ($loram || $hiram);
    }
}
