#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

use Emulator\VideoMemory;
use Emulator\ANSIRenderer;

/**
 * Graphics Demo - Test ANSI Terminal Renderer
 *
 * Demonstrates VideoMemory + ANSIRenderer integration with various test patterns
 */

function showMenu(): void
{
    echo "\n=== 6502 Graphics Demo ===\n";
    echo "1. Horizontal Gradient\n";
    echo "2. Vertical Gradient\n";
    echo "3. Color Bars (TV Test Pattern)\n";
    echo "4. Checkerboard\n";
    echo "5. Circle\n";
    echo "6. Plasma Effect (Animated)\n";
    echo "7. Random Noise (Animated)\n";
    echo "8. Draw Pixel Test\n";
    echo "q. Quit\n\n";
    echo "Select demo: ";
}

function horizontalGradient(VideoMemory $video): void
{
    for ($y = 0; $y < VideoMemory::HEIGHT; $y++) {
        for ($x = 0; $x < VideoMemory::WIDTH; $x++) {
            $color = (int)(($x / VideoMemory::WIDTH) * 255);
            $video->setPixel($x, $y, $color);
        }
    }
}

function verticalGradient(VideoMemory $video): void
{
    for ($y = 0; $y < VideoMemory::HEIGHT; $y++) {
        for ($x = 0; $x < VideoMemory::WIDTH; $x++) {
            $color = (int)(($y / VideoMemory::HEIGHT) * 255);
            $video->setPixel($x, $y, $color);
        }
    }
}

function colorBars(VideoMemory $video): void
{
    $colors = [255, 226, 51, 46, 201, 196, 21, 0];
    $barWidth = (int)(VideoMemory::WIDTH / count($colors));

    for ($y = 0; $y < VideoMemory::HEIGHT; $y++) {
        for ($x = 0; $x < VideoMemory::WIDTH; $x++) {
            $barIndex = min((int)($x / $barWidth), count($colors) - 1);
            $video->setPixel($x, $y, $colors[$barIndex]);
        }
    }
}

function checkerboard(VideoMemory $video): void
{
    $size = 16;
    for ($y = 0; $y < VideoMemory::HEIGHT; $y++) {
        for ($x = 0; $x < VideoMemory::WIDTH; $x++) {
            $color = ((int)($x / $size) + (int)($y / $size)) % 2 === 0 ? 255 : 0;
            $video->setPixel($x, $y, $color);
        }
    }
}

function circle(VideoMemory $video): void
{
    $video->clear(0);
    $centerX = VideoMemory::WIDTH / 2;
    $centerY = VideoMemory::HEIGHT / 2;
    $radius = 80;

    for ($y = 0; $y < VideoMemory::HEIGHT; $y++) {
        for ($x = 0; $x < VideoMemory::WIDTH; $x++) {
            $dx = $x - $centerX;
            $dy = $y - $centerY;
            $distance = sqrt($dx * $dx + $dy * $dy);

            if ($distance <= $radius) {
                $color = (int)((1.0 - ($distance / $radius)) * 255);
                $video->setPixel($x, $y, $color);
            }
        }
    }
}

function plasma(VideoMemory $video, float $time): void
{
    for ($y = 0; $y < VideoMemory::HEIGHT; $y++) {
        for ($x = 0; $x < VideoMemory::WIDTH; $x++) {
            $value = sin($x / 16.0 + $time);
            $value += sin($y / 8.0 + $time);
            $value += sin(($x + $y) / 16.0 + $time);
            $value += sin(sqrt($x * $x + $y * $y) / 8.0 + $time);

            $color = (int)((($value + 4.0) / 8.0) * 255);
            $video->setPixel($x, $y, $color);
        }
    }
}

function randomNoise(VideoMemory $video): void
{
    for ($y = 0; $y < VideoMemory::HEIGHT; $y++) {
        for ($x = 0; $x < VideoMemory::WIDTH; $x++) {
            $video->setPixel($x, $y, rand(0, 255));
        }
    }
}

function drawPixelTest(VideoMemory $video): void
{
    $video->clear(0);

    // Draw border
    for ($x = 0; $x < VideoMemory::WIDTH; $x++) {
        $video->setPixel($x, 0, 255);
        $video->setPixel($x, VideoMemory::HEIGHT - 1, 255);
    }
    for ($y = 0; $y < VideoMemory::HEIGHT; $y++) {
        $video->setPixel(0, $y, 255);
        $video->setPixel(VideoMemory::WIDTH - 1, $y, 255);
    }

    // Draw diagonal lines
    for ($i = 0; $i < min(VideoMemory::WIDTH, VideoMemory::HEIGHT); $i++) {
        $video->setPixel($i, $i, 196); // Red diagonal
        $video->setPixel(VideoMemory::WIDTH - 1 - $i, $i, 46); // Green diagonal
    }

    // Draw center cross
    $centerX = VideoMemory::WIDTH / 2;
    $centerY = VideoMemory::HEIGHT / 2;
    for ($x = 0; $x < VideoMemory::WIDTH; $x++) {
        $video->setPixel($x, (int)$centerY, 51); // Cyan horizontal
    }
    for ($y = 0; $y < VideoMemory::HEIGHT; $y++) {
        $video->setPixel((int)$centerX, $y, 226); // Yellow vertical
    }
}

function runAnimatedDemo(callable $drawFunc, VideoMemory $video, ANSIRenderer $renderer, int $frames = 60): void
{
    echo "Press Ctrl+C to stop...\n";
    sleep(1);

    for ($frame = 0; $frame < $frames; $frame++) {
        $time = $frame * 0.1;
        $drawFunc($video, $time);
        $renderer->display($video->getFramebuffer());
        usleep(33333); // ~30 FPS
    }

    echo "\n\nAnimation complete. Press Enter to continue...";
    readline();
}

function runStaticDemo(callable $drawFunc, VideoMemory $video, ANSIRenderer $renderer): void
{
    $drawFunc($video);
    $renderer->display($video->getFramebuffer());
    echo "\n\nPress Enter to continue...";
    readline();
}

// Main program
$video = new VideoMemory();
$renderer = new ANSIRenderer(true, 2); // Use half-blocks, scale=2 (128×60 chars)

while (true) {
    $renderer->clear();
    showMenu();

    $choice = trim((string)readline());

    switch ($choice) {
        case '1':
            runStaticDemo('horizontalGradient', $video, $renderer);
            break;

        case '2':
            runStaticDemo('verticalGradient', $video, $renderer);
            break;

        case '3':
            runStaticDemo('colorBars', $video, $renderer);
            break;

        case '4':
            runStaticDemo('checkerboard', $video, $renderer);
            break;

        case '5':
            runStaticDemo('circle', $video, $renderer);
            break;

        case '6':
            runAnimatedDemo('plasma', $video, $renderer, 120);
            break;

        case '7':
            runAnimatedDemo(fn($v, $t) => randomNoise($v), $video, $renderer, 60);
            break;

        case '8':
            runStaticDemo('drawPixelTest', $video, $renderer);
            break;

        case 'q':
        case 'Q':
            $renderer->clear();
            echo "Goodbye!\n";
            exit(0);

        default:
            echo "Invalid choice. Try again.\n";
            sleep(1);
    }
}
