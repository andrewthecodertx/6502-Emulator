<?php

declare(strict_types=1);

namespace Emulator\Systems\BenEater\Bus;

use Emulator\Core\CPU;
use Emulator\Core\BusInterface;
use Emulator\Systems\BenEater\RAM;
use Emulator\Systems\BenEater\ROM;

/**
 * Memory-mapped I/O bus for the BenEater 6502 system.
 *
 * Coordinates memory access between RAM, ROM, and peripherals with automatic
 * address decoding. Peripherals are checked first, falling through to ROM
 * ($8000-$FFFF) or RAM. Includes edge-triggered IRQ handling for peripherals.
 */
class SystemBus implements BusInterface
{
    private RAM $ram;
    private ROM $rom;
    private ?CPU $cpu = null;
    /** @var array<int, PeripheralInterface> */
    private array $peripherals = [];
    /** @var array<int, bool> */
    private array $lastIrqState = [];

    /**
     * Creates a new system bus with the specified RAM and ROM.
     *
     * @param RAM $ram The system RAM
     * @param ROM $rom The system ROM
     */
    public function __construct(RAM $ram, ROM $rom)
    {
        $this->ram = $ram;
        $this->rom = $rom;
    }

    /**
     * Attaches the CPU to this bus for IRQ signaling.
     *
     * @param CPU $cpu The CPU instance
     */
    public function setCpu(CPU $cpu): void
    {
        $this->cpu = $cpu;
    }

    /**
     * Adds a peripheral to the bus for memory-mapped I/O.
     *
     * Peripherals are checked in the order they are added.
     *
     * @param PeripheralInterface $peripheral The peripheral to add
     */
    public function addPeripheral(PeripheralInterface $peripheral): void
    {
        $this->peripherals[] = $peripheral;
    }

    /**
     * Reads a byte from memory at the specified address.
     *
     * Checks peripherals first, then ROM ($8000+), then RAM.
     *
     * @param int $address The memory address (will be masked to 16-bit)
     * @return int The byte value (0-255)
     */
    public function read(int $address): int
    {
        $address = $address & 0xFFFF;

        foreach ($this->peripherals as $peripheral) {
            if ($peripheral->handlesAddress($address)) {
                return $peripheral->read($address);
            }
        }

        if ($address >= ROM::ROM_START) {
            return $this->rom->readByte($address);
        }

        return $this->ram->readByte($address);
    }

    /**
     * Writes a byte to memory at the specified address.
     *
     * Checks peripherals first, then ignores ROM writes, then writes to RAM.
     *
     * @param int $address The memory address (will be masked to 16-bit)
     * @param int $value The byte value to write (will be masked to 8-bit)
     */
    public function write(int $address, int $value): void
    {
        $address = $address & 0xFFFF;
        $value = $value & 0xFF;

        foreach ($this->peripherals as $peripheral) {
            if ($peripheral->handlesAddress($address)) {
                $peripheral->write($address, $value);
                return;
            }
        }

        if ($address >= ROM::ROM_START) {
            // Cannot write to ROM
            return;
        }

        $this->ram->writeByte($address, $value);
    }

    /**
     * Updates all peripherals and handles edge-triggered IRQ requests.
     *
     * Called once per CPU cycle. Detects LOW->HIGH transitions on peripheral
     * IRQ lines and signals the CPU accordingly.
     */
    public function tick(): void
    {
        foreach ($this->peripherals as $index => $peripheral) {
            $peripheral->tick();

            // Edge-triggered IRQ: only request on LOW->HIGH transition
            $currentIrqState = $peripheral->hasInterruptRequest();
            $lastState = $this->lastIrqState[$index] ?? false;

            if ($this->cpu && $currentIrqState && !$lastState) {
                $this->cpu->requestIRQ();
            }

            $this->lastIrqState[$index] = $currentIrqState;
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
        return ($high << 8) | $low;
    }
}
