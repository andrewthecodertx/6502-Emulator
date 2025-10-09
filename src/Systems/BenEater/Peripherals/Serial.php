<?php

declare(strict_types=1);

namespace Emulator\Systems\BenEater\Peripherals;

use Emulator\Systems\BenEater\Bus\PeripheralInterface;

/**
 * Simple serial communication peripheral with data and status registers.
 *
 * Provides basic serial I/O with transmitter ready and receiver full flags.
 * Simpler alternative to UART for testing and basic communication.
 */
class Serial implements PeripheralInterface
{
    private const DATA_REGISTER = 0xFE00;
    private const STATUS_REGISTER = 0xFE01;
    private const TRANSMITTER_READY = 0b10000000;
    private const RECEIVER_FULL = 0b00000001;

    private string $outputBuffer = '';
    private string $inputBuffer = '';

    private bool $irq_pending = false;

    public function handlesAddress(int $address): bool
    {
        return $address >= self::DATA_REGISTER && $address <= self::STATUS_REGISTER;
    }

    public function read(int $address): int
    {
        if ($address === self::STATUS_REGISTER) {
            $status = self::TRANSMITTER_READY;
            if (strlen($this->inputBuffer) > 0) {
                $status |= self::RECEIVER_FULL;
            }
            return $status;
        }

        if ($address === self::DATA_REGISTER) {
            if (strlen($this->inputBuffer) > 0) {
                $char = substr($this->inputBuffer, 0, 1);
                $this->inputBuffer = substr($this->inputBuffer, 1);
                return ord($char);
            }
        }

        return 0;
    }

    public function write(int $address, int $value): void
    {
        if ($address === self::DATA_REGISTER) {
            $this->outputBuffer .= chr($value);
        }
    }

    public function tick(): void
    {
    }

    public function reset(): void
    {
        $this->outputBuffer = '';
        $this->inputBuffer = '';
    }

    public function getOutput(): string
    {
        $output = $this->outputBuffer;
        $this->outputBuffer = '';
        return $output;
    }

    public function setInput(string $input): void
    {
        $this->inputBuffer .= $input;
    }

    public function hasInterruptRequest(): bool
    {
        return $this->irq_pending;
    }
}
