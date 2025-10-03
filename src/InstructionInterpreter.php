<?php

declare(strict_types=1);

namespace Emulator;

class InstructionInterpreter
{
    public function __construct(private CPU $cpu)
    {
    }

    public function execute(Opcode $opcode): int
    {
        $execution = $opcode->getExecution();

        if ($execution === null) {
            throw new \RuntimeException("No execution metadata for opcode: {$opcode->getOpcode()}");
        }

        $type = $execution['type'] ?? null;

        if ($type === 'flag') {
            // Flag operations don't return a value or update additional flags
            $this->executeFlag($execution);
            $value = 0; // Not used for flag operations
        } else {
            $value = match($type) {
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
     * @param array<string, mixed> $execution
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
     * @param array<string, mixed> $execution
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
     * @param array<string, mixed> $execution
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
     * @param array<string, mixed> $execution
     */
    private function executeFlag(array $execution): void
    {
        $flag = $execution['flag'] ?? null;
        $value = $execution['value'] ?? null;

        if ($flag === null || $value === null) {
            throw new \RuntimeException("Flag operation requires flag name and value");
        }

        $flagConstant = match($flag) {
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
     * @param array<string, mixed> $execution
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
     * @param array<string, mixed> $execution
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

        $result = match($operation) {
            '&' => $accumulator & $memoryValue,
            '|' => $accumulator | $memoryValue,
            '^' => $accumulator ^ $memoryValue,
            default => throw new \RuntimeException("Unknown logic operation: {$operation}")
        };

        $this->cpu->setAccumulator($result);
        return $result;
    }

    /**
     * @param array<string, mixed> $execution
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

    private function getRegister(string $register): int
    {
        return match($register) {
            'accumulator' => $this->cpu->getAccumulator(),
            'registerX' => $this->cpu->getRegisterX(),
            'registerY' => $this->cpu->getRegisterY(),
            'stackPointer' => $this->cpu->getStackPointer(),
            default => throw new \RuntimeException("Unknown register: {$register}")
        };
    }

    private function setRegister(string $register, int $value): void
    {
        match($register) {
            'accumulator' => $this->cpu->setAccumulator($value),
            'registerX' => $this->cpu->setRegisterX($value),
            'registerY' => $this->cpu->setRegisterY($value),
            'stackPointer' => $this->cpu->setStackPointer($value),
            default => throw new \RuntimeException("Unknown register: {$register}")
        };
    }

    /**
     * @param array<string> $flags
     */
    private function updateFlags(int $value, array $flags): void
    {
        foreach ($flags as $flag) {
            match($flag) {
                'ZERO' => $this->cpu->status->set(StatusRegister::ZERO, ($value & 0xFF) === 0),
                'NEGATIVE' => $this->cpu->status->set(StatusRegister::NEGATIVE, ($value & 0x80) !== 0),
                'CARRY' => $this->cpu->status->set(StatusRegister::CARRY, $value >= 0),
                'OVERFLOW' => null, // Handle overflow separately when needed
                default => throw new \RuntimeException("Unknown flag: {$flag}")
            };
        }
    }
}
