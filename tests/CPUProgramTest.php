<?php

declare(strict_types=1);

namespace andrewthecoder\Tests;

use andrewthecoder\WDC65C02\CPU;
use andrewthecoder\WDC65C02\StatusRegister;
use PHPUnit\Framework\TestCase;

/**
 * Tests for complete programs running on the CPU with output
 */
class CPUProgramTest extends TestCase
{
    private SimpleBus $bus;
    private CPU $cpu;

    /** @var array<string> */
    private array $output = [];

    protected function setUp(): void
    {
        $this->bus = new SimpleBus();
        $this->cpu = new CPU($this->bus);
        $this->bus->setResetVector(0x8000);
        $this->output = [];
    }

    /**
     * Helper to capture output from memory-mapped I/O
     */
    private function captureOutput(int $address): void
    {
        $value = $this->bus->read($address);
        if ($value >= 0x20 && $value <= 0x7E) {
            $this->output[] = chr($value);
        }
    }

    public function testSimpleCounterProgram(): void
    {
        /*
         * Program: Count from 0 to 5 and store results in memory
         *
         * 8000: LDX #$00      ; Load X with 0
         * 8002: STX $10       ; Store in $10
         * 8004: INX           ; Increment X
         * 8005: STX $10       ; Store in $10
         * 8007: CPX #$05      ; Compare with 5
         * 8009: BNE $8004     ; Loop if not equal
         * 800B: (done)
         */
        $this->bus->loadProgram(0x8000, [
            0xA2, 0x00,        // LDX #$00
            0x86, 0x10,        // STX $10
            0xE8,              // INX
            0x86, 0x10,        // STX $10
            0xE0, 0x05,        // CPX #$05
            0xD0, 0xF9,        // BNE $8004 (relative -7)
        ]);

        $this->cpu->halt();
        $this->cpu->reset();
        $this->cpu->resume();

        // Run the program (limit iterations to prevent infinite loops in tests)
        $maxIterations = 1000;
        $iterations = 0;

        while ($this->cpu->pc !== 0x800B && $iterations < $maxIterations) {
            $this->cpu->executeInstruction();
            $iterations++;
        }

        // X should be 5
        $this->assertEquals(0x05, $this->cpu->registerX);

        // Memory at $10 should be 5
        $this->assertEquals(0x05, $this->bus->read(0x10));

        // Should have taken less than max iterations
        $this->assertLessThan($maxIterations, $iterations);
    }

    public function testAdditionProgram(): void
    {
        /*
         * Program: Add two numbers and store result
         *
         * 8000: LDA #$15      ; Load A with 21
         * 8002: CLC           ; Clear carry
         * 8003: ADC #$27      ; Add 39 (21 + 39 = 60 = 0x3C)
         * 8005: STA $20       ; Store result
         */
        $this->bus->loadProgram(0x8000, [
            0xA9, 0x15,        // LDA #$15
            0x18,              // CLC
            0x69, 0x27,        // ADC #$27
            0x85, 0x20,        // STA $20
        ]);

        $this->cpu->halt();
        $this->cpu->reset();
        $this->cpu->resume();

        // Execute each instruction
        $this->cpu->executeInstruction(); // LDA
        $this->cpu->executeInstruction(); // CLC
        $this->cpu->executeInstruction(); // ADC
        $this->cpu->executeInstruction(); // STA

        $this->assertEquals(0x3C, $this->cpu->accumulator, 'Result should be 0x3C (60)');
        $this->assertEquals(0x3C, $this->bus->read(0x20), 'Result should be stored at $20');
        $this->assertFalse($this->cpu->status->get(StatusRegister::CARRY), 'No carry should occur');
    }

    public function testAdditionWithCarry(): void
    {
        /*
         * Program: Add two numbers that generate a carry
         *
         * 8000: LDA #$FF      ; Load A with 255
         * 8002: CLC           ; Clear carry
         * 8003: ADC #$02      ; Add 2 (255 + 2 = 257, wraps to 1 with carry)
         * 8005: STA $20       ; Store result
         */
        $this->bus->loadProgram(0x8000, [
            0xA9, 0xFF,        // LDA #$FF
            0x18,              // CLC
            0x69, 0x02,        // ADC #$02
            0x85, 0x20,        // STA $20
        ]);

        $this->cpu->halt();
        $this->cpu->reset();
        $this->cpu->resume();

        $this->cpu->executeInstruction(); // LDA
        $this->cpu->executeInstruction(); // CLC
        $this->cpu->executeInstruction(); // ADC
        $this->cpu->executeInstruction(); // STA

        $this->assertEquals(0x01, $this->cpu->accumulator, 'Result should wrap to 1');
        $this->assertEquals(0x01, $this->bus->read(0x20));
        $this->assertTrue($this->cpu->status->get(StatusRegister::CARRY), 'Carry flag should be set');
    }

    public function testSubroutineCall(): void
    {
        /*
         * Program: Test JSR/RTS
         *
         * 8000: JSR $8010     ; Call subroutine
         * 8003: LDA #$99      ; After return
         * 8005: (done)
         *
         * 8010: LDX #$42      ; Subroutine
         * 8012: RTS           ; Return
         */
        $this->bus->loadProgram(0x8000, [
            0x20, 0x10, 0x80,  // JSR $8010
            0xA9, 0x99,        // LDA #$99
        ]);

        $this->bus->loadProgram(0x8010, [
            0xA2, 0x42,        // LDX #$42
            0x60,              // RTS
        ]);

        $this->cpu->halt();
        $this->cpu->reset();
        $this->cpu->resume();

        $this->cpu->executeInstruction(); // JSR
        $this->assertEquals(0x8010, $this->cpu->pc, 'PC should jump to subroutine');

        $this->cpu->executeInstruction(); // LDX in subroutine
        $this->assertEquals(0x42, $this->cpu->registerX);

        $this->cpu->executeInstruction(); // RTS
        $this->assertEquals(0x8003, $this->cpu->pc, 'PC should return after JSR');

        $this->cpu->executeInstruction(); // LDA after return
        $this->assertEquals(0x99, $this->cpu->accumulator);
    }

    public function testOutputToMemoryMappedIO(): void
    {
        /*
         * Program: Write "HI" to output (memory address $FE00)
         *
         * 8000: LDA #$48      ; 'H'
         * 8002: STA $FE00     ; Write to output
         * 8005: LDA #$49      ; 'I'
         * 8007: STA $FE00     ; Write to output
         */
        $this->bus->loadProgram(0x8000, [
            0xA9, 0x48,        // LDA #$48 ('H')
            0x8D, 0x00, 0xFE,  // STA $FE00
            0xA9, 0x49,        // LDA #$49 ('I')
            0x8D, 0x00, 0xFE,  // STA $FE00
        ]);

        $this->cpu->halt();
        $this->cpu->reset();
        $this->cpu->resume();

        $this->cpu->executeInstruction(); // LDA 'H'
        $this->cpu->executeInstruction(); // STA
        $this->captureOutput(0xFE00);

        $this->cpu->executeInstruction(); // LDA 'I'
        $this->cpu->executeInstruction(); // STA
        $this->captureOutput(0xFE00);

        $this->assertEquals(['H', 'I'], $this->output, 'Should output "HI"');
    }

    public function testFibonacciSequence(): void
    {
        /*
         * Program: Calculate first few Fibonacci numbers
         * Store sequence in memory starting at $40
         *
         * 8000: LDA #$00      ; fib(0) = 0
         * 8002: STA $40
         * 8004: LDA #$01      ; fib(1) = 1
         * 8006: STA $41
         * 8008: LDX #$02      ; index = 2
         * 800A: LDA $3E,X     ; Load fib(n-2)
         * 800C: CLC
         * 800D: ADC $3F,X     ; Add fib(n-1)
         * 800F: STA $40,X     ; Store fib(n)
         * 8011: INX
         * 8012: CPX #$08      ; Calculate up to fib(7)
         * 8014: BNE $800A     ; Loop
         */
        $this->bus->loadProgram(0x8000, [
            0xA9, 0x00,        // LDA #$00
            0x85, 0x40,        // STA $40
            0xA9, 0x01,        // LDA #$01
            0x85, 0x41,        // STA $41
            0xA2, 0x02,        // LDX #$02
            0xB5, 0x3E,        // LDA $3E,X (zero page indexed)
            0x18,              // CLC
            0x75, 0x3F,        // ADC $3F,X
            0x95, 0x40,        // STA $40,X
            0xE8,              // INX
            0xE0, 0x08,        // CPX #$08
            0xD0, 0xF4,        // BNE $800A (relative -12)
        ]);

        $this->cpu->halt();
        $this->cpu->reset();
        $this->cpu->resume();

        // Run until completion
        $maxIterations = 1000;
        $iterations = 0;

        while ($this->cpu->pc !== 0x8016 && $iterations < $maxIterations) {
            $this->cpu->executeInstruction();
            $iterations++;
        }

        // Check Fibonacci sequence: 0, 1, 1, 2, 3, 5, 8, 13
        $expected = [0, 1, 1, 2, 3, 5, 8, 13];
        for ($i = 0; $i < 8; $i++) {
            $this->assertEquals(
                $expected[$i],
                $this->bus->read(0x40 + $i),
                "Fibonacci[$i] should be {$expected[$i]}"
            );
        }
    }

    public function testBranchingLogic(): void
    {
        /*
         * Program: Branch based on comparison
         *
         * 8000: LDA #$10
         * 8002: CMP #$10      ; Compare with itself
         * 8004: BEQ $8008     ; Should branch (equal)
         * 8006: LDX #$FF      ; Should not execute
         * 8008: LDX #$42      ; Should execute
         */
        $this->bus->loadProgram(0x8000, [
            0xA9, 0x10,        // LDA #$10
            0xC9, 0x10,        // CMP #$10
            0xF0, 0x02,        // BEQ $8008 (relative +2)
            0xA2, 0xFF,        // LDX #$FF (skipped)
            0xA2, 0x42,        // LDX #$42
        ]);

        $this->cpu->halt();
        $this->cpu->reset();
        $this->cpu->resume();

        $this->cpu->executeInstruction(); // LDA
        $this->cpu->executeInstruction(); // CMP
        $this->assertTrue($this->cpu->status->get(StatusRegister::ZERO), 'Zero flag should be set');

        $this->cpu->executeInstruction(); // BEQ
        $this->assertEquals(0x8008, $this->cpu->pc, 'Should branch to $8008');

        $this->cpu->executeInstruction(); // LDX #$42
        $this->assertEquals(0x42, $this->cpu->registerX, 'Should have loaded $42, not $FF');
    }

    public function testMemoryCopy(): void
    {
        /*
         * Program: Copy 4 bytes from $50-$53 to $60-$63
         *
         * Setup: Store test data at source
         */
        $this->bus->write(0x50, 0xAA);
        $this->bus->write(0x51, 0xBB);
        $this->bus->write(0x52, 0xCC);
        $this->bus->write(0x53, 0xDD);

        /*
         * 8000: LDX #$00      ; Index
         * 8002: LDA $50,X     ; Load from source
         * 8004: STA $60,X     ; Store to dest
         * 8006: INX
         * 8007: CPX #$04      ; Copied 4 bytes?
         * 8009: BNE $8002     ; Loop
         */
        $this->bus->loadProgram(0x8000, [
            0xA2, 0x00,        // LDX #$00
            0xB5, 0x50,        // LDA $50,X
            0x95, 0x60,        // STA $60,X
            0xE8,              // INX
            0xE0, 0x04,        // CPX #$04
            0xD0, 0xF7,        // BNE $8002 (relative -9)
        ]);

        $this->cpu->halt();
        $this->cpu->reset();
        $this->cpu->resume();

        // Run until completion
        $maxIterations = 100;
        $iterations = 0;

        while ($this->cpu->pc !== 0x800B && $iterations < $maxIterations) {
            $this->cpu->executeInstruction();
            $iterations++;
        }

        // Verify copy
        $this->assertEquals(0xAA, $this->bus->read(0x60));
        $this->assertEquals(0xBB, $this->bus->read(0x61));
        $this->assertEquals(0xCC, $this->bus->read(0x62));
        $this->assertEquals(0xDD, $this->bus->read(0x63));
    }
}
