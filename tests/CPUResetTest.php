<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use Emulator\Core\CPU;
use Emulator\Systems\Eater\RAM;
use Emulator\Systems\Eater\ROM;
use Emulator\Core\StatusRegister;

class CPUResetTest extends TestCase
{
    private CPU $cpu;
    private RAM $ram;

    protected function setUp(): void
    {
        $rom = new class (null) extends ROM {
            public function readByte(int $address): int
            {
                if ($address === 0xFFFC) {
                    return 0x00;
                }
                if ($address === 0xFFFD) {
                    return 0x80;
                }

                // Fill ROM with NOP
                return 0xEA;
            }
        };

        $this->ram = new RAM();
        $bus = new \Emulator\Systems\Eater\Bus\SystemBus($this->ram, $rom);
        $this->cpu = new CPU($bus);
    }

    public function testCPUStartupInitialization(): void
    {
        $this->assertEquals(0xFF, $this->cpu->sp, "Stack pointer should start at 0xFF");
        $this->assertEquals(0, $this->cpu->pc, "Program counter should start at 0");
        $this->assertEquals(0, $this->cpu->accumulator, "Accumulator should start at 0");
        $this->assertEquals(0, $this->cpu->registerX, "Register X should start at 0");
        $this->assertEquals(0, $this->cpu->registerY, "Register Y should start at 0");
        $this->assertEquals(0, $this->cpu->cycles, "Cycles should start at 0");
        $this->assertFalse($this->cpu->halted, "CPU should not be halted initially");
    }

    public function testResetSequence(): void
    {
        $this->cpu->sp = 0x80;
        $this->cpu->pc = 0x5000;
        $this->cpu->accumulator = 0xFF;
        $this->cpu->registerX = 0xFF;
        $this->cpu->registerY = 0xFF;
        $this->cpu->cycles = 0; // Cycles must be 0 for interrupt checking

        $this->cpu->reset();

        $this->assertEquals(0x80, $this->cpu->sp, "SP should not change until reset is processed");
        $this->assertEquals(0x5000, $this->cpu->pc, "PC should not change until reset is processed");

        $this->cpu->step();

        $this->assertEquals(0x8000, $this->cpu->pc, "PC should be loaded from reset vector (0x8000)");
        $this->assertEquals(0x7D, $this->cpu->sp, "SP should be decremented by 3 (0x80 - 3 = 0x7D)");

        $this->assertEquals(0, $this->cpu->accumulator, "Accumulator should be reset to 0");
        $this->assertEquals(0, $this->cpu->registerX, "Register X should be reset to 0");
        $this->assertEquals(0, $this->cpu->registerY, "Register Y should be reset to 0");
        $this->assertFalse($this->cpu->halted, "CPU should not be halted after reset");

        $this->assertTrue($this->cpu->status->get(StatusRegister::INTERRUPT_DISABLE), "I flag should be set");
        $this->assertFalse($this->cpu->status->get(StatusRegister::DECIMAL_MODE), "D flag should be clear");
        $this->assertTrue($this->cpu->status->get(StatusRegister::UNUSED), "Unused flag should be set");
        $this->assertTrue($this->cpu->status->get(StatusRegister::BREAK_COMMAND), "B flag should be set");

        $this->assertEquals(7, $this->cpu->cycles, "Reset should set cycles to 7");
    }

    public function testResetVectorLoading(): void
    {
        $customRom = new class (null) extends ROM {
            public function readByte(int $address): int
            {
                if ($address === 0xFFFC) {
                    return 0x00;
                }
                if ($address === 0xFFFD) {
                    return 0x80;
                }
                return 0xEA;
            }
        };

        $customRam = new RAM();
        $bus = new \Emulator\Systems\Eater\Bus\SystemBus($customRam, $customRom);
        $cpu = new CPU($bus);
        $cpu->reset();
        $cpu->step();

        $this->assertEquals(0x8000, $cpu->pc, "PC should be loaded from custom reset vector (0x8000)");
    }

    public function testStackPointerDecrementDuringReset(): void
    {
        $testCases = [
            ['initial' => 0xFF, 'expected' => 0xFC], // 0xFF - 3 = 0xFC
            ['initial' => 0x80, 'expected' => 0x7D], // 0x80 - 3 = 0x7D
            ['initial' => 0x03, 'expected' => 0x00], // 0x03 - 3 = 0x00
            ['initial' => 0x02, 'expected' => 0xFF], // 0x02 - 3 = 0xFF (wraps)
            ['initial' => 0x01, 'expected' => 0xFE], // 0x01 - 3 = 0xFE (wraps)
            ['initial' => 0x00, 'expected' => 0xFD], // 0x00 - 3 = 0xFD (wraps)
        ];

        foreach ($testCases as $case) {
            $rom = new class (null) extends ROM {
                public function readByte(int $address): int
                {
                    if ($address === 0xFFFC) {
                        return 0x00;
                    }
                    if ($address === 0xFFFD) {
                        return 0x10;
                    }
                    return 0xEA;
                }
            };
            $ram = new RAM();
            $bus = new \Emulator\Systems\Eater\Bus\SystemBus($ram, $rom);
            $cpu = new CPU($bus);
            $cpu->sp = $case['initial'];

            $cpu->reset();
            $cpu->step();

            $this->assertEquals(
                $case['expected'],
                $cpu->sp,
                "SP should be decremented by 3 with wrapping: {$case['initial']} - 3 = {$case['expected']}"
            );
        }
    }

    public function testNOPExecutionAfterReset(): void
    {
        $this->cpu->reset();
        $this->cpu->step();

        $startingPC = $this->cpu->pc;
        $startingCycles = $this->cpu->cycles;

        $this->assertEquals(0x8000, $startingPC, "PC should be at reset vector after reset");
        $this->assertEquals(7, $startingCycles, "Should have 7 cycles from reset");

        while ($this->cpu->cycles > 0) {
            $this->cpu->step();
        }

        $this->cpu->step();
        while ($this->cpu->cycles > 0) {
            $this->cpu->step();
        }

        $this->assertEquals($startingPC + 1, $this->cpu->pc, "PC should advance by 1 after NOP");

        $secondPC = $this->cpu->pc;

        $this->cpu->step();
        while ($this->cpu->cycles > 0) {
            $this->cpu->step();
        }

        $this->assertEquals($secondPC + 1, $this->cpu->pc, "PC should advance by 1 after second NOP");
    }

    public function testResetClearsPendingInterrupts(): void
    {
        $this->cpu->status->set(StatusRegister::INTERRUPT_DISABLE, false);

        $this->cpu->requestIRQ();
        $this->cpu->requestNMI();

        $this->cpu->reset();
        $this->cpu->step();

        $startingPC = $this->cpu->pc;
        $this->assertEquals(0x8000, $startingPC, "PC should be at reset vector");

        while ($this->cpu->cycles > 0) {
            $this->cpu->step();
        }

        $this->cpu->step();

        $this->assertEquals(
            $startingPC + 1,
            $this->cpu->pc,
            "Should execute normal instruction, not process interrupts"
        );
        $this->assertGreaterThan(
            0,
            $this->cpu->cycles,
            "Should have cycles pending for NOP instruction"
        );
    }

    public function testResetFromDifferentCPUStates(): void
    {
        $this->cpu->status->set(StatusRegister::CARRY, true);
        $this->cpu->status->set(StatusRegister::ZERO, true);
        $this->cpu->status->set(StatusRegister::NEGATIVE, true);
        $this->cpu->status->set(StatusRegister::OVERFLOW, true);
        $this->cpu->status->set(StatusRegister::DECIMAL_MODE, true);

        $this->cpu->reset();
        $this->cpu->step(); // Process reset

        $this->assertTrue(
            $this->cpu->status->get(
                StatusRegister::INTERRUPT_DISABLE
            ),
            "I flag should be set after reset"
        );
        $this->assertFalse($this->cpu->status->get(StatusRegister::DECIMAL_MODE), "D flag should be clear after reset");
        $this->assertTrue($this->cpu->status->get(StatusRegister::UNUSED), "Unused flag should be set after reset");
        $this->assertTrue($this->cpu->status->get(StatusRegister::BREAK_COMMAND), "B flag should be set after reset");

        $rom = new class (null) extends ROM {
            public function readByte(int $address): int
            {
                if ($address === 0xFFFC) {
                    return 0x00;
                }
                if ($address === 0xFFFD) {
                    return 0x80;
                }
                return 0xEA;
            }
        };
        $ram = new RAM();
        $bus = new \Emulator\Systems\Eater\Bus\SystemBus($ram, $rom);
        $cpu2 = new CPU($bus);
        $cpu2->halt();
        $this->assertTrue($cpu2->isHalted(), "CPU should be halted before reset");

        $cpu2->reset();
        $cpu2->step();

        $this->assertFalse($cpu2->isHalted(), "CPU should not be halted after reset");
    }
}
