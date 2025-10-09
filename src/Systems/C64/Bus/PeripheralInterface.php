<?php

namespace Emulator\Systems\C64\Bus;

interface PeripheralInterface
{
    public function read(int $address): int;
    public function write(int $address, int $value): void;
    public function tick(): void;
    public function handlesAddress(int $address): bool;
}
