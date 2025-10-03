# PHP-6502 Emulator Instructions

This document provides comprehensive instructions on how to instantiate,
interact with, and program the PHP-6502 emulator.

## System Instantiation

The main components of the emulator are the `CPU`, `SystemBus`, `RAM`, `ROM`,
and Peripherals (like `UART`). The `SystemBus` is the central component that
connects everything.

### Basic Setup

```php
<?php

declare(strict_types=1);

require_once 'vendor/autoload.php';

use Emulator\CPU;
use Emulator\CPUMonitor;
use Emulator\RAM;
use Emulator\ROM;
use Emulator\UART;
use Emulator\Bus\SystemBus;

// 1. Create memory components
$rom = new ROM(null);
$rom->loadBinaryROM('roms/bios.bin');
$ram = new RAM();

// 2. Create and configure the system bus
$bus = new SystemBus($ram, $rom);

// 3. Add peripherals
$uart = new UART(0xFE00);
$bus->addPeripheral($uart);

// 4. Optional: Create CPU monitor for debugging
$monitor = new CPUMonitor();

// 5. Create CPU with optional monitoring
$cpu = new CPU($bus, $monitor);

// 6. Reset the CPU to initial state
$cpu->reset();

echo "System initialized and ready.\n";
```

## CPU Control and Execution

### Execution Methods

```php
// Execute a single complete instruction
$cpu->executeInstruction();

// Execute a single clock cycle (for precise timing)
$cpu->step();

// Start continuous execution
$cpu->run();

// Stop continuous execution
$cpu->stop();

// Pause/resume execution
$cpu->halt();
$cpu->resume();

// Check if CPU is halted
if ($cpu->isHalted()) {
    echo "CPU is halted\n";
}
```

### CPU State Access

```php
// Access CPU registers directly
$accumulator = $cpu->accumulator;
$registerX = $cpu->registerX;
$registerY = $cpu->registerY;
$programCounter = $cpu->pc;
$stackPointer = $cpu->sp;
$cycles = $cpu->cycles;

// Modify CPU registers
$cpu->setAccumulator(0x42);
$cpu->setRegisterX(0x10);
$cpu->setRegisterY(0x20);

// Get formatted state strings
echo $cpu->getRegistersState() . "\n";  // PC: 0x8000, SP: 0x01FC, A: 0x42, X: 0x10, Y: 0x20
echo $cpu->getFlagsState() . "\n";      // Flags: N-UBDIZC

// Access status register
$cpu->status->set(StatusRegister::CARRY, true);
$isCarrySet = $cpu->status->get(StatusRegister::CARRY);
$statusByte = $cpu->status->toInt();
$cpu->status->fromInt(0b00110100);
```

### Stack Operations

```php
// Stack manipulation (hardware stack at 0x0100-0x01FF)
$cpu->pushByte(0x42);           // Push byte to stack
$value = $cpu->pullByte();      // Pull byte from stack
$cpu->pushWord(0x1234);         // Push 16-bit word
$address = $cpu->pullWord();    // Pull 16-bit word
```

### Interrupt Control

```php
// Trigger interrupts
$cpu->requestReset();           // Request system reset
$cpu->requestNMI();             // Trigger Non-Maskable Interrupt
$cpu->releaseNMI();             // Release NMI line
$cpu->requestIRQ();             // Request maskable interrupt
$cpu->releaseIRQ();             // Release IRQ line
```

## Memory Management

### Direct Memory Access

```php
// SystemBus provides unified memory access
$bus->read(0x0000);                    // Read from zero page
$bus->write(0x0200, 0x42);             // Write to general RAM
$bus->readWord(0xFFFC);                // Read 16-bit word (reset vector)

// Direct RAM access (for debugging/setup)
$ram->readByte(0x0000);
$ram->writeByte(0x0000, 0xFF);

// ROM access (read-only)
$rom->readByte(0x8000);
$rom->loadBinaryROM('program.bin');    // Load new ROM image

// Multiple ROM loading with metadata
$rom->loadFromDirectory('roms/');      // Load all ROMs from directory
```

### Memory Monitoring

```php
// Enable memory monitoring on RAM
$ram->setMonitor($monitor);

// Monitor logs all memory accesses automatically
$accesses = $monitor->getMemoryAccesses();
foreach ($accesses as $access) {
    echo sprintf("0x%04X: %s 0x%02X\n",
        $access['address'],
        $access['type'],
        $access['data']
    );
}
```

## Peripheral Programming

### UART Communication

```php
// UART at base address 0xFE00
$uart = new UART(0xFE00);
$bus->addPeripheral($uart);

// Send character
$bus->write(0xFE00, ord('H'));         // Data register
$bus->write(0xFE00, ord('i'));         // Send another character

// Read character (non-blocking)
$char = chr($bus->read(0xFE00));       // Returns 0x00 if no data

// Check UART status
$status = $bus->read(0xFE01);          // Status register
$hasData = ($status & 0x08) !== 0;     // Receiver data ready
$canSend = ($status & 0x10) !== 0;     // Transmitter empty

// Configure UART
$bus->write(0xFE02, 0x09);             // Command register: enable DTR
$bus->write(0xFE03, 0x1F);             // Control register: 9600 baud, 8N1

// Direct UART methods (for debugging)
$uart->getStatusRegister();            // Get status byte
$uart->getReceiveBufferLength();       // Check receive buffer
$uart->getTransmitBufferLength();      // Check transmit buffer
$uart->isIrqPending();                 // Check interrupt status
$uart->reset();                        // Reset UART state
```

### Custom Peripherals

```php
// Create custom peripheral implementing PeripheralInterface
class CustomDevice implements PeripheralInterface {
    private int $baseAddress;

    public function __construct(int $baseAddress) {
        $this->baseAddress = $baseAddress;
    }

    public function handlesAddress(int $address): bool {
        return $address >= $this->baseAddress && $address < ($this->baseAddress + 16);
    }

    public function read(int $address): int {
        // Handle memory-mapped reads
        return 0x00;
    }

    public function write(int $address, int $value): void {
        // Handle memory-mapped writes
    }

    public function tick(): void {
        // Called every bus cycle for peripheral processing
    }
}

// Add to system
$device = new CustomDevice(0xC000);
$bus->addPeripheral($device);
```

## CPU Monitoring and Debugging

### Basic Monitoring

```php
// Create monitor
$monitor = new CPUMonitor();
$cpu->setMonitor($monitor);

// Monitor automatically logs:
// - All executed instructions with PC and opcode
// - Memory read/write operations (if enabled on RAM/ROM)
// - CPU cycle counts

// Access logged data
$instructions = $monitor->getInstructions();
$memoryAccesses = $monitor->getMemoryAccesses();
$totalCycles = $monitor->getTotalCycles();

// Control logging
$monitor->setLogging(false);           // Disable logging
$monitor->clearLog();                  // Clear all logs
$monitor->reset();                     // Reset counters and logs
```

### Advanced Debugging

```php
// Get last memory access
$lastAccess = $monitor->getLastMemoryAccess();
if ($lastAccess) {
    echo sprintf("Last access: 0x%04X %s 0x%02X\n",
        $lastAccess['address'],
        $lastAccess['type'],
        $lastAccess['data']
    );
}

// Monitor statistics
echo "Total accesses: " . $monitor->getAccessCount() . "\n";
echo "Total cycles: " . $monitor->getTotalCycles() . "\n";

// Check if monitoring is active
if ($cpu->isMonitored()) {
    echo "CPU monitoring is enabled\n";
}
```

## Advanced System Configuration

### Bus Control

```php
// Manual bus ticking (for precise timing control)
$cpu->setAutoTickBus(false);          // Disable automatic bus ticking
$bus->tick();                         // Manually tick peripherals

// Access system bus from CPU
$systemBus = $cpu->getBus();
$instructionRegister = $cpu->getInstructionRegister();
```

### ROM Management

```php
// Load multiple ROM files with priorities
// Create ROM metadata files (JSON format):
// {
//   "name": "BIOS",
//   "load_address": "0x8000",
//   "size": 8192,
//   "priority": 1
// }

$rom = new ROM('roms/');              // Auto-load from directory
// Loads all .json/.bin pairs in priority order

// Manual ROM loading
$rom->loadROM([
    0x8000 => 0xA9,  // LDA #$42
    0x8001 => 0x42,
    0x8002 => 0x00   // BRK
]);
```

### System Reset and State

```php
// System reset (maintains ROM, clears RAM state)
$cpu->reset();                        // CPU reset sequence
$uart->reset();                       // Reset peripheral
$monitor->reset();                    // Clear monitoring data

// Check system state
echo "CPU halted: " . ($cpu->halted ? "yes" : "no") . "\n";
echo "Cycles executed: " . $cpu->cycles . "\n";
```

## Programming Patterns

### Polling Loop

```php
// Simple polling loop for UART I/O
while (true) {
    // Execute one instruction
    $cpu->executeInstruction();

    // Check for UART input
    $status = $bus->read(0xFE01);
    if ($status & 0x08) {  // Data ready
        $char = chr($bus->read(0xFE00));
        echo "Received: $char\n";
    }

    // Check for system halt
    if ($cpu->isHalted()) {
        break;
    }
}
```

### Interrupt-Driven Execution

```php
// Set up interrupt handling
$cpu->status->set(StatusRegister::INTERRUPT_DISABLE, false);  // Enable IRQs

while (!$cpu->isHalted()) {
    $cpu->executeInstruction();

    // Peripherals can trigger interrupts via requestIRQ()
    if ($uart->isIrqPending()) {
        $cpu->requestIRQ();
    }
}
```

### Memory Programming

```php
// Load program into RAM
$program = [
    0xA9, 0x48,  // LDA #'H'
    0x8D, 0x00, 0xFE,  // STA $FE00 (UART data)
    0xA9, 0x69,  // LDA #'i'
    0x8D, 0x00, 0xFE,  // STA $FE00
    0x00  // BRK
];

$address = 0x0200;  // Load into general RAM
foreach ($program as $byte) {
    $bus->write($address++, $byte);
}

// Set PC to start of program
$cpu->pc = 0x0200;
```

This comprehensive API allows full control over the 6502 emulator system, from basic execution to advanced debugging and peripheral programming.
