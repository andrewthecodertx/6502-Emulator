<?php

declare(strict_types=1);

namespace andrewthecoder\Core;

/**
 * Interface for read-only memory implementations in 6502 systems.
 *
 * Defines the contract for ROM components used across different 6502-based
 * systems. ROM implementations may include BIOS, kernel, BASIC interpreters,
 * or other built-in programs.
 *
 * ROM is typically memory-mapped to the upper address space (e.g., $8000-$FFFF)
 * and contains the reset/interrupt vectors at $FFFA-$FFFF:
 * - $FFFA-$FFFB: NMI vector (Non-Maskable Interrupt)
 * - $FFFC-$FFFD: RESET vector (Power-on/reset entry point)
 * - $FFFE-$FFFF: IRQ/BRK vector (Maskable interrupt/break)
 *
 * ROM models physical hardware and loads data from binary files. For testing
 * with programmatic data, use SimpleBus or mock implementations instead.
 *
 * Address Space Rules:
 * - Each ROM must declare its address boundaries via getStartAddress/getEndAddress
 * - Address ranges must not overlap with other ROM, RAM, or peripherals
 * - ROM addresses are read-only from the CPU's perspective
 * - Address ranges can be anywhere in the 64KB space (0x0000-0xFFFF)
 */
interface ROMInterface
{
    /**
     * Placeholder constants for ROM address boundaries.
     *
     * These are interface-level constants that must be overridden by concrete
     * implementations via their own class constants. Implementations should
     * define their actual ROM_START and ROM_END values and return them from
     * getStartAddress() and getEndAddress().
     *
     * Example implementation:
     *   class ROM implements ROMInterface {
     *       public const ROM_START = 0x8000;
     *       public const ROM_END = 0xFFFF;
     *
     *       public function getStartAddress(): int {
     *           return self::ROM_START;
     *       }
     *   }
     */
    public const ROM_START = 0;
    public const ROM_END = 0;

    /**
     * Reads a byte from ROM at the specified address.
     *
     * Uninitialized addresses should return 0 or a sensible default value.
     * Read operations should not modify state.
     *
     * @param int $address The memory address to read from
     * @return int The byte value (0-255)
     */
    public function readByte(int $address): int;

    /**
     * Loads ROM data from a binary file.
     *
     * Reads a raw binary file and loads it sequentially into ROM starting at
     * the specified load address. Files larger than available ROM space will
     * be truncated.
     *
     * @param string $path Path to the binary file
     * @param int|null $loadAddress Starting address for loading (null = use default)
     * @throws \RuntimeException If file not found or cannot be read
     */
    public function loadFromFile(string $path, ?int $loadAddress = null): void;

    /**
     * Resets the ROM.
     *
     * ROM contents typically persist through reset, but this method allows
     * implementations to perform any necessary reset logic or state cleanup.
     */
    public function reset(): void;

    /**
     * Gets the start address of the ROM space.
     *
     * @return int The starting address of the ROM (e.g., 0x8000)
     */
    public function getStartAddress(): int;

    /**
     * Gets the end address of the ROM space.
     *
     * @return int The ending address of the ROM (e.g., 0xFFFF)
     */
    public function getEndAddress(): int;

    /**
     * Gets the size of the ROM in bytes.
     *
     * @return int The size of the ROM space
     */
    public function getSize(): int;

    /**
     * Checks if the ROM handles the specified address.
     *
     * Used by bus implementations to determine if a read should be
     * routed to this ROM component.
     *
     * @param int $address The memory address to check
     * @return bool True if this ROM handles the address
     */
    public function handlesAddress(int $address): bool;
}
