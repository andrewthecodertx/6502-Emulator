<?php

declare(strict_types=1);

namespace Emulator;

class InstructionRegister
{
    /** @var array<Opcode> */ private array $opcodes = [];

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

    public function getOpcode(string $opcode): ?Opcode
    {
        return $this->opcodes[$opcode] ?? null;
    }

    /** @return array<Opcode> */
    public function findOpcodesByMnemonic(string $mnemonic): array
    {
        return array_filter($this->opcodes, fn (Opcode $opcode) => $opcode->getMnemonic() === $mnemonic);
    }

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
