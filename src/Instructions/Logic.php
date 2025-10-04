<?php

declare(strict_types=1);

namespace Emulator\Instructions;

use Emulator\CPU;
use Emulator\Opcode;
use Emulator\StatusRegister;

class Logic
{
    public function __construct(
        private CPU $cpu
    ) {
    }

    public function and(Opcode $opcode): int
    {
        $address = $this->cpu->getAddress($opcode->getAddressingMode());
        $value = $this->cpu->getBus()->read($address);
        $result = $this->cpu->getAccumulator() & $value;

        $this->cpu->setAccumulator($result);

        $this->cpu->status->set(StatusRegister::ZERO, $result === 0);
        $this->cpu->status->set(StatusRegister::NEGATIVE, ($result & 0x80) !== 0);

        return $opcode->getCycles();
    }

    public function ora(Opcode $opcode): int
    {
        $address = $this->cpu->getAddress($opcode->getAddressingMode());
        $value = $this->cpu->getBus()->read($address);
        $result = $this->cpu->getAccumulator() | $value;

        $this->cpu->setAccumulator($result);

        $this->cpu->status->set(StatusRegister::ZERO, $result === 0);
        $this->cpu->status->set(StatusRegister::NEGATIVE, ($result & 0x80) !== 0);

        return $opcode->getCycles();
    }

    public function eor(Opcode $opcode): int
    {
        $address = $this->cpu->getAddress($opcode->getAddressingMode());
        $value = $this->cpu->getBus()->read($address);
        $result = $this->cpu->getAccumulator() ^ $value;

        $this->cpu->setAccumulator($result);

        $this->cpu->status->set(StatusRegister::ZERO, $result === 0);
        $this->cpu->status->set(StatusRegister::NEGATIVE, ($result & 0x80) !== 0);

        return $opcode->getCycles();
    }

    public function bit(Opcode $opcode): int
    {
        $address = $this->cpu->getAddress($opcode->getAddressingMode());
        $value = $this->cpu->getBus()->read($address);
        $result = $this->cpu->getAccumulator() & $value;

        $this->cpu->status->set(StatusRegister::ZERO, $result === 0);
        $this->cpu->status->set(StatusRegister::NEGATIVE, ($value & 0x80) !== 0);
        $this->cpu->status->set(StatusRegister::OVERFLOW, ($value & 0x40) !== 0);

        return $opcode->getCycles();
    }

    // ANC: AND with Carry (undocumented)
    public function anc(Opcode $opcode): int
    {
        $address = $this->cpu->getAddress($opcode->getAddressingMode());
        $value = $this->cpu->getBus()->read($address);
        $result = $this->cpu->getAccumulator() & $value;

        $this->cpu->setAccumulator($result);
        $this->cpu->status->set(StatusRegister::ZERO, $result === 0);
        $this->cpu->status->set(StatusRegister::NEGATIVE, ($result & 0x80) !== 0);
        $this->cpu->status->set(StatusRegister::CARRY, ($result & 0x80) !== 0);

        return $opcode->getCycles();
    }
}
