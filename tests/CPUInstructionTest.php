<?php

declare(strict_types=1);

namespace andrewthecoder\Tests;

use andrewthecoder\MOS6502\CPU;
use andrewthecoder\MOS6502\StatusRegister;
use PHPUnit\Framework\TestCase;

/**
 * Tests for 6502 instruction execution
 */
class CPUInstructionTest extends TestCase
{
    private SimpleBus $bus;
    private CPU $cpu;

    protected function setUp(): void
    {
        $this->bus = new SimpleBus();
        $this->cpu = new CPU($this->bus);
        $this->bus->setResetVector(0x8000);

        // Halt CPU and reset so it starts at 0x8000 immediately
        $this->cpu->halt();
        $this->cpu->reset();
        $this->cpu->resume();
    }

    public function testLDAImmediate(): void
    {
        // LDA #$42
        $this->bus->loadProgram(0x8000, [0xA9, 0x42]);

        $this->cpu->executeInstruction();

        $this->assertEquals(0x42, $this->cpu->accumulator);
        $this->assertFalse($this->cpu->status->get(StatusRegister::ZERO));
        $this->assertFalse($this->cpu->status->get(StatusRegister::NEGATIVE));
    }

    public function testLDAZeroFlag(): void
    {
        // LDA #$00
        $this->bus->loadProgram(0x8000, [0xA9, 0x00]);

        $this->cpu->executeInstruction();

        $this->assertEquals(0x00, $this->cpu->accumulator);
        $this->assertTrue($this->cpu->status->get(StatusRegister::ZERO), 'Zero flag should be set');
        $this->assertFalse($this->cpu->status->get(StatusRegister::NEGATIVE));
    }

    public function testLDANegativeFlag(): void
    {
        // LDA #$FF (negative in signed representation)
        $this->bus->loadProgram(0x8000, [0xA9, 0xFF]);

        $this->cpu->executeInstruction();

        $this->assertEquals(0xFF, $this->cpu->accumulator);
        $this->assertFalse($this->cpu->status->get(StatusRegister::ZERO));
        $this->assertTrue($this->cpu->status->get(StatusRegister::NEGATIVE), 'Negative flag should be set');
    }

    public function testSTAZeroPage(): void
    {
        // LDA #$42, STA $50
        $this->bus->loadProgram(0x8000, [
            0xA9, 0x42,  // LDA #$42
            0x85, 0x50   // STA $50
        ]);

        $this->cpu->executeInstruction(); // LDA
        $this->cpu->executeInstruction(); // STA

        $this->assertEquals(0x42, $this->bus->read(0x50), 'Value should be stored at $50');
    }

    public function testLDXImmediate(): void
    {
        // LDX #$33
        $this->bus->loadProgram(0x8000, [0xA2, 0x33]);

        $this->cpu->executeInstruction();

        $this->assertEquals(0x33, $this->cpu->registerX);
        $this->assertFalse($this->cpu->status->get(StatusRegister::ZERO));
    }

    public function testLDYImmediate(): void
    {
        // LDY #$44
        $this->bus->loadProgram(0x8000, [0xA0, 0x44]);

        $this->cpu->executeInstruction();

        $this->assertEquals(0x44, $this->cpu->registerY);
        $this->assertFalse($this->cpu->status->get(StatusRegister::ZERO));
    }

    public function testTAX(): void
    {
        // LDA #$42, TAX
        $this->bus->loadProgram(0x8000, [
            0xA9, 0x42,  // LDA #$42
            0xAA         // TAX
        ]);

        $this->cpu->executeInstruction(); // LDA
        $this->cpu->executeInstruction(); // TAX

        $this->assertEquals(0x42, $this->cpu->registerX, 'X should equal A');
        $this->assertEquals(0x42, $this->cpu->accumulator, 'A should be unchanged');
    }

    public function testTAY(): void
    {
        // LDA #$55, TAY
        $this->bus->loadProgram(0x8000, [
            0xA9, 0x55,  // LDA #$55
            0xA8         // TAY
        ]);

        $this->cpu->executeInstruction();
        $this->cpu->executeInstruction();

        $this->assertEquals(0x55, $this->cpu->registerY);
        $this->assertEquals(0x55, $this->cpu->accumulator);
    }

    public function testTXA(): void
    {
        // LDX #$66, TXA
        $this->bus->loadProgram(0x8000, [
            0xA2, 0x66,  // LDX #$66
            0x8A         // TXA
        ]);

        $this->cpu->executeInstruction();
        $this->cpu->executeInstruction();

        $this->assertEquals(0x66, $this->cpu->accumulator);
        $this->assertEquals(0x66, $this->cpu->registerX);
    }

    public function testTYA(): void
    {
        // LDY #$77, TYA
        $this->bus->loadProgram(0x8000, [
            0xA0, 0x77,  // LDY #$77
            0x98         // TYA
        ]);

        $this->cpu->executeInstruction();
        $this->cpu->executeInstruction();

        $this->assertEquals(0x77, $this->cpu->accumulator);
        $this->assertEquals(0x77, $this->cpu->registerY);
    }

    public function testINX(): void
    {
        // LDX #$05, INX
        $this->bus->loadProgram(0x8000, [
            0xA2, 0x05,  // LDX #$05
            0xE8         // INX
        ]);

        $this->cpu->executeInstruction();
        $this->cpu->executeInstruction();

        $this->assertEquals(0x06, $this->cpu->registerX);
        $this->assertFalse($this->cpu->status->get(StatusRegister::ZERO));
    }

    public function testINXWrapAround(): void
    {
        // LDX #$FF, INX
        $this->bus->loadProgram(0x8000, [
            0xA2, 0xFF,  // LDX #$FF
            0xE8         // INX
        ]);

        $this->cpu->executeInstruction();
        $this->cpu->executeInstruction();

        $this->assertEquals(0x00, $this->cpu->registerX, 'Should wrap to 0');
        $this->assertTrue($this->cpu->status->get(StatusRegister::ZERO));
    }

    public function testDEX(): void
    {
        // LDX #$05, DEX
        $this->bus->loadProgram(0x8000, [
            0xA2, 0x05,  // LDX #$05
            0xCA         // DEX
        ]);

        $this->cpu->executeInstruction();
        $this->cpu->executeInstruction();

        $this->assertEquals(0x04, $this->cpu->registerX);
    }

    public function testDEXWrapAround(): void
    {
        // LDX #$00, DEX
        $this->bus->loadProgram(0x8000, [
            0xA2, 0x00,  // LDX #$00
            0xCA         // DEX
        ]);

        $this->cpu->executeInstruction();
        $this->cpu->executeInstruction();

        $this->assertEquals(0xFF, $this->cpu->registerX, 'Should wrap to FF');
        $this->assertTrue($this->cpu->status->get(StatusRegister::NEGATIVE));
    }

    public function testCLC(): void
    {
        // Set carry, then clear it
        $this->cpu->status->set(StatusRegister::CARRY, true);

        // CLC
        $this->bus->loadProgram(0x8000, [0x18]);

        $this->cpu->executeInstruction();

        $this->assertFalse($this->cpu->status->get(StatusRegister::CARRY));
    }

    public function testSEC(): void
    {
        // SEC
        $this->bus->loadProgram(0x8000, [0x38]);

        $this->cpu->executeInstruction();

        $this->assertTrue($this->cpu->status->get(StatusRegister::CARRY));
    }

    public function testCLI(): void
    {
        $this->cpu->status->set(StatusRegister::INTERRUPT_DISABLE, true);

        // CLI
        $this->bus->loadProgram(0x8000, [0x58]);

        $this->cpu->executeInstruction();

        $this->assertFalse($this->cpu->status->get(StatusRegister::INTERRUPT_DISABLE));
    }

    public function testSEI(): void
    {
        // SEI
        $this->bus->loadProgram(0x8000, [0x78]);

        $this->cpu->executeInstruction();

        $this->assertTrue($this->cpu->status->get(StatusRegister::INTERRUPT_DISABLE));
    }

    public function testNOP(): void
    {
        $pcBefore = $this->cpu->pc;

        // NOP
        $this->bus->loadProgram(0x8000, [0xEA]);

        $this->cpu->executeInstruction();

        // PC should advance by 1, nothing else should change
        $this->assertEquals($pcBefore + 1, $this->cpu->pc);
    }

    public function testJMPAbsolute(): void
    {
        // JMP $C000
        $this->bus->loadProgram(0x8000, [0x4C, 0x00, 0xC0]);

        $this->cpu->executeInstruction();

        $this->assertEquals(0xC000, $this->cpu->pc, 'PC should jump to $C000');
    }

    public function testPHAPLA(): void
    {
        // LDA #$42, PHA, LDA #$00, PLA
        $this->bus->loadProgram(0x8000, [
            0xA9, 0x42,  // LDA #$42
            0x48,        // PHA
            0xA9, 0x00,  // LDA #$00
            0x68         // PLA
        ]);

        $this->cpu->executeInstruction(); // LDA #$42
        $this->assertEquals(0x42, $this->cpu->accumulator);

        $spBeforePush = $this->cpu->sp;
        $this->cpu->executeInstruction(); // PHA
        // Value should be at 0x0100 + SP_before_push (PHA writes then decrements)
        $this->assertEquals(0x42, $this->bus->read(0x0100 + $spBeforePush), 'Value on stack');

        $this->cpu->executeInstruction(); // LDA #$00
        $this->assertEquals(0x00, $this->cpu->accumulator);

        $this->cpu->executeInstruction(); // PLA
        $this->assertEquals(0x42, $this->cpu->accumulator, 'Should restore from stack');
    }

    public function testAbsoluteAddressing(): void
    {
        // Store value in memory, then load it
        $this->bus->write(0x1234, 0x99);

        // LDA $1234
        $this->bus->loadProgram(0x8000, [0xAD, 0x34, 0x12]);

        $this->cpu->executeInstruction();

        $this->assertEquals(0x99, $this->cpu->accumulator);
    }

    public function testIndexedAddressing(): void
    {
        // Store value in memory
        $this->bus->write(0x1234, 0x88);

        // LDX #$04, LDA $1230,X  (loads from $1234)
        $this->bus->loadProgram(0x8000, [
            0xA2, 0x04,        // LDX #$04
            0xBD, 0x30, 0x12   // LDA $1230,X
        ]);

        $this->cpu->executeInstruction(); // LDX
        $this->cpu->executeInstruction(); // LDA

        $this->assertEquals(0x88, $this->cpu->accumulator);
    }
}
