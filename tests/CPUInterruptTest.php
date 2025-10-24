<?php

declare(strict_types=1);

namespace andrewthecoder\Tests;

use andrewthecoder\WDC65C02\CPU;
use andrewthecoder\WDC65C02\StatusRegister;
use PHPUnit\Framework\TestCase;

/**
 * Tests for CPU interrupt handling (NMI, IRQ, RESET)
 */
class CPUInterruptTest extends TestCase
{
    private SimpleBus $bus;
    private CPU $cpu;

    protected function setUp(): void
    {
        $this->bus = new SimpleBus();
        $this->cpu = new CPU($this->bus);
    }

    public function testResetInterrupt(): void
    {
        // Set reset vector
        $this->bus->setResetVector(0xC000);

        // Halt and reset (immediate reset doesn't add cycles)
        $this->cpu->halt();
        $this->cpu->reset();
        $this->cpu->resume();

        // Should jump to reset vector
        $this->assertEquals(0xC000, $this->cpu->pc);

        // Should set interrupt disable flag
        $this->assertTrue($this->cpu->status->get(StatusRegister::INTERRUPT_DISABLE));

        // Immediate reset doesn't add cycles
        $this->assertEquals(0, $this->cpu->cycles);

        // SP should be decremented by 3
        $this->assertEquals(0xFC, $this->cpu->sp);
    }

    public function testNMIRequest(): void
    {
        $this->bus->setResetVector(0x8000);
        $this->bus->setNMIVector(0x9000);

        // Put NOP at reset location
        $this->bus->write(0x8000, 0xEA); // NOP

        // Put RTI at NMI handler to return
        $this->bus->write(0x9000, 0x40); // RTI

        $this->cpu->halt();
        $this->cpu->reset();
        $this->cpu->resume();

        // Execute one instruction (NOP)
        $this->cpu->executeInstruction();
        $pcAfterNop = $this->cpu->pc;
        $spBeforeNMI = $this->cpu->sp;

        // Request NMI
        $this->cpu->requestNMI();

        // Execute next instruction - should trigger NMI
        $this->cpu->step();

        // PC should jump to NMI vector
        $this->assertEquals(0x9000, $this->cpu->pc);

        // Return address should be pushed to stack
        $returnAddrLow = $this->bus->read(0x0100 + $spBeforeNMI);
        $returnAddrHigh = $this->bus->read(0x0100 + $spBeforeNMI - 1);
        $returnAddr = ($returnAddrHigh << 8) | $returnAddrLow;
        $this->assertEquals($pcAfterNop, $returnAddr);

        // Status should be pushed to stack
        $savedStatus = $this->bus->read(0x0100 + $spBeforeNMI - 2);
        $this->assertNotNull($savedStatus);

        // I flag should be set
        $this->assertTrue($this->cpu->status->get(StatusRegister::INTERRUPT_DISABLE));
    }

    public function testNMIEdgeTriggered(): void
    {
        $this->bus->setResetVector(0x8000);
        $this->bus->setNMIVector(0x9000);

        $this->bus->write(0x8000, 0xEA); // NOP

        $this->cpu->halt();
        $this->cpu->reset();
        $this->cpu->resume();

        // Request NMI multiple times without releasing
        $this->cpu->requestNMI();
        $this->cpu->requestNMI();
        $this->cpu->requestNMI();

        // Should only trigger once
        $this->cpu->step(); // Should trigger NMI
        $this->assertEquals(0x9000, $this->cpu->pc);

        // Reset to test location
        $this->cpu->pc = 0x8000;

        // Request again without release - should not trigger
        $this->cpu->requestNMI();
        $this->cpu->step();
        $this->assertNotEquals(0x9000, $this->cpu->pc, 'Should not trigger again');

        // Release and request again - should trigger
        $this->cpu->releaseNMI();
        $this->cpu->requestNMI();
        $this->cpu->step();
        $this->assertEquals(0x9000, $this->cpu->pc, 'Should trigger after release');
    }

    public function testIRQRequest(): void
    {
        $this->bus->setResetVector(0x8000);
        $this->bus->setIRQVector(0xA000);

        // Put NOP at reset location
        $this->bus->write(0x8000, 0xEA); // NOP

        // Put RTI at IRQ handler
        $this->bus->write(0xA000, 0x40); // RTI

        $this->cpu->halt();
        $this->cpu->reset();
        $this->cpu->resume();

        // Enable interrupts
        $this->cpu->status->set(StatusRegister::INTERRUPT_DISABLE, false);

        // Execute one instruction
        $this->cpu->executeInstruction();
        $pcAfterNop = $this->cpu->pc;

        // Request IRQ
        $this->cpu->requestIRQ();

        // Execute - should trigger IRQ
        $this->cpu->step();

        // PC should jump to IRQ vector
        $this->assertEquals(0xA000, $this->cpu->pc);

        // I flag should be set
        $this->assertTrue($this->cpu->status->get(StatusRegister::INTERRUPT_DISABLE));
    }

    public function testIRQMaskedByIFlag(): void
    {
        $this->bus->setResetVector(0x8000);
        $this->bus->setIRQVector(0xA000);

        $this->bus->write(0x8000, 0xEA); // NOP
        $this->bus->write(0x8001, 0xEA); // NOP

        $this->cpu->halt();
        $this->cpu->reset();
        $this->cpu->resume();

        // I flag is set after reset, interrupts disabled
        $this->assertTrue($this->cpu->status->get(StatusRegister::INTERRUPT_DISABLE));

        // Request IRQ
        $this->cpu->requestIRQ();

        // Execute instruction - IRQ should NOT trigger
        $this->cpu->executeInstruction();
        $this->assertNotEquals(0xA000, $this->cpu->pc, 'IRQ should be masked');
    }

    public function testIRQLevelTriggered(): void
    {
        $this->bus->setResetVector(0x8000);
        $this->bus->setIRQVector(0xA000);

        $this->bus->write(0x8000, 0xEA); // NOP

        $this->cpu->halt();
        $this->cpu->reset();
        $this->cpu->resume();
        $this->cpu->status->set(StatusRegister::INTERRUPT_DISABLE, false);

        // Request IRQ
        $this->cpu->requestIRQ();

        // Should trigger
        $this->cpu->step();
        $this->assertEquals(0xA000, $this->cpu->pc);

        // Reset PC
        $this->cpu->pc = 0x8000;

        // IRQ still asserted (level-triggered), should trigger again
        $this->cpu->status->set(StatusRegister::INTERRUPT_DISABLE, false);
        $this->cpu->step();
        $this->assertEquals(0xA000, $this->cpu->pc, 'IRQ should trigger again (level-triggered)');

        // Release IRQ
        $this->cpu->releaseIRQ();

        // Reset and verify no trigger
        $this->cpu->pc = 0x8000;
        $this->cpu->status->set(StatusRegister::INTERRUPT_DISABLE, false);
        $this->cpu->step();
        $this->assertNotEquals(0xA000, $this->cpu->pc, 'IRQ should not trigger after release');
    }

    public function testRTIInstruction(): void
    {
        $this->bus->setResetVector(0x8000);
        $this->bus->setNMIVector(0x9000);

        // Main program
        $this->bus->write(0x8000, 0xEA); // NOP

        // NMI handler
        $this->bus->write(0x9000, 0xA9); // LDA #$42
        $this->bus->write(0x9001, 0x42);
        $this->bus->write(0x9002, 0x40); // RTI

        $this->cpu->halt();
        $this->cpu->reset();
        $this->cpu->resume();

        // Execute NOP
        $this->cpu->executeInstruction();
        $pcBeforeInterrupt = $this->cpu->pc;

        // Manually set some status flags
        $this->cpu->status->set(StatusRegister::ZERO, true);
        $this->cpu->status->set(StatusRegister::CARRY, true);
        $statusBeforeInterrupt = $this->cpu->status->toInt();

        // Trigger NMI
        $this->cpu->requestNMI();
        $this->cpu->step(); // Trigger interrupt

        // Execute LDA in handler
        $this->cpu->executeInstruction();
        $this->assertEquals(0x42, $this->cpu->accumulator);

        // Flags will have changed (Z cleared by LDA)
        $this->assertFalse($this->cpu->status->get(StatusRegister::ZERO));

        // Execute RTI
        $this->cpu->executeInstruction();

        // PC should return to where we were
        $this->assertEquals($pcBeforeInterrupt, $this->cpu->pc);

        // Status flags should be restored
        $this->assertEquals($statusBeforeInterrupt, $this->cpu->status->toInt());
    }

    public function testInterruptPriority(): void
    {
        // RESET has highest priority, then NMI, then IRQ
        $this->bus->setResetVector(0x8000);
        $this->bus->setNMIVector(0x9000);
        $this->bus->setIRQVector(0xA000);

        $this->bus->write(0x8000, 0xEA); // NOP

        $this->cpu->halt();
        $this->cpu->reset();
        $this->cpu->resume();
        $this->cpu->status->set(StatusRegister::INTERRUPT_DISABLE, false);

        // Request both IRQ and NMI
        $this->cpu->requestIRQ();
        $this->cpu->requestNMI();

        // Step - NMI should trigger (higher priority)
        $this->cpu->step();
        $this->assertEquals(0x9000, $this->cpu->pc, 'NMI should trigger first');
    }

    public function testStackDuringInterrupt(): void
    {
        $this->bus->setResetVector(0x8000);
        $this->bus->setNMIVector(0x9000);

        $this->bus->write(0x8000, 0xEA); // NOP
        $this->bus->write(0x9000, 0x40); // RTI

        $this->cpu->halt();
        $this->cpu->reset();
        $this->cpu->resume();

        $initialSP = $this->cpu->sp;

        // Execute NOP
        $this->cpu->executeInstruction();

        // Trigger NMI
        $this->cpu->requestNMI();
        $this->cpu->step();

        // SP should have decremented by 3 (PC high, PC low, Status)
        $this->assertEquals($initialSP - 3, $this->cpu->sp);

        // Execute RTI
        $this->cpu->executeInstruction();

        // SP should be restored
        $this->assertEquals($initialSP, $this->cpu->sp);
    }

    public function testBRKInstruction(): void
    {
        $this->bus->setResetVector(0x8000);
        $this->bus->setIRQVector(0xA000);

        // BRK instruction
        $this->bus->write(0x8000, 0x00); // BRK

        // IRQ handler
        $this->bus->write(0xA000, 0xA9); // LDA #$FF
        $this->bus->write(0xA001, 0xFF);
        $this->bus->write(0xA002, 0x40); // RTI

        $this->cpu->halt();
        $this->cpu->reset();
        $this->cpu->resume();

        // Execute BRK
        $this->cpu->executeInstruction();

        // Should jump to IRQ vector (BRK uses IRQ vector)
        $this->assertEquals(0xA000, $this->cpu->pc);

        // I flag should be set
        $this->assertTrue($this->cpu->status->get(StatusRegister::INTERRUPT_DISABLE));

        // B flag should be set on stack
        $savedStatus = $this->bus->read(0x0100 + $this->cpu->sp + 1);
        $bFlagSet = ($savedStatus & (1 << StatusRegister::BREAK_COMMAND)) !== 0;
        $this->assertTrue($bFlagSet, 'B flag should be set in saved status');
    }
}
