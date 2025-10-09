# PHP-6502: A 6502 Emulator in PHP

A fully functional 6502 microprocessor emulator written entirely in PHP, with support
for multiple system architectures. Features a reusable CPU core that can be paired
with different system implementations including Ben Eater-style computers and the
Commodore 64.

This project was heavily inspired by Ben Eater's fantastic YouTube series,
["Build a 65c02-based computer from scratch"](https://www.youtube.com/playlist?list=PLowKtXNTBypFbtuVMUVXNR0z1mu7dp7eH).
While Ben builds a physical computer, this project emulates one in software.

## Features

### Core CPU Emulator
* **Complete 6502 CPU implementation** with all standard opcodes and addressing modes
* **Hybrid execution model** combining JSON-driven and custom handler-based instruction processing
* **Interrupt support** (NMI, IRQ, RESET) with proper edge/level triggering
* **CPU monitoring** for debugging and profiling with instruction tracing and cycle counting
* **Comprehensive PHPDoc documentation** for excellent IDE support

### System Implementations

#### BenEater System
* RAM and ROM memory components with flexible loading mechanisms
* Serial UART (6551 ACIA emulation) for interactive I/O
* Video memory with 256x240 framebuffer and ANSI terminal rendering
* 6522 VIA (Versatile Interface Adapter) with timers and interrupt control
* PS/2-style keyboard controller with FIFO buffer
* Four-channel sound controller
* Memory-mapped peripheral architecture

#### Commodore 64 System (In Development)
* MOS 6510 CPU with banking support
* VIC-II video chip emulation with sprite support and raster interrupts
* SID 6581 sound chip with three voices and ADSR envelopes
* CIA 6526 complex interface adapters (×2) with timers
* Full C64 memory banking ($A000-$BFFF BASIC ROM, $D000-$DFFF I/O/CharROM, $E000-$FFFF KERNAL ROM)
* Color RAM support

### Additional Features
* **Ability to run assembled 6502 machine code**
* **Wozmon monitor program** - classic Apple 1 monitor for memory inspection and code execution
* **Graphics demo** with ANSI terminal rendering
* **Extensible architecture** - easy to add new systems and peripherals

## Getting Started

### Prerequisites

* PHP 8.1 or higher
* Composer for dependency management
* `cc65` toolchain for assembling 6502 code. You can download it from the
[official cc65 website](https://cc65.github.io/).

### Installation

1. **Clone the repository:**

    ```bash
    git clone https://github.com/your-username/6502-Emulator.git
    cd 6502-Emulator
    ```

2. **Install PHP dependencies:**

    ```bash
    composer install
    ```

### Assembling Programs

The assembly source files are located in the `programs/` directory. A helper
script is provided to assemble them.

1. **Assemble the BIOS:**

    ```bash
    ./utilities/buildasm.sh bios.asm bios.cfg
    ```

2. **Assemble Wozmon:**

    ```bash
    ./utilities/buildasm.sh wozmon_uart.asm wozmon_uart.cfg
    ```

    Assembled binaries (`.bin` files) will be placed in the `roms/` directory.

### Running the Emulator

#### BenEater System

You can run any assembled program using the `loadbin.php` script located in `src/Systems/BenEater/examples/`.

1. **Run the BIOS:**
    The BIOS will perform a quick memory test and then wait for you to enter a
    memory address to jump to.

    ```bash
    php src/Systems/BenEater/examples/loadbin.php roms/bios.bin
    ```

2. **Run Wozmon:**
    Wozmon provides a simple monitor program that allows you to inspect memory
    and execute code.

    ```bash
    php src/Systems/BenEater/examples/loadbin.php roms/wozmon_uart.bin
    ```

    Once Wozmon starts, you can interact with it. For example, to view the
    contents of memory starting at address `$C000`, you would type `C000.C00F`
    and press Enter.

3. **Run the Graphics Demo:**
    A demonstration of the ANSI terminal rendering system.

    ```bash
    php src/Systems/BenEater/examples/graphics_demo.php
    ```

#### Commodore 64 System

The C64 system is currently in development. Basic system example:

```bash
php src/Systems/C64/examples/basic_system.php
```

## Architecture

The emulator uses a modular, reusable architecture that separates the core CPU from system-specific implementations.

### Core Components (`src/Core/`)

* **CPU** - The main 6502 processor with all registers, addressing modes, and instruction execution
* **BusInterface** - Abstraction for system buses to implement memory-mapped I/O
* **InstructionRegister** - Loads and provides access to opcode definitions from `opcodes.json`
* **InstructionInterpreter** - Executes instructions using declarative JSON metadata (78% of opcodes)
* **StatusRegister** - Manages the 8 CPU status flags (NV-BDIZC)
* **CPUMonitor** - Optional debugging and profiling tool
* **Instructions/** - Custom handlers for complex opcodes (arithmetic with overflow, branches, stack ops)

### System-Specific Components (`src/Systems/`)

Each system implements `BusInterface` and provides its own memory map and peripherals:

* **BenEater/** - Ben Eater-style computer with UART, video, and I/O peripherals
* **C64/** - Commodore 64 with VIC-II, SID, CIA chips and memory banking

See `docs/CPU_CORE_ARCHITECTURE.md` for detailed information on building new systems.

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
* **Test Coverage** - 56 tests covering CPU operations, addressing modes, and peripherals
* **CLAUDE.md** - Project-specific guidance for AI assistants

## Project Structure

```
src/
├── Core/                        # Reusable 6502 CPU core
│   ├── CPU.php                 # Main CPU emulator
│   ├── BusInterface.php        # Bus abstraction
│   ├── InstructionRegister.php # Opcode registry
│   ├── InstructionInterpreter.php # JSON-driven execution
│   ├── StatusRegister.php      # CPU flags
│   ├── Opcode.php              # Opcode metadata
│   ├── CPUMonitor.php          # Debugging tool
│   ├── opcodes.json            # Opcode definitions
│   └── Instructions/           # Complex instruction handlers
│
└── Systems/
    ├── BenEater/               # Ben Eater-style system
    │   ├── Bus/
    │   │   ├── SystemBus.php   # Memory-mapped I/O bus
    │   │   └── PeripheralInterface.php
    │   ├── RAM.php             # System RAM
    │   ├── ROM.php             # System ROM
    │   ├── UART.php            # Serial I/O
    │   ├── VideoMemory.php     # Framebuffer
    │   ├── ANSIRenderer.php    # Terminal graphics
    │   ├── ConsoleIO.php       # Console utilities
    │   ├── Peripherals/        # I/O peripherals
    │   │   ├── VIA.php         # 6522 VIA
    │   │   ├── KeyboardController.php
    │   │   ├── SoundController.php
    │   │   └── Serial.php
    │   └── examples/           # Example programs
    │
    └── C64/                    # Commodore 64 system
        ├── Bus/
        │   ├── C64Bus.php      # C64 memory banking
        │   └── PeripheralInterface.php
        ├── MOS6510.php         # C64 CPU variant
        └── Peripherals/        # C64 chips
            ├── VICII.php       # Video chip
            ├── CIA6526.php     # I/O chip (×2)
            └── SID6581.php     # Sound chip
```

## Future Plans

- **Complete Commodore 64 implementation** with full BASIC and KERNAL support
- **Apple II system** - Another classic 6502-based computer
- **NES emulation** - Nintendo Entertainment System support
- **Cycle-accurate timing** for more precise emulation
- **Performance optimizations** - JIT compilation, opcode caching
- **Web interface** - Browser-based emulator with canvas rendering

## Contributing

Contributions are welcome! The modular architecture makes it easy to:

- Add new 6502-based systems (see `docs/CPU_CORE_ARCHITECTURE.md`)
- Implement additional peripherals
- Improve emulation accuracy
- Add more test coverage

## License

This project is open source. See the LICENSE file for details.

## Acknowledgments

- **Ben Eater** - For the excellent YouTube series that inspired this project
- **6502.org** - For comprehensive 6502 documentation
- **cc65 project** - For the assembler and toolchain
