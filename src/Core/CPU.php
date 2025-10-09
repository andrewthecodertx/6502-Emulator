<?php

declare(strict_types=1);

namespace Emulator\Core;

use Emulator\Core\Instructions\LoadStore;
use Emulator\Core\Instructions\Transfer;
use Emulator\Core\Instructions\Arithmetic;
use Emulator\Core\Instructions\Logic;
use Emulator\Core\Instructions\ShiftRotate;
use Emulator\Core\Instructions\IncDec;
use Emulator\Core\Instructions\FlowControl;
use Emulator\Core\Instructions\Stack;
use Emulator\Core\Instructions\Flags;

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

        $this->initializeInstructionHandlers();
    }

    public function clock(): void
    {
        $this->cycles--;

        if ($this->monitor !== null) {
            $this->monitor->logCycle();
        }
    }

    public function run(): void
    {
        while ($this->running) {
            $this->step();
        }
    }

    public function stop(): void
    {
        $this->running = false;
    }

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

    public function executeInstruction(): void
    {
        $startingPC = $this->pc;
        do {
            $this->step();
        } while (!$this->halted && ($this->cycles > 0 || $this->pc == $startingPC));
    }

    public function halt(): void
    {
        $this->halted = true;
    }

    public function resume(): void
    {
        $this->halted = false;
    }

    public function isHalted(): bool
    {
        return $this->halted;
    }

    public function setAutoTickBus(bool $autoTick): void
    {
        $this->autoTickBus = $autoTick;
    }

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

          'INC' => fn (Opcode $opcode) => $this->incDecHandler->inc($opcode),
          'DEC' => fn (Opcode $opcode) => $this->incDecHandler->dec($opcode),
          'INX' => fn (Opcode $opcode) => $this->incDecHandler->inx($opcode),
          'DEX' => fn (Opcode $opcode) => $this->incDecHandler->dex($opcode),
          'INY' => fn (Opcode $opcode) => $this->incDecHandler->iny($opcode),
          'DEY' => fn (Opcode $opcode) => $this->incDecHandler->dey($opcode),

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

          'NOP' => fn (Opcode $opcode) => $opcode->getCycles(),
        ];
    }

    public function getAccumulator(): int
    {
        return $this->accumulator;
    }

    public function setAccumulator(int $value): void
    {
        $this->accumulator = $value & 0xFF;
    }

    public function getRegisterX(): int
    {
        return $this->registerX;
    }

    public function setRegisterX(int $value): void
    {
        $this->registerX = $value & 0xFF;
    }

    public function getRegisterY(): int
    {
        return $this->registerY;
    }

    public function setRegisterY(int $value): void
    {
        $this->registerY = $value & 0xFF;
    }

    public function getStackPointer(): int
    {
        return $this->sp;
    }

    public function setStackPointer(int $value): void
    {
        $this->sp = $value & 0xFF;
    }

    public function pushByte(int $value): void
    {
        $this->bus->write(0x0100 + $this->sp, $value & 0xFF);
        $this->sp = ($this->sp - 1) & 0xFF;
    }

    public function pullByte(): int
    {
        $this->sp = ($this->sp + 1) & 0xFF;
        return $this->bus->read(0x0100 + $this->sp);
    }

    public function pushWord(int $value): void
    {
        $this->pushByte(($value >> 8) & 0xFF);
        $this->pushByte($value & 0xFF);
    }

    public function pullWord(): int
    {
        $low = $this->pullByte();
        $high = $this->pullByte();
        return ($high << 8) | $low;
    }

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

    public function getBus(): BusInterface
    {
        return $this->bus;
    }

    public function getInstructionRegister(): InstructionRegister
    {
        return $this->instructionRegister;
    }

    public function requestNMI(): void
    {
        if ($this->nmiLastState === true) {
            $this->nmiPending = true;
        }
        $this->nmiLastState = false;
    }

    public function releaseNMI(): void
    {
        $this->nmiLastState = true;
    }

    public function requestIRQ(): void
    {
        $this->irqPending = true;
    }

    public function releaseIRQ(): void
    {
        $this->irqPending = false;
    }

    public function requestReset(): void
    {
        $this->resetPending = true;
    }

    public function setMonitor(?CPUMonitor $monitor): void
    {
        $this->monitor = $monitor;
    }

    public function getMonitor(): ?CPUMonitor
    {
        return $this->monitor;
    }

    public function isMonitored(): bool
    {
        return $this->monitor !== null;
    }
}
