<?php

declare(strict_types=1);

namespace andrewthecoder\Tests;

use andrewthecoder\MOS6502\BusInterface;

/**
 * Simple memory bus for testing
 *
 * Provides 64KB of RAM with no peripherals, perfect for unit testing.
 */
class SimpleBus implements BusInterface
{
    /** @var array<int, int> */
    private array $memory = [];

    /** @var array<int> */
    public array $writeLog = [];

    /** @var array<int> */
    public array $readLog = [];

    public int $tickCount = 0;

    public function read(int $address): int
    {
        $this->readLog[] = $address & 0xFFFF;
        return $this->memory[$address & 0xFFFF] ?? 0;
    }

    public function write(int $address, int $value): void
    {
        $addr = $address & 0xFFFF;
        $this->memory[$addr] = $value & 0xFF;
        $this->writeLog[] = $addr;
    }

    public function readWord(int $address): int
    {
        $low = $this->read($address);
        $high = $this->read($address + 1);
        return ($high << 8) | $low;
    }

    public function tick(): void
    {
        $this->tickCount++;
    }

    /**
     * Load a program into memory at the specified address
     *
     * @param int $startAddress Starting address
     * @param array<int> $bytes Program bytes
     */
    public function loadProgram(int $startAddress, array $bytes): void
    {
        foreach ($bytes as $offset => $byte) {
            $this->write($startAddress + $offset, $byte);
        }
    }

    /**
     * Set the reset vector
     *
     * @param int $address Address to jump to on reset
     */
    public function setResetVector(int $address): void
    {
        $this->write(0xFFFC, $address & 0xFF);
        $this->write(0xFFFD, ($address >> 8) & 0xFF);
    }

    /**
     * Set the IRQ vector
     *
     * @param int $address Address to jump to on IRQ
     */
    public function setIRQVector(int $address): void
    {
        $this->write(0xFFFE, $address & 0xFF);
        $this->write(0xFFFF, ($address >> 8) & 0xFF);
    }

    /**
     * Set the NMI vector
     *
     * @param int $address Address to jump to on NMI
     */
    public function setNMIVector(int $address): void
    {
        $this->write(0xFFFA, $address & 0xFF);
        $this->write(0xFFFB, ($address >> 8) & 0xFF);
    }

    /**
     * Clear access logs
     */
    public function clearLogs(): void
    {
        $this->writeLog = [];
        $this->readLog = [];
    }

    /**
     * Dump memory region for debugging
     *
     * @param int $start Start address
     * @param int $length Number of bytes
     * @return string Hex dump
     */
    public function dumpMemory(int $start, int $length): string
    {
        $result = [];
        for ($i = 0; $i < $length; $i++) {
            $addr = ($start + $i) & 0xFFFF;
            $byte = $this->read($addr);
            $result[] = sprintf("%02X", $byte);
        }
        return implode(" ", $result);
    }
}
