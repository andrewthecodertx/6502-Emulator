<?php

declare(strict_types=1);

require_once 'vendor/autoload.php';

use Emulator\CPU;
use Emulator\RAM;
use Emulator\ROM;
use Emulator\UART;
use Emulator\Bus\SystemBus;
use Emulator\Peripherals\VIA;

function showUsage(): void
{
    echo "Usage: php loadbin.php <program.bin> [load_address]\n";
    echo "\n";
    echo "Examples:\n";
    echo "  php loadbin.php bios.bin          # Load BIOS ROM (loads to \$8000)\n";
    echo "  php loadbin.php wozmon_uart.bin   # Load program to \$0200 (default)\n";
    echo "  php loadbin.php program.bin 7E00  # Load program to \$7E00\n";
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

// Initialize system
$ram = new RAM();
$rom = new ROM(null);
$uart = new UART(0xFE00);
$via = new VIA(0xFE40);
$bus = new SystemBus($ram, $rom);
$bus->addPeripheral($uart);
$bus->addPeripheral($via);

echo "PHP-6502 Emulator\n";
echo "=================\n\n";

$binaryData = file_get_contents($programPath);
if ($binaryData === false) {
    echo "Error: Could not read binary file: $programPath\n";
    exit(1);
}

$size = strlen($binaryData);

// Determine if this is BIOS or user program
$isBios = (basename($programBin) === 'bios.bin');

if ($isBios) {
    // BIOS: Load into ROM at $8000
    echo "Loading BIOS ROM...\n";
    $rom->loadBinaryROM($programPath);
    echo "  File: " . basename($programPath) . "\n";
    echo "  Size: $size bytes\n";
    echo "  Location: ROM (\$8000-\$FFFF)\n\n";

    $cpu = new CPU($bus, null);
    $bus->setCpu($cpu);
    echo "Starting system via RESET vector...\n";
    $cpu->reset();
} else {
    // User program: Load into RAM
    // Determine load address
    if ($argc >= 3) {
        $loadAddress = hexdec($argv[2]);
    } else {
        $loadAddress = 0x0200; // Default to $0200 (after zero page and stack)
    }

    echo "Loading program into RAM...\n";
    echo "  File: " . basename($programPath) . "\n";
    echo "  Size: $size bytes\n";
    echo "  Load address: \$" . strtoupper(sprintf("%04X", $loadAddress)) . "\n\n";

    // Load binary into memory
    $address = $loadAddress;
    foreach (str_split($binaryData) as $byte) {
        $bus->write($address++, ord($byte));
    }

    // Check if there's a BIOS to load
    $biosPath = $romsDir . '/bios.bin';
    if (file_exists($biosPath)) {
        echo "Loading BIOS ROM...\n";
        $rom->loadBinaryROM($biosPath);
        echo "  Location: ROM (\$8000-\$FFFF)\n\n";
    }

    $cpu = new CPU($bus, null);
    $bus->setCpu($cpu);

    // Set PC to load address and initialize stack
    $cpu->pc = $loadAddress;
    $cpu->sp = 0xFF;
    echo "Starting program at \$" . strtoupper(sprintf("%04X", $loadAddress)) . "...\n";
}

echo "\nSystem State:\n";
echo $cpu->getRegistersState() . "\n";
echo $cpu->getFlagsState() . "\n";
echo "\n";

$cpu->run();
