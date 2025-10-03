# PHP-6502: A 6502 Emulator in PHP

This project is a functional emulator for the 6502 microprocessor, written entirely in PHP. It emulates a simple 8-bit computer, complete with a CPU, RAM, ROM, and a serial UART for input/output.

This project was heavily inspired by Ben Eater's fantastic YouTube series, ["Build a 65c02-based computer from scratch"](https://www.youtube.com/playlist?list=PLowKtXNTBypFbtuVMUVXNR0z1mu7dp7eH). While Ben builds a physical computer, this project emulates one in software.

## Features

*   A fully functional 6502 CPU emulator.
*   A system bus to connect peripherals.
*   RAM and ROM memory components.
*   A serial UART for interactive I/O.
*   Ability to run assembled 6502 machine code.
*   Includes a port of the classic Wozmon monitor program.

## Getting Started

### Prerequisites

*   PHP 8.1 or higher
*   Composer for dependency management
*   `cc65` toolchain for assembling 6502 code. You can download it from the [official cc65 website](https://cc65.github.io/).

### Installation

1.  **Clone the repository:**
    ```bash
    git clone https://github.com/your-username/php-6502.git
    cd php-6502
    ```

2.  **Install PHP dependencies:**
    ```bash
    composer install
    ```

### Assembling Programs

The assembly source files are located in the `programs/` directory. A helper script is provided to assemble them.

1.  **Assemble the BIOS:**
    ```bash
    ./utilities/buildasm.sh bios.asm bios.cfg
    ```

2.  **Assemble Wozmon:**
    ```bash
    ./utilities/buildasm.sh wozmon_uart.asm wozmon_uart.cfg
    ```

    Assembled binaries (`.bin` files) will be placed in the `roms/` directory.

### Running the Emulator

You can run any assembled program using the `loadbin.php` script.

1.  **Run the BIOS:**
    The BIOS will perform a quick memory test and then wait for you to enter a memory address to jump to.
    ```bash
    php loadbin.php bios.bin
    ```

2.  **Run Wozmon:**
    Wozmon provides a simple monitor program that allows you to inspect memory and execute code.
    ```bash
    php loadbin.php wozmon_uart.bin
    ```
    Once Wozmon starts, you can interact with it. For example, to view the contents of memory starting at address `$C000`, you would type `C000.C00F` and press Enter.

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
