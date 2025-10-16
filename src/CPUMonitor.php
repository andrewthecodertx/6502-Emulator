<?php

declare(strict_types=1);

namespace andrewthecoder\MOS6502;

/**
 * CPU Monitor
 *
 * Provides debugging and profiling capabilities by tracking memory accesses,
 * executed instructions, and cycle counts. Can be attached to a CPU instance
 * to collect detailed execution traces.
 */
class CPUMonitor
{
    /** @var array<array{address: int, data: int, type: string, timestamp: float}> */
    private array $memoryAccesses = [];
    /** @var array<array{pc: int, instruction: int, opcode: string, timestamp: float}> */
    private array $instructions = [];
    private int $totalCycles = 0;
    private bool $logging = true;

    /**
     * Logs a memory read operation
     *
     * @param int $address The memory address being read
     * @param int $data The data value that was read
     */
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

    /**
     * Logs a memory write operation
     *
     * @param int $address The memory address being written
     * @param int $data The data value being written
     */
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

    /**
     * Logs an instruction execution
     *
     * @param int $pc The program counter at the time of fetch
     * @param int $instruction The raw opcode value
     * @param string $opcode The mnemonic string
     */
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

    /**
     * Increments the total cycle counter
     *
     * Called once per clock cycle by the CPU.
     */
    public function logCycle(): void
    {
        $this->totalCycles++;
    }

    /**
     * Gets all logged memory accesses
     *
     * @return array<array{address: int, data: int, type: string, timestamp: float}> Array of access records
     */
    public function getMemoryAccesses(): array
    {
        return $this->memoryAccesses;
    }

    /**
     * Gets all logged instruction executions
     *
     * @return array<array{pc: int, instruction: int, opcode: string, timestamp: float}> Array of instruction records
     */
    public function getInstructions(): array
    {
        return $this->instructions;
    }

    /**
     * Gets the total number of cycles logged
     *
     * @return int Total cycle count
     */
    public function getTotalCycles(): int
    {
        return $this->totalCycles;
    }

    /**
     * Clears memory access and instruction logs
     *
     * Does not reset the cycle counter. Use reset() to clear everything.
     */
    public function clearLog(): void
    {
        $this->memoryAccesses = [];
        $this->instructions = [];
    }

    /**
     * Enables or disables logging
     *
     * When disabled, logging calls have minimal overhead.
     *
     * @param bool $enabled True to enable logging, false to disable
     */
    public function setLogging(bool $enabled): void
    {
        $this->logging = $enabled;
    }

    /**
     * Checks if logging is currently enabled
     *
     * @return bool True if logging is enabled
     */
    public function isLogging(): bool
    {
        return $this->logging;
    }

    /**
     * Gets the most recent memory access
     *
     * @return array{address: int, data: int, type: string, timestamp: float}|null The last access record or null if none
     */
    public function getLastMemoryAccess(): ?array
    {
        return end($this->memoryAccesses) ?: null;
    }

    /**
     * Gets the total number of memory accesses logged
     *
     * @return int Count of memory accesses (reads and writes combined)
     */
    public function getAccessCount(): int
    {
        return count($this->memoryAccesses);
    }

    /**
     * Resets all monitor data
     *
     * Clears memory accesses, instructions, and resets the cycle counter.
     */
    public function reset(): void
    {
        $this->memoryAccesses = [];
        $this->instructions = [];
        $this->totalCycles = 0;
    }
}
