<?php

declare(strict_types=1);

namespace Emulator;

use Emulator\Bus\PeripheralInterface;

class VideoMemory implements PeripheralInterface
{
    public const DEFAULT_START = 0x0400;
    public const DEFAULT_END = 0xF3FF;  // Exactly 61,440 bytes for framebuffer

    public const WIDTH = 256;
    public const HEIGHT = 240;
    public const FRAMEBUFFER_SIZE = self::WIDTH * self::HEIGHT; // 61,440 bytes

    /** @var bool Tracks if display has been modified */ private bool $dirty = false;
    /** @var int Frame counter for vsync */ private int $frameCount = 0;

    public function __construct(
        private int $startAddress = self::DEFAULT_START,
        private int $endAddress = self::DEFAULT_END,
        /** @var array<int> $framebuffer */
        private array $framebuffer = []
    ) {
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
    }

    public function hasInterruptRequest(): bool
    {
        return false;
    }

    public function reset(): void
    {
        $this->framebuffer = array_fill(0, $this->endAddress - $this->startAddress + 1, 0);
        $this->dirty = false;
        $this->frameCount = 0;
    }

    /** @return array<int> Array of palette indices */
    public function getFramebuffer(): array
    {
        return array_slice($this->framebuffer, 0, self::FRAMEBUFFER_SIZE);
    }

    public function getFramebufferBinary(): string
    {
        return pack('C*', ...array_slice($this->framebuffer, 0, self::FRAMEBUFFER_SIZE));
    }

    public function isDirty(bool $reset = false): bool
    {
        $wasDirty = $this->dirty;

        if ($reset && $this->dirty) {
            $this->dirty = false;
            $this->frameCount++;
        }

        return $wasDirty;
    }

    public function getFrameCount(): int
    {
        return $this->frameCount;
    }

    /** @return array<string,int|string> */
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

    public function setPixel(int $x, int $y, int $color): void
    {
        if ($x < 0 || $x >= self::WIDTH || $y < 0 || $y >= self::HEIGHT) {
            return;
        }

        $offset = $y * self::WIDTH + $x;
        $this->framebuffer[$offset] = $color & 0xFF;
        $this->dirty = true;
    }

    public function getPixel(int $x, int $y): int
    {
        if ($x < 0 || $x >= self::WIDTH || $y < 0 || $y >= self::HEIGHT) {
            return 0;
        }

        $offset = $y * self::WIDTH + $x;
        return $this->framebuffer[$offset] ?? 0;
    }

    public function clear(int $color = 0): void
    {
        $this->framebuffer = array_fill(0, $this->endAddress - $this->startAddress + 1, $color & 0xFF);
        $this->dirty = true;
    }
}
