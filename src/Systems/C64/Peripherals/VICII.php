<?php

namespace Emulator\Systems\C64\Peripherals;

use Emulator\Systems\C64\Bus\PeripheralInterface;

/**
 * VIC-II Video Interface Controller (6567/6569)
 *
 * The VIC-II generates the C64's video output:
 * - 320×200 or 160×200 resolution
 * - 16 colors
 * - Character mode and bitmap modes
 * - 8 hardware sprites (movable objects)
 * - Smooth scrolling
 * - Raster interrupts
 *
 * Register Map ($D000-$D3FF, repeated):
 * $00-$0F: Sprite 0-7 X position (low byte)
 * $10: Sprite X position MSBs
 * $11: Control Register 1 (YSCROLL, RSEL, DEN, BMM, ECM)
 * $12: Raster counter/compare
 * $13-$14: Light pen X/Y
 * $15: Sprite enable
 * $16: Control Register 2 (XSCROLL, CSEL, MCM, RES)
 * $17: Sprite Y expansion
 * $18: Memory pointers (VM13-10, CB13-11)
 * $19: Interrupt status register (IRQ)
 * $1A: Interrupt enable register (IMR)
 * $1B: Sprite data priority
 * $1C: Sprite multicolor mode
 * $1D: Sprite X expansion
 * $1E: Sprite-sprite collision
 * $1F: Sprite-background collision
 * $20: Border color
 * $21: Background color 0
 * $22-$24: Background colors 1-3
 * $25-$2E: Sprite multicolors and colors 0-7
 *
 */
class VICII implements PeripheralInterface
{
    private const BASE_ADDRESS = 0xD000;
    private const RASTER_LINES = 312;  // PAL (263 for NTSC)

    /** @var array<int, int> */
    private array $registers;
    /** @var array<int, int> */
    private array $spriteX = [0, 0, 0, 0, 0, 0, 0, 0];
    /** @var array<int, int> */
    private array $spriteY = [0, 0, 0, 0, 0, 0, 0, 0];

    private int $rasterLine = 0;
    private int $rasterCompare = 0;
    private int $cycleCounter = 0;
    private const CYCLES_PER_LINE = 63;  // PAL

    private int $cr1 = 0x00;  // $D011
    private int $cr2 = 0x00;  // $D016
    private int $irqStatus = 0x00;   // $D019
    private int $irqMask = 0x00;     // $D01A
    private int $memorySetup = 0x00; // $D018

    /** @var array<int, int> */
    private array $colors = [
        0x000000, // Black
        0xFFFFFF, // White
        0x880000, // Red
        0xAAFFEE, // Cyan
        0xCC44CC, // Purple
        0x00CC55, // Green
        0x0000AA, // Blue
        0xEEEE77, // Yellow
        0xDD8855, // Orange
        0x664400, // Brown
        0xFF7777, // Light Red
        0x333333, // Dark Grey
        0x777777, // Grey
        0xAAFF66, // Light Green
        0x0088FF, // Light Blue
        0xBBBBBB, // Light Grey
    ];

    public function __construct()
    {
        $this->registers = array_fill(0, 0x2F, 0x00);
    }

    public function handlesAddress(int $address): bool
    {
        return $address >= 0xD000 && $address <= 0xD3FF;
    }

    public function read(int $address): int
    {
        $reg = ($address - self::BASE_ADDRESS) & 0x3F;

        return match ($reg) {
            0x00, 0x02, 0x04, 0x06, 0x08, 0x0A, 0x0C, 0x0E =>
                $this->spriteX[$reg >> 1] & 0xFF,

            0x01, 0x03, 0x05, 0x07, 0x09, 0x0B, 0x0D, 0x0F =>
                $this->spriteY[$reg >> 1],

            0x10 => $this->getSpriteXMSBs(),
            0x11 => ($this->cr1 & 0x7F) | (($this->rasterLine & 0x100) >> 1),
            0x12 => $this->rasterLine & 0xFF,
            0x13, 0x14 => 0x00,
            0x16 => $this->cr2,
            0x19 => $this->readIRQStatus(),
            0x1E => $this->registers[0x1E] & 0xFF,
            0x1F => $this->registers[0x1F] & 0xFF,

            default => $this->registers[$reg] ?? 0xFF,
        };
    }

    public function write(int $address, int $value): void
    {
        $value &= 0xFF;
        $reg = ($address - self::BASE_ADDRESS) & 0x3F;

        match ($reg) {
            0x00, 0x02, 0x04, 0x06, 0x08, 0x0A, 0x0C, 0x0E =>
                $this->spriteX[$reg >> 1] = ($this->spriteX[$reg >> 1] & 0x100) | $value,

            0x01, 0x03, 0x05, 0x07, 0x09, 0x0B, 0x0D, 0x0F =>
                $this->spriteY[$reg >> 1] = $value,

            0x10 => $this->setSpriteXMSBs($value),
            0x11 => $this->writeCR1($value),
            0x12 => $this->rasterCompare = ($this->rasterCompare & 0x100) | $value,
            0x1A => $this->irqMask = $value & 0x0F,
            0x18 => $this->memorySetup = $value,
            0x16 => $this->cr2 = $value,
            0x19 => $this->irqStatus &= ~($value & 0x0F),

            default => $this->registers[$reg] = $value,
        };
    }

    public function tick(): void
    {
        $this->cycleCounter++;

        if ($this->cycleCounter >= self::CYCLES_PER_LINE) {
            $this->cycleCounter = 0;
            $this->rasterLine++;

            if ($this->rasterLine >= self::RASTER_LINES) {
                $this->rasterLine = 0;
            }

            if ($this->rasterLine === $this->rasterCompare) {
                $this->irqStatus |= 0x01;  // Set raster IRQ flag

                if (($this->irqMask & 0x01) !== 0) {
                    $this->irqStatus |= 0x80;  // Set IRQ line
                }
            }
        }
    }

    private function writeCR1(int $value): void
    {
        $this->cr1 = $value;

        // Update raster compare bit 8
        if (($value & 0x80) !== 0) {
            $this->rasterCompare |= 0x100;
        } else {
            $this->rasterCompare &= 0xFF;
        }
    }

    private function getSpriteXMSBs(): int
    {
        $result = 0;
        for ($i = 0; $i < 8; $i++) {
            if ($this->spriteX[$i] & 0x100) {
                $result |= (1 << $i);
            }
        }
        return $result;
    }

    private function setSpriteXMSBs(int $value): void
    {
        for ($i = 0; $i < 8; $i++) {
            if (($value & (1 << $i)) !== 0) {
                $this->spriteX[$i] |= 0x100;
            } else {
                $this->spriteX[$i] &= 0xFF;
            }
        }
    }

    private function readIRQStatus(): int
    {
        $value = $this->irqStatus;

        if (($this->irqStatus & $this->irqMask & 0x0F) !== 0) {
            $value |= 0x80;
        }

        return $value;
    }

    public function isInterruptPending(): bool
    {
        return (($this->irqStatus & $this->irqMask & 0x0F) !== 0);
    }

    public function getRasterLine(): int
    {
        return $this->rasterLine;
    }

    /** @return array{extended_color: bool, bitmap_mode: bool, multicolor: bool, display_enabled: bool} */
    public function getScreenMode(): array
    {
        $ecm = ($this->cr1 & 0x40) !== 0;
        $bmm = ($this->cr1 & 0x20) !== 0;
        $mcm = ($this->cr2 & 0x10) !== 0;

        return [
            'extended_color' => $ecm,
            'bitmap_mode' => $bmm,
            'multicolor' => $mcm,
            'display_enabled' => ($this->cr1 & 0x10) !== 0,
        ];
    }

    /** @return array{video_matrix: int, char_data: int} */
    public function getMemoryPointers(): array
    {
        return [
            'video_matrix' => (($this->memorySetup >> 4) & 0x0F) * 0x0400,
            'char_data' => (($this->memorySetup >> 1) & 0x07) * 0x0800,
        ];
    }
}
