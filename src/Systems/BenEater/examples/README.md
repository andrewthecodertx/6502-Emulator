# BenEater System Examples

This directory contains example programs and utilities specific to the Ben Eater 6502 system.

## Programs

### loadbin.php
Main program loader for the BenEater system. Loads assembled 6502 programs into memory and executes them.

**Usage:**
```bash
php src/Systems/BenEater/examples/loadbin.php <program.bin> [load_address]
```

**Examples:**
```bash
# Load BIOS to ROM (default $8000)
php src/Systems/BenEater/examples/loadbin.php roms/bios.bin

# Load program to specific address
php src/Systems/BenEater/examples/loadbin.php roms/program.bin 0200

# Load Wozmon monitor
php src/Systems/BenEater/examples/loadbin.php roms/wozmon_uart.bin
```

**Default Addresses:**
- BIOS/ROM programs: Load to ROM at $8000
- User programs: Load to RAM at $0200 (default)

### graphics_demo.php
Demonstrates the ANSI terminal graphics capabilities using the VideoMemory and ANSIRenderer.

**Usage:**
```bash
php src/Systems/BenEater/examples/graphics_demo.php
```

**Features:**
- 256×240 framebuffer rendered to terminal
- ANSI 256-color support
- Unicode half-blocks for 2:1 vertical compression
- Interactive pattern selection (if implemented)
- Test patterns: gradients, color bars, checkerboard, etc.

## System Configuration

The BenEater system uses this memory map:

```
$0000-$7FFF: RAM (32KB)
$8000-$FFFF: ROM (32KB)

Memory-mapped I/O:
$0400-$F3FF: Video Memory (256×240 framebuffer)
$FE00-$FE03: UART (Serial I/O)
$FE10-$FE1F: VIA (6522 Versatile Interface Adapter)
```

## Building Programs

Use the assembler to build programs from source:

```bash
# Assemble a program
./utilities/buildasm.sh program.asm

# Output will be in roms/program.bin
```

See the main project README for more information about assembling programs.
