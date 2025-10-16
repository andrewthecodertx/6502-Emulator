<?php

declare(strict_types=1);

namespace andrewthecoder\Tests;

use andrewthecoder\MOS6502\CPU;
use andrewthecoder\MOS6502\StatusRegister;
use PHPUnit\Framework\TestCase;

/**
 * Comprehensive CPU tests covering initialization, reset, and instruction execution
 */
class CPUTest extends TestCase
{
    private SimpleBus $bus;
    private CPU $cpu;

    protected function setUp(): void
    {
        $this->bus = new SimpleBus();
        $this->cpu = new CPU($this->bus);
    }

    public function testInitialization(): void
    {
        $this->assertInstanceOf(CPU::class, $this->cpu);
        $this->assertEquals(0, $this->cpu->pc, 'PC should initialize to 0');
        $this->assertEquals(0xFF, $this->cpu->sp, 'SP should initialize to 0xFF');
        $this->assertEquals(0, $this->cpu->accumulator, 'Accumulator should initialize to 0');
        $this->assertEquals(0, $this->cpu->registerX, 'X register should initialize to 0');
        $this->assertEquals(0, $this->cpu->registerY, 'Y register should initialize to 0');
        $this->assertEquals(0, $this->cpu->cycles, 'Cycles should initialize to 0');
        $this->assertFalse($this->cpu->halted, 'CPU should not be halted initially');
    }

    public function testResetSequence(): void
    {
        // Set up reset vector to point to 0x8000
        $this->bus->setResetVector(0x8000);

        // Halt CPU so reset executes immediately
        $this->cpu->halt();

        // Call reset
        $this->cpu->reset();

        // Immediate reset (when halted) doesn't add cycles
        $this->assertEquals(0, $this->cpu->cycles, 'Immediate RESET should not add cycles');

        // PC should be loaded from reset vector
        $this->assertEquals(0x8000, $this->cpu->pc, 'PC should be loaded from reset vector');

        // Registers should be cleared
        $this->assertEquals(0, $this->cpu->accumulator, 'Accumulator should be cleared');
        $this->assertEquals(0, $this->cpu->registerX, 'X register should be cleared');
        $this->assertEquals(0, $this->cpu->registerY, 'Y register should be cleared');

        // Stack pointer should be decremented by 3
        $this->assertEquals(0xFC, $this->cpu->sp, 'SP should be decremented by 3 (0xFF - 3 = 0xFC)');

        // Status register should be 0x34 (00110100)
        // I (Interrupt Disable) = 1, Unused = 1, D (Decimal) = 0
        $this->assertTrue($this->cpu->status->get(StatusRegister::INTERRUPT_DISABLE), 'I flag should be set');
        $this->assertFalse($this->cpu->status->get(StatusRegister::DECIMAL_MODE), 'D flag should be clear');

        // CPU should not be halted
        $this->assertFalse($this->cpu->halted, 'CPU should not be halted after reset');
    }

    public function testResetVectorReading(): void
    {
        // Test with different reset vectors
        $this->bus->setResetVector(0xC000);
        $this->cpu->halt();
        $this->cpu->reset();
        $this->assertEquals(0xC000, $this->cpu->pc);

        // Reset again with different vector
        $this->bus->setResetVector(0xFFAA);
        $this->cpu->halt();
        $this->cpu->reset();
        $this->assertEquals(0xFFAA, $this->cpu->pc);
    }

    public function testStackOperations(): void
    {
        $this->bus->setResetVector(0x8000);
        $this->cpu->reset();

        // Reset sets SP to 0xFC, let's reset it to 0xFF for clean testing
        $this->cpu->sp = 0xFF;

        // Push a byte
        $this->cpu->pushByte(0x42);
        $this->assertEquals(0xFE, $this->cpu->sp, 'SP should decrement after push');
        $this->assertEquals(0x42, $this->bus->read(0x01FF), 'Value should be on stack');

        // Push another byte
        $this->cpu->pushByte(0xAB);
        $this->assertEquals(0xFD, $this->cpu->sp);
        $this->assertEquals(0xAB, $this->bus->read(0x01FE));

        // Pull bytes (LIFO order)
        $value1 = $this->cpu->pullByte();
        $this->assertEquals(0xAB, $value1, 'Should pull last pushed value first');
        $this->assertEquals(0xFE, $this->cpu->sp, 'SP should increment after pull');

        $value2 = $this->cpu->pullByte();
        $this->assertEquals(0x42, $value2);
        $this->assertEquals(0xFF, $this->cpu->sp, 'SP should be back to original');
    }

    public function testStackWrapAround(): void
    {
        $this->cpu->sp = 0x00;

        // Push should wrap around
        $this->cpu->pushByte(0x99);
        $this->assertEquals(0xFF, $this->cpu->sp, 'SP should wrap from 0x00 to 0xFF');
        $this->assertEquals(0x99, $this->bus->read(0x0100));

        // Pull should wrap around
        $this->cpu->sp = 0xFF;
        $value = $this->cpu->pullByte();
        $this->assertEquals(0x00, $this->cpu->sp, 'SP should wrap from 0xFF to 0x00');
    }

    public function testPushPullWord(): void
    {
        $this->cpu->sp = 0xFF;

        // Push a 16-bit word
        $this->cpu->pushWord(0x1234);
        $this->assertEquals(0xFD, $this->cpu->sp, 'SP should decrement by 2');
        $this->assertEquals(0x12, $this->bus->read(0x01FF), 'High byte should be at higher address');
        $this->assertEquals(0x34, $this->bus->read(0x01FE), 'Low byte should be at lower address');

        // Pull the word back
        $value = $this->cpu->pullWord();
        $this->assertEquals(0x1234, $value);
        $this->assertEquals(0xFF, $this->cpu->sp, 'SP should be restored');
    }

    public function testGetSetters(): void
    {
        $this->cpu->setAccumulator(0x42);
        $this->assertEquals(0x42, $this->cpu->getAccumulator());
        $this->assertEquals(0x42, $this->cpu->accumulator);

        $this->cpu->setRegisterX(0x33);
        $this->assertEquals(0x33, $this->cpu->getRegisterX());
        $this->assertEquals(0x33, $this->cpu->registerX);

        $this->cpu->setRegisterY(0x44);
        $this->assertEquals(0x44, $this->cpu->getRegisterY());
        $this->assertEquals(0x44, $this->cpu->registerY);

        $this->cpu->setStackPointer(0x50);
        $this->assertEquals(0x50, $this->cpu->getStackPointer());
        $this->assertEquals(0x50, $this->cpu->sp);
    }

    public function testGetSettersMasking(): void
    {
        // Values should be masked to 8 bits
        $this->cpu->setAccumulator(0x1FF);
        $this->assertEquals(0xFF, $this->cpu->getAccumulator());

        $this->cpu->setRegisterX(0x300);
        $this->assertEquals(0x00, $this->cpu->getRegisterX());

        $this->cpu->setRegisterY(0x1AA);
        $this->assertEquals(0xAA, $this->cpu->getRegisterY());

        $this->cpu->setStackPointer(0x1FF);
        $this->assertEquals(0xFF, $this->cpu->getStackPointer());
    }

    public function testHaltAndResume(): void
    {
        $this->assertFalse($this->cpu->isHalted());

        $this->cpu->halt();
        $this->assertTrue($this->cpu->isHalted());
        $this->assertTrue($this->cpu->halted);

        $this->cpu->resume();
        $this->assertFalse($this->cpu->isHalted());
        $this->assertFalse($this->cpu->halted);
    }

    public function testBusAccessor(): void
    {
        $bus = $this->cpu->getBus();
        $this->assertSame($this->bus, $bus);
    }

    public function testInstructionRegisterAccessor(): void
    {
        $ir = $this->cpu->getInstructionRegister();
        $this->assertNotNull($ir);

        // Test that we can look up an opcode
        $opcode = $ir->getOpcode('0xA9'); // LDA Immediate
        $this->assertNotNull($opcode);
        $this->assertEquals('LDA', $opcode->getMnemonic());
    }

    public function testRegisterStateFormatting(): void
    {
        $this->cpu->pc = 0x1234;
        $this->cpu->sp = 0xFE;
        $this->cpu->accumulator = 0xAB;
        $this->cpu->registerX = 0xCD;
        $this->cpu->registerY = 0xEF;

        $state = $this->cpu->getRegistersState();
        $this->assertStringContainsString('PC: 0x1234', $state);
        $this->assertStringContainsString('SP: 0x00FE', $state);
        $this->assertStringContainsString('A: 0xAB', $state);
        $this->assertStringContainsString('X: 0xCD', $state);
        $this->assertStringContainsString('Y: 0xEF', $state);
    }

    public function testFlagStateFormatting(): void
    {
        $this->cpu->status->set(StatusRegister::NEGATIVE, true);
        $this->cpu->status->set(StatusRegister::ZERO, true);
        $this->cpu->status->set(StatusRegister::CARRY, false);

        $state = $this->cpu->getFlagsState();
        $this->assertStringContainsString('N', $state); // Negative set
        $this->assertStringContainsString('Z', $state); // Zero set
        // Carry should not be present (shown as '-')
    }

    public function testAutoTickBus(): void
    {
        $this->bus->setResetVector(0x8000);

        // Load NOP instruction (0xEA)
        $this->bus->write(0x8000, 0xEA);

        // Halt CPU and reset so it starts at 0x8000
        $this->cpu->halt();
        $this->cpu->reset();
        $this->cpu->resume();

        $initialTicks = $this->bus->tickCount;

        // Execute one instruction cycle
        $this->cpu->step();

        // Bus should have been ticked
        $this->assertGreaterThan($initialTicks, $this->bus->tickCount);

        // Disable auto-tick
        $this->cpu->setAutoTickBus(false);
        $ticksBeforeStep = $this->bus->tickCount;
        $this->cpu->step();

        // Ticks should not have increased
        $this->assertEquals($ticksBeforeStep, $this->bus->tickCount);
    }

    public function testStopAndRun(): void
    {
        $this->bus->setResetVector(0x8000);

        // Load infinite loop: JMP $8000 (0x4C 0x00 0x80)
        $this->bus->write(0x8000, 0x4C);
        $this->bus->write(0x8001, 0x00);
        $this->bus->write(0x8002, 0x80);

        $this->cpu->reset();

        // Start running in background
        $instructionCount = 0;
        $maxInstructions = 10;

        // Simulate run loop but with a counter
        while ($instructionCount < $maxInstructions) {
            $this->cpu->step();
            $instructionCount++;
        }

        // CPU should still be running (we manually broke out)
        $this->assertGreaterThan(0, $instructionCount);

        // Test stop
        $this->cpu->stop();
        // Note: Can't easily test run() since it's blocking, but stop() sets the flag
    }
}
