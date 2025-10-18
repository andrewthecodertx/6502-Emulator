<?php

declare(strict_types=1);

namespace andrewthecoder\MOS6502;

/**
 * Peripheral interface that all system buses must implement.
 *
 * Peripherals respond to specific memory addresses, support read/write
 * operations, run periodic updates, and can generate hardware interrupts.
 */
interface PeripheralInterface
{
    /**
     * Determines if this peripheral handles the specified address.
     *
     * @param int $address The memory address to check
     * @return bool True if this peripheral handles this address
     */
    public function handlesAddress(int $address): bool;

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
     * Checks if this peripheral has a pending interrupt request.
     *
     * @return bool True if an IRQ is pending
     */
    public function hasInterruptRequest(): bool;
}
