<?php

declare(strict_types=1);

namespace AndrewTheCoder\Emulator\Core;

/**
 * JSON-Driven Instruction Interpreter
 *
 * Executes 6502 instructions based on declarative metadata from opcodes.json.
 * Handles transfer, load, store, increment, logic, compare, and flag operations
 * without requiring custom handler code for each instruction.
 */
class InstructionInterpreter
{
    /**
     * Creates an interpreter linked to a CPU instance
     *
     * @param CPU $cpu The CPU this interpreter operates on
     */
    public function __construct(
        private CPU $cpu
    ) {
    }

    /**
     * Executes an instruction based on its JSON metadata
     *
     * Dispatches to the appropriate execution method based on the operation type
     * defined in the opcode's execution block, then updates CPU flags accordingly.
     *
     * @param Opcode $opcode The opcode to execute with execution metadata
     * @return int Number of cycles consumed
     * @throws \RuntimeException If opcode has no execution metadata or unknown type
     */
    public function execute(Opcode $opcode): int
    {
        $execution = $opcode->getExecution();

        if ($execution === null) {
            throw new \RuntimeException("No execution metadata for opcode: {$opcode->getOpcode()}");
        }

        $type = $execution['type'] ?? null;

        if ($type === 'flag') {
            $this->executeFlag($execution);
            $value = 0; // Not used for flag operations
        } else {
            $value = match ($type) {
                'transfer' => $this->executeTransfer($execution),
                'load' => $this->executeLoad($opcode, $execution),
                'store' => $this->executeStore($opcode, $execution),
                'increment' => $this->executeIncrement($opcode, $execution),
                'logic' => $this->executeLogic($opcode, $execution),
                'compare' => $this->executeCompare($opcode, $execution),
                default => throw new \RuntimeException("Unknown execution type: {$type}")
            };

            $this->updateFlags($value, $execution['flags'] ?? []);
        }

        return $opcode->getCycles();
    }

    /**
     * Executes a register transfer operation (e.g., TAX, TYA)
     *
     * @param array<string, mixed> $execution Execution metadata with source and destination
     * @return int The transferred value for flag setting
     * @throws \RuntimeException If source or destination is missing or invalid
     */
    private function executeTransfer(array $execution): int
    {
        $source = $execution['source'] ?? null;
        $destination = $execution['destination'] ?? null;

        if ($source === null || $destination === null) {
            throw new \RuntimeException("Transfer requires source and destination");
        }

        $value = $this->getRegister($source);
        $this->setRegister($destination, $value);

        return $value;
    }

    /**
     * Executes a load operation (e.g., LDA, LDX, LDY)
     *
     * @param Opcode $opcode The opcode with addressing mode
     * @param array<string, mixed> $execution Execution metadata with destination register
     * @return int The loaded value for flag setting
     * @throws \RuntimeException If destination register is missing or invalid
     */
    private function executeLoad(Opcode $opcode, array $execution): int
    {
        $destination = $execution['destination'] ?? null;

        if ($destination === null) {
            throw new \RuntimeException("Load requires destination register");
        }

        $address = $this->cpu->getAddress($opcode->getAddressingMode());
        $value = $this->cpu->getBus()->read($address);
        $this->setRegister($destination, $value);

        return $value;
    }

    /**
     * Executes a store operation (e.g., STA, STX, STY)
     *
     * @param Opcode $opcode The opcode with addressing mode
     * @param array<string, mixed> $execution Execution metadata with source register
     * @return int The stored value for flag setting
     * @throws \RuntimeException If source register is missing or invalid
     */
    private function executeStore(Opcode $opcode, array $execution): int
    {
        $source = $execution['source'] ?? null;

        if ($source === null) {
            throw new \RuntimeException("Store requires source register");
        }

        $value = $this->getRegister($source);
        $address = $this->cpu->getAddress($opcode->getAddressingMode());
        $this->cpu->getBus()->write($address, $value);

        return $value;
    }

    /**
     * Executes a flag set/clear operation (e.g., SEC, CLC, SEI)
     *
     * @param array<string, mixed> $execution Execution metadata with flag name and value
     * @throws \RuntimeException If flag name or value is missing or invalid
     */
    private function executeFlag(array $execution): void
    {
        $flag = $execution['flag'] ?? null;
        $value = $execution['value'] ?? null;

        if ($flag === null || $value === null) {
            throw new \RuntimeException("Flag operation requires flag name and value");
        }

        $flagConstant = match ($flag) {
            'CARRY' => StatusRegister::CARRY,
            'ZERO' => StatusRegister::ZERO,
            'INTERRUPT_DISABLE' => StatusRegister::INTERRUPT_DISABLE,
            'DECIMAL_MODE' => StatusRegister::DECIMAL_MODE,
            'OVERFLOW' => StatusRegister::OVERFLOW,
            'NEGATIVE' => StatusRegister::NEGATIVE,
            default => throw new \RuntimeException("Unknown flag: {$flag}")
        };

        $this->cpu->status->set($flagConstant, $value);
    }

    /**
     * Executes an increment/decrement operation (e.g., INC, DEC, INX)
     *
     * Supports both memory and register targets with positive or negative amounts.
     *
     * @param Opcode $opcode The opcode with addressing mode
     * @param array<string, mixed> $execution Execution metadata with target and amount
     * @return int The result value for flag setting
     * @throws \RuntimeException If target or amount is missing or invalid
     */
    private function executeIncrement(Opcode $opcode, array $execution): int
    {
        $target = $execution['target'] ?? null;
        $amount = $execution['amount'] ?? null;

        if ($target === null || $amount === null) {
            throw new \RuntimeException("Increment operation requires target and amount");
        }

        if ($target === 'memory') {
            // Memory increment/decrement
            $address = $this->cpu->getAddress($opcode->getAddressingMode());
            $value = $this->cpu->getBus()->read($address);
            $result = ($value + $amount) & 0xFF;
            $this->cpu->getBus()->write($address, $result);
            return $result;
        } else {
            // Register increment/decrement
            $value = $this->getRegister($target);
            $result = ($value + $amount) & 0xFF;
            $this->setRegister($target, $result);
            return $result;
        }
    }

    /**
     * Executes a logical operation (e.g., AND, ORA, EOR)
     *
     * @param Opcode $opcode The opcode with addressing mode
     * @param array<string, mixed> $execution Execution metadata with operation symbol
     * @return int The result value for flag setting
     * @throws \RuntimeException If operation is missing or invalid
     */
    private function executeLogic(Opcode $opcode, array $execution): int
    {
        $operation = $execution['operation'] ?? null;

        if ($operation === null) {
            throw new \RuntimeException("Logic operation requires operation symbol");
        }

        $address = $this->cpu->getAddress($opcode->getAddressingMode());
        $memoryValue = $this->cpu->getBus()->read($address);
        $accumulator = $this->cpu->getAccumulator();

        $result = match ($operation) {
            '&' => $accumulator & $memoryValue,
            '|' => $accumulator | $memoryValue,
            '^' => $accumulator ^ $memoryValue,
            default => throw new \RuntimeException("Unknown logic operation: {$operation}")
        };

        $this->cpu->setAccumulator($result);
        return $result;
    }

    /**
     * Executes a comparison operation (e.g., CMP, CPX, CPY)
     *
     * Performs subtraction without storing result, only updating flags.
     *
     * @param Opcode $opcode The opcode with addressing mode
     * @param array<string, mixed> $execution Execution metadata with register name
     * @return int The comparison result for flag setting
     * @throws \RuntimeException If register name is missing or invalid
     */
    private function executeCompare(Opcode $opcode, array $execution): int
    {
        $register = $execution['register'] ?? null;

        if ($register === null) {
            throw new \RuntimeException("Compare operation requires register name");
        }

        $address = $this->cpu->getAddress($opcode->getAddressingMode());
        $memoryValue = $this->cpu->getBus()->read($address);
        $registerValue = $this->getRegister($register);

        // Comparison is subtraction without storing the result
        $result = $registerValue - $memoryValue;

        return $result;
    }

    /**
     * Gets the value of a CPU register by name
     *
     * @param string $register Register name (accumulator, registerX, registerY, stackPointer)
     * @return int The register value
     * @throws \RuntimeException If register name is not recognized
     */
    private function getRegister(string $register): int
    {
        return match ($register) {
            'accumulator' => $this->cpu->getAccumulator(),
            'registerX' => $this->cpu->getRegisterX(),
            'registerY' => $this->cpu->getRegisterY(),
            'stackPointer' => $this->cpu->getStackPointer(),
            default => throw new \RuntimeException("Unknown register: {$register}")
        };
    }

    /**
     * Sets the value of a CPU register by name
     *
     * @param string $register Register name (accumulator, registerX, registerY, stackPointer)
     * @param int $value Value to set (will be masked to 8 bits)
     * @throws \RuntimeException If register name is not recognized
     */
    private function setRegister(string $register, int $value): void
    {
        match ($register) {
            'accumulator' => $this->cpu->setAccumulator($value),
            'registerX' => $this->cpu->setRegisterX($value),
            'registerY' => $this->cpu->setRegisterY($value),
            'stackPointer' => $this->cpu->setStackPointer($value),
            default => throw new \RuntimeException("Unknown register: {$register}")
        };
    }

    /**
     * Updates CPU status flags based on operation result
     *
     * @param int $value The result value to test
     * @param array<string> $flags List of flag names to update (ZERO, NEGATIVE, CARRY, etc.)
     * @throws \RuntimeException If a flag name is not recognized
     */
    private function updateFlags(int $value, array $flags): void
    {
        foreach ($flags as $flag) {
            match ($flag) {
                'ZERO' => $this->cpu->status->set(StatusRegister::ZERO, ($value & 0xFF) === 0),
                'NEGATIVE' => $this->cpu->status->set(StatusRegister::NEGATIVE, ($value & 0x80) !== 0),
                'CARRY' => $this->cpu->status->set(StatusRegister::CARRY, $value >= 0),
                'OVERFLOW' => null, // Handle overflow separately when needed
                default => throw new \RuntimeException("Unknown flag: {$flag}")
            };
        }
    }
}
