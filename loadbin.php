<?php

declare(strict_types=1);

require_once 'vendor/autoload.php';

use Emulator\CPU;
use Emulator\RAM;
use Emulator\ROM;
use Emulator\UART;
use Emulator\Bus\SystemBus;

function showUsage(): void
{
  echo "Usage: php loadbin.php <program.bin>\n";
  echo "Example: php loadbin.php wozmon_uart.bin\n";
  echo "\n";
  echo "This will:\n";
  echo "1. Load BIOS ROM at \$8000 (protected)\n";
  echo "2. Load the specified program at its intended address\n";
  echo "3. Start the system\n";
  exit(1);
}

function loadMetadata(string $jsonPath): ?array
{
  if (!file_exists($jsonPath)) {
    return null;
  }

  $content = file_get_contents($jsonPath);
  if ($content === false) {
    return null;
  }

  $metadata = json_decode($content, true);
  if (json_last_error() !== JSON_ERROR_NONE) {
    echo "Error: Invalid JSON in metadata file: " . json_last_error_msg() . "\n";
    return null;
  }

  return $metadata;
}

function checkBiosConflict(array $metadata): bool
{
  if (isset($metadata['conflicts_with_bios']) && $metadata['conflicts_with_bios']) {
    return true;
  }

  // Additional check - parse load_address
  if (isset($metadata['load_address'])) {
    $loadAddr = hexdec(str_replace('0x', '', $metadata['load_address']));
    // BIOS space is 0x8000 to roughly 0x8500 (with safety margin)
    if ($loadAddr >= 0x8000 && $loadAddr <= 0x8500) {
      return true;
    }
  }

  if (isset($metadata['linker_segments'])) {
    foreach ($metadata['linker_segments'] as $segment) {
      if (isset($segment['start'])) {
        $startAddr = hexdec(str_replace('0x', '', $segment['start']));
        if ($startAddr >= 0x8000 && $startAddr <= 0x8500) {
          return true;
        }
      }
    }
  }

  return false;
}

function loadBinaryToMemory(SystemBus $bus, string $binaryPath, array $metadata): void
{
  $binaryData = file_get_contents($binaryPath);
  if ($binaryData === false) {
    throw new RuntimeException("Could not read binary file: $binaryPath");
  }

  $loadAddress = 0x0200; // Default load address
  if (isset($metadata['load_address'])) {
    $loadAddress = hexdec(str_replace('0x', '', $metadata['load_address']));
  }

  echo "Loading " . basename($binaryPath) . " to 0x" . strtoupper(dechex($loadAddress)) . "\n";
  echo "Size: " . strlen($binaryData) . " bytes\n";

  $address = $loadAddress;
  foreach (str_split($binaryData) as $byte) {
    $bus->write($address++, ord($byte));
  }
}

if ($argc !== 2) {
  showUsage();
}

$programBin = $argv[1];
$romsDir = __DIR__ . '/roms';
$programPath = $romsDir . '/' . $programBin;
$programJsonPath = $romsDir . '/' . pathinfo($programBin, PATHINFO_FILENAME) . '.json';
$biosPath = $romsDir . '/bios.bin';
$biosJsonPath = $romsDir . '/bios.json';

if (!file_exists($programPath)) {
  echo "Error: Program binary not found: $programPath\n";
  echo "Available binaries in roms/:\n";
  $binaries = glob($romsDir . '/*.bin');
  foreach ($binaries as $binary) {
    echo "  " . basename($binary) . "\n";
  }
  exit(1);
}

if (!file_exists($biosPath)) {
  echo "Error: BIOS not found: $biosPath\n";
  echo "Please build BIOS first.\n";
  exit(1);
}

$programMetadata = loadMetadata($programJsonPath);
if ($programMetadata === null) {
  echo "Warning: No metadata found for $programBin (expected: $programJsonPath)\n";
  echo "Using default load address 0x0200\n";
  $programMetadata = [
    'name' => pathinfo($programBin, PATHINFO_FILENAME),
    'load_address' => '0x0200',
    'size' => filesize($programPath)
  ];
}

$biosMetadata = loadMetadata($biosJsonPath);
if ($biosMetadata === null) {
  echo "Warning: No BIOS metadata found\n";
}

if (checkBiosConflict($programMetadata)) {
  echo "ERROR: Program conflicts with BIOS protected space!\n";
  echo "BIOS occupies: 0x8000-0x8500 (protected)\n";
  echo "Program wants: " . ($programMetadata['load_address'] ?? 'unknown') . "\n";
  echo "\nBIOS space is sacred and cannot be overwritten.\n";
  echo "Please modify your program to load elsewhere.\n";
  exit(1);
}

echo "PHP-6502 System Loader\n";
echo "======================\n";
echo "BIOS: " . basename($biosPath) . " (protected at \$8000)\n";
echo "Program: " . basename($programPath) . "\n";
echo "Load Address: " . ($programMetadata['load_address'] ?? '0x0200') . "\n";
echo "\n";

$ram = new RAM();
$rom = new ROM(null);

echo "Loading BIOS into ROM...\n";
$rom->loadBinaryROM($biosPath);

$uart = new UART(0xFE00);
$bus = new SystemBus($ram, $rom);
$bus->addPeripheral($uart);

echo "Loading program into memory...\n";
loadBinaryToMemory($bus, $programPath, $programMetadata);

$cpu = new CPU($bus, null);

$loadAddress = 0x0200;
if (isset($programMetadata['load_address'])) {
  $loadAddress = hexdec(str_replace('0x', '', $programMetadata['load_address']));
}

if ($loadAddress < 0x8000) {
  echo "\nJumping to user program at 0x" . strtoupper(dechex($loadAddress)) . "...\n";
  $cpu->pc = $loadAddress;
  $cpu->sp = 0xFF; // Initialize stack pointer
  echo "System ready!\n";
  echo $cpu->getRegistersState() . PHP_EOL;
  echo $cpu->getFlagsState() . PHP_EOL;
  echo "\n";
} else {
  echo "\nStarting BIOS...\n";
  $cpu->reset();
  echo "System ready!\n";
  echo $cpu->getRegistersState() . PHP_EOL;
  echo $cpu->getFlagsState() . PHP_EOL;
  echo "\n";
}

$cpu->run();

