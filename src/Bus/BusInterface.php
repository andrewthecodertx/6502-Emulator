<?php

declare(strict_types=1);

namespace Emulator\Bus;

interface BusInterface
{
    public function read(int $address): int;
    public function readWord(int $address): int;
    public function write(int $address, int $value): void;
    public function tick(): void;
}
