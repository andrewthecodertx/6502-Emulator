<?php

declare(strict_types=1);

namespace Emulator\Systems\Eater;

use Emulator\Systems\Eater\Bus\PeripheralInterface;

/**
 * Framebuffer memory for 256x240 graphics display.
 *
 * Provides memory-mapped access to a 61,440-byte framebuffer where each byte
 * represents one pixel's palette index. Includes dirty tracking for efficient
 * rendering and frame counting for vsync.
 */
class VideoMemory implements PeripheralInterface
{
    public const DEFAULT_START = 0x0400;
    public const DEFAULT_END = 0xF3FF;  // Exactly 61,440 bytes for framebuffer

    public const WIDTH = 256;
    public const HEIGHT = 240;
    public const FRAMEBUFFER_SIZE = self::WIDTH * self::HEIGHT; // 61,440 bytes

    /** @var bool Tracks if display has been modified */ private bool $dirty = false;
    /** @var int Frame counter for vsync */ private int $frameCount = 0;

    /** @var array<int, int> */
    private array $framebuffer = [];

    /**
     * Creates a new video memory instance.
     *
     * @param int $startAddress The starting memory address (default: $0400)
     * @param int $endAddress The ending memory address (default: $F3FF)
     */
    public function __construct(
        private int $startAddress = self::DEFAULT_START,
        private int $endAddress = self::DEFAULT_END
    ) {
        $this->framebuffer = array_fill(0, $this->endAddress - $this->startAddress + 1, 0);
    }

    /**
     * Checks if this video memory handles the specified address.
     *
     * @param int $address The memory address to check
     * @return bool True if address is within framebuffer range
     */
    public function handlesAddress(int $address): bool
    {
        return $address >= $this->startAddress && $address <= $this->endAddress;
    }

    /** @param int $address The memory address to read
     * @return int The palette index (0-255) or 0xFF if out of range */
    public function read(int $address): int
    {
        if (!$this->handlesAddress($address)) {
            return 0xFF;
        }

        $offset = $address - $this->startAddress;
        return $this->framebuffer[$offset] ?? 0;
    }

    /**
     * Writes a palette index to video memory and marks framebuffer dirty.
     *
     * @param int $address The memory address to write
     * @param int $value The palette index (will be masked to 8-bit)
     */
    public function write(int $address, int $value): void
    {
        if (!$this->handlesAddress($address)) {
            return;
        }

        $offset = $address - $this->startAddress;
        $this->framebuffer[$offset] = $value & 0xFF;
        $this->dirty = true;
    }

    /** Performs one cycle of video memory operation (no-op). */
    public function tick(): void
    {
    }

    /** @return bool Always false (video memory does not generate interrupts) */
    public function hasInterruptRequest(): bool
    {
        return false;
    }

    /** Clears the framebuffer and resets dirty/frame tracking. */
    public function reset(): void
    {
        $this->framebuffer = array_fill(0, $this->endAddress - $this->startAddress + 1, 0);
        $this->dirty = false;
        $this->frameCount = 0;
    }

    /** @return array<int, int> Array of palette indices (256x240 pixels) */
    public function getFramebuffer(): array
    {
        return array_slice($this->framebuffer, 0, self::FRAMEBUFFER_SIZE);
    }

    /** @return string Binary framebuffer data */
    public function getFramebufferBinary(): string
    {
        return pack('C*', ...array_slice($this->framebuffer, 0, self::FRAMEBUFFER_SIZE));
    }

    /**
     * Checks if framebuffer has been modified since last reset.
     *
     * @param bool $reset If true, clears dirty flag and increments frame counter
     * @return bool True if framebuffer was dirty
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

    /** @return int The frame count */
    public function getFrameCount(): int
    {
        return $this->frameCount;
    }

    /** @return array{width: int, height: int, framebuffer_size: int, start_address: string, end_address: string, total_size: int} */
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
     * Sets a pixel to the specified color.
     *
     * @param int $x X coordinate (0-255)
     * @param int $y Y coordinate (0-239)
     * @param int $color Palette index (will be masked to 8-bit)
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
     * Gets the color of a pixel.
     *
     * @param int $x X coordinate (0-255)
     * @param int $y Y coordinate (0-239)
     * @return int The palette index (0-255) or 0 if out of bounds
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
     * Clears the entire framebuffer to the specified color.
     *
     * @param int $color Palette index to fill with (default: 0)
     */
    public function clear(int $color = 0): void
    {
        $this->framebuffer = array_fill(0, $this->endAddress - $this->startAddress + 1, $color & 0xFF);
        $this->dirty = true;
    }
}
