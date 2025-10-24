<?php

declare(strict_types=1);

namespace andrewthecoder\MOS6502;

/**
 * Instruction Register
 *
 * Loads and provides access to all 6502 opcode definitions from opcodes.json.
 * Supports lookup by opcode value, mnemonic, or mnemonic with addressing mode.
 */
class InstructionRegister
{
    /** @var array<string, Opcode> */
    private array $opcodes = [];

    /**
     * Initializes the instruction register and loads opcodes from JSON
     *
     * @throws \RuntimeException If the opcodes.json file cannot be read or parsed
     */
    public function __construct()
    {
        $this->loadOpcodes();
    }

    private function loadOpcodes(): void
    {
        $jsonPath = __DIR__ . '/opcodes.json';
        $json = file_get_contents($jsonPath);
        if ($json === false) {
            throw new \RuntimeException('Failed to read opcode JSON file');
        }

        $data = json_decode($json, true);
        if ($data === null) {
            throw new \RuntimeException('Failed to decode opcode JSON file');
        }

        if (!isset($data['OPCODES'])) {
            throw new \RuntimeException('Invalid opcode JSON structure');
        }

        foreach ($data['OPCODES'] as $instruction) {
            $opcode = new Opcode(
                $instruction['opcode'],
                $instruction['mnemonic'],
                $instruction['addressing mode'],
                $instruction['bytes'],
                $instruction['cycles'],
                $instruction['additional cycles'] ?? null,
                $instruction['operation'] ?? null,
                $instruction['execution'] ?? null
            );

            $this->opcodes[$instruction['opcode']] = $opcode;
        }
    }

    /**
     * Gets an opcode by its hex value
     *
     * @param string $opcode Hex opcode string (e.g., "0xA9")
     * @return Opcode|null The opcode object or null if not found
     */
    public function getOpcode(string $opcode): ?Opcode
    {
        return $this->opcodes[$opcode] ?? null;
    }

    /**
     * Finds all opcodes matching a given mnemonic
     *
     * Returns all variants of an instruction across different addressing modes.
     *
     * @param string $mnemonic The instruction mnemonic (e.g., "LDA", "JMP")
     * @return array<string, Opcode> Array of matching opcode objects
     */
    public function findOpcodesByMnemonic(string $mnemonic): array
    {
        return array_filter($this->opcodes, fn (Opcode $opcode) => $opcode->getMnemonic() === $mnemonic);
    }

    /**
     * Finds a specific opcode by mnemonic and addressing mode
     *
     * @param string $mnemonic The instruction mnemonic (e.g., "LDA")
     * @param string $addressingMode The addressing mode (e.g., "Immediate", "Absolute")
     * @return Opcode|null The matching opcode or null if not found
     */
    public function findOpcode(string $mnemonic, string $addressingMode): ?Opcode
    {
        foreach ($this->opcodes as $opcode) {
            if ($opcode->getMnemonic() === $mnemonic && $opcode->getAddressingMode() === $addressingMode) {
                return $opcode;
            }
        }

        return null;
    }
}
