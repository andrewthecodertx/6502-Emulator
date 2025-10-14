<?php

declare(strict_types=1);

namespace Tests;

use Emulator\Systems\Eater\VideoMemory;
use PHPUnit\Framework\TestCase;

class VideoMemoryTest extends TestCase
{
    private VideoMemory $video;

    protected function setUp(): void
    {
        $this->video = new VideoMemory();
    }

    public function testHandlesAddressInRange(): void
    {
        $this->assertTrue($this->video->handlesAddress(0x0400));
        $this->assertTrue($this->video->handlesAddress(0x7000));
        $this->assertTrue($this->video->handlesAddress(0xF3FF));
    }

    public function testDoesNotHandleAddressOutOfRange(): void
    {
        $this->assertFalse($this->video->handlesAddress(0x03FF));
        $this->assertFalse($this->video->handlesAddress(0xF400));
        $this->assertFalse($this->video->handlesAddress(0x0000));
    }

    public function testWriteAndReadPixel(): void
    {
        $this->video->write(0x0400, 0x0F);
        $this->assertEquals(0x0F, $this->video->read(0x0400));

        $this->video->write(0x0401, 0xFF);
        $this->assertEquals(0xFF, $this->video->read(0x0401));
    }

    public function testWriteSetsDirtyFlag(): void
    {
        $this->assertFalse($this->video->isDirty());

        $this->video->write(0x0400, 0x01);
        $this->assertTrue($this->video->isDirty());
    }

    public function testDirtyFlagReset(): void
    {
        $this->video->write(0x0400, 0x01);
        $this->assertTrue($this->video->isDirty());

        $this->video->isDirty(true); // Reset flag
        $this->assertFalse($this->video->isDirty());
    }

    public function testSetPixelByCoordinates(): void
    {
        $this->video->setPixel(0, 0, 0x0F);
        $this->assertEquals(0x0F, $this->video->getPixel(0, 0));

        $this->video->setPixel(255, 239, 0x01);
        $this->assertEquals(0x01, $this->video->getPixel(255, 239));

        // Test coordinate to address mapping
        $this->video->setPixel(10, 5, 0xAB);
        $address = 0x0400 + (5 * 256 + 10);
        $this->assertEquals(0xAB, $this->video->read($address));
    }

    public function testSetPixelOutOfBounds(): void
    {
        $this->video->setPixel(-1, 0, 0xFF);
        $this->video->setPixel(256, 0, 0xFF);
        $this->video->setPixel(0, -1, 0xFF);
        $this->video->setPixel(0, 240, 0xFF);

        // Should not crash, just ignore
        $this->assertTrue(true);
    }

    public function testGetPixelOutOfBounds(): void
    {
        $this->assertEquals(0, $this->video->getPixel(-1, 0));
        $this->assertEquals(0, $this->video->getPixel(256, 0));
        $this->assertEquals(0, $this->video->getPixel(0, -1));
        $this->assertEquals(0, $this->video->getPixel(0, 240));
    }

    public function testClearFramebuffer(): void
    {
        $this->video->write(0x0400, 0xFF);
        $this->video->write(0x0401, 0xFF);

        $this->video->clear(0x00);

        $this->assertEquals(0x00, $this->video->read(0x0400));
        $this->assertEquals(0x00, $this->video->read(0x0401));
        $this->assertTrue($this->video->isDirty());
    }

    public function testClearWithColor(): void
    {
        $this->video->clear(0x0F);

        $this->assertEquals(0x0F, $this->video->read(0x0400));
        $this->assertEquals(0x0F, $this->video->read(0x7000));
    }

    public function testReset(): void
    {
        $this->video->write(0x0400, 0xFF);
        $this->video->isDirty(true); // Increment frame count

        $this->video->reset();

        $this->assertEquals(0x00, $this->video->read(0x0400));
        $this->assertFalse($this->video->isDirty());
        $this->assertEquals(0, $this->video->getFrameCount());
    }

    public function testFrameCounter(): void
    {
        $this->assertEquals(0, $this->video->getFrameCount());

        $this->video->write(0x0400, 0x01);
        $this->video->isDirty(true); // Reset dirty and increment frame
        $this->assertEquals(1, $this->video->getFrameCount());

        $this->video->write(0x0401, 0x02);
        $this->video->isDirty(true);
        $this->assertEquals(2, $this->video->getFrameCount());
    }

    public function testGetFramebuffer(): void
    {
        $this->video->write(0x0400, 0x01);
        $this->video->write(0x0401, 0x02);
        $this->video->write(0x0402, 0x03);

        $buffer = $this->video->getFramebuffer();

        $this->assertIsArray($buffer);
        $this->assertEquals(VideoMemory::FRAMEBUFFER_SIZE, count($buffer));
        $this->assertEquals(0x01, $buffer[0]);
        $this->assertEquals(0x02, $buffer[1]);
        $this->assertEquals(0x03, $buffer[2]);
    }

    public function testGetFramebufferBinary(): void
    {
        $this->video->write(0x0400, 0x01);
        $this->video->write(0x0401, 0x02);
        $this->video->write(0x0402, 0x03);

        $binary = $this->video->getFramebufferBinary();

        $this->assertIsString($binary);
        $this->assertEquals(VideoMemory::FRAMEBUFFER_SIZE, strlen($binary));
        $this->assertEquals(0x01, ord($binary[0]));
        $this->assertEquals(0x02, ord($binary[1]));
        $this->assertEquals(0x03, ord($binary[2]));
    }

    public function testGetConfig(): void
    {
        $config = $this->video->getConfig();

        $this->assertIsArray($config);
        $this->assertEquals(256, $config['width']);
        $this->assertEquals(240, $config['height']);
        $this->assertEquals(61440, $config['framebuffer_size']);
        $this->assertEquals('$0400', $config['start_address']);
        $this->assertEquals('$F3FF', $config['end_address']);
    }

    public function testCustomAddressRange(): void
    {
        $video = new VideoMemory(0x2000, 0x3FFF);

        $this->assertTrue($video->handlesAddress(0x2000));
        $this->assertTrue($video->handlesAddress(0x3FFF));
        $this->assertFalse($video->handlesAddress(0x4000));

        $video->write(0x2000, 0xAB);
        $this->assertEquals(0xAB, $video->read(0x2000));
    }

    public function testValueMasking(): void
    {
        // Should mask to 8-bit
        $this->video->write(0x0400, 0x1FF);
        $this->assertEquals(0xFF, $this->video->read(0x0400));

        $this->video->setPixel(0, 0, 0x300);
        $this->assertEquals(0x00, $this->video->getPixel(0, 0));
    }
}
