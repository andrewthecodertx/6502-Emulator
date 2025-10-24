<?php

declare(strict_types=1);

namespace andrewthecoder\WDC65C02;

/**
 * Peripheral interface for memory-mapped devices in 6502 systems.
 *
 * Peripherals are memory-mapped devices that respond to specific address ranges
 * within the 64KB address space. They can perform I/O operations, run periodic
 * updates, and generate hardware interrupts.
 *
 * All peripherals must declare their address boundaries to enable:
 * - Memory map validation and conflict detection
 * - System documentation and debugging
 * - Proper bus routing decisions
 *
 * Address Space Rules:
 * - Each peripheral must occupy a contiguous address range
 * - Address ranges should not overlap with other peripherals or memory
 * - Both start and end addresses are inclusive
 * - Address ranges can be anywhere in the 64KB space (0x0000-0xFFFF)
 * - Zero page (0x0000-0x00FF) is allowed for peripherals
 */
interface PeripheralInterface
{
    /**
     * Gets the first address handled by this peripheral.
     *
     * This defines the start of the peripheral's memory-mapped address range.
     * The peripheral will handle all addresses from this value through the
     * value returned by getEndAddress() (inclusive).
     *
     * Used by bus implementations for routing, validation, and memory map
     * documentation. Must be consistent with handlesAddress() implementation.
     *
     * @return int The starting address (0x0000-0xFFFF)
     */
    public function getStartAddress(): int;

    /**
     * Gets the last address handled by this peripheral.
     *
     * This defines the end of the peripheral's memory-mapped address range.
     * The peripheral will handle all addresses from getStartAddress() through
     * this value (inclusive).
     *
     * Used by bus implementations for routing, validation, and memory map
     * documentation. Must be consistent with handlesAddress() implementation.
     *
     * @return int The ending address (0x0000-0xFFFF)
     */
    public function getEndAddress(): int;

    /**
     * Determines if this peripheral handles the specified address.
     *
     * This method is called for every memory access to determine routing.
     * Must return true for all addresses in the range [getStartAddress(), getEndAddress()].
     *
     * Performance Note: This is called frequently during execution. For best
     * performance, implementations should use simple range checks:
     *
     * Example:
     *   return $address >= $this->baseAddress &&
     *          $address <= $this->baseAddress + $this->size - 1;
     *
     * @param int $address The memory address to check (0x0000-0xFFFF)
     * @return bool True if this peripheral handles this address
     */
    public function handlesAddress(int $address): bool;

    /**
     * Reads a byte from the peripheral at the specified address.
     *
     * Called when the CPU reads from a memory address handled by this peripheral.
     * The address is guaranteed to be within [getStartAddress(), getEndAddress()]
     * when called through a properly implemented bus.
     *
     * @param int $address The memory address to read (0x0000-0xFFFF)
     * @return int The byte value (0x00-0xFF)
     */
    public function read(int $address): int;

    /**
     * Writes a byte to the peripheral at the specified address.
     *
     * Called when the CPU writes to a memory address handled by this peripheral.
     * The address is guaranteed to be within [getStartAddress(), getEndAddress()]
     * when called through a properly implemented bus.
     *
     * @param int $address The memory address to write (0x0000-0xFFFF)
     * @param int $value The byte value to write (0x00-0xFF)
     */
    public function write(int $address, int $value): void;

    /**
     * Performs one cycle of peripheral operation.
     *
     * Called once per CPU cycle to allow the peripheral to update its internal
     * state, advance timers, process I/O operations, etc. This enables accurate
     * timing emulation for peripherals that have time-dependent behavior.
     *
     * Examples:
     * - Decrement timer counters
     * - Process serial communication buffers
     * - Update display state
     * - Generate timed interrupts
     */
    public function tick(): void;

    /**
     * Checks if this peripheral has a pending interrupt request.
     *
     * The 6502 CPU checks this during instruction execution to determine if
     * an IRQ should be processed. Peripherals can signal interrupt requests
     * by returning true from this method.
     *
     * IRQ Behavior:
     * - IRQs are maskable (disabled when the I flag is set)
     * - Multiple peripherals can request IRQ simultaneously
     * - The CPU will process IRQ at the end of the current instruction
     * - Peripherals should maintain IRQ state until acknowledged
     *
     * @return bool True if this peripheral has a pending IRQ
     */
    public function hasInterruptRequest(): bool;
}
