# Commodore 64 Emulator

A functional Commodore 64 system emulator built on the reusable 6502 CPU core.

## Architecture

The C64 system consists of:

### MOS 6510 CPU
- Extended 6502 with built-in 6-bit I/O port at $0000-$0001
- Controls memory banking (BASIC ROM, KERNAL ROM, Character ROM, I/O)
- Runs at ~1 MHz (PAL: 0.985 MHz, NTSC: 1.023 MHz)

### Memory System (C64Bus)
- 64KB RAM with complex banking
- 8KB BASIC ROM ($A000-$BFFF) - switchable
- 4KB Character ROM ($D000-$DFFF) - switchable with I/O
- 8KB KERNAL ROM ($E000-$FFFF) - switchable
- 1KB Color RAM ($D800-$DBFF) - only lower 4 bits used

### Banking Control
Memory banking is controlled by bits 0-2 of CPU port ($0001):
- Bit 0 (LORAM): Controls BASIC ROM at $A000-$BFFF
- Bit 1 (HIRAM): Controls KERNAL ROM at $E000-$FFFF
- Bit 2 (CHAREN): Controls Character ROM vs I/O at $D000-$DFFF

Common banking configurations:
- `$37`: Default (all ROMs + I/O enabled)
- `$36`: I/O + KERNAL only
- `$35`: I/O only
- `$34`: All RAM visible
- `$30`: All RAM (no I/O)

### Peripherals

#### CIA #1 (6526) - $DC00-$DCFF
- Keyboard matrix scanning (Port A/B)
- Joystick #1 and #2 input
- Two programmable timers (A and B)
- IRQ interrupt generation
- Paddle/mouse input

#### CIA #2 (6526) - $DD00-$DDFF
- Serial bus control (disk drives)
- RS-232 interface (user port)
- VIC-II bank selection
- NMI interrupt generation
- Two programmable timers

#### VIC-II (6567/6569) - $D000-$D3FF
- 320×200 or 160×200 resolution
- 16 colors (4-bit color depth)
- 8 hardware sprites (movable objects)
- Character mode (40×25 text)
- Bitmap modes (320×200 or 160×200)
- Extended color mode, multicolor mode
- Smooth scrolling (hardware pixel scrolling)
- Raster interrupts (critical for effects)
- Light pen support

#### SID (6581/8580) - $D400-$D7FF
- 3-voice synthesizer
- 4 waveforms per voice: triangle, sawtooth, pulse, noise
- ADSR envelope generator per voice
- Programmable filters: low-pass, high-pass, band-pass, notch
- Ring modulation and oscillator sync
- Master volume control
- Paddle/potentiometer input (reused for game controllers)

## Memory Map

```
$0000-$0001  CPU I/O Port (banking control)
$0002-$00FF  Zero Page
$0100-$01FF  Stack
$0200-$03FF  BASIC/KERNAL working storage
$0400-$07FF  Screen RAM (default, 1KB for 40×25 chars)
$0800-$9FFF  BASIC program area / User RAM
$A000-$BFFF  BASIC ROM (8KB) or RAM
$C000-$CFFF  User RAM (4KB)
$D000-$D3FF  VIC-II registers
$D400-$D7FF  SID registers
$D800-$DBFF  Color RAM (1KB, nybbles)
$DC00-$DCFF  CIA #1 registers
$DD00-$DDFF  CIA #2 registers
$DE00-$DEFF  I/O Expansion 1
$DF00-$DFFF  I/O Expansion 2
$E000-$FFFF  KERNAL ROM (8KB) or RAM
```

## Usage

### Basic Example

```php
use Emulator\Systems\C64\MOS6510;
use Emulator\Systems\C64\Bus\C64Bus;
use Emulator\Systems\C64\Peripherals\{CIA6526, VICII, SID6581};

// Create the bus
$bus = new C64Bus();

// Attach peripherals
$bus->attachPeripheral(new CIA6526(0xDC00, "CIA1"));
$bus->attachPeripheral(new CIA6526(0xDD00, "CIA2"));
$bus->attachPeripheral(new VICII());
$bus->attachPeripheral(new SID6581());

// Create CPU
$cpu = new MOS6510($bus);

// Load ROMs (you'll need actual C64 ROM files)
$bus->loadBasicRom('path/to/basic.rom');
$bus->loadKernalRom('path/to/kernal.rom');
$bus->loadCharacterRom('path/to/char.rom');

// Reset and run
$cpu->reset();
while (true) {
    $cpu->step();
}
```

### Running the Example

```bash
php src/Systems/C64/examples/basic_system.php
```

## Implementation Status

### Completed
- ✅ C64Bus with full memory banking
- ✅ MOS 6510 CPU with I/O port
- ✅ CIA 6526 (timers, I/O ports, interrupts)
- ✅ VIC-II (registers, raster timing, interrupts)
- ✅ SID (registers, basic ADSR, voice control)

### TODO
- ⬜ VIC-II video rendering (character mode)
- ⬜ VIC-II sprite rendering
- ⬜ VIC-II bitmap modes
- ⬜ SID audio output (waveform generation)
- ⬜ SID filters (low-pass, high-pass, band-pass)
- ⬜ CIA keyboard matrix scanning
- ⬜ CIA joystick input
- ⬜ Load actual C64 ROMs
- ⬜ Serial bus / disk drive emulation
- ⬜ Cartridge support
- ⬜ Tape support

## Resources

### Official Documentation
- [C64 Programmer's Reference Guide](https://archive.org/details/c64-programmer-ref)
- [C64 Memory Maps](https://sta.c64.org/cbm64mem.html)
- [VIC-II specifications](https://www.commodore.ca/manuals/funet/cbm/c64/vic-ii.txt)
- [SID specifications](https://www.waitingforfriday.com/?p=661)
- [CIA 6526 datasheet](http://archive.6502.org/datasheets/mos_6526_cia.pdf)

### Community Resources
- [C64 Wiki](https://www.c64-wiki.com/)
- [Codebase64](https://codebase64.org/)
- [VICE Emulator](https://vice-emu.sourceforge.io/) - reference implementation

## Testing

The C64 system can be tested with:
- 6502 test suites (Klaus Dormann's tests)
- C64 diagnostic cartridges
- Real C64 programs and games (requires ROM files)

## Development Notes

This emulator prioritizes:
1. **Accuracy** - Faithful register-level emulation
2. **Modularity** - Clean separation of concerns
3. **Extensibility** - Easy to add missing features

The current implementation focuses on the core chips and their interactions. Video and audio rendering are stubbed out but can be implemented by extending the VIC-II and SID classes.

For building new systems on this CPU core, see `docs/CPU_CORE_ARCHITECTURE.md`.
