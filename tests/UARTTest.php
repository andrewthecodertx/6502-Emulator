<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use Emulator\Systems\BenEater\UART;
use Emulator\Systems\BenEater\RAM;
use Emulator\Systems\BenEater\ROM;
use Emulator\Core\CPU;
use Emulator\Systems\BenEater\Bus\SystemBus;

class UARTTest extends TestCase
{
    private UART $uart;
    private SystemBus $bus;
    private RAM $ram;
    private ROM $rom;
    private CPU $cpu;

    protected function setUp(): void
    {
        // Create system components
        $this->ram = new RAM();
        $this->rom = new ROM(null);
        $this->bus = new SystemBus($this->ram, $this->rom);

        // Create UART at default address 0xFE00
        $this->uart = new UART(0xFE00);
        $this->bus->addPeripheral($this->uart);

        // Create CPU
        $this->cpu = new CPU($this->bus);
    }

    public function testUARTInitialization(): void
    {
        // Test UART initializes with correct default values
        $this->assertEquals(0xFE00, $this->getBaseAddress());

        // Status register should have TDRE set (transmitter empty)
        $status = $this->uart->getStatusRegister();
        $this->assertEquals(0x10, $status & 0x10, "TDRE bit should be set on initialization");

        // Command and control registers should be zero
        $this->assertEquals(0x00, $this->uart->getCommandRegister());
        $this->assertEquals(0x00, $this->uart->getControlRegister());

        // Flow control should be enabled by default (CTSB low)
        $this->assertTrue($this->uart->isTransmitterEnabled());
        $this->assertFalse($this->uart->getCTSB());
    }

    public function testUARTAddressHandling(): void
    {
        // Test UART handles its address range correctly
        $this->assertTrue($this->uart->handlesAddress(0xFE00)); // Data register
        $this->assertTrue($this->uart->handlesAddress(0xFE01)); // Status register
        $this->assertTrue($this->uart->handlesAddress(0xFE02)); // Command register
        $this->assertTrue($this->uart->handlesAddress(0xFE03)); // Control register

        // Test addresses outside range
        $this->assertFalse($this->uart->handlesAddress(0xFDFF));
        $this->assertFalse($this->uart->handlesAddress(0xFE04));
        $this->assertFalse($this->uart->handlesAddress(0x8000));
    }

    public function testSystemBusIntegration(): void
    {
        // Test UART is properly integrated with SystemBus

        // Write to UART data register via bus
        $this->bus->write(0xFE00, ord('A'));

        // Read UART status register via bus
        $status = $this->bus->read(0xFE01);
        $this->assertEquals(0x10, $status & 0x10, "TDRE should be set after write");

        // Test command register write (read via getter since it's write-only)
        $this->bus->write(0xFE02, 0x05); // Set DTR and some other bits
        $command = $this->uart->getCommandRegister();
        $this->assertEquals(0x05, $command);

        // Test control register write (read via getter since it's write-only)
        $this->bus->write(0xFE03, 0x0B); // 8N1, 300 baud
        $control = $this->uart->getControlRegister();
        $this->assertEquals(0x0B, $control);
    }

    public function testMemoryAccess(): void
    {
        // Test system can access RAM while UART is present
        $this->bus->write(0x0200, 0x42);
        $this->assertEquals(0x42, $this->bus->read(0x0200));

        // Test system can access ROM while UART is present
        // Load test data into ROM
        $testRom = [
            0x8000 => 0xEA, // NOP
            0x8001 => 0x4C, // JMP
            0x8002 => 0x00,
            0x8003 => 0x80
        ];
        $this->rom->loadROM($testRom);

        $this->assertEquals(0xEA, $this->bus->read(0x8000));
        $this->assertEquals(0x4C, $this->bus->read(0x8001));

        // Test UART doesn't interfere with other memory regions
        $this->bus->write(0x1000, 0x55);
        $this->assertEquals(0x55, $this->bus->read(0x1000));
    }

    public function testDataTransmission(): void
    {
        // Test basic data transmission
        $this->bus->write(0xFE00, ord('H'));
        $this->bus->write(0xFE00, ord('i'));

        // Status should show transmitter ready
        $status = $this->bus->read(0xFE01);
        $this->assertEquals(0x10, $status & 0x10, "TDRE should be set");

        // Test transmitter buffer length tracking
        $this->assertEquals(0, $this->uart->getTransmitBufferLength());
    }

    public function testControlRegisterFunctionality(): void
    {
        // Test baud rate selection
        $this->bus->write(0xFE03, 0x00); // 115.2K baud
        $this->assertEquals(0x00, $this->uart->getSelectedBaudRate());

        $this->bus->write(0xFE03, 0x0F); // 19.2K baud
        $this->assertEquals(0x0F, $this->uart->getSelectedBaudRate());

        // Test word length settings
        $this->bus->write(0xFE03, 0x00); // 8 bits
        $this->assertEquals(8, $this->uart->getWordLength());

        $this->bus->write(0xFE03, 0x20); // 7 bits (WL=01)
        $this->assertEquals(7, $this->uart->getWordLength());

        $this->bus->write(0xFE03, 0x40); // 6 bits (WL=10)
        $this->assertEquals(6, $this->uart->getWordLength());

        $this->bus->write(0xFE03, 0x60); // 5 bits (WL=11)
        $this->assertEquals(5, $this->uart->getWordLength());

        // Test stop bits
        $this->bus->write(0xFE03, 0x00); // 1 stop bit
        $this->assertEquals(1, $this->uart->getStopBits());

        $this->bus->write(0xFE03, 0x80); // 2 stop bits for 8-bit words
        $this->assertEquals(2, $this->uart->getStopBits());

        $this->bus->write(0xFE03, 0xE0); // 1.5 stop bits for 5-bit words (WL=11, SBN=1)
        $this->assertEquals(1.5, $this->uart->getStopBits());

        // Test receiver clock source
        $this->bus->write(0xFE03, 0x00); // External clock (RCS=0)
        $this->assertTrue($this->uart->isUsingExternalReceiverClock());

        $this->bus->write(0xFE03, 0x10); // Internal baud rate (RCS=1)
        $this->assertFalse($this->uart->isUsingExternalReceiverClock());
    }

    public function testCommandRegisterFunctionality(): void
    {
        // Test DTR control
        $this->bus->write(0xFE02, 0x01); // Set DTR
        $command = $this->uart->getCommandRegister();
        $this->assertEquals(0x01, $command & 0x01);

        // Test IRD (Interrupt Request Disabled)
        $this->bus->write(0xFE02, 0x02); // Set IRD
        $command = $this->uart->getCommandRegister();
        $this->assertEquals(0x02, $command & 0x02);

        // Test echo mode
        $this->bus->write(0xFE02, 0x10); // Set echo mode
        $command = $this->uart->getCommandRegister();
        $this->assertEquals(0x10, $command & 0x10);

        // Test combined settings
        $this->bus->write(0xFE02, 0x13); // DTR + IRD + echo
        $command = $this->uart->getCommandRegister();
        $this->assertEquals(0x13, $command);
    }

    public function testHardwareFlowControl(): void
    {
        // Test CTSB flow control

        // Initially transmitter should be enabled
        $this->assertTrue($this->uart->isTransmitterEnabled());

        // Disable transmitter with CTSB high
        $this->uart->setCTSB(true);
        $this->assertFalse($this->uart->isTransmitterEnabled());
        $this->assertTrue($this->uart->getCTSB());

        // Try to transmit while disabled
        $this->bus->write(0xFE00, ord('X'));

        // Data should be accepted but transmitter buffer should remain empty
        // (since transmission is disabled)
        $this->assertEquals(0, $this->uart->getTransmitBufferLength());

        // Re-enable transmitter
        $this->uart->setCTSB(false);
        $this->assertTrue($this->uart->isTransmitterEnabled());
        $this->assertFalse($this->uart->getCTSB());

        // Now transmission should work
        $this->bus->write(0xFE00, ord('Y'));
        // Buffer should be cleared after immediate transmission
        $this->assertEquals(0, $this->uart->getTransmitBufferLength());
    }

    public function testStatusRegisterReading(): void
    {
        // Test IRQ bit auto-clearing on status read

        // Simulate an interrupt condition (this would normally be set by updateIrqStatus)
        $this->uart->clearIrq(); // Start clean

        // Read status - should not have IRQ initially
        $status = $this->bus->read(0xFE01);
        $this->assertEquals(0, $status & 0x80, "IRQ bit should be clear initially");

        // Test TDRE bit is always set (per W65C51N behavior)
        $this->assertEquals(0x10, $status & 0x10, "TDRE bit should always be set");

        // Test multiple status reads don't affect other bits
        $status1 = $this->bus->read(0xFE01);
        $status2 = $this->bus->read(0xFE01);
        $this->assertEquals($status1 & 0x7F, $status2 & 0x7F, "Non-IRQ bits should be stable");
    }

    public function testUARTReset(): void
    {
        // Configure UART with non-default values
        $this->bus->write(0xFE02, 0x15); // Command register
        $this->bus->write(0xFE03, 0x8F); // Control register
        $this->uart->setCTSB(true);      // Disable transmitter

        // Reset UART
        $this->uart->reset();

        // Verify reset state
        $this->assertEquals(0x00, $this->uart->getCommandRegister());
        $this->assertEquals(0x00, $this->uart->getControlRegister());
        $this->assertTrue($this->uart->isTransmitterEnabled()); // CTSB should be low
        $this->assertEquals(8, $this->uart->getWordLength());   // Default word length
        $this->assertEquals(1, $this->uart->getStopBits());     // Default stop bits

        // Status should have TDRE set
        $status = $this->uart->getStatusRegister();
        $this->assertEquals(0x10, $status & 0x10);
    }

    public function testCPUUARTIntegration(): void
    {
        // Test CPU can interact with UART

        // Load simple program that writes to UART with reset vector
        $program = [
            // Program code
            0x8000 => 0xA9, 0x8001 => 0x48,           // LDA #'H'
            0x8002 => 0x8D, 0x8003 => 0x00, 0x8004 => 0xFE, // STA $FE00
            0x8005 => 0xA9, 0x8006 => 0x69,           // LDA #'i'
            0x8007 => 0x8D, 0x8008 => 0x00, 0x8009 => 0xFE, // STA $FE00
            0x800A => 0x00,                           // BRK
            // Reset vector (little-endian: low byte first)
            0xFFFC => 0x00, 0xFFFD => 0x80
        ];
        $this->rom->loadROM($program);

        // Verify reset vector is loaded correctly
        $this->assertEquals(0x00, $this->bus->read(0xFFFC), "Reset vector low byte");
        $this->assertEquals(0x80, $this->bus->read(0xFFFD), "Reset vector high byte");

        // Reset CPU and execute program
        $this->cpu->reset();
        $this->cpu->step(); // Complete the reset sequence

        // Check PC after reset
        $this->assertEquals(0x8000, $this->cpu->pc, "PC should be at reset vector");

        // Verify program is loaded in ROM
        $this->assertEquals(0xA9, $this->bus->read(0x8000), "ROM should contain LDA opcode at 0x8000");
        $this->assertEquals(0x48, $this->bus->read(0x8001), "ROM should contain 'H' at 0x8001");

        // Execute LDA #'H'
        $firstOpcode = $this->bus->read($this->cpu->pc);
        $this->assertEquals(0xA9, $firstOpcode, "First opcode should be LDA immediate (0xA9)");

        $this->cpu->executeInstruction();
        $this->assertEquals(ord('H'), $this->cpu->accumulator);

        // Execute STA $FE00 (write to UART)
        $this->cpu->executeInstruction();

        // Execute LDA #'i'
        $this->cpu->executeInstruction();
        $this->assertEquals(ord('i'), $this->cpu->accumulator);

        // Execute STA $FE00 (write to UART)
        $this->cpu->executeInstruction();

        // Verify UART status
        $status = $this->bus->read(0xFE01);
        $this->assertEquals(0x10, $status & 0x10, "TDRE should be set after transmission");
    }

    /**
     * Helper method to access private baseAddress property for testing
     */
    private function getBaseAddress(): int
    {
        $reflection = new \ReflectionClass($this->uart);
        $property = $reflection->getProperty('baseAddress');
        $property->setAccessible(true);
        return $property->getValue($this->uart);
    }
}