<?php

declare(strict_types=1);

namespace andrewthecoder\MOS6502\Instructions;

use andrewthecoder\MOS6502\CPU;
use andrewthecoder\Core\Opcode;
use andrewthecoder\Core\StatusRegister;

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
        // BRK - Software Interrupt
        // 1. PC is already incremented by 1 (opcode fetch)
        // 2. Increment PC by 1 more (BRK has a dummy operand byte)
        // 3. Push PCH to stack
        // 4. Push PCL to stack
        // 5. Push P to stack with B flag SET (to distinguish from hardware IRQ)
        // 6. Set I flag to disable interrupts
        // 7. Load PC from IRQ vector (0xFFFE-0xFFFF)

        $this->cpu->pc++; // Skip the dummy operand byte

        $this->cpu->pushByte(($this->cpu->pc >> 8) & 0xFF);
        $this->cpu->pushByte($this->cpu->pc & 0xFF);

        // Push status with B flag set (unlike hardware interrupts)
        $statusValue = $this->cpu->status->toInt() | (1 << StatusRegister::BREAK_COMMAND);
        $this->cpu->pushByte($statusValue);

        $this->cpu->status->set(StatusRegister::INTERRUPT_DISABLE, true);

        $irqLow = $this->cpu->getBus()->read(0xFFFE);
        $irqHigh = $this->cpu->getBus()->read(0xFFFF);

        $this->cpu->pc = ($irqHigh << 8) | $irqLow;

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
