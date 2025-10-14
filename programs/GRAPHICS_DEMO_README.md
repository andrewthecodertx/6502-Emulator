# Graphics Demo for PHP-6502 Emulator

A demonstration of the VideoMemory and ANSIRenderer capabilities of the PHP-6502 emulator, written in 6502 assembly language.

## Overview

This program showcases the 256x240 pixel framebuffer by drawing various animated patterns directly to video memory at `$0400-$F3FF`. The output is rendered to the terminal using ANSI escape codes with Unicode half-block characters for improved resolution.

## Features

The demo cycles through six different visual patterns:

1. **Horizontal Gradient** - Smooth gradient from black (left) to white (right)
2. **Vertical Gradient** - Smooth gradient from black (top) to white (bottom)
3. **Checkerboard** - Classic black and white checkerboard pattern
4. **Animated Plasma** - XOR-based plasma effect with frame animation
5. **Radial Gradient** - Distance-based gradient from center point
6. **Color Bars** - SMPTE-style vertical color bars (white, yellow, cyan, green, magenta, red, blue, black)

Each pattern displays for 60 frames (120 frames for the plasma effect).

## Building

Assemble the program using the build script:

```bash
./programs/buildasm.sh graphics_demo.asm
```

This will create `roms/graphics_demo.bin`.

## Running

Run the demo using the included PHP runner:

```bash
php programs/run_graphics_demo.php
```

Or use the standard program loader:

```bash
php src/Systems/Eater/examples/loadbin.php roms/graphics_demo.bin 0200
```

Note: The runner script (`run_graphics_demo.php`) is specifically configured with VideoMemory attached to the bus, which is required for this demo.

## Technical Details

### Memory Map

- **$0200-$03FF**: Program code and data (371 bytes)
- **$0400-$F3FF**: Video memory (61,440 bytes / 256×240 pixels)
- **$0010-$0017**: Zero page variables

### Zero Page Variables

| Address | Name       | Purpose                          |
|---------|------------|----------------------------------|
| $10     | ZP_X       | Current X coordinate             |
| $11     | ZP_Y       | Current Y coordinate             |
| $12     | ZP_COLOR   | Current color value              |
| $13     | ZP_FRAME   | Frame counter (for animation)    |
| $14-$15 | ZP_PTR     | Video memory pointer (16-bit)    |
| $16     | ZP_TEMP    | Temporary storage                |
| $17     | ZP_PATTERN | Current pattern selector (0-5)   |

### Video Memory Layout

The framebuffer is organized as a linear array of 61,440 bytes:
- Resolution: 256 pixels wide × 240 pixels high
- Format: 8-bit indexed color (1 byte per pixel)
- Address calculation: `offset = (y * 256) + x`
- Each byte represents an ANSI 256-color palette index (0-255)

### Performance

The program uses a simple delay loop between pattern switches. Pattern rendering routines vary in complexity:

- **CLEAR_SCREEN**: ~240 pages × 256 bytes = 61,440 bytes cleared
- **DRAW_HORIZONTAL_GRADIENT**: ~61,440 writes with simple index-based coloring
- **DRAW_PLASMA**: Includes XOR operations and frame counter for animation

Expected cycle counts vary by pattern complexity, with the animated plasma being the most CPU-intensive.

## Code Structure

### Main Loop (`MAIN`)

1. Initialize system and clear screen
2. Cycle through six patterns, each displayed for a set number of frames
3. Loop infinitely

### Pattern Drawing Routines

- `CLEAR_SCREEN` - Fill entire screen with black (color 0)
- `DRAW_HORIZONTAL_GRADIENT` - X-axis gradient effect
- `DRAW_VERTICAL_GRADIENT` - Y-axis gradient effect
- `DRAW_CHECKERBOARD` - XOR-based checkerboard pattern
- `DRAW_PLASMA` - Animated plasma using frame counter
- `DRAW_RADIAL` - Manhattan distance from center
- `DRAW_COLOR_BARS` - Eight vertical color bars using lookup table

### Color Palette

The demo uses ANSI 256-color codes:
- 0: Black
- 21: Blue
- 46: Green
- 51: Cyan
- 196: Red
- 201: Magenta
- 226: Yellow
- 255: White
- 0-255: Full grayscale and color spectrum

## Customization

You can modify the assembly source to:
- Add new pattern routines
- Adjust display duration (change loop counters)
- Modify colors (change palette indices)
- Create animations using the frame counter (`ZP_FRAME`)

## Requirements

- PHP 8.1 or higher
- Composer dependencies installed
- cc65 toolchain (ca65, ld65) for assembly
- Terminal with ANSI 256-color support and Unicode half-block characters (▀)

## Notes

- The program runs indefinitely until the configured cycle limit
- Press Ctrl+C to exit the demo
- Terminal size should be at least 128 columns × 120 rows for full display (with scale factor 2)
- Some patterns may appear pixelated due to terminal font limitations

## See Also

- `src/Systems/Eater/VideoMemory.php` - Framebuffer implementation
- `src/Systems/Eater/ANSIRenderer.php` - Terminal rendering with ANSI codes
- `docs/MEMORY_MAP.md` - Complete system memory map
