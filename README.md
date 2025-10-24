# PHP-6502: A 6502 Emulator in PHP

A fully functional 6502 microprocessor emulator written entirely in PHP,
packaged as a Composer library for building custom 6502-based systems. Features
two CPU cores (NMOS 6502 and WDC 65C02) that can be paired with different system implementations.

## Installation

Install via Composer:

```bash
composer require andrewthecoder/6502-emulator
```

## Features

### Dual CPU Core Support

* **MOS 6502 (NMOS)** - Original MOS Technology 6502 with 56 documented instructions and illegal opcodes
* **WDC 65C02 (CMOS)** - Western Design Center 65C02 with 70 instructions including BRA, STZ, PHX/PHY, bit manipulation, and more
* **Hybrid execution model** combining JSON-driven and custom handler-based instruction processing
* **Interrupt support** (NMI, IRQ, RESET) with proper edge/level triggering
* **CPU monitoring** for debugging and profiling with instruction tracing and cycle counting
* **Comprehensive PHPDoc documentation** for IDE support

## Quick Start

### Using the CPU in Your Project

```php
<?php

require 'vendor/autoload.php';

// Choose your CPU variant:
// For NMOS 6502 (original):
use andrewthecoder\MOS6502\CPU;
use andrewthecoder\MOS6502\BusInterface;

// OR for WDC 65C02 (CMOS with additional instructions):
// use andrewthecoder\WDC65C02\CPU;
// use andrewthecoder\WDC65C02\BusInterface;

// Implement a simple bus with 64KB RAM
class SimpleBus implements BusInterface
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
        // Called after each CPU cycle
    }
}

// Create CPU with your bus
$bus = new SimpleBus();
$cpu = new CPU($bus);

// Load a simple program: LDA #$42, STA $00
$bus->write(0x8000, 0xA9);  // LDA #$42
$bus->write(0x8001, 0x42);
$bus->write(0x8002, 0x85);  // STA $00
$bus->write(0x8003, 0x00);

// Set reset vector
$bus->write(0xFFFC, 0x00);
$bus->write(0xFFFD, 0x80);

// Run
$cpu->reset();
$cpu->executeInstruction();
$cpu->executeInstruction();

echo sprintf("Value at $00: 0x%02X\n", $bus->read(0x00)); // 0x42
```

### Development Setup

For developing this library itself or running the included examples:

1. **Clone the repository:**

    ```bash
    git clone https://github.com/your-username/6502-Emulator.git
    cd 6502-Emulator
    ```

2. **Install dependencies:**

    ```bash
    composer install
    ```

3. **Optional: Install cc65 toolchain** for assembling 6502 programs:
Download from [official cc65 website](https://cc65.github.io/) and place `ca65`
and `ld65` in `bin/`

## Architecture

The emulator uses a modular, reusable architecture designed for Composer integration with two separate CPU cores.

### Core Components

The emulator provides two complete CPU implementations:

**`andrewthecoder\MOS6502` namespace** - Original NMOS 6502:
* **CPU** - MOS Technology 6502 (NMOS) with 56 documented instructions
* Includes illegal/undocumented opcodes
* JMP indirect has page boundary bug (hardware-accurate)
* Decimal mode doesn't set N, V, Z flags (hardware-accurate)

**`andrewthecoder\WDC65C02` namespace** - CMOS 65C02:
* **CPU** - WDC 65C02S with 70 instructions
* Additional instructions: BRA, STZ, PHX/PHY/PLX/PLY, TRB/TSB, WAI/STP
* Bit manipulation: BBR/BBS, RMB/SMB instructions
* Fixed JMP indirect page boundary bug
* Proper decimal mode flag handling

**Shared interfaces (in both namespaces):**
* **BusInterface** - Abstraction for system buses to implement memory-mapped I/O
* **RAMInterface** - Contract for RAM implementations
* **ROMInterface** - Contract for ROM implementations
* **PeripheralInterface** - Contract for memory-mapped peripherals
* **InstructionRegister** - Loads and provides access to opcode definitions from `opcodes.json`
* **InstructionInterpreter** - Executes instructions using declarative JSON metadata
* **StatusRegister** - Manages the 8 CPU status flags (NV-BDIZC)
* **CPUMonitor** - Optional debugging and profiling tool
* **Instructions/** - Custom handlers for complex opcodes

### Building Your Own System

To create a custom 6502 system:

1. Install the package via Composer
2. Choose your CPU variant (MOS6502 or WDC65C02)
3. Implement `BusInterface` with your desired memory map
4. Optionally implement `RAMInterface`, `ROMInterface`, and `PeripheralInterface`
5. Instantiate `CPU` with your bus

See `docs/CPU_CORE_ARCHITECTURE.md` for detailed instructions and examples.

## Development

### Running Tests

The project uses PHPUnit for unit testing. To run the test suite:

```bash
./vendor/bin/phpunit
```

### Static Analysis

PHPStan is used for static analysis. To check the codebase:

```bash
./vendor/bin/phpstan analyse src
```

### Code Quality

* **Comprehensive PHPDoc** - All public methods and classes are fully documented
* **Type Safety** - Strict typing throughout with detailed array type annotations
* **Test Coverage** - 56 tests covering CPU operations, addressing modes, and
peripherals (coming soon)

## Project Structure

```
src/                            # andrewthecoder\MOS6502 namespace
├── CPU.php                     # Main CPU emulator
├── BusInterface.php            # Bus abstraction
├── InstructionRegister.php     # Opcode registry
├── InstructionInterpreter.php  # JSON-driven execution
├── StatusRegister.php          # CPU flags
├── Opcode.php                  # Opcode metadata
├── CPUMonitor.php              # Debugging tool
├── opcodes.json                # Complete opcode definitions
├── Instructions/               # Complex instruction handlers
│   ├── Arithmetic.php          # ADC, SBC with overflow
│   ├── ShiftRotate.php         # ASL, LSR, ROL, ROR
│   ├── FlowControl.php         # Branches and jumps
│   ├── Stack.php               # Stack operations
│   └── ...                     # Other handlers
│
```

## Using in External Projects

After installing via Composer, you can use the CPU core to build any 6502-based system:

### Minimal Example

```php
<?php

use andrewthecoder\MOS6502\CPU;
use andrewthecoder\MOS6502\BusInterface;

class MyBus implements BusInterface {
    private array $ram = [];

    public function read(int $address): int {
        return $this->ram[$address & 0xFFFF] ?? 0;
    }

    public function write(int $address, int $value): void {
        $this->ram[$address & 0xFFFF] = $value & 0xFF;
    }

    public function readWord(int $address): int {
        return $this->read($address) | ($this->read($address + 1) << 8);
    }

    public function tick(): void {
        // Update peripherals, check interrupts, etc.
    }
}

$cpu = new CPU(new MyBus());
$cpu->reset();
```

### Advanced: Memory-Mapped Peripherals

```php
<?php

class MyBus implements BusInterface {
    private RAM $ram;
    private ROM $rom;
    private array $peripherals = [];

    public function addPeripheral(PeripheralInterface $peripheral): void {
        $this->peripherals[] = $peripheral;
    }

    public function read(int $address): int {
        // Check peripherals first
        foreach ($this->peripherals as $peripheral) {
            if ($peripheral->handlesAddress($address)) {
                return $peripheral->read($address);
            }
        }

        // Fall through to RAM/ROM
        return $address < 0x8000
            ? $this->ram->read($address)
            : $this->rom->read($address);
    }

    // ... write(), readWord(), tick() implementations
}
```

## Contributing

Contributions are welcome! The modular architecture makes it easy to:

* Add new 6502-based systems (see `docs/CPU_CORE_ARCHITECTURE.md`)
* Implement additional peripherals
* Improve emulation accuracy
* Add more test coverage
* Enhance the hybrid JSON/PHP execution model

## License

This project is licensed under the MIT License - see the [LICENSE](https://github.com/andrewthecodertx/6502-Emulator/blob/main/LICENSE) file for details.

## Acknowledgments

* **6502.org** - For comprehensive 6502 documentation
* **cc65 project** - For the assembler and toolchain
