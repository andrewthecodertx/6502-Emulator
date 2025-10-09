<?php

namespace Emulator\Systems\C64\Bus;

/**
 * Contract for memory-mapped peripherals on the C64 system bus.
 *
 * Similar to BenEater's PeripheralInterface but without interrupt support
 * (C64 peripherals handle their own interrupt signaling).
 */
interface PeripheralInterface
{
    /**
     * Reads a byte from the peripheral at the specified address.
     *
     * @param int $address The memory address to read
     * @return int The byte value (0-255)
     */
    public function read(int $address): int;

    /**
     * Writes a byte to the peripheral at the specified address.
     *
     * @param int $address The memory address to write
     * @param int $value The byte value to write (0-255)
     */
    public function write(int $address, int $value): void;

    /**
     * Performs one cycle of peripheral operation.
     *
     * Called once per CPU cycle to update timers, process I/O, etc.
     */
    public function tick(): void;

    /**
     * Determines if this peripheral handles the specified address.
     *
     * @param int $address The memory address to check
     * @return bool True if this peripheral handles this address
     */
    public function handlesAddress(int $address): bool;
}
