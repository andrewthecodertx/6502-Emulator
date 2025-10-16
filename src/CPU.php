<?php

declare(strict_types=1);

namespace Andrewthecoder\MOS6502;

use Andrewthecoder\MOS6502\Instructions\LoadStore;
use Andrewthecoder\MOS6502\Instructions\Transfer;
use Andrewthecoder\MOS6502\Instructions\Arithmetic;
use Andrewthecoder\MOS6502\Instructions\Logic;
use Andrewthecoder\MOS6502\Instructions\ShiftRotate;
use Andrewthecoder\MOS6502\Instructions\IncDec;
use Andrewthecoder\MOS6502\Instructions\FlowControl;
use Andrewthecoder\MOS6502\Instructions\Stack;
use Andrewthecoder\MOS6502\Instructions\Flags;
use Andrewthecoder\MOS6502\Instructions\IllegalOpcodes;

/**
 * 6502 CPU Emulator
 *
 * Implements a fully functional 6502 microprocessor with support for all standard
 * opcodes, addressing modes, interrupts (NMI, IRQ, RESET), and a hybrid execution
 * model combining JSON-driven and custom handler-based instruction processing.
 */
class CPU
{
    public int $pc = 0;
    public int $sp = 0xFF;
    public int $accumulator = 0;
    public int $registerX = 0;
    public int $registerY = 0;
    public int $cycles = 0;

    public bool $halted = false;

    /** @var array<int, string> */
    private array $pcTrace = [];

    private bool $nmiPending = false;
    private bool $irqPending = false;
    private bool $resetPending = false;
    private bool $nmiLastState = true;
    private bool $running = true;
    private bool $autoTickBus = true;

    /** @var array<string, callable(Opcode): int> */
    private array $instructionHandlers = [];

    private readonly InstructionInterpreter $interpreter;
    private readonly LoadStore $loadStoreHandler;
    private readonly Transfer $transferHandler;
    private readonly Arithmetic $arithmeticHandler;
    private readonly Logic $logicHandler;
    private readonly ShiftRotate $shiftRotateHandler;
    private readonly IncDec $incDecHandler;
    private readonly FlowControl $flowControlHandler;
    private readonly Stack $stackHandler;
    private readonly Flags $flagsHandler;
    private readonly IllegalOpcodes $illegalOpcodesHandler;

    /**
     * Initializes the CPU with a bus interface and optional monitoring
     *
     * @param BusInterface $bus The system bus for memory access
     * @param CPUMonitor|null $monitor Optional monitor for debugging and profiling
     * @param InstructionRegister $instructionRegister The opcode registry
     * @param StatusRegister $status The CPU status flags register
     */
    public function __construct(
        private readonly BusInterface $bus,
        private ?CPUMonitor $monitor = null,
        private readonly InstructionRegister $instructionRegister = new InstructionRegister(),
        public readonly StatusRegister $status = new StatusRegister()
    ) {
        $this->interpreter = new InstructionInterpreter($this);
        $this->loadStoreHandler = new LoadStore($this);
        $this->transferHandler = new Transfer($this);
        $this->arithmeticHandler = new Arithmetic($this);
        $this->logicHandler = new Logic($this);
        $this->shiftRotateHandler = new ShiftRotate($this);
        $this->incDecHandler = new IncDec($this);
        $this->flowControlHandler = new FlowControl($this);
        $this->stackHandler = new Stack($this);
        $this->flagsHandler = new Flags($this);
        $this->illegalOpcodesHandler = new IllegalOpcodes($this);

        $this->initializeInstructionHandlers();
    }

    /**
     * Decrements the cycle counter and logs to monitor if enabled
     */
    public function clock(): void
    {
        $this->cycles--;

        if ($this->monitor !== null) {
            $this->monitor->logCycle();
        }
    }

    /**
     * Runs the CPU continuously until stopped
     *
     * Executes instructions in a loop until stop() is called.
     * Dispatches signals every 10000 instructions for CTRL-C handling.
     */
    public function run(): void
    {
        $instructionCount = 0;
        while ($this->running) {
            $this->step();

            // Dispatch signals every 10000 instructions for CTRL-C handling
            if (++$instructionCount % 10000 == 0 && function_exists('pcntl_signal_dispatch')) {
                pcntl_signal_dispatch();
            }
        }
    }

    /**
     * Stops the CPU execution loop
     *
     * Causes run() to exit after the current instruction completes.
     */
    public function stop(): void
    {
        $this->running = false;
    }

    /**
     * Executes a single CPU cycle
     *
     * Handles interrupts (RESET, NMI, IRQ), fetches and executes instructions,
     * and manages the cycle counter. Uses either JSON-driven or handler-based
     * execution depending on the opcode.
     *
     * @throws \InvalidArgumentException If an unknown opcode is encountered
     * @throws \RuntimeException If an instruction handler is not implemented
     */
    public function step(): void
    {
        if ($this->halted) {
            if ($this->cycles > 0) {
                $this->cycles--;
            }
            return;
        }

        if ($this->cycles === 0) {
            if ($this->resetPending) {
                $this->handleReset();
                return;
            }

            if ($this->nmiPending) {
                $this->handleNMI();
                return;
            }

            if ($this->irqPending && !$this->status->get(StatusRegister::INTERRUPT_DISABLE)) {
                $this->handleIRQ();
                return;
            }

            $pcBeforeRead = $this->pc;
            $opcode = $this->bus->read($this->pc);

            // Track PC for debugging
            $this->pcTrace[] = sprintf("0x%04X", $pcBeforeRead);
            if (count($this->pcTrace) > 10) {
                array_shift($this->pcTrace);
            }

            if ($this->monitor !== null) {
                $opcodeHex = sprintf('0x%02X', $opcode);
                $opcodeData = $this->instructionRegister->getOpcode($opcodeHex);
                $mnemonic = $opcodeData ? $opcodeData->getMnemonic() : 'UNKNOWN';
                $this->monitor->logInstruction($this->pc, $opcode, $mnemonic);
            }

            $this->pc++;

            $opcodeData = $this->instructionRegister->getOpcode(sprintf('0x%02X', $opcode));

            if (!$opcodeData) {
                fprintf(
                    STDERR,
                    "DEBUG: Last 10 PCs: %s\n",
                    implode(" -> ", $this->pcTrace)
                );
                fprintf(
                    STDERR,
                    "DEBUG: Fetched opcode 0x%02X from PC 0x%04X (PC after inc: 0x%X)\n",
                    $opcode,
                    $pcBeforeRead,
                    $this->pc
                );
                throw new \InvalidArgumentException(
                    sprintf("Unknown opcode: 0x%02X at PC: 0x%04X", $opcode, $pcBeforeRead)
                );
            }

            if ($opcodeData->hasExecution()) {
                $this->cycles += $this->interpreter->execute($opcodeData);
            } else {
                $mnemonic = $opcodeData->getMnemonic();

                if (!isset($this->instructionHandlers[$mnemonic])) {
                    throw new \RuntimeException("Instruction {$mnemonic} not implemented");
                }

                $handler = $this->instructionHandlers[$mnemonic];
                $this->cycles += $handler($opcodeData);
            }
        }
        $this->clock();

        if ($this->bus != null && $this->autoTickBus) {
            $this->bus->tick();
        }
    }

    /**
     * Executes a complete instruction including all cycles
     *
     * Calls step() repeatedly until the instruction completes and all cycles
     * are consumed. Useful for debugging or single-stepping through instructions.
     */
    public function executeInstruction(): void
    {
        $startingPC = $this->pc;
        do {
            $this->step();
        } while (!$this->halted && ($this->cycles > 0 || $this->pc == $startingPC));
    }

    /**
     * Halts CPU execution
     *
     * Sets the halted flag, causing the CPU to stop executing instructions
     * while continuing to decrement the cycle counter.
     */
    public function halt(): void
    {
        $this->halted = true;
    }

    /**
     * Resumes CPU execution after halt
     */
    public function resume(): void
    {
        $this->halted = false;
    }

    /**
     * Checks if the CPU is currently halted
     *
     * @return bool True if halted, false otherwise
     */
    public function isHalted(): bool
    {
        return $this->halted;
    }

    /**
     * Enables or disables automatic bus ticking during step()
     *
     * @param bool $autoTick If true, bus->tick() is called each cycle
     */
    public function setAutoTickBus(bool $autoTick): void
    {
        $this->autoTickBus = $autoTick;
    }

    /**
     * Resets the CPU via the RESET interrupt
     *
     * Requests a RESET interrupt and executes it immediately if the CPU is halted.
     * Otherwise, RESET will be processed at the next instruction boundary.
     */
    public function reset(): void
    {
        $this->requestReset();

        if ($this->halted) {
            $this->handleReset();
        }
    }

    private function handleReset(): void
    {
        if ($this->monitor !== null) {
            $this->monitor->clearLog();
        }

        // W65C02S RESET sequence per datasheet:
        // - Takes 7 clock cycles
        // - SP decremented by 3
        // - PC loaded from reset vector (0xFFFC-0xFFFD)
        // - Status register: I=1, D=0, unused=1
        $this->cycles += 7;
        $this->sp = ($this->sp - 3) & 0xFF;

        $resetLow = $this->bus->read(0xFFFC);
        $resetHigh = $this->bus->read(0xFFFD);
        $this->pc = ($resetHigh << 8) | $resetLow;

        $this->accumulator = 0;
        $this->registerX = 0;
        $this->registerY = 0;

        $this->status->fromInt(0b00110100); // Binary: NVUBDIZC = 00110100

        $this->halted = false;
        $this->resetPending = false;

        $this->nmiPending = false;
        $this->irqPending = false;
    }

    private function handleNMI(): void
    {
        // NMI interrupt sequence per W65C02S datasheet:
        // 1. Complete current instruction (already done in step())
        // 2. Push PC high byte to stack
        // 3. Push PC low byte to stack
        // 4. Push status register to stack
        // 5. Set I flag (though NMI cannot be masked)
        // 6. Load PC from NMI vector (0xFFFA-0xFFFB)

        $this->pushByte(($this->pc >> 8) & 0xFF);
        $this->pushByte($this->pc & 0xFF);

        $statusValue = $this->status->toInt() & ~(1 << StatusRegister::BREAK_COMMAND);
        $this->pushByte($statusValue);
        $this->status->set(StatusRegister::INTERRUPT_DISABLE, true);

        $nmiLow = $this->bus->read(0xFFFA);
        $nmiHigh = $this->bus->read(0xFFFB);

        $this->pc = ($nmiHigh << 8) | $nmiLow;
        $this->cycles += 7;
        $this->nmiPending = false;
    }

    private function handleIRQ(): void
    {
        // IRQ interrupt sequence per W65C02S datasheet:
        // 1. Complete current instruction (already done in step())
        // 2. Push PC high byte to stack
        // 3. Push PC low byte to stack
        // 4. Push status register to stack
        // 5. Set I flag to disable further IRQs
        // 6. Load PC from IRQ vector (0xFFFE-0xFFFF)

        $this->pushByte(($this->pc >> 8) & 0xFF);
        $this->pushByte($this->pc & 0xFF);

        $statusValue = $this->status->toInt() & ~(1 << StatusRegister::BREAK_COMMAND);

        $this->pushByte($statusValue);
        $this->status->set(StatusRegister::INTERRUPT_DISABLE, true);

        $irqLow = $this->bus->read(0xFFFE);
        $irqHigh = $this->bus->read(0xFFFF);

        $this->pc = ($irqHigh << 8) | $irqLow;
        $this->cycles += 7;
        $this->irqPending = false;
    }

    private function initializeInstructionHandlers(): void
    {
        $this->instructionHandlers = [
          'LDA' => fn (Opcode $opcode) => $this->loadStoreHandler->lda($opcode),
          'LDX' => fn (Opcode $opcode) => $this->loadStoreHandler->ldx($opcode),
          'LDY' => fn (Opcode $opcode) => $this->loadStoreHandler->ldy($opcode),
          'STA' => fn (Opcode $opcode) => $this->loadStoreHandler->sta($opcode),
          'SAX' => fn (Opcode $opcode) => $this->loadStoreHandler->sax($opcode),
          'STX' => fn (Opcode $opcode) => $this->loadStoreHandler->stx($opcode),
          'STY' => fn (Opcode $opcode) => $this->loadStoreHandler->sty($opcode),

          'TAX' => fn (Opcode $opcode) => $this->transferHandler->tax($opcode),
          'TAY' => fn (Opcode $opcode) => $this->transferHandler->tay($opcode),
          'TXA' => fn (Opcode $opcode) => $this->transferHandler->txa($opcode),
          'TYA' => fn (Opcode $opcode) => $this->transferHandler->tya($opcode),
          'TSX' => fn (Opcode $opcode) => $this->transferHandler->tsx($opcode),
          'TXS' => fn (Opcode $opcode) => $this->transferHandler->txs($opcode),

          'ADC' => fn (Opcode $opcode) => $this->arithmeticHandler->adc($opcode),
          'SBC' => fn (Opcode $opcode) => $this->arithmeticHandler->sbc($opcode),
          'CMP' => fn (Opcode $opcode) => $this->arithmeticHandler->cmp($opcode),
          'CPX' => fn (Opcode $opcode) => $this->arithmeticHandler->cpx($opcode),
          'CPY' => fn (Opcode $opcode) => $this->arithmeticHandler->cpy($opcode),

          'AND' => fn (Opcode $opcode) => $this->logicHandler->and($opcode),
          'ORA' => fn (Opcode $opcode) => $this->logicHandler->ora($opcode),
          'EOR' => fn (Opcode $opcode) => $this->logicHandler->eor($opcode),
          'BIT' => fn (Opcode $opcode) => $this->logicHandler->bit($opcode),
          'ANC' => fn (Opcode $opcode) => $this->logicHandler->anc($opcode),

          'ASL' => fn (Opcode $opcode) => $this->shiftRotateHandler->asl($opcode),
          'LSR' => fn (Opcode $opcode) => $this->shiftRotateHandler->lsr($opcode),
          'ROL' => fn (Opcode $opcode) => $this->shiftRotateHandler->rol($opcode),
          'ROR' => fn (Opcode $opcode) => $this->shiftRotateHandler->ror($opcode),
          'RLA' => fn (Opcode $opcode) => $this->shiftRotateHandler->rla($opcode),
          'SLO' => fn (Opcode $opcode) => $this->shiftRotateHandler->slo($opcode),

          'INC' => fn (Opcode $opcode) => $this->incDecHandler->inc($opcode),
          'DEC' => fn (Opcode $opcode) => $this->incDecHandler->dec($opcode),
          'INX' => fn (Opcode $opcode) => $this->incDecHandler->inx($opcode),
          'DEX' => fn (Opcode $opcode) => $this->incDecHandler->dex($opcode),
          'INY' => fn (Opcode $opcode) => $this->incDecHandler->iny($opcode),
          'DEY' => fn (Opcode $opcode) => $this->incDecHandler->dey($opcode),
          'ISC' => fn (Opcode $opcode) => $this->incDecHandler->isc($opcode),

          'BEQ' => fn (Opcode $opcode) => $this->flowControlHandler->beq($opcode),
          'BNE' => fn (Opcode $opcode) => $this->flowControlHandler->bne($opcode),
          'BCC' => fn (Opcode $opcode) => $this->flowControlHandler->bcc($opcode),
          'BCS' => fn (Opcode $opcode) => $this->flowControlHandler->bcs($opcode),
          'BPL' => fn (Opcode $opcode) => $this->flowControlHandler->bpl($opcode),
          'BMI' => fn (Opcode $opcode) => $this->flowControlHandler->bmi($opcode),
          'BVC' => fn (Opcode $opcode) => $this->flowControlHandler->bvc($opcode),
          'BVS' => fn (Opcode $opcode) => $this->flowControlHandler->bvs($opcode),
          'JMP' => fn (Opcode $opcode) => $this->flowControlHandler->jmp($opcode),
          'JSR' => fn (Opcode $opcode) => $this->flowControlHandler->jsr($opcode),
          'RTS' => fn (Opcode $opcode) => $this->flowControlHandler->rts($opcode),
          'BRK' => fn (Opcode $opcode) => $this->flowControlHandler->brk($opcode),
          'RTI' => fn (Opcode $opcode) => $this->flowControlHandler->rti($opcode),
          'JAM' => fn (Opcode $opcode) => $this->flowControlHandler->jam($opcode),

          'PHA' => fn (Opcode $opcode) => $this->stackHandler->pha($opcode),
          'PLA' => fn (Opcode $opcode) => $this->stackHandler->pla($opcode),
          'PHP' => fn (Opcode $opcode) => $this->stackHandler->php($opcode),
          'PLP' => fn (Opcode $opcode) => $this->stackHandler->plp($opcode),

          'SEC' => fn (Opcode $opcode) => $this->flagsHandler->sec($opcode),
          'CLC' => fn (Opcode $opcode) => $this->flagsHandler->clc($opcode),
          'SEI' => fn (Opcode $opcode) => $this->flagsHandler->sei($opcode),
          'CLI' => fn (Opcode $opcode) => $this->flagsHandler->cli($opcode),
          'SED' => fn (Opcode $opcode) => $this->flagsHandler->sed($opcode),
          'CLD' => fn (Opcode $opcode) => $this->flagsHandler->cld($opcode),
          'CLV' => fn (Opcode $opcode) => $this->flagsHandler->clv($opcode),

          'NOP' => fn (Opcode $opcode) => $this->nop($opcode),

          // Illegal/undocumented opcodes
          'ALR' => fn (Opcode $opcode) => $this->illegalOpcodesHandler->alr($opcode),
          'ANE' => fn (Opcode $opcode) => $this->illegalOpcodesHandler->ane($opcode),
          'ARR' => fn (Opcode $opcode) => $this->illegalOpcodesHandler->arr($opcode),
          'DCP' => fn (Opcode $opcode) => $this->illegalOpcodesHandler->dcp($opcode),
          'LAS' => fn (Opcode $opcode) => $this->illegalOpcodesHandler->las($opcode),
          'LAX' => fn (Opcode $opcode) => $this->illegalOpcodesHandler->lax($opcode),
          'LXA' => fn (Opcode $opcode) => $this->illegalOpcodesHandler->lxa($opcode),
          'RRA' => fn (Opcode $opcode) => $this->illegalOpcodesHandler->rra($opcode),
          'SBX' => fn (Opcode $opcode) => $this->illegalOpcodesHandler->sbx($opcode),
          'SHA' => fn (Opcode $opcode) => $this->illegalOpcodesHandler->sha($opcode),
          'SHS' => fn (Opcode $opcode) => $this->illegalOpcodesHandler->shs($opcode),
          'SHX' => fn (Opcode $opcode) => $this->illegalOpcodesHandler->shx($opcode),
          'SHY' => fn (Opcode $opcode) => $this->illegalOpcodesHandler->shy($opcode),
          'SRE' => fn (Opcode $opcode) => $this->illegalOpcodesHandler->sre($opcode),
        ];
    }

    /**
     * Handles NOP (No Operation) instruction
     *
     * Does nothing but must consume operand bytes for multi-byte NOPs.
     * For illegal NOPs (DOP/TOP), the operand is read but ignored.
     *
     * @param Opcode $opcode The NOP opcode
     * @return int Number of cycles taken
     */
    private function nop(Opcode $opcode): int
    {
        // For NOPs with operands (not Implied addressing), we need to
        // advance PC over the operand bytes by calling getAddress()
        $addressingMode = $opcode->getAddressingMode();
        if ($addressingMode !== 'Implied') {
            $this->getAddress($addressingMode);
        }

        return $opcode->getCycles();
    }

    /**
     * Gets the current value of the accumulator register
     *
     * @return int Accumulator value (0x00-0xFF)
     */
    public function getAccumulator(): int
    {
        return $this->accumulator;
    }

    /**
     * Sets the accumulator register value
     *
     * @param int $value Value to set (automatically masked to 8 bits)
     */
    public function setAccumulator(int $value): void
    {
        $this->accumulator = $value & 0xFF;
    }

    /**
     * Gets the current value of the X index register
     *
     * @return int X register value (0x00-0xFF)
     */
    public function getRegisterX(): int
    {
        return $this->registerX;
    }

    /**
     * Sets the X index register value
     *
     * @param int $value Value to set (automatically masked to 8 bits)
     */
    public function setRegisterX(int $value): void
    {
        $this->registerX = $value & 0xFF;
    }

    /**
     * Gets the current value of the Y index register
     *
     * @return int Y register value (0x00-0xFF)
     */
    public function getRegisterY(): int
    {
        return $this->registerY;
    }

    /**
     * Sets the Y index register value
     *
     * @param int $value Value to set (automatically masked to 8 bits)
     */
    public function setRegisterY(int $value): void
    {
        $this->registerY = $value & 0xFF;
    }

    /**
     * Gets the current stack pointer value
     *
     * @return int Stack pointer (0x00-0xFF, points to next free location)
     */
    public function getStackPointer(): int
    {
        return $this->sp;
    }

    /**
     * Sets the stack pointer value
     *
     * @param int $value Value to set (automatically masked to 8 bits)
     */
    public function setStackPointer(int $value): void
    {
        $this->sp = $value & 0xFF;
    }

    /**
     * Pushes a byte onto the stack
     *
     * Writes the value to the current stack location (0x0100 + SP) and
     * decrements the stack pointer. Stack grows downward from 0x01FF.
     *
     * @param int $value Byte to push (automatically masked to 8 bits)
     */
    public function pushByte(int $value): void
    {
        $this->bus->write(0x0100 + $this->sp, $value & 0xFF);
        $this->sp = ($this->sp - 1) & 0xFF;
    }

    /**
     * Pulls a byte from the stack
     *
     * Increments the stack pointer and reads from the stack location.
     *
     * @return int Byte value pulled from stack (0x00-0xFF)
     */
    public function pullByte(): int
    {
        $this->sp = ($this->sp + 1) & 0xFF;
        return $this->bus->read(0x0100 + $this->sp);
    }

    /**
     * Pushes a 16-bit word onto the stack
     *
     * Pushes high byte first, then low byte (standard 6502 convention).
     *
     * @param int $value 16-bit value to push
     */
    public function pushWord(int $value): void
    {
        $this->pushByte(($value >> 8) & 0xFF);
        $this->pushByte($value & 0xFF);
    }

    /**
     * Pulls a 16-bit word from the stack
     *
     * Pulls low byte first, then high byte (standard 6502 convention).
     *
     * @return int 16-bit value pulled from stack
     */
    public function pullWord(): int
    {
        $low = $this->pullByte();
        $high = $this->pullByte();
        return ($high << 8) | $low;
    }

    /**
     * Calculates effective address for a given addressing mode
     *
     * Reads necessary bytes from memory after PC and advances PC accordingly.
     * Supports all standard 6502 addressing modes including zero page, absolute,
     * indexed, indirect, and relative modes.
     *
     * @param string $addressingMode The addressing mode name
     * @return int The effective address calculated for this mode
     * @throws \InvalidArgumentException If addressing mode is not recognized
     */
    public function getAddress(string $addressingMode): int
    {
        return match ($addressingMode) {
            'Immediate' => $this->immediate(),
            'Zero Page' => $this->zeroPage(),
            'X-Indexed Zero Page' => $this->zeroPageX(),
            'Y-Indexed Zero Page' => $this->zeroPageY(),
            'Absolute' => $this->absolute(),
            'X-Indexed Absolute' => $this->absoluteX(),
            'Y-Indexed Absolute' => $this->absoluteY(),
            'X-Indexed Zero Page Indirect' => $this->indirectX(),
            'Zero Page Indirect Y-Indexed' => $this->indirectY(),
            'Absolute Indirect' => $this->absoluteIndirect(),
            'Relative' => $this->relative(),
            'Implied' => $this->implied(),
            'Accumulator' => $this->accumulator(),
            default => throw new \InvalidArgumentException("Invalid addressing mode: {$addressingMode}"),
        };
    }

    private function immediate(): int
    {
        $this->pc++;
        return $this->pc - 1;
    }

    private function zeroPage(): int
    {
        $address = $this->bus->read($this->pc);
        $this->pc++;
        return $address;
    }

    private function zeroPageX(): int
    {
        $address = $this->bus->read($this->pc) + $this->registerX;
        $this->pc++;
        return $address & 0xFF;
    }

    private function zeroPageY(): int
    {
        $address = $this->bus->read($this->pc) + $this->registerY;
        $this->pc++;
        return $address & 0xFF;
    }

    private function absolute(): int
    {
        $low = $this->bus->read($this->pc);
        $this->pc++;
        $high = $this->bus->read($this->pc);
        $this->pc++;
        return ($high << 8) | $low;
    }

    private function absoluteX(): int
    {
        $low = $this->bus->read($this->pc);
        $this->pc++;
        $high = $this->bus->read($this->pc);
        $this->pc++;
        $address = (($high << 8) | $low) + $this->registerX;

        return $address & 0xFFFF;
    }

    private function absoluteY(): int
    {
        $low = $this->bus->read($this->pc);
        $this->pc++;
        $high = $this->bus->read($this->pc);
        $this->pc++;
        $address = (($high << 8) | $low) + $this->registerY;

        return $address & 0xFFFF;
    }

    private function indirectX(): int
    {
        $zeroPageAddress = $this->bus->read($this->pc) + $this->registerX;
        $this->pc++;
        $low = $this->bus->read($zeroPageAddress & 0xFF);
        $high = $this->bus->read(($zeroPageAddress + 1) & 0xFF);

        return ($high << 8) | $low;
    }

    private function indirectY(): int
    {
        $zeroPageAddress = $this->bus->read($this->pc);
        $this->pc++;
        $low = $this->bus->read($zeroPageAddress & 0xFF);
        $high = $this->bus->read(($zeroPageAddress + 1) & 0xFF);
        $address = (($high << 8) | $low) + $this->registerY;

        return $address & 0xFFFF;
    }

    private function absoluteIndirect(): int
    {
        $low = $this->bus->read($this->pc);
        $this->pc++;
        $high = $this->bus->read($this->pc);
        $this->pc++;
        $indirectAddress = ($high << 8) | $low;

        if (($indirectAddress & 0xFF) == 0xFF) {
            $targetLow = $this->bus->read($indirectAddress);
            $targetHigh = $this->bus->read($indirectAddress & 0xFF00);

            return ($targetHigh << 8) | $targetLow;
        } else {
            return $this->bus->readWord($indirectAddress);
        }
    }

    private function relative(): int
    {
        $offset = $this->bus->read($this->pc);
        $this->pc++;
        return $offset;
    }

    private function implied(): int
    {
        return 0;
    }

    private function accumulator(): int
    {
        return 0;
    }

    /**
     * Returns a formatted string of CPU register states
     *
     * @return string Formatted string showing PC, SP, A, X, Y registers in hex
     */
    public function getRegistersState(): string
    {
        return sprintf(
            "PC: 0x%04X, SP: 0x%04X, A: 0x%02X, X: 0x%02X, Y: 0x%02X",
            $this->pc,
            $this->sp,
            $this->accumulator,
            $this->registerX,
            $this->registerY
        );
    }

    /**
     * Returns a formatted string of CPU status flags
     *
     * @return string Formatted string showing all status flags (NVUBDIZC)
     */
    public function getFlagsState(): string
    {
        return sprintf(
            "Flags: %s%s%s%s%s%s%s%s",
            $this->status->get(StatusRegister::NEGATIVE) ? 'N' : '-',
            $this->status->get(StatusRegister::OVERFLOW) ? 'V' : '-',
            '-',
            $this->status->get(StatusRegister::BREAK_COMMAND) ? 'B' : '-',
            $this->status->get(StatusRegister::DECIMAL_MODE) ? 'D' : '-',
            $this->status->get(StatusRegister::INTERRUPT_DISABLE) ? 'I' : '-',
            $this->status->get(StatusRegister::ZERO) ? 'Z' : '-',
            $this->status->get(StatusRegister::CARRY) ? 'C' : '-'
        );
    }

    /**
     * Gets the system bus interface
     *
     * @return BusInterface The bus for memory and I/O access
     */
    public function getBus(): BusInterface
    {
        return $this->bus;
    }

    /**
     * Gets the instruction register containing opcode definitions
     *
     * @return InstructionRegister The opcode registry
     */
    public function getInstructionRegister(): InstructionRegister
    {
        return $this->instructionRegister;
    }

    /**
     * Requests a Non-Maskable Interrupt (NMI)
     *
     * NMI is edge-triggered and cannot be masked by the I flag. Will execute
     * at the next instruction boundary. Only triggers on falling edge.
     */
    public function requestNMI(): void
    {
        if ($this->nmiLastState === true) {
            $this->nmiPending = true;
        }
        $this->nmiLastState = false;
    }

    /**
     * Releases the NMI line to high state
     *
     * Prepares for the next falling edge detection.
     */
    public function releaseNMI(): void
    {
        $this->nmiLastState = true;
    }

    /**
     * Requests an Interrupt Request (IRQ)
     *
     * IRQ is level-triggered and can be masked by the I flag. Will execute
     * at the next instruction boundary if interrupts are enabled.
     */
    public function requestIRQ(): void
    {
        $this->irqPending = true;
    }

    /**
     * Releases the IRQ line, clearing the pending interrupt
     */
    public function releaseIRQ(): void
    {
        $this->irqPending = false;
    }

    /**
     * Requests a RESET interrupt
     *
     * RESET has highest priority and will execute at the next instruction boundary.
     */
    public function requestReset(): void
    {
        $this->resetPending = true;
    }

    /**
     * Sets or clears the CPU monitor for debugging
     *
     * @param CPUMonitor|null $monitor Monitor instance or null to disable
     */
    public function setMonitor(?CPUMonitor $monitor): void
    {
        $this->monitor = $monitor;
    }

    /**
     * Gets the current CPU monitor instance
     *
     * @return CPUMonitor|null Monitor instance or null if not monitored
     */
    public function getMonitor(): ?CPUMonitor
    {
        return $this->monitor;
    }

    /**
     * Checks if the CPU is currently being monitored
     *
     * @return bool True if a monitor is attached, false otherwise
     */
    public function isMonitored(): bool
    {
        return $this->monitor !== null;
    }
}
