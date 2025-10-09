<?php

declare(strict_types=1);

namespace Emulator\Core\Instructions;

use Emulator\Core\CPU;
use Emulator\Core\Opcode;
use Emulator\Core\StatusRegister;

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
}
