<?php

declare(strict_types=1);

namespace Emulator\Core;

class CPUMonitor
{
    /** @var array<array{address: int, data: int, type: string, timestamp: float}> */
    private array $memoryAccesses = [];
    /** @var array<array{pc: int, instruction: int, opcode: string, timestamp: float}> */
    private array $instructions = [];
    private int $totalCycles = 0;
    private bool $logging = true;

    public function logMemoryRead(int $address, int $data): void
    {
        if (!$this->logging) {
            return;
        }

        $this->memoryAccesses[] = [
          'address' => $address,
          'data' => $data,
          'type' => 'read',
          'timestamp' => microtime(true),
        ];
    }

    public function logMemoryWrite(int $address, int $data): void
    {
        if (!$this->logging) {
            return;
        }

        $this->memoryAccesses[] = [
          'address' => $address,
          'data' => $data,
          'type' => 'write',
          'timestamp' => microtime(true),
        ];
    }

    public function logInstruction(int $pc, int $instruction, string $opcode): void
    {
        if (!$this->logging) {
            return;
        }

        $this->instructions[] = [
          'pc' => $pc,
          'instruction' => $instruction,
          'opcode' => $opcode,
          'timestamp' => microtime(true),
        ];
    }

    public function logCycle(): void
    {
        $this->totalCycles++;
    }

    /** @return array<array{address: int, data: int, type: string, timestamp: float}> */
    public function getMemoryAccesses(): array
    {
        return $this->memoryAccesses;
    }

    /** @return array<array{pc: int, instruction: int, opcode: string, timestamp: float}> */
    public function getInstructions(): array
    {
        return $this->instructions;
    }

    public function getTotalCycles(): int
    {
        return $this->totalCycles;
    }

    public function clearLog(): void
    {
        $this->memoryAccesses = [];
        $this->instructions = [];
    }

    public function setLogging(bool $enabled): void
    {
        $this->logging = $enabled;
    }

    public function isLogging(): bool
    {
        return $this->logging;
    }

    /** @return array{address: int, data: int, type: string, timestamp: float}|null */
    public function getLastMemoryAccess(): ?array
    {
        return end($this->memoryAccesses) ?: null;
    }

    public function getAccessCount(): int
    {
        return count($this->memoryAccesses);
    }

    public function reset(): void
    {
        $this->memoryAccesses = [];
        $this->instructions = [];
        $this->totalCycles = 0;
    }
}
