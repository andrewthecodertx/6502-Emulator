<?php

declare(strict_types=1);

namespace Emulator\Systems\BenEater;

/**
 * Terminal renderer using ANSI escape codes for framebuffer display.
 *
 * Converts framebuffer data to ANSI color codes for terminal output. Supports
 * Unicode half-block characters for double vertical resolution and configurable
 * scaling. Uses ANSI 256-color mode with customizable palette mapping.
 */
class ANSIRenderer
{
    /** @var array<int, int> Maps 8-bit palette indices to ANSI 256-color codes */
    private array $paletteMap = [];
    /** @var bool Whether to use Unicode half-blocks for double vertical resolution */
    private bool $useHalfBlocks = true;
    /** @var int Scale factor (1 = full size, 2 = half size, etc.) */
    private int $scale = 1;
    /** @var string ANSI escape sequence to clear screen and move cursor to top */
    private const CLEAR_SCREEN = "\033[2J\033[H";
    /** @var string ANSI escape sequence to hide cursor */
    private const HIDE_CURSOR = "\033[?25l";
    /** @var string ANSI escape sequence to show cursor */
    private const SHOW_CURSOR = "\033[?25h";
    /** @var string Unicode half-block upper character */
    private const HALF_BLOCK = '▀';

    /**
     * Creates a new ANSI renderer.
     *
     * @param bool $useHalfBlocks Use Unicode half-blocks for better vertical resolution
     * @param int $scale Scale factor (1=full size, 2=half size, etc.)
     */
    public function __construct(
        bool $useHalfBlocks = true,
        int $scale = 2
    ) {
        $this->useHalfBlocks = $useHalfBlocks;
        $this->scale = max(1, $scale);
        $this->initializeDefaultPalette();
    }

    private function initializeDefaultPalette(): void
    {
        // Direct mapping: use ANSI 256-color mode
        // 0-15: standard colors
        // 16-231: 6x6x6 color cube
        // 232-255: greyscale ramp
        for ($i = 0; $i < 256; $i++) {
            $this->paletteMap[$i] = $i;
        }
    }

    /**
     * Sets a custom palette mapping.
     *
     * @param array<int, int> $palette Array mapping palette index to ANSI color code
     */
    public function setPalette(array $palette): void
    {
        $this->paletteMap = $palette;
    }

    private function getANSIColor(int $paletteIndex): int
    {
        return $this->paletteMap[$paletteIndex] ?? $paletteIndex;
    }

    private function fgColor(int $color): string
    {
        return "\033[38;5;{$color}m";
    }

    private function bgColor(int $color): string
    {
        return "\033[48;5;{$color}m";
    }

    private function reset(): string
    {
        return "\033[0m";
    }

    /**
     * Renders framebuffer to ANSI string for terminal output.
     *
     * @param array<int, int> $framebuffer Array of palette indices (must be 61440 bytes)
     * @return string ANSI-formatted output string
     * @throws \InvalidArgumentException If framebuffer size is incorrect
     */
    public function render(array $framebuffer): string
    {
        if (count($framebuffer) !== VideoMemory::FRAMEBUFFER_SIZE) {
            throw new \InvalidArgumentException(
                'Framebuffer must be exactly ' . VideoMemory::FRAMEBUFFER_SIZE . ' bytes'
            );
        }

        $output = self::CLEAR_SCREEN . self::HIDE_CURSOR;

        if ($this->useHalfBlocks) {
            $output .= $this->renderHalfBlocks($framebuffer);
        } else {
            $output .= $this->renderFullBlocks($framebuffer);
        }

        $output .= $this->reset() . self::SHOW_CURSOR;

        return $output;
    }

    /** @param array<int, int> $framebuffer */
    private function renderHalfBlocks(array $framebuffer): string
    {
        $output = '';
        $height = VideoMemory::HEIGHT;
        $width = VideoMemory::WIDTH;

        // Process two rows at a time, applying scale
        for ($y = 0; $y < $height; $y += 2 * $this->scale) {
            for ($x = 0; $x < $width; $x += $this->scale) {
                $upperPixel = $framebuffer[$y * $width + $x];
                $lowerPixel = $framebuffer[($y + 1) * $width + $x] ?? $upperPixel;

                $upperColor = $this->getANSIColor($upperPixel);
                $lowerColor = $this->getANSIColor($lowerPixel);

                // Upper half-block: foreground = upper pixel, background = lower pixel
                $output .= $this->fgColor($upperColor);
                $output .= $this->bgColor($lowerColor);
                $output .= self::HALF_BLOCK;
            }
            $output .= $this->reset() . "\n";
        }

        return $output;
    }

    /** @param array<int, int> $framebuffer */
    private function renderFullBlocks(array $framebuffer): string
    {
        $output = '';
        $height = VideoMemory::HEIGHT;
        $width = VideoMemory::WIDTH;

        for ($y = 0; $y < $height; $y += $this->scale) {
            for ($x = 0; $x < $width; $x += $this->scale) {
                $pixel = $framebuffer[$y * $width + $x];
                $color = $this->getANSIColor($pixel);

                $output .= $this->bgColor($color) . ' ';
            }
            $output .= $this->reset() . "\n";
        }

        return $output;
    }

    /**
     * Renders and immediately outputs framebuffer to terminal.
     *
     * @param array<int, int> $framebuffer Array of palette indices
     * @throws \InvalidArgumentException If framebuffer size is incorrect
     */
    public function display(array $framebuffer): void
    {
        echo $this->render($framebuffer);
    }

    /** Clears the terminal screen. */
    public function clear(): void
    {
        echo self::CLEAR_SCREEN;
    }

    /**
     * Creates a test pattern framebuffer with horizontal gradient.
     *
     * @return array<int, int> Framebuffer with test pattern
     */
    public static function createTestPattern(): array
    {
        $buffer = [];

        for ($y = 0; $y < VideoMemory::HEIGHT; $y++) {
            for ($x = 0; $x < VideoMemory::WIDTH; $x++) {
                // Create gradient pattern
                $color = (int)(($x / VideoMemory::WIDTH) * 255);
                $buffer[] = $color;
            }
        }

        return $buffer;
    }

    /**
     * Creates a color bar test pattern framebuffer.
     *
     * @return array<int, int> Framebuffer with vertical color bars
     */
    public static function createColorBars(): array
    {
        $buffer = [];
        $colors = [
            255, // White
            226, // Yellow
            51,  // Cyan
            46,  // Green
            201, // Magenta
            196, // Red
            21,  // Blue
            0,   // Black
        ];

        $barWidth = (int)(VideoMemory::WIDTH / count($colors));

        for ($y = 0; $y < VideoMemory::HEIGHT; $y++) {
            for ($x = 0; $x < VideoMemory::WIDTH; $x++) {
                $barIndex = min((int)($x / $barWidth), count($colors) - 1);
                $buffer[] = $colors[$barIndex];
            }
        }

        return $buffer;
    }
}
