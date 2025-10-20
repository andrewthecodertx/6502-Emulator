<?php

declare(strict_types=1);

namespace andrewthecoder\MOS6502;

/**
 * Interface for random access memory implementations in 6502 systems.
 *
 * Defines the contract for RAM components used across different 6502-based
 * systems. RAM provides read/write storage for program execution, variables,
 * stack operations, and general data storage.
 *
 * RAM is typically memory-mapped to the lower address space and must include:
 * - Zero Page ($0000-$00FF): Fast direct-page addressing
 * - Stack ($0100-$01FF): Hardware stack for CPU operations
 * - General purpose memory for program data and variables
 *
 * The 6502 CPU requires specific RAM regions:
 * - Stack region ($0100-$01FF) MUST be RAM for proper CPU operation
 * - Zero page ($0000-$00FF) is typically RAM for optimal performance
 *
 * Address Space Rules:
 * - Each RAM must declare its address boundaries via getStartAddress/getEndAddress
 * - Address ranges must not overlap with other RAM, ROM, or peripherals
 * - RAM addresses are readable and writable from the CPU's perspective
 * - Address ranges can be anywhere in the 64KB space (0x0000-0xFFFF)
 */
interface RAMInterface
{
    /**
     * Placeholder constants for RAM address boundaries.
     *
     * These are interface-level constants that must be overridden by concrete
     * implementations via their own class constants. Implementations should
     * define their actual RAM_START and RAM_END values and return them from
     * getStartAddress() and getEndAddress().
     *
     * Example implementation:
     *   class RAM implements RAMInterface {
     *       public const RAM_START = 0x0000;
     *       public const RAM_END = 0x7FFF;
     *
     *       public function getStartAddress(): int {
     *           return self::RAM_START;
     *       }
     *   }
     */
    public const RAM_START = 0;
    public const RAM_END = 0;

    /**
     * Reads a byte from RAM at the specified address.
     *
     * Uninitialized addresses should return 0 or random data depending on
     * the desired emulation accuracy. Real hardware typically contains
     * unpredictable values on power-up.
     *
     * @param int $address The memory address to read from (0x0000-0xFFFF)
     * @return int The byte value (0x00-0xFF)
     */
    public function readByte(int $address): int;

    /**
     * Writes a byte to RAM at the specified address.
     *
     * Stores the byte value at the specified address. The value should be
     * masked to 8 bits (0x00-0xFF) and the address should be masked to
     * 16 bits (0x0000-0xFFFF) by the implementation.
     *
     * @param int $address The memory address to write to (0x0000-0xFFFF)
     * @param int $value The byte value to write (0x00-0xFF)
     */
    public function writeByte(int $address, int $value): void;

    /**
     * Resets the RAM.
     *
     * Behavior on reset varies by implementation:
     * - Clear all memory to 0x00 (predictable state for testing)
     * - Fill with random values (accurate hardware simulation)
     * - Leave contents unchanged (some systems preserve RAM through reset)
     *
     * Implementations should document their reset behavior.
     */
    public function reset(): void;

    /**
     * Gets the start address of the RAM space.
     *
     * @return int The starting address of the RAM (e.g., 0x0000)
     */
    public function getStartAddress(): int;

    /**
     * Gets the end address of the RAM space.
     *
     * @return int The ending address of the RAM (e.g., 0x7FFF)
     */
    public function getEndAddress(): int;

    /**
     * Gets the size of the RAM in bytes.
     *
     * @return int The size of the RAM space
     */
    public function getSize(): int;

    /**
     * Checks if the RAM handles the specified address.
     *
     * Used by bus implementations to determine if a read/write should be
     * routed to this RAM component.
     *
     * @param int $address The memory address to check (0x0000-0xFFFF)
     * @return bool True if this RAM handles the address
     */
    public function handlesAddress(int $address): bool;
}
