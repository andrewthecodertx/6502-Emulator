<?php

declare(strict_types=1);

namespace andrewthecoder\MOS6502\Instructions;

use andrewthecoder\MOS6502\CPU;
use andrewthecoder\MOS6502\Opcode;
use andrewthecoder\MOS6502\StatusRegister;

/**
 * 65C02 CMOS-specific Instructions
 *
 * This class implements instructions that are unique to the 65C02 CMOS variant
 * and were not present in the original NMOS 6502.
 */
class CMOS65C02
{
    public function __construct(
        private CPU $cpu
    ) {
    }

    /**
     * BRA - Branch Always (unconditional branch)
     * This is a 65C02-specific instruction that always branches
     */
    public function bra(Opcode $opcode): int
    {
        $offset = $this->cpu->getBus()->read($this->cpu->pc);
        $this->cpu->pc = ($this->cpu->pc + 1) & 0xFFFF;

        // Sign extend the offset
        if ($offset & 0x80) {
            $offset |= 0xFF00;
        }

        $oldPC = $this->cpu->pc;
        $this->cpu->pc = ($this->cpu->pc + $offset) & 0xFFFF;

        // Add 1 cycle for branch taken, +1 more if page boundary crossed
        $cycles = $opcode->getCycles();
        if (($oldPC & 0xFF00) !== ($this->cpu->pc & 0xFF00)) {
            $cycles++;
        }

        return $cycles;
    }

    /**
     * STZ - Store Zero in Memory
     * Stores $00 to memory location
     */
    public function stz(Opcode $opcode): int
    {
        $address = $this->cpu->getAddress($opcode->getAddressingMode());
        $this->cpu->getBus()->write($address, 0x00);

        return $opcode->getCycles();
    }

    /**
     * TRB - Test and Reset Bits
     * Performs AND between accumulator and memory, sets Z flag,
     * then stores memory AND (NOT accumulator) back to memory
     */
    public function trb(Opcode $opcode): int
    {
        $address = $this->cpu->getAddress($opcode->getAddressingMode());
        $memory = $this->cpu->getBus()->read($address);

        // Test: A AND M, set Z flag
        $test = $this->cpu->getAccumulator() & $memory;
        $this->cpu->status->set(StatusRegister::ZERO, $test === 0);

        // Reset: M AND (NOT A) -> M
        $result = $memory & (~$this->cpu->getAccumulator() & 0xFF);
        $this->cpu->getBus()->write($address, $result);

        return $opcode->getCycles();
    }

    /**
     * TSB - Test and Set Bits
     * Performs AND between accumulator and memory, sets Z flag,
     * then stores memory OR accumulator back to memory
     */
    public function tsb(Opcode $opcode): int
    {
        $address = $this->cpu->getAddress($opcode->getAddressingMode());
        $memory = $this->cpu->getBus()->read($address);

        // Test: A AND M, set Z flag
        $test = $this->cpu->getAccumulator() & $memory;
        $this->cpu->status->set(StatusRegister::ZERO, $test === 0);

        // Set: M OR A -> M
        $result = $memory | $this->cpu->getAccumulator();
        $this->cpu->getBus()->write($address, $result);

        return $opcode->getCycles();
    }

    /**
     * WAI - Wait for Interrupt
     * Halts the CPU until an interrupt (NMI or IRQ) occurs
     */
    public function wai(Opcode $opcode): int
    {
        // Set waiting state - CPU will remain in this state until interrupt
        // The RDY line is pulled low by the 65C02 during WAI
        $this->cpu->setWaiting(true);

        return $opcode->getCycles();
    }

    /**
     * STP - Stop
     * Halts the CPU completely until RESET
     */
    public function stp(Opcode $opcode): int
    {
        // Halt the CPU - only RESET can recover from this
        $this->cpu->halt();

        return $opcode->getCycles();
    }

    /**
     * BBR - Branch on Bit Reset
     * Branches if specified bit in zero page memory is 0
     */
    public function bbr(Opcode $opcode, int $bit): int
    {
        $zpAddress = $this->cpu->getBus()->read($this->cpu->pc);
        $this->cpu->pc = ($this->cpu->pc + 1) & 0xFFFF;

        $offset = $this->cpu->getBus()->read($this->cpu->pc);
        $this->cpu->pc = ($this->cpu->pc + 1) & 0xFFFF;

        $value = $this->cpu->getBus()->read($zpAddress);

        // Branch if bit is 0
        if (($value & (1 << $bit)) === 0) {
            // Sign extend the offset
            if ($offset & 0x80) {
                $offset |= 0xFF00;
            }
            $this->cpu->pc = ($this->cpu->pc + $offset) & 0xFFFF;
        }

        return $opcode->getCycles();
    }

    /**
     * BBS - Branch on Bit Set
     * Branches if specified bit in zero page memory is 1
     */
    public function bbs(Opcode $opcode, int $bit): int
    {
        $zpAddress = $this->cpu->getBus()->read($this->cpu->pc);
        $this->cpu->pc = ($this->cpu->pc + 1) & 0xFFFF;

        $offset = $this->cpu->getBus()->read($this->cpu->pc);
        $this->cpu->pc = ($this->cpu->pc + 1) & 0xFFFF;

        $value = $this->cpu->getBus()->read($zpAddress);

        // Branch if bit is 1
        if (($value & (1 << $bit)) !== 0) {
            // Sign extend the offset
            if ($offset & 0x80) {
                $offset |= 0xFF00;
            }
            $this->cpu->pc = ($this->cpu->pc + $offset) & 0xFFFF;
        }

        return $opcode->getCycles();
    }

    /**
     * RMB - Reset Memory Bit
     * Clears specified bit in zero page memory
     */
    public function rmb(Opcode $opcode, int $bit): int
    {
        $address = $this->cpu->getBus()->read($this->cpu->pc);
        $this->cpu->pc = ($this->cpu->pc + 1) & 0xFFFF;

        $value = $this->cpu->getBus()->read($address);
        $value &= ~(1 << $bit);
        $this->cpu->getBus()->write($address, $value);

        return $opcode->getCycles();
    }

    /**
     * SMB - Set Memory Bit
     * Sets specified bit in zero page memory
     */
    public function smb(Opcode $opcode, int $bit): int
    {
        $address = $this->cpu->getBus()->read($this->cpu->pc);
        $this->cpu->pc = ($this->cpu->pc + 1) & 0xFFFF;

        $value = $this->cpu->getBus()->read($address);
        $value |= (1 << $bit);
        $this->cpu->getBus()->write($address, $value);

        return $opcode->getCycles();
    }

    // Individual methods for BBR0-7
    public function bbr0(Opcode $opcode): int
    {
        return $this->bbr($opcode, 0);
    }
    public function bbr1(Opcode $opcode): int
    {
        return $this->bbr($opcode, 1);
    }
    public function bbr2(Opcode $opcode): int
    {
        return $this->bbr($opcode, 2);
    }
    public function bbr3(Opcode $opcode): int
    {
        return $this->bbr($opcode, 3);
    }
    public function bbr4(Opcode $opcode): int
    {
        return $this->bbr($opcode, 4);
    }
    public function bbr5(Opcode $opcode): int
    {
        return $this->bbr($opcode, 5);
    }
    public function bbr6(Opcode $opcode): int
    {
        return $this->bbr($opcode, 6);
    }
    public function bbr7(Opcode $opcode): int
    {
        return $this->bbr($opcode, 7);
    }

    // Individual methods for BBS0-7
    public function bbs0(Opcode $opcode): int
    {
        return $this->bbs($opcode, 0);
    }
    public function bbs1(Opcode $opcode): int
    {
        return $this->bbs($opcode, 1);
    }
    public function bbs2(Opcode $opcode): int
    {
        return $this->bbs($opcode, 2);
    }
    public function bbs3(Opcode $opcode): int
    {
        return $this->bbs($opcode, 3);
    }
    public function bbs4(Opcode $opcode): int
    {
        return $this->bbs($opcode, 4);
    }
    public function bbs5(Opcode $opcode): int
    {
        return $this->bbs($opcode, 5);
    }
    public function bbs6(Opcode $opcode): int
    {
        return $this->bbs($opcode, 6);
    }
    public function bbs7(Opcode $opcode): int
    {
        return $this->bbs($opcode, 7);
    }

    // Individual methods for RMB0-7
    public function rmb0(Opcode $opcode): int
    {
        return $this->rmb($opcode, 0);
    }
    public function rmb1(Opcode $opcode): int
    {
        return $this->rmb($opcode, 1);
    }
    public function rmb2(Opcode $opcode): int
    {
        return $this->rmb($opcode, 2);
    }
    public function rmb3(Opcode $opcode): int
    {
        return $this->rmb($opcode, 3);
    }
    public function rmb4(Opcode $opcode): int
    {
        return $this->rmb($opcode, 4);
    }
    public function rmb5(Opcode $opcode): int
    {
        return $this->rmb($opcode, 5);
    }
    public function rmb6(Opcode $opcode): int
    {
        return $this->rmb($opcode, 6);
    }
    public function rmb7(Opcode $opcode): int
    {
        return $this->rmb($opcode, 7);
    }

    // Individual methods for SMB0-7
    public function smb0(Opcode $opcode): int
    {
        return $this->smb($opcode, 0);
    }
    public function smb1(Opcode $opcode): int
    {
        return $this->smb($opcode, 1);
    }
    public function smb2(Opcode $opcode): int
    {
        return $this->smb($opcode, 2);
    }
    public function smb3(Opcode $opcode): int
    {
        return $this->smb($opcode, 3);
    }
    public function smb4(Opcode $opcode): int
    {
        return $this->smb($opcode, 4);
    }
    public function smb5(Opcode $opcode): int
    {
        return $this->smb($opcode, 5);
    }
    public function smb6(Opcode $opcode): int
    {
        return $this->smb($opcode, 6);
    }
    public function smb7(Opcode $opcode): int
    {
        return $this->smb($opcode, 7);
    }
}
