<?php

declare(strict_types=1);

namespace Emulator\Bus;

use Emulator\CPU;
use Emulator\RAM;
use Emulator\ROM;

class SystemBus implements BusInterface
{
    private RAM $ram;
    private ROM $rom;
    private ?CPU $cpu = null;
    /** @var array<PeripheralInterface> */ private array $peripherals = [];

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
        foreach ($this->peripherals as $peripheral) {
            $peripheral->tick();
            if ($this->cpu && $peripheral->hasInterruptRequest()) {
                $this->cpu->requestIRQ();
            }
        }
    }

    public function readWord(int $address): int
    {
        $low = $this->read($address);
        $high = $this->read($address + 1);
        return ($high << 8) | $low;
    }
}
