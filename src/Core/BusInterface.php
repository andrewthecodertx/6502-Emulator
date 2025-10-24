<?php

declare(strict_types=1);

namespace andrewthecoder\Core;

/**
 * Bus interface that all system buses must implement.
 *
 * The bus is responsible for routing memory read/write operations to the appropriate
 * memory regions (RAM, ROM, memory-mapped I/O devices, etc.) and coordinating
 * peripheral device updates.
 */
interface BusInterface
{
    /**
     * Read a byte from the specified memory address.
     *
     * @param int $address The 16-bit memory address to read from (0x0000-0xFFFF)
     * @return int The byte value at that address (0x00-0xFF)
     */
    public function read(int $address): int;

    /**
     * Read a 16-bit word from the specified memory address (little-endian).
     *
     * Reads two consecutive bytes and combines them into a 16-bit value.
     * The byte at $address is the low byte, $address+1 is the high byte.
     *
     * @param int $address The 16-bit memory address to read from (0x0000-0xFFFF)
     * @return int The 16-bit word value (0x0000-0xFFFF)
     */
    public function readWord(int $address): int;

    /**
     * Write a byte to the specified memory address.
     *
     * @param int $address The 16-bit memory address to write to (0x0000-0xFFFF)
     * @param int $value The byte value to write (0x00-0xFF)
     */
    public function write(int $address, int $value): void;

    /**
     * Update all peripherals attached to the bus.
     *
     * Called once per CPU cycle to allow peripherals to update their state,
     * handle timers, generate interrupts, etc.
     */
    public function tick(): void;
}
