<?php

declare(strict_types=1);

namespace Andrewthecoder\MOS6502\Instructions;

use Andrewthecoder\MOS6502\CPU;
use Andrewthecoder\MOS6502\Opcode;
use Andrewthecoder\MOS6502\StatusRegister;

class IncDec
{
    public function __construct(
        private CPU $cpu
    ) {
    }

    public function inc(Opcode $opcode): int
    {
        $address = $this->cpu->getAddress($opcode->getAddressingMode());
        $value = $this->cpu->getBus()->read($address);
        $result = ($value + 1) & 0xFF;

        $this->cpu->getBus()->write($address, $result);

        $this->cpu->status->set(StatusRegister::ZERO, $result === 0);
        $this->cpu->status->set(StatusRegister::NEGATIVE, ($result & 0x80) !== 0);

        return $opcode->getCycles();
    }

    public function dec(Opcode $opcode): int
    {
        $address = $this->cpu->getAddress($opcode->getAddressingMode());
        $value = $this->cpu->getBus()->read($address);
        $result = ($value - 1) & 0xFF;

        $this->cpu->getBus()->write($address, $result);

        $this->cpu->status->set(StatusRegister::ZERO, $result === 0);
        $this->cpu->status->set(StatusRegister::NEGATIVE, ($result & 0x80) !== 0);

        return $opcode->getCycles();
    }

    public function inx(Opcode $opcode): int
    {
        $result = ($this->cpu->getRegisterX() + 1) & 0xFF;
        $this->cpu->setRegisterX($result);

        $this->cpu->status->set(StatusRegister::ZERO, $result === 0);
        $this->cpu->status->set(StatusRegister::NEGATIVE, ($result & 0x80) !== 0);

        return $opcode->getCycles();
    }

    public function dex(Opcode $opcode): int
    {
        $result = ($this->cpu->getRegisterX() - 1) & 0xFF;
        $this->cpu->setRegisterX($result);

        $this->cpu->status->set(StatusRegister::ZERO, $result === 0);
        $this->cpu->status->set(StatusRegister::NEGATIVE, ($result & 0x80) !== 0);

        return $opcode->getCycles();
    }

    public function iny(Opcode $opcode): int
    {
        $result = ($this->cpu->getRegisterY() + 1) & 0xFF;
        $this->cpu->setRegisterY($result);

        $this->cpu->status->set(StatusRegister::ZERO, $result === 0);
        $this->cpu->status->set(StatusRegister::NEGATIVE, ($result & 0x80) !== 0);

        return $opcode->getCycles();
    }

    public function dey(Opcode $opcode): int
    {
        $result = ($this->cpu->getRegisterY() - 1) & 0xFF;
        $this->cpu->setRegisterY($result);

        $this->cpu->status->set(StatusRegister::ZERO, $result === 0);
        $this->cpu->status->set(StatusRegister::NEGATIVE, ($result & 0x80) !== 0);

        return $opcode->getCycles();
    }

    // ISC: Increment memory then Subtract with Carry (undocumented)
    public function isc(Opcode $opcode): int
    {
        // First, increment the memory value
        $address = $this->cpu->getAddress($opcode->getAddressingMode());
        $value = $this->cpu->getBus()->read($address);
        $incrementedValue = ($value + 1) & 0xFF;
        $this->cpu->getBus()->write($address, $incrementedValue);

        // Then, perform SBC with the incremented value
        $accumulator = $this->cpu->getAccumulator();
        $carry = $this->cpu->status->get(StatusRegister::CARRY) ? 1 : 0;
        $result = $accumulator - $incrementedValue - (1 - $carry);

        $this->cpu->status->set(StatusRegister::CARRY, $result >= 0);
        $this->cpu->status->set(StatusRegister::ZERO, ($result & 0xFF) === 0);
        $this->cpu->status->set(StatusRegister::NEGATIVE, ($result & 0x80) !== 0);

        $overflow = ((($accumulator ^ $incrementedValue) & ($accumulator ^ $result)) & 0x80) !== 0;
        $this->cpu->status->set(StatusRegister::OVERFLOW, $overflow);

        $this->cpu->setAccumulator($result & 0xFF);

        return $opcode->getCycles();
    }
}
