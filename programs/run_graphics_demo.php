<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Emulator\Core\CPU;
use Emulator\Systems\Eater\Bus\SystemBus;
use Emulator\Systems\Eater\RAM;
use Emulator\Systems\Eater\ROM;
use Emulator\Systems\Eater\VideoMemory;
use Emulator\Systems\Eater\ANSIRenderer;

// Configuration
$programFile = __DIR__ . '/../roms/graphics_demo.bin';
$maxCycles = 50000000; // 50 million cycles max
$renderInterval = 10000; // Render every N cycles

if (!file_exists($programFile)) {
    echo "Error: {$programFile} not found.\n";
    echo "Please assemble the program first:\n";
    echo "  ./programs/buildasm.sh graphics_demo.asm\n";
    exit(1);
}

// Load program binary
$program = file_get_contents($programFile);
if ($program === false) {
    echo "Error: Could not read {$programFile}\n";
    exit(1);
}

echo "PHP-6502 Graphics Demo\n";
echo "======================\n\n";
echo "Loading program: {$programFile} (" . strlen($program) . " bytes)\n";

// Create system components
$ram = new RAM();  // RAM takes optional CPUMonitor
$rom = new ROM(null);  // ROM takes optional directory path
$video = new VideoMemory(0x0400, 0xF3FF);

// Create system bus and attach components
$bus = new SystemBus($ram, $rom);
$bus->addPeripheral($video);

// Create CPU
$cpu = new CPU($bus);
$bus->setCpu($cpu);

// Load program into RAM at $0200
$loadAddress = 0x0200;
for ($i = 0; $i < strlen($program); $i++) {
    $bus->write($loadAddress + $i, ord($program[$i]));
}

// Initialize CPU - set PC directly without using reset vector
// (For RAM-based programs, we don't need the ROM reset vector mechanism)
$cpu->pc = 0x0200;

// Create renderer
$renderer = new ANSIRenderer(true, 2); // Use half-blocks, scale 2

// Clear screen
$renderer->clear();

echo "Starting graphics demo...\n";
echo "Press Ctrl+C to exit\n\n";

// Small delay to let user read the message
usleep(500000); // 0.5 seconds

$cycleCount = 0;
$lastRenderCycle = 0;
$frameCount = 0;
$instructionCount = 0;

try {
    while ($cycleCount < $maxCycles) {
        // Execute one instruction
        $cpu->step();
        $cycleCount++;

        // Track instruction count
        if ($cpu->cycles == 0) {
            $instructionCount++;
        }

        // Render periodically
        if ($cycleCount - $lastRenderCycle >= $renderInterval) {
            // Always render to see what's happening
            $renderer->display($video->getFramebuffer());
            $frameCount++;

            $wasDirty = $video->isDirty(true);

            // Show status (top of screen)
            echo "\033[1;1H"; // Move cursor to top
            echo "\033[38;5;15m\033[48;5;0m"; // White on black
            echo sprintf(
                "Graphics Demo | Cycles: %8d | Frames: %4d | PC: \$%04X | Dirty: %s | Inst: %d",
                $cycleCount,
                $frameCount,
                $cpu->pc,
                $wasDirty ? "YES" : "NO ",
                $instructionCount
            );
            echo "\033[K"; // Clear to end of line
            echo "\033[0m"; // Reset

            $lastRenderCycle = $cycleCount;
        }
    }
} catch (Exception $e) {
    echo "\n\nError: " . $e->getMessage() . "\n";
    echo "At PC: $" . sprintf("%04X", $cpu->pc) . "\n";
    echo "Instruction count: " . $instructionCount . "\n";
    exit(1);
}

// Final render
if ($video->isDirty(true)) {
    $renderer->display($video->getFramebuffer());
}

echo "\n\nDemo completed!\n";
echo "Total cycles: {$cycleCount}\n";
echo "Frames rendered: {$frameCount}\n";
