<?php

declare(strict_types=1);

namespace Emulator\Systems\BenEater\Bus;

use Emulator\Core\CPU;
use Emulator\Core\BusInterface;
use Emulator\Systems\BenEater\RAM;
use Emulator\Systems\BenEater\ROM;

class SystemBus implements BusInterface
{
    private RAM $ram;
    private ROM $rom;
    private ?CPU $cpu = null;
    /** @var array<PeripheralInterface> */ private array $peripherals = [];
    /** @var array<int, bool> */ private array $lastIrqState = [];

    public function __construct(RAM $ram, ROM $rom)
    {
        $this->ram = $ram;
        $this->rom = $rom;
    }

    public function setCpu(CPU $cpu): void
    {
        $this->cpu = $cpu;
    }

    public function addPeripheral(PeripheralInterface $peripheral): void
    {
        $this->peripherals[] = $peripheral;
    }

    public function read(int $address): int
    {
        $address = $address & 0xFFFF;

        foreach ($this->peripherals as $peripheral) {
            if ($peripheral->handlesAddress($address)) {
                return $peripheral->read($address);
            }
        }

        if ($address >= ROM::ROM_START) {
            return $this->rom->readByte($address);
        }

        return $this->ram->readByte($address);
    }

    public function write(int $address, int $value): void
    {
        $address = $address & 0xFFFF;
        $value = $value & 0xFF;

        foreach ($this->peripherals as $peripheral) {
            if ($peripheral->handlesAddress($address)) {
                $peripheral->write($address, $value);
                return;
            }
        }

        if ($address >= ROM::ROM_START) {
            // Cannot write to ROM
            return;
        }

        $this->ram->writeByte($address, $value);
    }

    public function tick(): void
    {
        foreach ($this->peripherals as $index => $peripheral) {
            $peripheral->tick();

            // Edge-triggered IRQ: only request on LOW->HIGH transition
            $currentIrqState = $peripheral->hasInterruptRequest();
            $lastState = $this->lastIrqState[$index] ?? false;

            if ($this->cpu && $currentIrqState && !$lastState) {
                $this->cpu->requestIRQ();
            }

            $this->lastIrqState[$index] = $currentIrqState;
        }
    }

    public function readWord(int $address): int
    {
        $low = $this->read($address);
        $high = $this->read($address + 1);
        return ($high << 8) | $low;
    }
}
