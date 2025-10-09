<?php

declare(strict_types=1);

namespace Emulator\Systems\BenEater\Peripherals;

use Emulator\Systems\BenEater\Bus\PeripheralInterface;

class KeyboardController implements PeripheralInterface
{
    public const KEYBOARD_BASE = 0xC000;
    public const KEYBOARD_END = 0xC00F;
    public const KEY_DATA = 0xC000;       // Key data register
    public const KEY_STATUS = 0xC001;     // Key status register
    public const KEY_CTRL = 0xC002;       // Control register
    public const STATUS_READY = 0x01;     // Key available
    public const STATUS_OVERFLOW = 0x80;  // Buffer overflow
    public const CTRL_ENABLE = 0x01;      // Enable keyboard
    public const CTRL_INTERRUPT = 0x02;   // Enable interrupts

    private const BUFFER_SIZE = 16;

    /** @var array<int> */ private array $keyBuffer = [];

    private int $keyData = 0;
    private int $keyStatus = 0;
    private int $keyControl = self::CTRL_ENABLE;

    private bool $irq_pending = false;

    public function __construct()
    {
        $this->reset();
    }

    public function handlesAddress(int $address): bool
    {
        return $address >= self::KEYBOARD_BASE && $address <= self::KEYBOARD_END;
    }

    public function read(int $address): int
    {
        switch ($address) {
            case self::KEY_DATA:
                return $this->readKeyData();
            case self::KEY_STATUS:
                return $this->keyStatus;
            case self::KEY_CTRL:
                return $this->keyControl;
            default:
                return 0;
        }
    }

    public function write(int $address, int $value): void
    {
        switch ($address) {
            case self::KEY_CTRL:
                $this->keyControl = $value & 0xFF;
                break;
            case self::KEY_STATUS:
                // Writing to status clears overflow flag
                $this->keyStatus &= ~self::STATUS_OVERFLOW;
                break;
                // KEY_DATA is read-only
        }
    }

    public function tick(): void
    {
        if (!($this->keyControl & self::CTRL_ENABLE)) {
            return;
        }

        $this->updateStatus();
    }

    public function reset(): void
    {
        $this->keyBuffer = [];
        $this->keyData = 0;
        $this->keyStatus = 0;
        $this->keyControl = self::CTRL_ENABLE;
    }

    public function addKey(int $keyCode): void
    {
        if (!($this->keyControl & self::CTRL_ENABLE)) {
            return;
        }

        if (count($this->keyBuffer) >= self::BUFFER_SIZE) {
            $this->keyStatus |= self::STATUS_OVERFLOW;
            return;
        }

        $this->keyBuffer[] = $keyCode & 0xFF;
        $this->updateStatus();
    }

    public function addString(string $text): void
    {
        foreach (str_split($text) as $char) {
            $this->addKey(ord($char));
        }
    }

    public function checkStdin(): void
    {
        if (php_sapi_name() !== 'cli') {
            return;
        }

        $read = [STDIN];
        $write = [];
        $except = [];

        if (stream_select($read, $write, $except, 0) > 0) {
            $input = fgetc(STDIN);
            if ($input !== false) {
                $this->addKey(ord($input));
            }
        }
    }

    private function readKeyData(): int
    {
        if (empty($this->keyBuffer)) {
            return $this->keyData;
        }

        $this->keyData = array_shift($this->keyBuffer);
        $this->updateStatus();

        return $this->keyData;
    }

    private function updateStatus(): void
    {
        if (!empty($this->keyBuffer)) {
            $this->keyStatus |= self::STATUS_READY;
        } else {
            $this->keyStatus &= ~self::STATUS_READY;
        }
    }

    public function hasKey(): bool
    {
        return ($this->keyStatus & self::STATUS_READY) !== 0;
    }

    public function getBufferSize(): int
    {
        return count($this->keyBuffer);
    }

    public function clearBuffer(): void
    {
        $this->keyBuffer = [];
        $this->keyStatus &= ~(self::STATUS_READY | self::STATUS_OVERFLOW);
    }

    public function setEnabled(bool $enabled): void
    {
        if ($enabled) {
            $this->keyControl |= self::CTRL_ENABLE;
        } else {
            $this->keyControl &= ~self::CTRL_ENABLE;
            $this->clearBuffer();
        }
    }

    /** @return array<string, mixed> */
    public function getStatus(): array
    {
        return [
          'enabled' => ($this->keyControl & self::CTRL_ENABLE) !== 0,
          'has_key' => $this->hasKey(),
          'buffer_size' => $this->getBufferSize(),
          'overflow' => ($this->keyStatus & self::STATUS_OVERFLOW) !== 0,
          'last_key' => $this->keyData,
        ];
    }

    public function hasInterruptRequest(): bool
    {
        return $this->irq_pending;
    }
}
