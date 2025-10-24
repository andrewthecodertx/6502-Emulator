<?php

declare(strict_types=1);

namespace andrewthecoder\MOS6502\Instructions;

use andrewthecoder\MOS6502\CPU;
use andrewthecoder\Core\Opcode;
use andrewthecoder\Core\StatusRegister;

class ShiftRotate
{
    public function __construct(
        private CPU $cpu
    ) {
    }

    public function asl(Opcode $opcode): int
    {
        $addressingMode = $opcode->getAddressingMode();

        if ($addressingMode === 'Accumulator') {
            $value = $this->cpu->getAccumulator();
            $result = $value << 1;

            $this->cpu->status->set(StatusRegister::CARRY, ($value & 0x80) !== 0);
            $this->cpu->setAccumulator($result & 0xFF);
        } else {
            $address = $this->cpu->getAddress($addressingMode);
            $value = $this->cpu->getBus()->read($address);
            $result = $value << 1;

            $this->cpu->status->set(StatusRegister::CARRY, ($value & 0x80) !== 0);
            $this->cpu->getBus()->write($address, $result & 0xFF);
        }

        $this->cpu->status->set(StatusRegister::ZERO, ($result & 0xFF) === 0);
        $this->cpu->status->set(StatusRegister::NEGATIVE, ($result & 0x80) !== 0);

        return $opcode->getCycles();
    }

    public function lsr(Opcode $opcode): int
    {
        $addressingMode = $opcode->getAddressingMode();

        if ($addressingMode === 'Accumulator') {
            $value = $this->cpu->getAccumulator();
            $result = $value >> 1;

            $this->cpu->status->set(StatusRegister::CARRY, ($value & 0x01) !== 0);
            $this->cpu->setAccumulator($result);
        } else {
            $address = $this->cpu->getAddress($addressingMode);
            $value = $this->cpu->getBus()->read($address);
            $result = $value >> 1;

            $this->cpu->status->set(StatusRegister::CARRY, ($value & 0x01) !== 0);
            $this->cpu->getBus()->write($address, $result);
        }

        $this->cpu->status->set(StatusRegister::ZERO, $result === 0);
        $this->cpu->status->set(StatusRegister::NEGATIVE, false);

        return $opcode->getCycles();
    }

    public function rol(Opcode $opcode): int
    {
        $addressingMode = $opcode->getAddressingMode();
        $carry = $this->cpu->status->get(StatusRegister::CARRY) ? 1 : 0;

        if ($addressingMode === 'Accumulator') {
            $value = $this->cpu->getAccumulator();
            $result = ($value << 1) | $carry;

            $this->cpu->status->set(StatusRegister::CARRY, ($value & 0x80) !== 0);
            $this->cpu->setAccumulator($result & 0xFF);
        } else {
            $address = $this->cpu->getAddress($addressingMode);
            $value = $this->cpu->getBus()->read($address);
            $result = ($value << 1) | $carry;

            $this->cpu->status->set(StatusRegister::CARRY, ($value & 0x80) !== 0);
            $this->cpu->getBus()->write($address, $result & 0xFF);
        }

        $this->cpu->status->set(StatusRegister::ZERO, ($result & 0xFF) === 0);
        $this->cpu->status->set(StatusRegister::NEGATIVE, ($result & 0x80) !== 0);

        return $opcode->getCycles();
    }

    public function ror(Opcode $opcode): int
    {
        $addressingMode = $opcode->getAddressingMode();
        $carry = $this->cpu->status->get(StatusRegister::CARRY) ? 0x80 : 0;

        if ($addressingMode === 'Accumulator') {
            $value = $this->cpu->getAccumulator();
            $result = ($value >> 1) | $carry;

            $this->cpu->status->set(StatusRegister::CARRY, ($value & 0x01) !== 0);
            $this->cpu->setAccumulator($result);
        } else {
            $address = $this->cpu->getAddress($addressingMode);
            $value = $this->cpu->getBus()->read($address);
            $result = ($value >> 1) | $carry;

            $this->cpu->status->set(StatusRegister::CARRY, ($value & 0x01) !== 0);
            $this->cpu->getBus()->write($address, $result);
        }

        $this->cpu->status->set(StatusRegister::ZERO, $result === 0);
        $this->cpu->status->set(StatusRegister::NEGATIVE, ($result & 0x80) !== 0);

        return $opcode->getCycles();
    }

    // RLA: Rotate Left and AND (undocumented)
    public function rla(Opcode $opcode): int
    {
        $addressingMode = $opcode->getAddressingMode();
        $address = $this->cpu->getAddress($addressingMode);
        $value = $this->cpu->getBus()->read($address);
        $carry = $this->cpu->status->get(StatusRegister::CARRY) ? 1 : 0;
        $rotatedValue = (($value << 1) | $carry) & 0xFF;

        $this->cpu->status->set(StatusRegister::CARRY, ($value & 0x80) !== 0);
        $this->cpu->getBus()->write($address, $rotatedValue);

        $accumulator = $this->cpu->getAccumulator();
        $result = $accumulator & $rotatedValue;

        $this->cpu->setAccumulator($result);
        $this->cpu->status->set(StatusRegister::ZERO, $result === 0);
        $this->cpu->status->set(StatusRegister::NEGATIVE, ($result & 0x80) !== 0);

        return $opcode->getCycles();
    }

    // SLO: Shift Left and OR (undocumented, also known as ASO)
    public function slo(Opcode $opcode): int
    {
        $addressingMode = $opcode->getAddressingMode();
        $address = $this->cpu->getAddress($addressingMode);
        $value = $this->cpu->getBus()->read($address);

        // First: ASL - Arithmetic Shift Left
        $shiftedValue = ($value << 1) & 0xFF;
        $this->cpu->status->set(StatusRegister::CARRY, ($value & 0x80) !== 0);
        $this->cpu->getBus()->write($address, $shiftedValue);

        // Second: ORA - OR with accumulator
        $accumulator = $this->cpu->getAccumulator();
        $result = $accumulator | $shiftedValue;

        $this->cpu->setAccumulator($result);
        $this->cpu->status->set(StatusRegister::ZERO, $result === 0);
        $this->cpu->status->set(StatusRegister::NEGATIVE, ($result & 0x80) !== 0);

        return $opcode->getCycles();
    }
}
