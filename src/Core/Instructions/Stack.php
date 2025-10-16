<?php

declare(strict_types=1);

namespace AndrewTheCoder\Emulator\Core\Instructions;

use AndrewTheCoder\Emulator\Core\CPU;
use AndrewTheCoder\Emulator\Core\Opcode;
use AndrewTheCoder\Emulator\Core\StatusRegister;

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
}
