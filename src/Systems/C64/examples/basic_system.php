<?php

require_once __DIR__ . '/../../../../vendor/autoload.php';

use Emulator\Systems\C64\MOS6510;
use Emulator\Systems\C64\Bus\C64Bus;
use Emulator\Systems\C64\Peripherals\CIA6526;
use Emulator\Systems\C64\Peripherals\VICII;
use Emulator\Systems\C64\Peripherals\SID6581;

/**
 * Basic C64 System Example
 *
 * This demonstrates the C64 system architecture with all major chips:
 * - MOS 6510 CPU with I/O port
 * - C64Bus with memory banking
 * - CIA #1 and CIA #2 (timers, I/O ports)
 * - VIC-II (video chip with raster interrupts)
 * - SID (sound chip)
 *
 * This example runs a simple test program that:
 * 1. Sets up the screen and sound
 * 2. Demonstrates memory banking
 * 3. Uses CIA timers
 * 4. Shows basic system operation
 */

echo "Commodore 64 Emulator - Basic System Test\n";
echo "==========================================\n\n";

// Create the bus
$bus = new C64Bus();

// Create and attach peripherals
$cia1 = new CIA6526(0xDC00, "CIA1");
$cia2 = new CIA6526(0xDD00, "CIA2");
$vic = new VICII();
$sid = new SID6581();

$bus->attachPeripheral($cia1);
$bus->attachPeripheral($cia2);
$bus->attachPeripheral($vic);
$bus->attachPeripheral($sid);

// Create the CPU
$cpu = new MOS6510($bus);

echo "System initialized:\n";
echo "- MOS 6510 CPU with I/O port\n";
echo "- 64KB RAM with banking\n";
echo "- CIA #1 (keyboard/joystick)\n";
echo "- CIA #2 (serial/RS-232)\n";
echo "- VIC-II (video)\n";
echo "- SID (sound)\n\n";

// Load a simple test program at $0801 (BASIC start)
// This is a minimal program that:
// - Sets memory banking to I/O visible (no ROMs)
// - Writes a pattern to screen memory
// - Sets up a CIA timer
// - Plays a note on the SID
$program = [
    // Set CPU port to I/O + RAM mode ($0001 = $35)
    0xA9, 0x35,        // LDA #$35
    0x85, 0x01,        // STA $01

    // Write to screen memory ($0400)
    0xA9, 0x01,        // LDA #$01  (character code)
    0x8D, 0x00, 0x04,  // STA $0400 (top-left corner)

    // Write to color RAM ($D800)
    0xA9, 0x0E,        // LDA #$0E  (light blue)
    0x8D, 0x00, 0xD8,  // STA $D800

    // Set up CIA1 Timer A (100 cycles)
    0xA9, 0x64,        // LDA #$64  (100 low byte)
    0x8D, 0x04, 0xDC,  // STA $DC04 (Timer A low)
    0xA9, 0x00,        // LDA #$00  (high byte)
    0x8D, 0x05, 0xDC,  // STA $DC05 (Timer A high)
    0xA9, 0x01,        // LDA #$01  (start timer)
    0x8D, 0x0E, 0xDC,  // STA $DC0E (CRA)

    // Play a note on SID Voice 1
    0xA9, 0x00,        // LDA #$00
    0x8D, 0x00, 0xD4,  // STA $D400 (Freq low = $1000)
    0xA9, 0x10,        // LDA #$10
    0x8D, 0x01, 0xD4,  // STA $D401 (Freq high)
    0xA9, 0x11,        // LDA #$11  (triangle wave + gate)
    0x8D, 0x04, 0xD4,  // STA $D404 (Control)
    0xA9, 0x0F,        // LDA #$0F  (full volume)
    0x8D, 0x18, 0xD4,  // STA $D418 (Volume)

    // Loop forever
    0x4C, 0x35, 0x08,  // JMP $0835 (loop back)
];

$bus->loadProgram($program, 0x0801);

// Temporarily switch to all-RAM mode to set up reset vector
$bus->write(0x0001, 0x30);  // All RAM mode

// Set up reset vector to point to our program
$bus->write(0xFFFC, 0x01);
$bus->write(0xFFFD, 0x08);

// Reset the CPU (will read reset vector)
$cpu->reset();

echo "Program loaded at \$0801\n";
echo "Reset vector set to \$0801\n\n";

// Display initial banking state
echo "Initial CPU port (\$0001): \$" . sprintf("%02X", $cpu->getCpuPortData()) . "\n";
echo "- BASIC ROM: " . ($cpu->isBasicRomEnabled() ? "enabled" : "disabled") . "\n";
echo "- KERNAL ROM: " . ($cpu->isKernalRomEnabled() ? "enabled" : "disabled") . "\n";
echo "- I/O: " . ($cpu->isIOEnabled() ? "enabled" : "disabled") . "\n\n";

// Run the program for a bit
echo "Running program...\n\n";

for ($i = 0; $i < 100; $i++) {
    $cpu->step();

    // Show interesting state changes
    if ($i === 10) {
        echo "After 10 instructions:\n";
        echo "CPU port (\$0001): \$" . sprintf("%02X", $cpu->getCpuPortData()) . "\n";
        echo "- Memory banking changed to all RAM\n";
        echo "Screen memory (\$0400): \$" . sprintf("%02X", $bus->read(0x0400)) . "\n";
        echo "Color RAM (\$D800): \$" . sprintf("%02X", $bus->read(0xD800)) . "\n\n";
    }

    if ($i === 50) {
        echo "After 50 instructions:\n";
        echo "CIA1 Timer A running: " . ($bus->read(0xDC0E) & 0x01 ? "yes" : "no") . "\n";
        echo "SID Voice 1 frequency: \$" . sprintf("%04X",
            $bus->read(0xD400) | ($bus->read(0xD401) << 8)) . "\n";
        echo "SID Voice 1 gate: " . ($bus->read(0xD404) & 0x01 ? "on" : "off") . "\n";
        echo "SID Volume: " . ($bus->read(0xD418) & 0x0F) . "\n\n";
    }
}

echo "Execution summary:\n";
echo "- Instructions executed: 100\n";
echo "- PC: \$" . sprintf("%04X", $cpu->pc) . "\n";
echo "- A: \$" . sprintf("%02X", $cpu->accumulator) . "\n";
echo "- X: \$" . sprintf("%02X", $cpu->registerX) . "\n";
echo "- Y: \$" . sprintf("%02X", $cpu->registerY) . "\n";
echo "- SP: \$" . sprintf("%02X", $cpu->sp) . "\n\n";

// Display chip states
echo "Chip States:\n";
echo "-------------\n";

echo "VIC-II:\n";
$screenMode = $vic->getScreenMode();
echo "  Raster line: " . $vic->getRasterLine() . "\n";
echo "  Display enabled: " . ($screenMode['display_enabled'] ? "yes" : "no") . "\n\n";

echo "CIA1:\n";
echo "  Port A: \$" . sprintf("%02X", $cia1->getPortAOutput()) . "\n";
echo "  Port B: \$" . sprintf("%02X", $cia1->getPortBOutput()) . "\n";
echo "  Interrupt pending: " . ($cia1->isInterruptPending() ? "yes" : "no") . "\n\n";

echo "SID:\n";
$voice1 = $sid->getVoiceInfo(1);
echo "  Voice 1 frequency: \$" . sprintf("%04X", $voice1['frequency']) . "\n";
echo "  Voice 1 gate: " . ($voice1['gate'] ? "on" : "off") . "\n";
echo "  Voice 1 waveform: " . getWaveformName($voice1['waveform']) . "\n";
echo "  Volume: " . $sid->getVolume() . "/15\n\n";

echo "C64 system test complete!\n";

/**
 * Get human-readable waveform name from SID control bits
 */
function getWaveformName(int $waveform): string
{
    $names = [];
    if ($waveform & 0x01) $names[] = "triangle";
    if ($waveform & 0x02) $names[] = "sawtooth";
    if ($waveform & 0x04) $names[] = "pulse";
    if ($waveform & 0x08) $names[] = "noise";
    return empty($names) ? "none" : implode("+", $names);
}
