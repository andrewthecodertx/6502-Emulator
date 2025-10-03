<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use Emulator\CPU;
use Emulator\RAM;
use Emulator\ROM;
use Emulator\StatusRegister;

class CPUMachineCodeTest extends TestCase
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

                $program = [
                    0x8000 => 0xA9, // LDA #immediate
                    0x8001 => 0x2A, // Operand: 42 decimal (0x2A hex)
                    0x8002 => 0x8D, // STA absolute
                    0x8003 => 0x00, // Address low byte: $6000
                    0x8004 => 0x60, // Address high byte: $6000
                    0x8005 => 0xEA, // NOP (to end cleanly)
                ];

                return $program[$address] ?? 0xEA;
            }
        };

        $this->ram = new RAM();
        $bus = new \Emulator\Bus\SystemBus($this->ram, $rom);
        $this->cpu = new CPU($bus);
    }

    public function testLDAImmediate(): void
    {
        $this->cpu->reset();
        $this->cpu->step();

        $this->assertEquals(0x8000, $this->cpu->pc, "PC should be at program start (0x8000)");

        $this->cpu->executeInstruction();

        $this->assertEquals(42, $this->cpu->accumulator, "Accumulator should contain 42");
        $this->assertEquals(0x8002, $this->cpu->pc, "PC should advance to next instruction (0x8002)");

        $this->assertFalse($this->cpu->status->get(StatusRegister::ZERO), "Zero flag should be clear (42 ≠ 0)");
        $this->assertFalse($this->cpu->status->get(StatusRegister::NEGATIVE), "Negative flag should be clear (42 > 0)");
    }

    public function testSTAAbsolute(): void
    {
        $this->cpu->reset();
        $this->cpu->step();
        $this->cpu->executeInstruction();

        $this->assertEquals(42, $this->cpu->accumulator, "Accumulator should contain 42");
        $this->assertEquals(0x8002, $this->cpu->pc, "PC should be at STA instruction");

        $this->cpu->executeInstruction();

        $storedValue = $this->ram->readByte(0x6000);

        $this->assertEquals(42, $storedValue, "Memory location $6000 should contain 42");
        $this->assertEquals(0x8005, $this->cpu->pc, "PC should advance to next instruction (0x8005)");
        $this->assertEquals(42, $this->cpu->accumulator, "Accumulator should still contain 42");
    }

    public function testCompleteProgram(): void
    {
        $this->cpu->reset();
        $this->cpu->step();

        $this->assertEquals(0x8000, $this->cpu->pc, "PC should start at program location");
        $this->assertEquals(0, $this->cpu->accumulator, "Accumulator should start at 0");

        $this->cpu->executeInstruction(); // LDA #42
        $this->cpu->executeInstruction(); // STA $6000

        $this->assertEquals(42, $this->cpu->accumulator, "Accumulator should contain 42");
        $this->assertEquals(42, $this->ram->readByte(0x6000), "Memory $6000 should contain 42");
        $this->assertEquals(0x8005, $this->cpu->pc, "PC should be at next instruction");
    }

    public function testProgramWithDifferentValues(): void
    {
        $testValues = [0x00, 0x01, 0x7F, 0x80, 0xFF];

        foreach ($testValues as $testValue) {
            $customRom = new class ($testValue, null) extends ROM {
                private int $testValue;

                public function __construct(int $testValue, ?string $romDirectory)
                {
                    $this->testValue = $testValue;
                    parent::__construct($romDirectory);
                }

                public function readByte(int $address): int
                {
                    if ($address === 0xFFFC) {
                        return 0x00;
                    }
                    if ($address === 0xFFFD) {
                        return 0x80;
                    }

                    $program = [
                        0x8000 => 0xA9, // LDA #immediate
                        0x8001 => $this->testValue,
                        0x8002 => 0x8D, // STA absolute
                        0x8003 => 0x00, // Address low: $6000
                        0x8004 => 0x60, // Address high: $6000
                    ];

                    return $program[$address] ?? 0xEA;
                }
            };

            $customRam = new RAM();
            $bus = new \Emulator\Bus\SystemBus($customRam, $customRom);
            $customCpu = new CPU($bus);

            $customCpu->reset();
            $customCpu->step();
            $customCpu->executeInstruction(); // LDA #testValue
            $customCpu->executeInstruction(); // STA $6000

            $this->assertEquals(
                $testValue,
                $customCpu->accumulator,
                "Accumulator should contain test value 0x" . sprintf('%02X', $testValue)
            );
            $this->assertEquals(
                $testValue,
                $customRam->readByte(0x6000),
                "Memory $6000 should contain test value 0x" . sprintf('%02X', $testValue)
            );

            if ($testValue === 0) {
                $this->assertTrue(
                    $customCpu->status->get(StatusRegister::ZERO),
                    "Zero flag should be set when loading 0"
                );
            } else {
                $this->assertFalse(
                    $customCpu->status->get(StatusRegister::ZERO),
                    "Zero flag should be clear when loading non-zero value"
                );
            }

            if ($testValue & 0x80) {
                $this->assertTrue(
                    $customCpu->status->get(StatusRegister::NEGATIVE),
                    "Negative flag should be set when bit 7 is set (0x" . sprintf('%02X', $testValue) . ")"
                );
            } else {
                $this->assertFalse(
                    $customCpu->status->get(StatusRegister::NEGATIVE),
                    "Negative flag should be clear when bit 7 is clear (0x" . sprintf('%02X', $testValue) . ")"
                );
            }
        }
    }

    public function testMemoryLocations(): void
    {
        $testAddresses = [0x0200, 0x3000, 0x6000, 0x7FFF]; // Various RAM addresses

        foreach ($testAddresses as $address) {
            $addrLow = $address & 0xFF;
            $addrHigh = ($address >> 8) & 0xFF;

            $customRom = new class ($addrLow, $addrHigh, null) extends ROM {
                private int $addrLow;
                private int $addrHigh;

                public function __construct(int $addrLow, int $addrHigh, ?string $romDirectory)
                {
                    $this->addrLow = $addrLow;
                    $this->addrHigh = $addrHigh;
                    parent::__construct($romDirectory);
                }

                public function readByte(int $address): int
                {
                    if ($address === 0xFFFC) {
                        return 0x00;
                    }
                    if ($address === 0xFFFD) {
                        return 0x80;
                    }

                    $program = [
                        0x8000 => 0xA9, // LDA #immediate
                        0x8001 => 0x2A, // Value: 42
                        0x8002 => 0x8D, // STA absolute
                        0x8003 => $this->addrLow,  // Address low byte
                        0x8004 => $this->addrHigh, // Address high byte
                    ];

                    return $program[$address] ?? 0xEA;
                }
            };

            $customRam = new RAM();
            $bus = new \Emulator\Bus\SystemBus($customRam, $customRom);
            $customCpu = new CPU($bus);

            $customCpu->reset();
            $customCpu->step(); // Process reset
            $customCpu->executeInstruction(); // LDA #42
            $customCpu->executeInstruction(); // STA address

            $this->assertEquals(
                42,
                $customRam->readByte($address),
                "Memory location 0x" . sprintf('%04X', $address) . " should contain 42"
            );

            $otherAddress = ($address === 0x6000) ? 0x5000 : 0x6000;
            $this->assertEquals(
                0,
                $customRam->readByte($otherAddress),
                "Other memory location 0x" . sprintf('%04X', $otherAddress) . " should be unaffected"
            );
        }
    }

    public function testInstructionCycles(): void
    {
        $this->cpu->reset();
        $this->cpu->step();

        while ($this->cpu->cycles > 0) {
            $this->cpu->step();
        }

        $this->cpu->step(); // Start LDA instruction
        $this->assertEquals(1, $this->cpu->cycles, "LDA #immediate should have 1 cycle remaining after step()");

        while ($this->cpu->cycles > 0) {
            $this->cpu->step();
        }

        $this->assertEquals(42, $this->cpu->accumulator, "Accumulator should contain 42 after LDA");

        $this->cpu->step();
        $this->assertEquals(3, $this->cpu->cycles, "STA absolute should have 3 cycles remaining after step()");

        while ($this->cpu->cycles > 0) {
            $this->cpu->step();
        }

        $this->assertEquals(42, $this->ram->readByte(0x6000), "Memory $6000 should contain 42 after STA");
    }
}
