# PHP-6502 Emulator Memory Map

## Overview

The PHP-6502 emulator implements a classic 65C02 memory architecture with 64KB
of addressable space (0x0000-0xFFFF). The memory is divided between RAM, ROM,
and memory-mapped I/O peripherals.

## Memory Layout

```
     ┌─────────────────┬──────────────────────────────────────────────────┐
     │   Address Range │ Description                                      │
     ├─────────────────┼──────────────────────────────────────────────────┤
     │ 0x0000 - 0x00FF │ Zero Page RAM (256 bytes)                        │
     │ 0x0100 - 0x01FF │ Stack (256 bytes)                                │
     │ 0x0200 - 0x7FFF │ General Purpose RAM (31.5KB)                     │
     │ 0x8000 - 0xFFFF │ ROM Space (32KB)                                 │
     │    0xFE00-0xFE03│ ├─ W65C51N UART (memory-mapped in ROM space)     │
     │    0xFE40-0xFE4F│ ├─ W65C22 VIA (memory-mapped in ROM space)       │
     │    0xFFFA-0xFFFB│ ├─ NMI Vector                                    │
     │    0xFFFC-0xFFFD│ ├─ Reset Vector                                  │
     │    0xFFFE-0xFFFF│ └─ IRQ/BRK Vector                                │
     └─────────────────┴──────────────────────────────────────────────────┘
```

## Detailed Memory Regions

### RAM Space (0x0000 - 0x7FFF) - 32KB

#### Zero Page (0x0000 - 0x00FF)

- **Size**: 256 bytes
- **Purpose**: Fast access memory for 6502 zero-page addressing modes
- **Usage**: Variables, temporary storage, indirect addressing pointers

#### Stack (0x0100 - 0x01FF)

- **Size**: 256 bytes
- **Purpose**: Hardware stack for subroutine calls and interrupts
- **Stack Pointer**: Starts at 0xFF (address 0x01FF), grows downward
- **Usage**: Return addresses, register saves, local variables

#### General RAM (0x0200 - 0x7FFF)

- **Size**: 31.5KB
- **Purpose**: General purpose memory
- **Usage**: Program variables, buffers, data storage

### ROM Space (0x8000 - 0xFFFF) - 32KB

The ROM space is a contiguous 32KB region that contains program code, data, and
system vectors. Memory-mapped peripherals are accessed through the SystemBus
which intercepts peripheral addresses before they reach ROM.

#### Program ROM (0x8000 - 0xFDFF)

- **Size**: ~24KB (exact size depends on peripheral mappings)
- **Purpose**: Main program storage
- **Usage**: Application code, subroutines, constants, data tables

#### Memory-Mapped Peripherals (Various locations in ROM space)

- **UART**: 0xFE00-0xFE03 (W65C51N)
- **VIA**: 0xFE40-0xFE4F (W65C22)
- **Other peripherals**: Can be mapped anywhere in 0x8000-0xFFFF range
- **Note**: SystemBus intercepts peripheral accesses before ROM

#### System Vectors (0xFFFA - 0xFFFF)

- **Size**: 6 bytes
- **Purpose**: CPU interrupt and reset vectors
- **Contains**: NMI vector (0xFFFA-0xFFFB), Reset vector (0xFFFC-0xFFFD), IRQ
vector (0xFFFE-0xFFFF)

## Peripheral Memory Map

### Currently Implemented Peripherals

#### W65C51N UART (0xFE00 - 0xFE03)

```
┌─────────┬─────────────┬──────────────────────────────────────────┐
│ Address │ Register    │ Description                              │
├─────────┼─────────────┼──────────────────────────────────────────┤
│ 0xFE00  │ Data        │ Transmit/Receive Data Register (R/W)     │
│ 0xFE01  │ Status      │ Status Register (Read Only)              │
│ 0xFE02  │ Command     │ Command Register (Write Only)            │
│ 0xFE03  │ Control     │ Control Register (Write Only)            │
└─────────┴─────────────┴──────────────────────────────────────────┘
```

**Status Register Bits (0xFE01)**:

- Bit 0: Parity Error
- Bit 1: Framing Error
- Bit 2: Overrun Error
- Bit 3: Receiver Data Ready
- Bit 4: Transmitter Data Empty
- Bit 5: Data Carrier Detect (DCD)
- Bit 6: Data Set Ready (DSR)
- Bit 7: Interrupt Request (IRQ)

#### W65C22 VIA - Versatile Interface Adapter (0xFE40 - 0xFE4F)

```
┌─────────┬─────────────┬──────────────────────────────────────────┐
│ Address │ Register    │ Description                              │
├─────────┼─────────────┼──────────────────────────────────────────┤
│ 0xFE40  │ ORB/IRB     │ Output/Input Register B (R/W)            │
│ 0xFE41  │ ORA/IRA     │ Output/Input Register A (R/W)            │
│ 0xFE42  │ DDRB        │ Data Direction Register B (R/W)          │
│ 0xFE43  │ DDRA        │ Data Direction Register A (R/W)          │
│ 0xFE44  │ T1C-L       │ Timer 1 Counter Low Byte (R/W)           │
│ 0xFE45  │ T1C-H       │ Timer 1 Counter High Byte (R/W)          │
│ 0xFE46  │ T1L-L       │ Timer 1 Latch Low Byte (R/W)             │
│ 0xFE47  │ T1L-H       │ Timer 1 Latch High Byte (R/W)            │
│ 0xFE48  │ T2C-L       │ Timer 2 Counter Low Byte (R/W)           │
│ 0xFE49  │ T2C-H       │ Timer 2 Counter High Byte (R/W)          │
│ 0xFE4A  │ SR          │ Shift Register (R/W)                     │
│ 0xFE4B  │ ACR         │ Auxiliary Control Register (R/W)         │
│ 0xFE4C  │ PCR         │ Peripheral Control Register (R/W)        │
│ 0xFE4D  │ IFR         │ Interrupt Flag Register (R/W)            │
│ 0xFE4E  │ IER         │ Interrupt Enable Register (R/W)          │
│ 0xFE4F  │ ORA-NH      │ Output Register A (No Handshake) (R/W)   │
└─────────┴─────────────┴──────────────────────────────────────────┘
```

**VIA Features**:

- Two 8-bit bidirectional I/O ports (Port A and Port B)
- Two 16-bit programmable timers/counters
- Shift register for serial data transfer
- Interrupt capability with individual enable/flag bits
- Handshaking control for data transfers
- Timer modes: One-shot, free-running, pulse counting

**Interrupt Flag Register (IFR) Bits**:

- Bit 0: CA2 Active Edge
- Bit 1: CA1 Active Edge
- Bit 2: Shift Register
- Bit 3: CB2 Active Edge
- Bit 4: CB1 Active Edge
- Bit 5: Timer 2
- Bit 6: Timer 1
- Bit 7: IRQ (Any interrupt)

### Available Peripherals (Not Currently Connected)

#### Video Memory (0x0400 - 0xF3FF)

```
┌─────────────────┬──────────────────────────────────────────────────┐
│   Address Range │ Description                                      │
├─────────────────┼──────────────────────────────────────────────────┤
│ 0x0400 - 0xF3FF │ Framebuffer (61,440 bytes / 256×240 pixels)      │
└─────────────────┴──────────────────────────────────────────────────┘
```

**Video Memory Features**:

- Resolution: 256×240 pixels
- Format: 8-bit indexed color (1 byte per pixel)
- Total size: 61,440 bytes
- Linear framebuffer layout
- **Note**: When enabled, shadows ROM space 0x8000-0xF3FF
- **Status**: Available but not currently connected in loadbin.php

#### Keyboard Controller (0xC000 - 0xC00F)

```
┌─────────┬─────────────┬──────────────────────────────────────────┐
│ Address │ Register    │ Description                              │
├─────────┼─────────────┼──────────────────────────────────────────┤
│ 0xC000  │ Key Data    │ Key code data register                   │
│ 0xC001  │ Key Status  │ Keyboard status register                 │
│ 0xC002  │ Key Control │ Keyboard control register                │
└─────────┴─────────────┴──────────────────────────────────────────┘
```

#### Sound Controller (0xC400 - 0xC40F)

```
┌─────────┬─────────────┬──────────────────────────────────────────┐
│ Address │ Register    │ Description                              │
├─────────┼─────────────┼──────────────────────────────────────────┤
│ 0xC400  │ CH0_FREQ_LO │ Channel 0 Frequency Low Byte             │
│ 0xC401  │ CH0_FREQ_HI │ Channel 0 Frequency High Byte            │
│ 0xC402  │ CH0_VOLUME  │ Channel 0 Volume Control                 │
│ 0xC403  │ CH0_CONTROL │ Channel 0 Control Register               │
│ 0xC404  │ CH1_FREQ_LO │ Channel 1 Frequency Low Byte             │
│ 0xC405  │ CH1_FREQ_HI │ Channel 1 Frequency High Byte            │
│ 0xC406  │ CH1_VOLUME  │ Channel 1 Volume Control                 │
│ 0xC407  │ CH1_CONTROL │ Channel 1 Control Register               │
│ 0xC408  │ CH2_FREQ_LO │ Channel 2 Frequency Low Byte             │
│ 0xC409  │ CH2_FREQ_HI │ Channel 2 Frequency High Byte            │
│ 0xC40A  │ CH2_VOLUME  │ Channel 2 Volume Control                 │
│ 0xC40B  │ CH2_CONTROL │ Channel 2 Control Register               │
│ 0xC40C  │ CH3_FREQ_LO │ Channel 3 Frequency Low Byte             │
│ 0xC40D  │ CH3_FREQ_HI │ Channel 3 Frequency High Byte            │
│ 0xC40E  │ CH3_VOLUME  │ Channel 3 Volume Control                 │
│ 0xC40F  │ CH3_CONTROL │ Channel 3 Control Register               │
└─────────┴─────────────┴──────────────────────────────────────────┘
```

#### Legacy Serial (0xFE00 - 0xFE01)

*Note: Superseded by W65C51N UART*

```
┌─────────┬─────────────┬──────────────────────────────────────────┐
│ Address │ Register    │ Description                              │
├─────────┼─────────────┼──────────────────────────────────────────┤
│ 0xFE00  │ Data        │ Serial data register                     │
│ 0xFE01  │ Status      │ Serial status register                   │
└─────────┴─────────────┴──────────────────────────────────────────┘
```

## Special Memory Locations

### Interrupt Vectors (in ROM space)

```
┌─────────┬─────────────┬──────────────────────────────────────────┐
│ Address │ Vector      │ Description                              │
├─────────┼─────────────┼──────────────────────────────────────────┤
│ 0xFFFA  │ NMI         │ Non-Maskable Interrupt Vector (Low)      │
│ 0xFFFB  │ NMI         │ Non-Maskable Interrupt Vector (High)     │
│ 0xFFFC  │ RESET       │ Reset Vector (Low Byte)                  │
│ 0xFFFD  │ RESET       │ Reset Vector (High Byte)                 │
│ 0xFFFE  │ IRQ/BRK     │ IRQ/Break Interrupt Vector (Low)         │
│ 0xFFFF  │ IRQ/BRK     │ IRQ/Break Interrupt Vector (High)        │
└─────────┴─────────────┴──────────────────────────────────────────┘
```

## Memory Access Architecture

### SystemBus Memory Routing

The SystemBus implements the following access priority:

1. **Peripheral Check**: For any memory access, SystemBus first checks if any
registered peripheral handles that address
1. **ROM Access**: If no peripheral handles the address and it's in range
0x8000-0xFFFF, access goes to ROM
1. **RAM Access**: All other addresses (0x0000-0x7FFF) go to RAM

This design allows peripherals to be memory-mapped anywhere in the ROM address
space while maintaining clean separation of concerns.

## Current System Configuration

### Program Loader (`loadbin.php`)

The main program loader currently configures:

- **RAM**: 0x0000 - 0x7FFF (32KB)
  - Zero page: 0x0000-0x00FF
  - Stack: 0x0100-0x01FF
  - General RAM: 0x0200-0x7FFF
- **ROM**: 0x8000 - 0xFFFF (32KB)
  - Program code and data
  - System vectors at 0xFFFA-0xFFFF
- **UART**: W65C51N at 0xFE00-0xFE03 (memory-mapped via SystemBus)
- **VIA**: W65C22 at 0xFE40-0xFE4F (memory-mapped via SystemBus)
- **SystemBus**: Routes memory access between RAM, ROM, and peripherals with edge-triggered interrupt support

## Expansion Possibilities

The current architecture supports easy addition of:

- Video display (VideoMemory class available at 0x0400-0xF3FF)
- Keyboard input (KeyboardController class available at 0xC000-0xC00F)
- Sound output (SoundController class available at 0xC400-0xC40F)
- Graphics controllers in 0xC000-0xC3FF range
- Additional UART devices
- Additional VIA chips for more I/O
- Timer/counter peripherals
- GPIO controllers

All peripherals implement `PeripheralInterface` and are managed by the
`SystemBus` for automatic memory mapping, bus arbitration, and edge-triggered
interrupt handling.

## Interrupt System

The emulator implements a proper interrupt system with:

- **Edge-Triggered IRQs**: SystemBus only requests CPU interrupt on LOW→HIGH
  transitions, preventing interrupt storms
- **Peripheral Integration**: All peripherals can signal interrupts via
  `hasInterruptRequest()` method
- **CPU Interrupt Handling**: Proper IRQ/NMI handling with vector support
- **Interrupt Vectors**:
  - NMI: 0xFFFA-0xFFFB (Non-maskable, edge-triggered)
  - RESET: 0xFFFC-0xFFFD (System initialization)
  - IRQ/BRK: 0xFFFE-0xFFFF (Maskable, edge-triggered via I flag)

