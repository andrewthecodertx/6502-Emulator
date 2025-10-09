# CPU Core Architecture

## Overview

The 6502 CPU emulator has been refactored into a **reusable core** that can be used to build different 6502-based systems. The core CPU implementation is completely decoupled from any specific system, allowing you to create new systems (NES, C64, Apple II, etc.) by implementing a simple bus interface.

## Directory Structure

```
src/
├── Core/                          # Reusable 6502 CPU core
│   ├── CPU.php                    # Main CPU implementation
│   ├── BusInterface.php           # Bus abstraction interface
│   ├── StatusRegister.php         # CPU status flags
│   ├── InstructionRegister.php    # Opcode registry
│   ├── Opcode.php                 # Opcode metadata
│   ├── InstructionInterpreter.php # JSON-driven execution
│   ├── CPUMonitor.php             # Optional debugging tool
│   ├── opcodes.json               # Complete 6502 opcode set
│   └── Instructions/              # Instruction handlers
│       ├── Arithmetic.php         # ADC, SBC
│       ├── LoadStore.php          # LDA, STA, etc.
│       ├── Logic.php              # AND, ORA, EOR, BIT
│       ├── ShiftRotate.php        # ASL, LSR, ROL, ROR
│       ├── FlowControl.php        # Branches, jumps
│       ├── Stack.php              # Stack operations
│       ├── Transfer.php           # Register transfers
│       ├── IncDec.php             # INC, DEC
│       └── Flags.php              # Flag operations
│
└── Systems/
    └── BenEater/                  # Ben Eater-style system
        ├── Bus/
        │   ├── SystemBus.php      # Memory-mapped I/O bus
        │   └── PeripheralInterface.php
        ├── RAM.php
        ├── ROM.php
        ├── UART.php
        ├── VideoMemory.php
        ├── ANSIRenderer.php
        ├── ConsoleIO.php
        └── Peripherals/
            ├── VIA.php            # 6522 VIA
            ├── KeyboardController.php
            ├── SoundController.php
            └── Serial.php
```

## Building a New System

To create a new 6502-based system, you only need to implement a bus that conforms to `BusInterface`.

### 1. Create Your Bus Implementation

```php
<?php

namespace Emulator\Systems\YourSystem;

use Emulator\Core\BusInterface;

class SystemBus implements BusInterface
{
    public function read(int $address): int
    {
        // Implement memory-mapped reads
        // Route to RAM, ROM, peripherals, etc.
    }

    public function write(int $address, int $value): void
    {
        // Implement memory-mapped writes
    }

    public function readWord(int $address): int
    {
        // Read 16-bit value (little-endian)
        $low = $this->read($address);
        $high = $this->read($address + 1);
        return ($high << 8) | $low;
    }

    public function tick(): void
    {
        // Called after each CPU cycle
        // Use this to tick peripherals, handle interrupts, etc.
    }
}
```

### 2. Instantiate the CPU

```php
use Emulator\Core\CPU;
use Emulator\Systems\YourSystem\SystemBus;

$bus = new SystemBus();
$cpu = new CPU($bus);

// Configure reset vector (where CPU starts)
// $8000 in this example
$bus->write(0xFFFC, 0x00);  // Low byte
$bus->write(0xFFFD, 0x80);  // High byte

// Reset and run
$cpu->reset();
while (!$cpu->isHalted()) {
    $cpu->step();
}
```

## BusInterface Reference

### Required Methods

#### `read(int $address): int`
Read a byte from the given address. This is where you implement your memory map.

**Example:**
```php
public function read(int $address): int
{
    if ($address < 0x8000) {
        return $this->ram->read($address);
    } else {
        return $this->rom->read($address);
    }
}
```

#### `write(int $address, int $value): void`
Write a byte to the given address. Handle ROM write protection and memory-mapped I/O.

**Example:**
```php
public function write(int $address, int $value): void
{
    if ($address < 0x8000) {
        $this->ram->write($address, $value);
    }
    // ROM writes are ignored
}
```

#### `readWord(int $address): int`
Read a 16-bit little-endian value. Default implementation:

```php
public function readWord(int $address): int
{
    $low = $this->read($address);
    $high = $this->read($address + 1);
    return ($high << 8) | $low;
}
```

#### `tick(): void`
Called after every CPU cycle. Use this to:
- Tick peripherals
- Check for interrupts
- Update timers
- Handle cycle-accurate emulation

**Example:**
```php
public function tick(): void
{
    foreach ($this->peripherals as $peripheral) {
        $peripheral->tick();
        if ($peripheral->hasInterruptRequest()) {
            $this->cpu->requestIRQ();
        }
    }
}
```

## CPU API Reference

### Properties
- `public int $pc` - Program counter
- `public int $sp` - Stack pointer (0x00-0xFF)
- `public int $accumulator` - A register
- `public int $registerX` - X register
- `public int $registerY` - Y register
- `public int $cycles` - Remaining cycles for current instruction
- `public readonly StatusRegister $status` - CPU flags

### Methods

#### Execution
- `reset()` - Trigger CPU reset sequence
- `step()` - Execute one CPU cycle
- `executeInstruction()` - Execute complete instruction (multiple cycles)
- `run()` - Run until halted
- `stop()` - Stop execution
- `halt()` - Halt CPU
- `resume()` - Resume from halt

#### Interrupts
- `requestNMI()` - Trigger non-maskable interrupt
- `requestIRQ()` - Trigger maskable interrupt
- `releaseIRQ()` - Clear IRQ line

#### State Access
- `getAccumulator(): int`
- `setAccumulator(int $value): void`
- `getRegisterX(): int`
- `setRegisterX(int $value): void`
- `getRegisterY(): int`
- `setRegisterY(int $value): void`
- `getStackPointer(): int`
- `setStackPointer(int $value): void`
- `getBus(): BusInterface`

#### Stack Operations
- `pushByte(int $value): void`
- `pullByte(): int`
- `pushWord(int $value): void`
- `pullWord(): int`

## Memory Map Considerations

The CPU core makes no assumptions about memory layout. Common patterns:

### Simple System (like Ben Eater)
```
$0000-$7FFF: RAM (32KB)
$8000-$FFFF: ROM (32KB)
```

### NES-Style
```
$0000-$07FF: RAM (2KB, mirrored)
$0800-$1FFF: RAM mirrors
$2000-$2007: PPU registers (mirrored)
$4000-$4017: APU and I/O registers
$4020-$FFFF: Cartridge space (ROM + mapper)
```

### C64-Style
```
$0000-$00FF: Zero page
$0100-$01FF: Stack
$0200-$9FFF: RAM
$A000-$BFFF: BASIC ROM / RAM (switchable)
$C000-$CFFF: RAM
$D000-$DFFF: I/O (VIC-II, SID, CIA)
$E000-$FFFF: KERNAL ROM / RAM (switchable)
```

## Adding Peripherals

Create peripherals that your bus can route to:

```php
interface PeripheralInterface
{
    public function handlesAddress(int $address): bool;
    public function read(int $address): int;
    public function write(int $address, int $value): void;
    public function tick(): void;
    public function hasInterruptRequest(): bool;
}
```

Then in your bus:

```php
public function read(int $address): int
{
    foreach ($this->peripherals as $peripheral) {
        if ($peripheral->handlesAddress($address)) {
            return $peripheral->read($address);
        }
    }
    // Fall through to RAM/ROM
    return $this->ram->read($address);
}
```

## Example: Minimal System

```php
<?php

use Emulator\Core\CPU;
use Emulator\Core\BusInterface;

class MinimalBus implements BusInterface
{
    private array $memory = [];

    public function read(int $address): int
    {
        return $this->memory[$address & 0xFFFF] ?? 0;
    }

    public function write(int $address, int $value): void
    {
        $this->memory[$address & 0xFFFF] = $value & 0xFF;
    }

    public function readWord(int $address): int
    {
        $low = $this->read($address);
        $high = $this->read($address + 1);
        return ($high << 8) | $low;
    }

    public function tick(): void
    {
        // No peripherals, nothing to do
    }

    public function loadProgram(int $startAddr, array $bytes): void
    {
        foreach ($bytes as $offset => $byte) {
            $this->write($startAddr + $offset, $byte);
        }
    }
}

// Create system
$bus = new MinimalBus();
$cpu = new CPU($bus);

// Load program
$bus->loadProgram(0x8000, [
    0xA9, 0x42,  // LDA #$42
    0x85, 0x00,  // STA $00
]);

// Set reset vector
$bus->write(0xFFFC, 0x00);
$bus->write(0xFFFD, 0x80);

// Run
$cpu->reset();
$cpu->executeInstruction(); // LDA
$cpu->executeInstruction(); // STA

echo sprintf("Value at $00: 0x%02X\n", $bus->read(0x00)); // 0x42
```

## Next Steps

1. Study `src/Systems/BenEater/` for a complete working example
2. Implement your bus with the memory map you need
3. Add peripherals as needed
4. Load your ROM/programs
5. Run the CPU!

## Resources

- [6502 Instruction Set](http://www.obelisk.me.uk/6502/reference.html)
- [Ben Eater's 6502 Computer](https://eater.net/6502)
- [NES Architecture](https://www.nesdev.org/wiki/CPU)
- [C64 Memory Map](https://sta.c64.org/cbm64mem.html)
