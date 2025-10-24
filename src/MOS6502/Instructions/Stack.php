<?php

declare(strict_types=1);

namespace andrewthecoder\MOS6502\Instructions;

use andrewthecoder\MOS6502\CPU;
use andrewthecoder\Core\Opcode;
use andrewthecoder\Core\StatusRegister;

class Stack
{
    public function __construct(
        private CPU $cpu
    ) {
    }

    public function pha(Opcode $opcode): int
    {
        $this->cpu->pushByte($this->cpu->getAccumulator());

        return $opcode->getCycles();
    }

    public function pla(Opcode $opcode): int
    {
        $value = $this->cpu->pullByte();
        $this->cpu->setAccumulator($value);

        $this->cpu->status->set(StatusRegister::ZERO, $value === 0);
        $this->cpu->status->set(StatusRegister::NEGATIVE, ($value & 0x80) !== 0);

        return $opcode->getCycles();
    }

    public function php(Opcode $opcode): int
    {
        $status = $this->cpu->status->toInt() | (1 << StatusRegister::BREAK_COMMAND);
        $this->cpu->pushByte($status);

        return $opcode->getCycles();
    }

    public function plp(Opcode $opcode): int
    {
        $status = $this->cpu->pullByte();
        $status &= ~(1 << StatusRegister::BREAK_COMMAND);
        $status |= (1 << StatusRegister::UNUSED);

        $this->cpu->status->fromInt($status);

        return $opcode->getCycles();
    }

    /**
     * PHX - Push X Register to Stack (65C02)
     */
    public function phx(Opcode $opcode): int
    {
        $this->cpu->pushByte($this->cpu->getRegisterX());

        return $opcode->getCycles();
    }

    /**
     * PHY - Push Y Register to Stack (65C02)
     */
    public function phy(Opcode $opcode): int
    {
        $this->cpu->pushByte($this->cpu->getRegisterY());

        return $opcode->getCycles();
    }

    /**
     * PLX - Pull X Register from Stack (65C02)
     */
    public function plx(Opcode $opcode): int
    {
        $value = $this->cpu->pullByte();
        $this->cpu->setRegisterX($value);

        $this->cpu->status->set(StatusRegister::ZERO, $value === 0);
        $this->cpu->status->set(StatusRegister::NEGATIVE, ($value & 0x80) !== 0);

        return $opcode->getCycles();
    }

    /**
     * PLY - Pull Y Register from Stack (65C02)
     */
    public function ply(Opcode $opcode): int
    {
        $value = $this->cpu->pullByte();
        $this->cpu->setRegisterY($value);

        $this->cpu->status->set(StatusRegister::ZERO, $value === 0);
        $this->cpu->status->set(StatusRegister::NEGATIVE, ($value & 0x80) !== 0);

        return $opcode->getCycles();
    }
}
