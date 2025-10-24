<?php

declare(strict_types=1);

namespace andrewthecoder\WDC65C02;

/**
 * Opcode Metadata
 *
 * Represents a single 6502 opcode with all its metadata including mnemonic,
 * addressing mode, cycle count, and optional execution metadata for JSON-driven
 * instruction processing.
 */
class Opcode
{
    /**
     * Creates an opcode with complete metadata
     *
     * @param string $opcode Hex opcode value (e.g., "0xA9")
     * @param string $mnemonic Three-letter instruction name (e.g., "LDA")
     * @param string $addressingMode Addressing mode (e.g., "Immediate", "Absolute")
     * @param int $bytes Number of bytes the instruction occupies (1-3)
     * @param int $cycles Base number of clock cycles required
     * @param string|null $additionalCycles Description of conditional extra cycles
     * @param string|null $operation Human-readable operation description
     * @param array<string, mixed>|null $execution JSON execution metadata for interpreter
     */
    public function __construct(
        private readonly string $opcode,
        private readonly string $mnemonic,
        private readonly string $addressingMode,
        private readonly int $bytes,
        private readonly int $cycles,
        private readonly ?string $additionalCycles = null,
        private readonly ?string $operation = null,
        /** @var array<string, mixed>|null */
        private readonly ?array $execution = null
    ) {
    }

    /**
     * Gets the opcode hex value
     *
     * @return string Hex string (e.g., "0xA9")
     */
    public function getOpcode(): string
    {
        return $this->opcode;
    }

    /**
     * Gets the instruction mnemonic
     *
     * @return string Three-letter mnemonic (e.g., "LDA", "STA")
     */
    public function getMnemonic(): string
    {
        return $this->mnemonic;
    }

    /**
     * Gets the addressing mode
     *
     * @return string Mode name (e.g., "Immediate", "Zero Page")
     */
    public function getAddressingMode(): string
    {
        return $this->addressingMode;
    }

    /**
     * Gets the instruction byte length
     *
     * @return int Number of bytes (1-3)
     */
    public function getBytes(): int
    {
        return $this->bytes;
    }

    /**
     * Gets the base cycle count
     *
     * @return int Number of clock cycles required
     */
    public function getCycles(): int
    {
        return $this->cycles;
    }

    /**
     * Gets the description of conditional additional cycles
     *
     * @return string|null Description of when extra cycles are added, or null if none
     */
    public function getAdditionalCycles(): ?string
    {
        return $this->additionalCycles;
    }

    /**
     * Gets the human-readable operation description
     *
     * @return string|null Operation description or null if not provided
     */
    public function getOperation(): ?string
    {
        return $this->operation;
    }

    /**
     * Gets the JSON execution metadata
     *
     * Returns the execution block used by InstructionInterpreter for
     * declarative instruction processing.
     *
     * @return array<string, mixed>|null Execution metadata or null if handler-based
     */
    public function getExecution(): ?array
    {
        return $this->execution;
    }

    /**
     * Checks if this opcode has execution metadata
     *
     * @return bool True if JSON-driven, false if requires custom handler
     */
    public function hasExecution(): bool
    {
        return $this->execution !== null;
    }
}
