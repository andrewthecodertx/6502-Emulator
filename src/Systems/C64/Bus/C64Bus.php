<?php

namespace Emulator\Systems\C64\Bus;

use Emulator\Core\BusInterface;
use Emulator\Systems\C64\Bus\PeripheralInterface;

/**
 * Commodore 64 System Bus
 *
 * Memory Map:
 * $0000-$0001  6510 I/O Port (controls banking)
 * $0002-$00FF  Zero Page
 * $0100-$01FF  Stack
 * $0200-$9FFF  RAM (40KB)
 * $A000-$BFFF  BASIC ROM (8KB) or RAM (bankable)
 * $C000-$CFFF  RAM (4KB)
 * $D000-$DFFF  I/O Area or Character ROM (4KB) (bankable)
 *   $D000-$D3FF  VIC-II registers
 *   $D400-$D7FF  SID registers
 *   $D800-$DBFF  Color RAM (1KB, nybbles only)
 *   $DC00-$DCFF  CIA #1
 *   $DD00-$DDFF  CIA #2
 *   $DE00-$DEFF  I/O Expansion 1
 *   $DF00-$DFFF  I/O Expansion 2
 * $E000-$FFFF  KERNAL ROM (8KB) or RAM (bankable)
 *
 * Banking controlled by bits 0-2 of address $0001:
 * Bit 0: LORAM - 0=RAM, 1=BASIC ROM at $A000-$BFFF
 * Bit 1: HIRAM - 0=RAM, 1=KERNAL ROM at $E000-$FFFF
 * Bit 2: CHAREN - 0=Character ROM, 1=I/O at $D000-$DFFF
 */
class C64Bus implements BusInterface
{
    /** @var array<int, int> 64KB RAM */
    private array $ram;
    /** @var array<int, int> 8KB BASIC ROM */
    private array $basicRom;
    /** @var array<int, int> 4KB Character ROM */
    private array $characterRom;
    /** @var array<int, int> 8KB KERNAL ROM */
    private array $kernalRom;
    /** @var array<int, int> 1KB Color RAM (nybbles) */
    private array $colorRam;

    private int $cpuPort = 0x37;     // CPU port data register at $0001 (default: all banks enabled)
    private int $cpuPortDir = 0x2F;  // CPU port direction register at $0000

    /** @var PeripheralInterface[] */
    private array $peripherals = [];

    /** Creates a new C64 bus with default memory configuration. */
    public function __construct()
    {
        $this->ram = array_fill(0, 0x10000, 0);
        $this->basicRom = array_fill(0, 0x2000, 0);      // 8KB
        $this->characterRom = array_fill(0, 0x1000, 0);  // 4KB
        $this->kernalRom = array_fill(0, 0x2000, 0);     // 8KB
        $this->colorRam = array_fill(0, 0x0400, 0);
    }

    /**
     * Attaches a peripheral to the bus for I/O area access.
     *
     * @param PeripheralInterface $peripheral The peripheral to attach
     */
    public function attachPeripheral(PeripheralInterface $peripheral): void
    {
        $this->peripherals[] = $peripheral;
    }

    /**
     * Loads BASIC ROM from file.
     *
     * @param string $filename Path to 8KB BASIC ROM file
     * @throws \RuntimeException If file cannot be read
     */
    public function loadBasicRom(string $filename): void
    {
        $data = file_get_contents($filename);
        if ($data === false) {
            throw new \RuntimeException("Failed to load BASIC ROM: $filename");
        }

        for ($i = 0; $i < min(strlen($data), 0x2000); $i++) {
            $this->basicRom[$i] = ord($data[$i]);
        }
    }

    /**
     * Loads Character ROM from file.
     *
     * @param string $filename Path to 4KB Character ROM file
     * @throws \RuntimeException If file cannot be read
     */
    public function loadCharacterRom(string $filename): void
    {
        $data = file_get_contents($filename);
        if ($data === false) {
            throw new \RuntimeException("Failed to load Character ROM: $filename");
        }

        for ($i = 0; $i < min(strlen($data), 0x1000); $i++) {
            $this->characterRom[$i] = ord($data[$i]);
        }
    }

    /**
     * Loads KERNAL ROM from file.
     *
     * @param string $filename Path to 8KB KERNAL ROM file
     * @throws \RuntimeException If file cannot be read
     */
    public function loadKernalRom(string $filename): void
    {
        $data = file_get_contents($filename);
        if ($data === false) {
            throw new \RuntimeException("Failed to load KERNAL ROM: $filename");
        }

        for ($i = 0; $i < min(strlen($data), 0x2000); $i++) {
            $this->kernalRom[$i] = ord($data[$i]);
        }
    }

    /**
     * Loads a program into RAM.
     *
     * @param array<int, int> $data Program bytes
     * @param int $startAddress Load address (default: $0801 for BASIC)
     */
    public function loadProgram(array $data, int $startAddress = 0x0801): void
    {
        foreach ($data as $offset => $byte) {
            $address = ($startAddress + $offset) & 0xFFFF;
            $this->ram[$address] = $byte & 0xFF;
        }
    }

    /**
     * Reads a byte from memory with banking logic.
     *
     * Respects LORAM, HIRAM, CHAREN bits for ROM/RAM/I/O selection.
     *
     * @param int $address The memory address (will be masked to 16-bit)
     * @return int The byte value (0-255)
     */
    public function read(int $address): int
    {
        $address &= 0xFFFF;

        if ($address === 0x0000) {
            return $this->cpuPortDir;
        }
        if ($address === 0x0001) {
            return $this->cpuPort;
        }

        $loram = ($this->cpuPort & 0x01) !== 0;
        $hiram = ($this->cpuPort & 0x02) !== 0;
        $charen = ($this->cpuPort & 0x04) !== 0;

        if ($address >= 0xA000 && $address <= 0xBFFF) {
            if ($loram && $hiram) {
                // BASIC ROM
                return $this->basicRom[$address - 0xA000];
            }
            return $this->ram[$address];
        }

        if ($address >= 0xD000 && $address <= 0xDFFF) {
            if ($charen && ($loram || $hiram)) {
                // I/O Area
                return $this->readIO($address);
            } elseif (!$charen && ($loram && $hiram)) {
                // Character ROM
                return $this->characterRom[$address - 0xD000];
            }
            return $this->ram[$address];
        }

        if ($address >= 0xE000) {
            if ($hiram) {
                // KERNAL ROM
                return $this->kernalRom[$address - 0xE000];
            }
            return $this->ram[$address];
        }

        return $this->ram[$address];
    }

    /**
     * Writes a byte to memory with banking logic.
     *
     * Respects banking bits for I/O area writes vs RAM writes.
     *
     * @param int $address The memory address (will be masked to 16-bit)
     * @param int $value The byte value to write (will be masked to 8-bit)
     */
    public function write(int $address, int $value): void
    {
        $address &= 0xFFFF;
        $value &= 0xFF;

        if ($address === 0x0000) {
            $this->cpuPortDir = $value;
            return;
        }
        if ($address === 0x0001) {
            $this->cpuPort = $value;
            return;
        }

        if ($address >= 0xD000 && $address <= 0xDFFF) {
            $charen = ($this->cpuPort & 0x04) !== 0;
            $loram = ($this->cpuPort & 0x01) !== 0;
            $hiram = ($this->cpuPort & 0x02) !== 0;

            if ($charen && ($loram || $hiram)) {
                // I/O Area - write to peripherals
                $this->writeIO($address, $value);
                return;
            }
        }

        $this->ram[$address] = $value;
    }

    /**
     * Reads from I/O area ($D000-$DFFF).
     *
     * @param int $address The I/O address
     * @return int The byte value (0-255)
     */
    private function readIO(int $address): int
    {
        // Color RAM ($D800-$DBFF) - only lower 4 bits used
        if ($address >= 0xD800 && $address <= 0xDBFF) {
            return $this->colorRam[$address - 0xD800] & 0x0F;
        }

        // Check peripherals
        foreach ($this->peripherals as $peripheral) {
            if ($peripheral->handlesAddress($address)) {
                return $peripheral->read($address);
            }
        }

        return 0xFF;
    }

    /**
     * Writes to I/O area ($D000-$DFFF).
     *
     * @param int $address The I/O address
     * @param int $value The byte value to write
     */
    private function writeIO(int $address, int $value): void
    {
        // Color RAM ($D800-$DBFF) - only lower 4 bits used
        if ($address >= 0xD800 && $address <= 0xDBFF) {
            $this->colorRam[$address - 0xD800] = $value & 0x0F;
            return;
        }

        foreach ($this->peripherals as $peripheral) {
            if ($peripheral->handlesAddress($address)) {
                $peripheral->write($address, $value);
                return;
            }
        }
    }

    /**
     * Reads a 16-bit word from memory in little-endian format.
     *
     * @param int $address The starting memory address
     * @return int The 16-bit word value (0-65535)
     */
    public function readWord(int $address): int
    {
        $low = $this->read($address);
        $high = $this->read($address + 1);
        return $low | ($high << 8);
    }

    /** Updates all peripherals for one cycle. */
    public function tick(): void
    {
        foreach ($this->peripherals as $peripheral) {
            $peripheral->tick();
        }
    }

    /** @return int The CPU port data register value */
    public function getCpuPort(): int
    {
        return $this->cpuPort;
    }

    /** @return array<int, int> The 64KB RAM array */
    public function getRam(): array
    {
        return $this->ram;
    }
}
