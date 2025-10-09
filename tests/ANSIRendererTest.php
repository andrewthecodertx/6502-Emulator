<?php

declare(strict_types=1);

namespace Tests;

use Emulator\ANSIRenderer;
use Emulator\VideoMemory;
use PHPUnit\Framework\TestCase;

class ANSIRendererTest extends TestCase
{
    private ANSIRenderer $renderer;

    protected function setUp(): void
    {
        $this->renderer = new ANSIRenderer(true, 1); // Use scale=1 for testing (full size)
    }

    public function testRendererCreation(): void
    {
        $renderer = new ANSIRenderer();
        $this->assertInstanceOf(ANSIRenderer::class, $renderer);
    }

    public function testRenderThrowsExceptionForInvalidFramebufferSize(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Framebuffer must be exactly 61440 bytes');

        $invalidBuffer = array_fill(0, 100, 0);
        $this->renderer->render($invalidBuffer);
    }

    public function testRenderReturnsString(): void
    {
        $framebuffer = array_fill(0, VideoMemory::FRAMEBUFFER_SIZE, 0);
        $output = $this->renderer->render($framebuffer);

        $this->assertIsString($output);
        $this->assertNotEmpty($output);
    }

    public function testRenderContainsANSIEscapeCodes(): void
    {
        $framebuffer = array_fill(0, VideoMemory::FRAMEBUFFER_SIZE, 255);
        $output = $this->renderer->render($framebuffer);

        // Should contain ANSI escape sequences
        $this->assertStringContainsString("\033[", $output);

        // Should contain clear screen
        $this->assertStringContainsString("\033[2J", $output);

        // Should contain cursor control
        $this->assertStringContainsString("\033[H", $output);
    }

    public function testRenderWithHalfBlocks(): void
    {
        $renderer = new ANSIRenderer(true, 1); // scale=1 for full size
        $framebuffer = array_fill(0, VideoMemory::FRAMEBUFFER_SIZE, 0);

        $output = $renderer->render($framebuffer);

        // Should contain Unicode half-block character
        $this->assertStringContainsString('▀', $output);

        // Should contain 256-color ANSI codes (38;5 for foreground, 48;5 for background)
        $this->assertStringContainsString('38;5;', $output);
        $this->assertStringContainsString('48;5;', $output);
    }

    public function testRenderWithoutHalfBlocks(): void
    {
        $renderer = new ANSIRenderer(false, 1); // scale=1 for full size
        $framebuffer = array_fill(0, VideoMemory::FRAMEBUFFER_SIZE, 0);

        $output = $renderer->render($framebuffer);

        // Should not contain Unicode half-block character
        $this->assertStringNotContainsString('▀', $output);

        // Should still contain ANSI color codes
        $this->assertStringContainsString('48;5;', $output);
    }

    public function testSetCustomPalette(): void
    {
        $customPalette = [];
        for ($i = 0; $i < 256; $i++) {
            $customPalette[$i] = 255 - $i; // Inverted palette
        }

        $this->renderer->setPalette($customPalette);

        // Create a framebuffer with known values
        $framebuffer = array_fill(0, VideoMemory::FRAMEBUFFER_SIZE, 0);
        $framebuffer[0] = 0; // Should map to 255 in custom palette
        $framebuffer[1] = 255; // Should map to 0 in custom palette

        $output = $this->renderer->render($framebuffer);

        // Verify output contains expected color codes from custom palette
        $this->assertStringContainsString('38;5;255', $output);
        $this->assertStringContainsString('38;5;0', $output);
    }

    public function testCreateTestPattern(): void
    {
        $pattern = ANSIRenderer::createTestPattern();

        $this->assertIsArray($pattern);
        $this->assertCount(VideoMemory::FRAMEBUFFER_SIZE, $pattern);

        // Should contain gradient values
        $this->assertEquals(0, $pattern[0]); // First pixel (leftmost)
        $this->assertGreaterThan(200, end($pattern)); // Last pixel should be near 255
    }

    public function testCreateColorBars(): void
    {
        $pattern = ANSIRenderer::createColorBars();

        $this->assertIsArray($pattern);
        $this->assertCount(VideoMemory::FRAMEBUFFER_SIZE, $pattern);

        // First bar should be consistent across first few pixels
        $this->assertEquals($pattern[0], $pattern[1]);
        $this->assertEquals($pattern[0], $pattern[2]);
    }

    public function testClearMethod(): void
    {
        // Just verify it doesn't throw - output goes to stdout
        ob_start();
        $this->renderer->clear();
        $output = ob_get_clean();

        $this->assertIsString($output);
        $this->assertStringContainsString("\033[2J", $output);
    }

    public function testDisplayMethod(): void
    {
        $framebuffer = array_fill(0, VideoMemory::FRAMEBUFFER_SIZE, 0);

        ob_start();
        $this->renderer->display($framebuffer);
        $output = ob_get_clean();

        $this->assertIsString($output);
        $this->assertNotEmpty($output);
        $this->assertStringContainsString("\033[", $output);
    }

    public function testRenderContainsCursorControl(): void
    {
        $framebuffer = array_fill(0, VideoMemory::FRAMEBUFFER_SIZE, 0);
        $output = $this->renderer->render($framebuffer);

        // Should hide cursor at start
        $this->assertStringContainsString("\033[?25l", $output);

        // Should show cursor at end
        $this->assertStringContainsString("\033[?25h", $output);
    }

    public function testRenderContainsResetSequence(): void
    {
        $framebuffer = array_fill(0, VideoMemory::FRAMEBUFFER_SIZE, 0);
        $output = $this->renderer->render($framebuffer);

        // Should contain reset sequence
        $this->assertStringContainsString("\033[0m", $output);
    }

    public function testRenderProducesCorrectNumberOfLines(): void
    {
        $renderer = new ANSIRenderer(true, 1); // Half-blocks, scale=1 (full size)
        $framebuffer = array_fill(0, VideoMemory::FRAMEBUFFER_SIZE, 0);
        $output = $renderer->render($framebuffer);

        // With half-blocks: 240 rows / 2 = 120 lines
        // Count newlines (excluding ANSI sequences)
        $lines = substr_count($output, "\n");

        // Should have approximately 120 lines (may have extra for clear/reset)
        $this->assertGreaterThanOrEqual(120, $lines);
        $this->assertLessThanOrEqual(125, $lines);
    }

    public function testRenderHandlesDifferentColorValues(): void
    {
        $framebuffer = array_fill(0, VideoMemory::FRAMEBUFFER_SIZE, 0);

        // Set various color values
        $framebuffer[0] = 0;
        $framebuffer[256] = 127;
        $framebuffer[512] = 255;

        $output = $this->renderer->render($framebuffer);

        // Should contain different color codes (either as foreground 38;5 or background 48;5)
        $this->assertStringContainsString(';5;0', $output);
        $this->assertStringContainsString(';5;127', $output);
        $this->assertStringContainsString(';5;255', $output);
    }
}
