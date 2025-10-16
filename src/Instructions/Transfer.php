<?php

declare(strict_types=1);

namespace andrewthecoder\MOS6502\Instructions;

use andrewthecoder\MOS6502\CPU;
use andrewthecoder\MOS6502\Opcode;
use andrewthecoder\MOS6502\StatusRegister;

class Transfer
{
    public function __construct(
        private CPU $cpu
    ) {
    }

    public function tax(Opcode $opcode): int
    {
        $value = $this->cpu->getAccumulator();
        $this->cpu->setRegisterX($value);

        $this->cpu->status->set(StatusRegister::ZERO, $value === 0);
        $this->cpu->status->set(StatusRegister::NEGATIVE, ($value & 0x80) !== 0);

        return $opcode->getCycles();
    }

    public function tay(Opcode $opcode): int
    {
        $value = $this->cpu->getAccumulator();
        $this->cpu->setRegisterY($value);

        $this->cpu->status->set(StatusRegister::ZERO, $value === 0);
        $this->cpu->status->set(StatusRegister::NEGATIVE, ($value & 0x80) !== 0);

        return $opcode->getCycles();
    }

    public function txa(Opcode $opcode): int
    {
        $value = $this->cpu->getRegisterX();
        $this->cpu->setAccumulator($value);

        $this->cpu->status->set(StatusRegister::ZERO, $value === 0);
        $this->cpu->status->set(StatusRegister::NEGATIVE, ($value & 0x80) !== 0);

        return $opcode->getCycles();
    }

    public function tya(Opcode $opcode): int
    {
        $value = $this->cpu->getRegisterY();
        $this->cpu->setAccumulator($value);

        $this->cpu->status->set(StatusRegister::ZERO, $value === 0);
        $this->cpu->status->set(StatusRegister::NEGATIVE, ($value & 0x80) !== 0);

        return $opcode->getCycles();
    }

    public function tsx(Opcode $opcode): int
    {
        $value = $this->cpu->sp;
        $this->cpu->setRegisterX($value);

        $this->cpu->status->set(StatusRegister::ZERO, $value === 0);
        $this->cpu->status->set(StatusRegister::NEGATIVE, ($value & 0x80) !== 0);

        return $opcode->getCycles();
    }

    public function txs(Opcode $opcode): int
    {
        $this->cpu->sp = $this->cpu->getRegisterX();

        return $opcode->getCycles();
    }
}
