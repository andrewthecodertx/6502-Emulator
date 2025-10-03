<?php

declare(strict_types=1);

namespace Emulator;

use Emulator\Bus\PeripheralInterface;

/**
 * Video Memory Peripheral
 *
 * Provides a framebuffer for graphical output.
 * Supports 256x240 resolution with 8-bit color (palette indices).
 *
 * Memory layout:
 * - Framebuffer: 61,440 bytes (256 * 240 pixels)
 * - Each byte represents one pixel as a palette index (0-255)
 *
 * Default address range: $0400-$F3FF (61,440 bytes = 256x240 framebuffer)
 */
class VideoMemory implements PeripheralInterface
{
    public const DEFAULT_START = 0x0400;
    public const DEFAULT_END = 0xF3FF;  // Exactly 61,440 bytes for framebuffer

    public const WIDTH = 256;
    public const HEIGHT = 240;
    public const FRAMEBUFFER_SIZE = self::WIDTH * self::HEIGHT; // 61,440 bytes

    private int $startAddress;
    private int $endAddress;

    /** @var array<int> Framebuffer data (palette indices) */
    private array $framebuffer = [];

    /** @var bool Tracks if display has been modified */
    private bool $dirty = false;

    /** @var int Frame counter for vsync */
    private int $frameCount = 0;

    public function __construct(int $startAddress = self::DEFAULT_START, int $endAddress = self::DEFAULT_END)
    {
        $this->startAddress = $startAddress;
        $this->endAddress = $endAddress;

        // Initialize framebuffer with zeros (black)
        $this->framebuffer = array_fill(0, $this->endAddress - $this->startAddress + 1, 0);
    }

    public function handlesAddress(int $address): bool
    {
        return $address >= $this->startAddress && $address <= $this->endAddress;
    }

    public function read(int $address): int
    {
        if (!$this->handlesAddress($address)) {
            return 0xFF;
        }

        $offset = $address - $this->startAddress;
        return $this->framebuffer[$offset] ?? 0;
    }

    public function write(int $address, int $value): void
    {
        if (!$this->handlesAddress($address)) {
            return;
        }

        $offset = $address - $this->startAddress;
        $this->framebuffer[$offset] = $value & 0xFF;
        $this->dirty = true;
    }

    public function tick(): void
    {
        // Could be used for vsync or other timing-related tasks
    }

    public function reset(): void
    {
        $this->framebuffer = array_fill(0, $this->endAddress - $this->startAddress + 1, 0);
        $this->dirty = false;
        $this->frameCount = 0;
    }

    /**
     * Get the framebuffer data
     *
     * @return array<int> Array of palette indices
     */
    public function getFramebuffer(): array
    {
        return array_slice($this->framebuffer, 0, self::FRAMEBUFFER_SIZE);
    }

    /**
     * Get framebuffer as a packed binary string for efficient transmission
     *
     * @return string Binary string of framebuffer data
     */
    public function getFramebufferBinary(): string
    {
        return pack('C*', ...array_slice($this->framebuffer, 0, self::FRAMEBUFFER_SIZE));
    }

    /**
     * Check if the display has been modified since last check
     *
     * @param bool $reset If true, resets the dirty flag
     * @return bool True if framebuffer has been modified
     */
    public function isDirty(bool $reset = false): bool
    {
        $wasDirty = $this->dirty;

        if ($reset && $this->dirty) {
            $this->dirty = false;
            $this->frameCount++;
        }

        return $wasDirty;
    }

    /**
     * Get the number of frames rendered
     */
    public function getFrameCount(): int
    {
        return $this->frameCount;
    }

    /**
     * Get video memory configuration
     *
     * @return array<string, mixed>
     */
    public function getConfig(): array
    {
        return [
            'width' => self::WIDTH,
            'height' => self::HEIGHT,
            'framebuffer_size' => self::FRAMEBUFFER_SIZE,
            'start_address' => sprintf('$%04X', $this->startAddress),
            'end_address' => sprintf('$%04X', $this->endAddress),
            'total_size' => $this->endAddress - $this->startAddress + 1,
        ];
    }

    /**
     * Set a pixel at specific coordinates
     *
     * @param int $x X coordinate (0-255)
     * @param int $y Y coordinate (0-239)
     * @param int $color Palette index (0-255)
     */
    public function setPixel(int $x, int $y, int $color): void
    {
        if ($x < 0 || $x >= self::WIDTH || $y < 0 || $y >= self::HEIGHT) {
            return;
        }

        $offset = $y * self::WIDTH + $x;
        $this->framebuffer[$offset] = $color & 0xFF;
        $this->dirty = true;
    }

    /**
     * Get a pixel at specific coordinates
     *
     * @param int $x X coordinate (0-255)
     * @param int $y Y coordinate (0-239)
     * @return int Palette index (0-255)
     */
    public function getPixel(int $x, int $y): int
    {
        if ($x < 0 || $x >= self::WIDTH || $y < 0 || $y >= self::HEIGHT) {
            return 0;
        }

        $offset = $y * self::WIDTH + $x;
        return $this->framebuffer[$offset] ?? 0;
    }

    /**
     * Clear the framebuffer to a specific color
     *
     * @param int $color Palette index (0-255)
     */
    public function clear(int $color = 0): void
    {
        $this->framebuffer = array_fill(0, $this->endAddress - $this->startAddress + 1, $color & 0xFF);
        $this->dirty = true;
    }
}
