<?php

declare(strict_types=1);

namespace andrewthecoder\MOS6502\Instructions;

use andrewthecoder\MOS6502\CPU;
use andrewthecoder\MOS6502\Opcode;
use andrewthecoder\MOS6502\StatusRegister;

class Flags
{
    public function __construct(
        private CPU $cpu
    ) {
    }

    public function sec(Opcode $opcode): int
    {
        $this->cpu->status->set(StatusRegister::CARRY, true);

        return $opcode->getCycles();
    }

    public function clc(Opcode $opcode): int
    {
        $this->cpu->status->set(StatusRegister::CARRY, false);

        return $opcode->getCycles();
    }

    public function sei(Opcode $opcode): int
    {
        $this->cpu->status->set(StatusRegister::INTERRUPT_DISABLE, true);

        return $opcode->getCycles();
    }

    public function cli(Opcode $opcode): int
    {
        $this->cpu->status->set(StatusRegister::INTERRUPT_DISABLE, false);

        return $opcode->getCycles();
    }

    public function sed(Opcode $opcode): int
    {
        $this->cpu->status->set(StatusRegister::DECIMAL_MODE, true);

        return $opcode->getCycles();
    }

    public function cld(Opcode $opcode): int
    {
        $this->cpu->status->set(StatusRegister::DECIMAL_MODE, false);

        return $opcode->getCycles();
    }

    public function clv(Opcode $opcode): int
    {
        $this->cpu->status->set(StatusRegister::OVERFLOW, false);

        return $opcode->getCycles();
    }
}
