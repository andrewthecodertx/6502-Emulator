#!/bin/bash
# Build a combined ROM with BIOS and WozMon permanently embedded
# Creates a single 32KB ROM with both programs

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(dirname "$SCRIPT_DIR")"
PROGRAMS_DIR="$PROJECT_ROOT/programs"
ROMS_DIR="$PROJECT_ROOT/roms"

echo "Building combined BIOS + WozMon ROM..."

# Build BIOS
echo "Building BIOS..."
cd "$SCRIPT_DIR"
./buildasm.sh bios.asm bios.cfg
if [ $? -ne 0 ]; then
    echo "BIOS build failed!"
    exit 1
fi

# Build WozMon for ROM location
echo "Building WozMon..."
./buildasm.sh wozmon_uart.asm wozmon_uart.cfg
if [ $? -ne 0 ]; then
    echo "WozMon build failed!"
    exit 1
fi

# Create combined ROM
echo "Creating combined ROM..."
COMBINED_ROM="$ROMS_DIR/combined.bin"

# Start with BIOS (32KB)
cp "$ROMS_DIR/bios.bin" "$COMBINED_ROM"

# Overlay WozMon at 0xFF00 (offset 0x7F00 in the ROM file)
dd if="$ROMS_DIR/wozmon_uart.bin" of="$COMBINED_ROM" bs=1 seek=$((0x7F00)) conv=notrunc 2>/dev/null

echo "Combined ROM created: $COMBINED_ROM"
echo "Size: $(stat -c%s "$COMBINED_ROM") bytes"
echo ""
echo "Memory layout:"
echo "  0x8000-0xFEFF: BIOS (28KB)"
echo "  0xFF00-0xFFFC: WozMon (252 bytes)"
echo "  0xFFFA-0xFFFF: Interrupt vectors"
echo ""
echo "To use: Load combined.bin instead of bios.bin"