# GEMINI.md

## Project Overview

This project is a 6502 microprocessor emulator written in PHP. It emulates a simple 8-bit computer, including a CPU, RAM, ROM, and a serial UART for I/O. The project is inspired by Ben Eater's "Build a 65c02-based computer from scratch" YouTube series.

The architecture consists of a `CPU` class that interacts with a `SystemBus`. The `SystemBus` manages memory and peripherals, such as `RAM`, `ROM`, and `UART`. The emulator can load and run 6502 machine code that has been assembled into binary format.

The project uses `composer` for dependency management, `phpunit` for testing, and `phpstan` for static analysis. The 6502 assembly code is assembled using the `cc65` toolchain.

## Building and Running

### Prerequisites

*   PHP 8.1 or higher
*   Composer
*   `cc65` toolchain (`ca65`, `ld65`)

### Building

The assembly source files are located in the `programs/` directory. A helper script is provided to assemble them.

1.  **Assemble the BIOS:**

    ```bash
    ./utilities/buildasm.sh bios.asm bios.cfg
    ```

2.  **Assemble Wozmon:**

    ```bash
    ./utilities/buildasm.sh wozmon_uart.asm wozmon_uart.cfg
    ```

Assembled binaries (`.bin` files will be placed in the `roms/` directory.

### Running the Emulator

You can run any assembled program using the `loadbin.php` script.

1.  **Run the BIOS:**

    ```bash
    php loadbin.php bios.bin
    ```

2.  **Run Wozmon:**

    ```bash
    php loadbin.php wozmon_uart.bin
    ```

## Development Conventions

### Testing

The project uses PHPUnit for unit testing. To run the test suite:

```bash
./vendor/bin/phpunit
```

### Static Analysis

PHPStan is used for static analysis. To check the codebase:

```bash
./vendor/bin/phpstan analyse src
```
