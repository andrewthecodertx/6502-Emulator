<?php

declare(strict_types=1);

namespace Emulator\Core\Instructions;

use Emulator\Core\CPU;
use Emulator\Core\Opcode;
use Emulator\Core\StatusRegister;

/**
 * Illegal/undocumented 6502 opcodes
 *
 * These opcodes were not officially documented by MOS Technology but were
 * discovered by the community. They combine multiple operations in a single
 * instruction. Some are unstable and behavior may vary between chips.
 */
class IllegalOpcodes
{
    public function __construct(
        private CPU $cpu
    ) {
    }

    /**
     * ALR (ASR) - AND with accumulator then logical shift right
     * Immediate addressing only
     */
    public function alr(Opcode $opcode): int
    {
        $address = $this->cpu->getAddress($opcode->getAddressingMode());
        $value = $this->cpu->getBus()->read($address);

        // AND with accumulator
        $result = $this->cpu->getAccumulator() & $value;

        // Logical shift right
        $this->cpu->status->set(StatusRegister::CARRY, ($result & 0x01) !== 0);
        $result = ($result >> 1) & 0x7F;

        $this->cpu->setAccumulator($result);
        $this->cpu->status->set(StatusRegister::ZERO, $result === 0);
        $this->cpu->status->set(StatusRegister::NEGATIVE, ($result & 0x80) !== 0);

        return $opcode->getCycles();
    }

    /**
     * ANE (XAA) - Highly unstable, AND X with accumulator then AND with immediate
     * Warning: Behavior varies between chips
     */
    public function ane(Opcode $opcode): int
    {
        $address = $this->cpu->getAddress($opcode->getAddressingMode());
        $value = $this->cpu->getBus()->read($address);

        // (A OR CONST) AND X AND operand
        // CONST is typically 0xEE or 0x00, we'll use 0xEE for stability
        $result = ($this->cpu->getAccumulator() | 0xEE) & $this->cpu->getRegisterX() & $value;

        $this->cpu->setAccumulator($result);
        $this->cpu->status->set(StatusRegister::ZERO, $result === 0);
        $this->cpu->status->set(StatusRegister::NEGATIVE, ($result & 0x80) !== 0);

        return $opcode->getCycles();
    }

    /**
     * ARR - AND with accumulator then rotate right
     * Complex interaction with decimal mode
     */
    public function arr(Opcode $opcode): int
    {
        $address = $this->cpu->getAddress($opcode->getAddressingMode());
        $value = $this->cpu->getBus()->read($address);

        // AND with accumulator
        $result = $this->cpu->getAccumulator() & $value;

        // Rotate right (with carry)
        $oldCarry = $this->cpu->status->get(StatusRegister::CARRY) ? 1 : 0;
        $newCarry = ($result & 0x01) !== 0;
        $result = (($result >> 1) | ($oldCarry << 7)) & 0xFF;

        $this->cpu->setAccumulator($result);
        $this->cpu->status->set(StatusRegister::CARRY, $newCarry);
        $this->cpu->status->set(StatusRegister::ZERO, $result === 0);
        $this->cpu->status->set(StatusRegister::NEGATIVE, ($result & 0x80) !== 0);

        // Overflow flag: bit 6 XOR bit 5
        $bit6 = ($result & 0x40) !== 0;
        $bit5 = ($result & 0x20) !== 0;
        $this->cpu->status->set(StatusRegister::OVERFLOW, $bit6 !== $bit5);

        return $opcode->getCycles();
    }

    /**
     * DCP (DCM) - Decrement memory then compare with accumulator
     */
    public function dcp(Opcode $opcode): int
    {
        $address = $this->cpu->getAddress($opcode->getAddressingMode());
        $value = $this->cpu->getBus()->read($address);

        // Decrement memory
        $value = ($value - 1) & 0xFF;
        $this->cpu->getBus()->write($address, $value);

        // Compare with accumulator
        $result = ($this->cpu->getAccumulator() - $value) & 0x1FF;
        $this->cpu->status->set(StatusRegister::CARRY, $result < 0x100);
        $this->cpu->status->set(StatusRegister::ZERO, ($result & 0xFF) === 0);
        $this->cpu->status->set(StatusRegister::NEGATIVE, ($result & 0x80) !== 0);

        return $opcode->getCycles();
    }

    /**
     * LAS (LAR) - AND memory with stack pointer, transfer to A, X, and SP
     */
    public function las(Opcode $opcode): int
    {
        $address = $this->cpu->getAddress($opcode->getAddressingMode());
        $value = $this->cpu->getBus()->read($address);

        // AND memory with stack pointer
        $result = $value & $this->cpu->getStackPointer();

        // Transfer to A, X, and SP
        $this->cpu->setAccumulator($result);
        $this->cpu->setRegisterX($result);
        $this->cpu->setStackPointer($result);

        $this->cpu->status->set(StatusRegister::ZERO, $result === 0);
        $this->cpu->status->set(StatusRegister::NEGATIVE, ($result & 0x80) !== 0);

        return $opcode->getCycles();
    }

    /**
     * LAX - Load accumulator and X register with memory
     */
    public function lax(Opcode $opcode): int
    {
        $address = $this->cpu->getAddress($opcode->getAddressingMode());
        $value = $this->cpu->getBus()->read($address);

        $this->cpu->setAccumulator($value);
        $this->cpu->setRegisterX($value);

        $this->cpu->status->set(StatusRegister::ZERO, $value === 0);
        $this->cpu->status->set(StatusRegister::NEGATIVE, ($value & 0x80) !== 0);

        return $opcode->getCycles();
    }

    /**
     * LXA (LAX immediate) - Highly unstable, AND with accumulator then transfer to X
     * Warning: Behavior varies between chips
     */
    public function lxa(Opcode $opcode): int
    {
        $address = $this->cpu->getAddress($opcode->getAddressingMode());
        $value = $this->cpu->getBus()->read($address);

        // (A OR CONST) AND operand
        // CONST is typically 0xEE or 0x00, we'll use 0xEE for stability
        $result = ($this->cpu->getAccumulator() | 0xEE) & $value;

        $this->cpu->setAccumulator($result);
        $this->cpu->setRegisterX($result);

        $this->cpu->status->set(StatusRegister::ZERO, $result === 0);
        $this->cpu->status->set(StatusRegister::NEGATIVE, ($result & 0x80) !== 0);

        return $opcode->getCycles();
    }

    /**
     * RRA - Rotate right then add with carry
     */
    public function rra(Opcode $opcode): int
    {
        $address = $this->cpu->getAddress($opcode->getAddressingMode());
        $value = $this->cpu->getBus()->read($address);

        // Rotate right
        $oldCarry = $this->cpu->status->get(StatusRegister::CARRY) ? 1 : 0;
        $newCarry = ($value & 0x01) !== 0;
        $value = (($value >> 1) | ($oldCarry << 7)) & 0xFF;
        $this->cpu->getBus()->write($address, $value);

        // Add with carry (reuse ADC logic)
        $accumulator = $this->cpu->getAccumulator();
        $carry = $newCarry ? 1 : 0;

        if ($this->cpu->status->get(StatusRegister::DECIMAL_MODE)) {
            // BCD mode
            $lowNibble = ($accumulator & 0x0F) + ($value & 0x0F) + $carry;
            if ($lowNibble > 0x09) {
                $lowNibble += 0x06;
            }

            $highNibble = ($accumulator >> 4) + ($value >> 4) + ($lowNibble > 0x0F ? 1 : 0);
            if ($highNibble > 0x09) {
                $highNibble += 0x06;
            }

            $result = (($highNibble << 4) | ($lowNibble & 0x0F)) & 0xFF;
            $this->cpu->status->set(StatusRegister::CARRY, $highNibble > 0x0F);
        } else {
            // Binary mode
            $result = $accumulator + $value + $carry;
            $this->cpu->status->set(StatusRegister::CARRY, $result > 0xFF);

            // Overflow: (A^result) & (M^result) & 0x80
            $overflow = ((($accumulator ^ $result) & ($value ^ $result)) & 0x80) !== 0;
            $this->cpu->status->set(StatusRegister::OVERFLOW, $overflow);

            $result &= 0xFF;
        }

        $this->cpu->setAccumulator($result);
        $this->cpu->status->set(StatusRegister::ZERO, $result === 0);
        $this->cpu->status->set(StatusRegister::NEGATIVE, ($result & 0x80) !== 0);

        return $opcode->getCycles();
    }

    /**
     * SBX (AXS, SAX) - AND X register with accumulator and subtract immediate
     */
    public function sbx(Opcode $opcode): int
    {
        $address = $this->cpu->getAddress($opcode->getAddressingMode());
        $value = $this->cpu->getBus()->read($address);

        // (A AND X) - operand
        $temp = $this->cpu->getAccumulator() & $this->cpu->getRegisterX();
        $result = ($temp - $value) & 0x1FF;

        $this->cpu->setRegisterX($result & 0xFF);
        $this->cpu->status->set(StatusRegister::CARRY, $result < 0x100);
        $this->cpu->status->set(StatusRegister::ZERO, ($result & 0xFF) === 0);
        $this->cpu->status->set(StatusRegister::NEGATIVE, ($result & 0x80) !== 0);

        return $opcode->getCycles();
    }

    /**
     * SHA (AHX, AXA) - Store A AND X AND (high-byte of address + 1)
     * Warning: Unstable, behavior varies
     */
    public function sha(Opcode $opcode): int
    {
        $address = $this->cpu->getAddress($opcode->getAddressingMode());

        // A AND X AND (high byte + 1)
        $highByte = ($address >> 8) & 0xFF;
        $result = $this->cpu->getAccumulator() & $this->cpu->getRegisterX() & (($highByte + 1) & 0xFF);

        $this->cpu->getBus()->write($address, $result);

        return $opcode->getCycles();
    }

    /**
     * SHS (TAS, XAS) - Transfer A AND X to SP then store A AND X AND (high-byte + 1)
     * Warning: Unstable, behavior varies
     */
    public function shs(Opcode $opcode): int
    {
        $address = $this->cpu->getAddress($opcode->getAddressingMode());

        // A AND X -> SP
        $temp = $this->cpu->getAccumulator() & $this->cpu->getRegisterX();
        $this->cpu->setStackPointer($temp);

        // Store A AND X AND (high byte + 1)
        $highByte = ($address >> 8) & 0xFF;
        $result = $temp & (($highByte + 1) & 0xFF);

        $this->cpu->getBus()->write($address, $result);

        return $opcode->getCycles();
    }

    /**
     * SHX (SXA, XAS) - Store X AND (high-byte of address + 1)
     * Warning: Unstable, behavior varies
     */
    public function shx(Opcode $opcode): int
    {
        $address = $this->cpu->getAddress($opcode->getAddressingMode());

        // X AND (high byte + 1)
        $highByte = ($address >> 8) & 0xFF;
        $result = $this->cpu->getRegisterX() & (($highByte + 1) & 0xFF);

        $this->cpu->getBus()->write($address, $result);

        return $opcode->getCycles();
    }

    /**
     * SHY (SYA, SAY) - Store Y AND (high-byte of address + 1)
     * Warning: Unstable, behavior varies
     */
    public function shy(Opcode $opcode): int
    {
        $address = $this->cpu->getAddress($opcode->getAddressingMode());

        // Y AND (high byte + 1)
        $highByte = ($address >> 8) & 0xFF;
        $result = $this->cpu->getRegisterY() & (($highByte + 1) & 0xFF);

        $this->cpu->getBus()->write($address, $result);

        return $opcode->getCycles();
    }

    /**
     * SRE (LSE) - Logical shift right then XOR with accumulator
     */
    public function sre(Opcode $opcode): int
    {
        $address = $this->cpu->getAddress($opcode->getAddressingMode());
        $value = $this->cpu->getBus()->read($address);

        // Logical shift right
        $this->cpu->status->set(StatusRegister::CARRY, ($value & 0x01) !== 0);
        $value = ($value >> 1) & 0x7F;
        $this->cpu->getBus()->write($address, $value);

        // XOR with accumulator
        $result = $this->cpu->getAccumulator() ^ $value;
        $this->cpu->setAccumulator($result);

        $this->cpu->status->set(StatusRegister::ZERO, $result === 0);
        $this->cpu->status->set(StatusRegister::NEGATIVE, ($result & 0x80) !== 0);

        return $opcode->getCycles();
    }
}
