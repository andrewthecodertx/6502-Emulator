<?php

declare(strict_types=1);

namespace Emulator\Systems\BenEater\Bus;

interface PeripheralInterface
{
    public function handlesAddress(int $address): bool;
    public function read(int $address): int;
    public function write(int $address, int $value): void;
    public function tick(): void;
    public function hasInterruptRequest(): bool;
}
