<?php

namespace Emulator\Systems\C64\Peripherals;

use Closure;
use Emulator\Systems\C64\Bus\PeripheralInterface;

/**
 * MOS 6526 Complex Interface Adapter (CIA)
 *
 * The C64 has two CIA chips:
 * - CIA #1 ($DC00-$DCFF): Keyboard, joystick #1/#2, timers, IRQ generation
 * - CIA #2 ($DD00-$DDFF): Serial bus, user port, RS-232, VIC-II banking, NMI generation
 *
 * Register Map (16 registers, repeated to fill 256 bytes):
 * $00: Data Port A (PRA)
 * $01: Data Port B (PRB)
 * $02: Data Direction Register A (DDRA)
 * $03: Data Direction Register B (DDRB)
 * $04: Timer A Low Byte
 * $05: Timer A High Byte
 * $06: Timer B Low Byte
 * $07: Timer B High Byte
 * $08: Time of Day (TOD) 1/10 seconds
 * $09: TOD Seconds
 * $0A: TOD Minutes
 * $0B: TOD Hours + AM/PM
 * $0C: Serial Data Register (SDR)
 * $0D: Interrupt Control Register (ICR)
 * $0E: Control Register A (CRA)
 * $0F: Control Register B (CRB)
 *
 */
class CIA6526 implements PeripheralInterface
{
    private string $name;

    private int $baseAddress;
    private int $portA = 0xFF;
    private int $portB = 0xFF;
    private int $ddra = 0x00;  // 0=input, 1=output
    private int $ddrb = 0x00;
    private int $timerALatch = 0xFFFF;
    private int $timerBLatch = 0xFFFF;
    private int $timerACounter = 0xFFFF;
    private int $timerBCounter = 0xFFFF;
    private int $cra = 0x00;
    private int $crb = 0x00;
    private int $icr = 0x00;      // Interrupt data/status
    private int $icrMask = 0x00;  // Interrupt mask
    private int $todTenths = 0;
    private int $todSeconds = 0;
    private int $todMinutes = 0;
    private int $todHours = 0;
    private int $sdr = 0x00;

    private bool $timerARunning = false;
    private bool $timerBRunning = false;
    private bool $timerAUnderflow = false;
    private bool $timerBUnderflow = false;

    private ?\Closure $portAReader = null;
    private ?\Closure $portBReader = null;

    public function __construct(int $baseAddress, string $name = "CIA")
    {
        $this->baseAddress = $baseAddress;
        $this->name = $name;
    }


    /** @param Closure $reader */
    public function setPortAReader(\Closure $reader): void
    {
        $this->portAReader = $reader;
    }


    /** @param Closure $reader */
    public function setPortBReader(\Closure $reader): void
    {
        $this->portBReader = $reader;
    }

    public function handlesAddress(int $address): bool
    {
        return $address >= $this->baseAddress && $address < ($this->baseAddress + 0x100);
    }

    public function read(int $address): int
    {
        $reg = ($address - $this->baseAddress) & 0x0F;

        return match ($reg) {
            0x00 => $this->readPortA(),
            0x01 => $this->readPortB(),
            0x02 => $this->ddra,
            0x03 => $this->ddrb,
            0x04 => $this->timerACounter & 0xFF,
            0x05 => ($this->timerACounter >> 8) & 0xFF,
            0x06 => $this->timerBCounter & 0xFF,
            0x07 => ($this->timerBCounter >> 8) & 0xFF,
            0x08 => $this->todTenths,
            0x09 => $this->todSeconds,
            0x0A => $this->todMinutes,
            0x0B => $this->todHours,
            0x0C => $this->sdr,
            0x0D => $this->readICR(),
            0x0E => $this->cra,
            0x0F => $this->crb,

            default => 0xFF,
        };
    }

    public function write(int $address, int $value): void
    {
        $value &= 0xFF;
        $reg = ($address - $this->baseAddress) & 0x0F;

        match ($reg) {
            0x00 => $this->portA = $value,
            0x01 => $this->portB = $value,
            0x02 => $this->ddra = $value,
            0x03 => $this->ddrb = $value,
            0x04 => $this->timerALatch = ($this->timerALatch & 0xFF00) | $value,
            0x05 => $this->timerALatch = ($this->timerALatch & 0x00FF) | ($value << 8),
            0x06 => $this->timerBLatch = ($this->timerBLatch & 0xFF00) | $value,
            0x07 => $this->timerBLatch = ($this->timerBLatch & 0x00FF) | ($value << 8),
            0x08 => $this->todTenths = $value & 0x0F,
            0x09 => $this->todSeconds = $value & 0x7F,
            0x0A => $this->todMinutes = $value & 0x7F,
            0x0B => $this->todHours = $value & 0x9F,
            0x0C => $this->sdr = $value,
            0x0D => $this->writeICR($value),
            0x0E => $this->writeCRA($value),
            0x0F => $this->writeCRB($value),

            default => null,
        };
    }

    public function tick(): void
    {
        if ($this->timerARunning) {
            $this->timerACounter--;
            if ($this->timerACounter < 0) {
                $this->timerACounter = $this->timerALatch;
                $this->timerAUnderflow = true;
                $this->icr |= 0x01;

                if (($this->cra & 0x08) !== 0) {
                    $this->timerARunning = false;
                    $this->cra &= ~0x01;
                }
            }
        }

        if ($this->timerBRunning) {
            $countMode = ($this->crb >> 5) & 0x03;

            $shouldCount = match ($countMode) {
                0x00 => true,                    // Count PHI2 pulses (every cycle)
                0x01 => $this->timerAUnderflow,  // Count Timer A underflows
                0x02 => false,                   // Count CNT pin (not implemented)
                0x03 => false,                   // Count Timer A underflows while CNT high

                default => false,
            };

            if ($shouldCount) {
                $this->timerBCounter--;
                if ($this->timerBCounter < 0) {
                    $this->timerBCounter = $this->timerBLatch;
                    $this->timerBUnderflow = true;
                    $this->icr |= 0x02;

                    if (($this->crb & 0x08) !== 0) {
                        $this->timerBRunning = false;
                        $this->crb &= ~0x01;
                    }
                }
            }
        }

        $this->timerAUnderflow = false;
        $this->timerBUnderflow = false;
    }

    private function readPortA(): int
    {
        $output = $this->portA & $this->ddra;  // Output pins
        $input = 0xFF;

        if ($this->portAReader !== null) {
            $input = ($this->portAReader)();
        }

        $input &= ~$this->ddra;  // Input pins
        return $output | $input;
    }

    private function readPortB(): int
    {
        $output = $this->portB & $this->ddrb;  // Output pins
        $input = 0xFF;

        if ($this->portBReader !== null) {
            $input = ($this->portBReader)();
        }

        $input &= ~$this->ddrb;  // Input pins
        return $output | $input;
    }

    private function readICR(): int
    {
        $value = $this->icr;

        if (($this->icr & $this->icrMask & 0x1F) !== 0) {
            $value |= 0x80;
        }

        $this->icr = 0x00;

        return $value;
    }

    private function writeICR(int $value): void
    {
        if (($value & 0x80) !== 0) {
            // Set mask bits
            $this->icrMask |= ($value & 0x1F);
        } else {
            // Clear mask bits
            $this->icrMask &= ~($value & 0x1F);
        }
    }

    private function writeCRA(int $value): void
    {
        $this->cra = $value;
        $this->timerARunning = ($value & 0x01) !== 0;

        if (($value & 0x10) !== 0) {
            $this->timerACounter = $this->timerALatch;
        }
    }

    private function writeCRB(int $value): void
    {
        $this->crb = $value;

        // Bit 0: Start/stop
        $this->timerBRunning = ($value & 0x01) !== 0;

        // Bit 4: Force load timer from latch
        if (($value & 0x10) !== 0) {
            $this->timerBCounter = $this->timerBLatch;
        }
    }

    public function isInterruptPending(): bool
    {
        return (($this->icr & $this->icrMask & 0x1F) !== 0);
    }

    public function getPortAOutput(): int
    {
        return $this->portA & $this->ddra;
    }

    public function getPortBOutput(): int
    {
        return $this->portB & $this->ddrb;
    }
}
