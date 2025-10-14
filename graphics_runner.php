#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once './vendor/autoload.php';

use Emulator\Core\CPU;
use Emulator\Systems\Eater\RAM;
use Emulator\Systems\Eater\ROM;
use Emulator\Systems\Eater\VideoMemory;
use Emulator\Systems\Eater\ANSIRenderer;
use Emulator\Systems\Eater\Bus\SystemBus;

function showUsage(): void
{
    echo "Usage: php graphics_runner.php <program.bin> [load_address]\n";
    echo "\n";
    echo "Examples:\n";
    echo "  php graphics_runner.php graphics_colorbars.bin      # Load to default \$7E00\n";
    echo "  php graphics_runner.php graphics_checker.bin 7E00   # Load to \$7E00\n";
    echo "\n";
    exit(1);
}

if ($argc < 2) {
    showUsage();
}

$programBin = $argv[1];
$romsDir = __DIR__ . '/roms';
$programPath = $romsDir . '/' . $programBin;

if (!file_exists($programPath)) {
    echo "Error: Program binary not found: $programPath\n";
    echo "Available binaries in roms/:\n";
    $binaries = glob($romsDir . '/*.bin');
    foreach ($binaries as $binary) {
        echo "  " . basename($binary) . "\n";
    }
    exit(1);
}

// Determine load address
if ($argc >= 3) {
    $loadAddress = hexdec($argv[2]);
} else {
    $loadAddress = 0x7E00; // Default for graphics programs
}

// Initialize system with VideoMemory
$ram = new RAM();
$rom = new ROM(null);
$videoMemory = new VideoMemory();
$bus = new SystemBus($ram, $rom);
$bus->addPeripheral($videoMemory); // VideoMemory handles $0400-$F3FF

echo "PHP-6502 Graphics Runner\n";
echo "========================\n\n";

// Load program binary
$binaryData = file_get_contents($programPath);
if ($binaryData === false) {
    echo "Error: Could not read binary file: $programPath\n";
    exit(1);
}

$size = strlen($binaryData);

echo "Loading graphics program...\n";
echo "  File: " . basename($programPath) . "\n";
echo "  Size: $size bytes\n";
echo "  Load address: \$" . strtoupper(sprintf("%04X", $loadAddress)) . "\n\n";

// Parse the binary - last 6 bytes are interrupt vectors
// The program has two parts: CODE and VECTORS
$codeStart = $loadAddress;

// Assume the last 6 bytes are always vectors ($FFFA-$FFFF)
$codeSize = $size - 6;
$vectorsData = substr($binaryData, -6);
$codeData = substr($binaryData, 0, $codeSize);

// Load code to specified address
$address = $codeStart;
foreach (str_split($codeData) as $byte) {
    $bus->write($address++, ord($byte));
}

// Load vectors to $FFFA
$address = 0xFFFA;
foreach (str_split($vectorsData) as $byte) {
    $bus->write($address++, ord($byte));
}

echo "Loaded CODE segment: \$" . sprintf("%04X", $codeStart) . "-\$" . sprintf("%04X", $codeStart + $codeSize - 1) . "\n";
echo "Loaded VECTORS: \$FFFA-\$FFFF\n";

// Display the reset vector
$resetLo = ord($vectorsData[2]);
$resetHi = ord($vectorsData[3]);
$resetVector = ($resetHi << 8) | $resetLo;
echo "Reset vector: \$" . sprintf("%04X", $resetVector) . "\n\n";

// Create CPU and initialize
$cpu = new CPU($bus, null);
$bus->setCpu($cpu);

// Initialize renderer
$renderer = new ANSIRenderer(true, 2); // Use half-blocks, scale=2 (128×60)
$renderer->clear();

echo "Starting graphics program...\n";
echo "Running CPU...\n\n";

// Reset the CPU to start from the reset vector
$cpu->reset();

// Run the CPU for a limited number of cycles
$maxCycles = 1000000; // Run up to 1 million cycles
$cyclesRun = 0;

while (!$cpu->isHalted() && $cyclesRun < $maxCycles) {
    $cpu->step();
    $cyclesRun++;

    // Check if PC is at $8000 (program done, tried to jump to BIOS)
    if ($cpu->pc === 0x8000) {
        echo "Program completed (jumped to \$8000)\n";
        break;
    }
}

if ($cyclesRun >= $maxCycles) {
    echo "Warning: Maximum cycles reached ($maxCycles)\n";
}

echo "Cycles executed: $cyclesRun\n\n";

// Display the video memory
echo "Rendering framebuffer...\n";
$renderer->display($videoMemory->getFramebuffer());

echo "\n\nGraphics program execution complete.\n";
echo "Press Enter to exit...";
readline();
$renderer->clear();
