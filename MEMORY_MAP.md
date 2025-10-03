# Memory Map

This document describes the memory layout of the PHP-6502 emulator system.

## Overview

The 6502 processor has a 16-bit address bus, providing access to 64KB (65,536 bytes) of memory space. This emulator uses memory-mapped I/O, where peripheral devices are accessed through specific memory addresses.

## Default Memory Layout

```
$0000 - $00FF   Zero Page (256 bytes)
$0100 - $01FF   Stack (256 bytes)
$0200 - $03FF   System RAM (512 bytes)
$0400 - $F3FF   Video Memory / Framebuffer (61,440 bytes)
$F400 - $FDFF   Available RAM (2,560 bytes)
$FE00 - $FEFF   I/O Space (256 bytes)
$FF00 - $FFFF   System Vectors & Available (256 bytes)
```

## Detailed Memory Regions

### Zero Page ($0000 - $00FF)
- **Size**: 256 bytes
- **Purpose**: Fast access memory for 6502 zero-page addressing modes
- **Usage**: Variables, pointers, temporary storage
- **Performance**: Zero-page instructions execute faster and use fewer bytes

### Stack ($0100 - $01FF)
- **Size**: 256 bytes
- **Purpose**: Hardware stack for subroutine calls and interrupts
- **Stack Pointer**: Starts at $01FF (SP = $FF) and grows downward
- **Usage**:
  - JSR/RTS instructions (subroutine calls)
  - PHA/PLA, PHP/PLP (push/pull operations)
  - Interrupt handling (saves PC and status register)

### System RAM ($0200 - $03FF)
- **Size**: 512 bytes
- **Purpose**: General purpose RAM
- **Usage**: Program variables, buffers, data storage

### Video Memory ($0400 - $F3FF)
- **Size**: 61,440 bytes (256 × 240 pixels)
- **Purpose**: Framebuffer for graphical display
- **Format**: 8-bit palette indices (0-255 per pixel)
- **Layout**: Linear framebuffer, row-major order
  - Pixel(x, y) = $0400 + (y × 256) + x
  - Top-left pixel: $0400
  - Top-right pixel: $04FF
  - Bottom-left pixel: $F300
  - Bottom-right pixel: $F3FF

**Display Specifications:**
- Resolution: 256 × 240 pixels
- Color depth: 8-bit (256 colors from palette)
- Byte per pixel: 1
- Total framebuffer: 61,440 bytes

**Usage Example:**
```assembly
; Set pixel at (10, 5) to color $0F (white)
LDA #$0F
STA $0950    ; $0400 + (5 * 256) + 10 = $0950
```

### Available RAM ($F400 - $FDFF)
- **Size**: 2,560 bytes
- **Purpose**: Additional general purpose RAM
- **Usage**: Can be used for larger data structures, buffers, or expanded programs

### I/O Space ($FE00 - $FEFF)
- **Size**: 256 bytes
- **Purpose**: Memory-mapped I/O peripherals

#### UART (Serial Communication) - $FE00 - $FE03
- `$FE00`: UART Data Register (read = receive, write = transmit)
- `$FE01`: UART Status Register
  - Bit 0: RX Ready (1 = data available to read)
  - Bit 1: TX Ready (1 = ready to transmit)
- `$FE02`: UART Control Register
- `$FE03`: Reserved

**Usage Example:**
```assembly
; Write 'A' to UART
LDA #'A'
STA $FE00

; Read from UART (polling)
wait_uart:
    LDA $FE01      ; Check status
    AND #$01       ; Test RX Ready bit
    BEQ wait_uart  ; Wait if no data
    LDA $FE00      ; Read data
```

#### Future I/O Peripherals
- `$FE10 - $FE1F`: Keyboard Controller (planned)
- `$FE20 - $FE2F`: Sound Controller (planned)
- `$FE30 - $FEFF`: Reserved for expansion

### System Vectors ($FF00 - $FFFF)
- **Size**: 256 bytes
- **Purpose**: Interrupt vectors and available RAM

#### 6502 Hardware Vectors ($FFFA - $FFFF)
- `$FFFA-$FFFB`: NMI Vector (Non-Maskable Interrupt)
- `$FFFC-$FFFD`: RESET Vector (Power-on/Reset)
- `$FFFE-$FFFF`: IRQ/BRK Vector (Interrupt Request/Break)

**Note**: These vectors are typically stored in ROM in the BIOS region.

## ROM Configuration

The system supports ROM loading at various addresses. Common configurations:

### BIOS ROM (when loaded)
- **Address**: $8000 - $FFFF (32KB)
- **Purpose**: Boot code, system initialization, interrupt handlers
- **Protection**: Read-only, cannot be written to
- **Typical Contents**:
  - Boot/reset handler at RESET vector
  - Interrupt handlers
  - System subroutines
  - Character ROM or other data

**Note**: When BIOS ROM is loaded, it occupies the upper 32KB and overlaps the I/O space. I/O peripherals take priority in the bus arbitration, so $FE00-$FEFF remains accessible for I/O even with ROM loaded.

## Memory Access Priority

When multiple components claim the same address, the bus uses this priority order:

1. **Peripherals** (highest priority) - I/O devices
2. **ROM** - Read-only memory
3. **RAM** (lowest priority) - Random access memory

This ensures that I/O space ($FE00-$FEFF) is always accessible even when ROM is loaded.

## Memory-Mapped I/O Examples

### Drawing Pixels to Display

```assembly
; Fill screen with color $01 (blue)
clear_screen:
    LDX #$00
    LDA #$01
loop:
    STA $0400,X    ; First 256 bytes
    STA $0500,X    ; Next 256 bytes
    STA $0600,X    ; ... continue
    ; ... (would need more pages for full screen)
    INX
    BNE loop
    RTS
```

### Vertical Line Drawing

```assembly
; Draw vertical line at X=10 from Y=0 to Y=239
; Color = $0F (white)
draw_vline:
    LDY #239       ; Start at bottom
    LDA #$0F       ; White color
line_loop:
    ; Calculate address: $0400 + (Y * 256) + 10
    TYA            ; Y to accumulator
    CLC
    ADC #$04       ; Add high byte of base
    STA $01        ; Store in zero page pointer (high)
    LDA #$0A       ; X coordinate (10)
    STA $00        ; Store in zero page pointer (low)

    ; Write pixel
    LDA #$0F       ; White
    STA ($00),Y    ; Write via indirect indexed

    DEY
    BPL line_loop
    RTS
```

## Memory Considerations

### Available User Memory

With video memory enabled:
- Zero Page: 256 bytes (often reserved for system use)
- Stack: 256 bytes (dynamic, shared)
- System RAM: 512 bytes ($0200-$03FF)
- Extra RAM: 2,560 bytes ($F400-$FDFF)
- **Total user RAM: ~3,328 bytes** (excluding zero page and stack)

### Framebuffer Overhead

The video framebuffer consumes a significant portion (61,440 bytes) of the 64KB address space. If graphics are not needed, this region can be reclaimed as general RAM by not attaching the VideoMemory peripheral to the bus.

### Memory Banking (Future)

For systems requiring more memory, banking mechanisms could be implemented:
- Bank switching at specific address ranges
- Overlapping ROM/RAM selection
- Extended memory beyond 64KB through bank registers

## Customizing the Memory Map

The memory map can be customized when initializing the system:

```php
// Custom video memory at different address
$video = new VideoMemory(0x2000, 0x5FFF);  // 16KB at $2000
$bus->addPeripheral($video);

// Custom UART address
$uart = new UART(0xF000, 0xF003);
$bus->addPeripheral($uart);

// Different ROM size
$rom = new ROM(0xC000);  // 16KB ROM starting at $C000
$bus = new SystemBus($ram, $rom);
```

## Address Decoding

The system bus performs address decoding in this order:

1. Check all peripherals (via `handlesAddress()`)
2. If address >= ROM start: read from ROM
3. Otherwise: read from/write to RAM

This allows flexible memory configurations while maintaining predictable behavior.
