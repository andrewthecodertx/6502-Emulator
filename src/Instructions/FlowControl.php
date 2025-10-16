<?php

declare(strict_types=1);

namespace Andrewthecoder\MOS6502\Instructions;

use Andrewthecoder\MOS6502\CPU;
use Andrewthecoder\MOS6502\Opcode;
use Andrewthecoder\MOS6502\StatusRegister;

class FlowControl
{
    public function __construct(
        private CPU $cpu
    ) {
    }

    public function beq(Opcode $opcode): int
    {
        return $this->branch($opcode, $this->cpu->status->get(StatusRegister::ZERO));
    }

    public function bne(Opcode $opcode): int
    {
        return $this->branch($opcode, !$this->cpu->status->get(StatusRegister::ZERO));
    }

    public function bcc(Opcode $opcode): int
    {
        return $this->branch($opcode, !$this->cpu->status->get(StatusRegister::CARRY));
    }

    public function bcs(Opcode $opcode): int
    {
        return $this->branch($opcode, $this->cpu->status->get(StatusRegister::CARRY));
    }

    public function bpl(Opcode $opcode): int
    {
        return $this->branch($opcode, !$this->cpu->status->get(StatusRegister::NEGATIVE));
    }

    public function bmi(Opcode $opcode): int
    {
        return $this->branch($opcode, $this->cpu->status->get(StatusRegister::NEGATIVE));
    }

    public function bvc(Opcode $opcode): int
    {
        return $this->branch($opcode, !$this->cpu->status->get(StatusRegister::OVERFLOW));
    }

    public function bvs(Opcode $opcode): int
    {
        return $this->branch($opcode, $this->cpu->status->get(StatusRegister::OVERFLOW));
    }

    public function jmp(Opcode $opcode): int
    {
        $address = $this->cpu->getAddress($opcode->getAddressingMode());
        $this->cpu->pc = $address;

        return $opcode->getCycles();
    }

    public function jsr(Opcode $opcode): int
    {
        $this->cpu->pushWord($this->cpu->pc + 1);

        $address = $this->cpu->getAddress($opcode->getAddressingMode());
        $this->cpu->pc = $address;

        return $opcode->getCycles();
    }

    public function rts(Opcode $opcode): int
    {
        $returnAddress = $this->cpu->pullWord();
        $this->cpu->pc = $returnAddress + 1;

        return $opcode->getCycles();
    }

    public function brk(Opcode $opcode): int
    {
        $this->cpu->halt();

        return $opcode->getCycles();
    }

    public function rti(Opcode $opcode): int
    {
        $status = $this->cpu->pullByte();
        $this->cpu->status->fromInt($status);
        $this->cpu->pc = $this->cpu->pullWord();

        return $opcode->getCycles();
    }

    private function branch(Opcode $opcode, bool $condition): int
    {
        $cycles = $opcode->getCycles();
        $offset = $this->cpu->getAddress($opcode->getAddressingMode());

        if ($condition) {
            $oldPC = $this->cpu->pc;

            if ($offset & 0x80) {
                $offset -= 256;
            }
            $this->cpu->pc = ($oldPC + $offset) & 0xFFFF;

            $cycles++;

            if (($oldPC & 0xFF00) !== ($this->cpu->pc & 0xFF00)) {
                $cycles++;
            }
        }

        return $cycles;
    }

    // JAM: Halt processor (undocumented)
    public function jam(Opcode $opcode): int
    {

        $this->cpu->halted = true;

        return $opcode->getCycles();
    }
}
