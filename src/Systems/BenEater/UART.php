<?php

declare(strict_types=1);

namespace Emulator\Systems\BenEater;

use Emulator\Systems\BenEater\Bus\PeripheralInterface;

/**
 * Memory-mapped serial UART for console I/O.
 *
 * Emulates a 6551 ACIA (Asynchronous Communications Interface Adapter) with
 * full register support for data transmission/reception, status monitoring,
 * and configuration. Connected to STDIN/STDOUT for terminal interaction.
 *
 * Register Map (offset from base address):
 * - $00: Data Register (read/write)
 * - $01: Status Register (read only)
 * - $02: Command Register (write only)
 * - $03: Control Register (write only)
 */
class UART implements PeripheralInterface
{
    private const DATA_REGISTER = 0x00;    // RS1=0, RS0=0 (Read/Write)
    private const STATUS_REGISTER = 0x01;  // RS1=0, RS0=1 (Read Only)
    private const COMMAND_REGISTER = 0x02; // RS1=1, RS0=0 (Write Only)
    private const CONTROL_REGISTER = 0x03; // RS1=1, RS0=1 (Write Only)
    private const STATUS_FRAMING_ERROR = 0x02;       // Bit 1
    private const STATUS_OVERRUN_ERROR = 0x04;       // Bit 2
    private const STATUS_RECEIVER_DATA_READY = 0x08; // Bit 3
    private const STATUS_TRANSMITTER_DATA_EMPTY = 0x10; // Bit 4
    private const STATUS_DCD = 0x20;                 // Bit 5 - Data Carrier Detect
    private const STATUS_DSR = 0x40;                 // Bit 6 - Data Set Ready
    private const STATUS_IRQ = 0x80;                 // Bit 7 - Interrupt Request
    private const COMMAND_DTR = 0x01;                // Bit 0 - Data Terminal Ready
    private const COMMAND_IRD = 0x02;                // Bit 1 - Receiver Interrupt Request Disabled
    private const COMMAND_TIC_MASK = 0x0C;           // Bits 2-3 - Transmitter Interrupt Control
    private const COMMAND_TIC_RTS_HIGH_IRQ_OFF = 0x00; // TIC=00: RTS=High, transmit interrupt disabled
    private const COMMAND_TIC_RTS_LOW_IRQ_OFF = 0x08;  // TIC=10: RTS=Low, transmit interrupt disabled
    private const COMMAND_TIC_RTS_LOW_BREAK = 0x0C;    // TIC=11: RTS=Low, transmit break on TxD
    private const COMMAND_ECHO_MODE = 0x10;          // Bit 4 - Receiver Echo Mode
    private const CONTROL_BAUD_RATE_MASK = 0x0F;     // Bits 0-3
    private const CONTROL_RECEIVER_CLOCK_SOURCE = 0x10; // Bit 4
    private const CONTROL_WORD_LENGTH_MASK = 0x60;   // Bits 5-6
    private const CONTROL_STOP_BITS = 0x80;          // Bit 7

    private int $statusRegister = 0x10; // Transmitter empty on reset
    private int $commandRegister = 0x00;
    private int $controlRegister = 0x00;

    private string $transmitBuffer = '';
    private string $receiveBuffer = '';
    private int $transmitData = 0x00;
    private int $receiveData = 0x00;

    /** @var resource|null */ private $inputStream;
    /** @var resource|null */ private $outputStream;
    private bool $terminalConnected = false;
    private bool $irqEnabled = false;
    private bool $irqPending = false;
    private bool $useExternalReceiverClock = false; // RCS bit: false=external, true=internal
    private int $selectedBaudRate = 0x00;           // SBR bits 0-3
    private int $wordLength = 8;                    // WL bits: 5-8 bits
    private float $stopBits = 1;                    // SBN bit: 1, 1.5, or 2 stop bits
    private bool $ctsbState = false;                // CTSB input: false=low (enabled), true=high (disabled)

    /**
     * Creates a new UART instance.
     *
     * Automatically connects to the terminal and configures non-blocking input.
     *
     * @param int $baseAddress The base memory address (default: $FE00)
     */
    public function __construct(
        private int $baseAddress = 0xFE00
    ) {
        $this->reset();
        $this->connectToTerminal();
    }

    /**
     * Checks if this UART handles the specified address.
     *
     * @param int $address The memory address to check
     * @return bool True if address is within base+0 to base+3
     */
    public function handlesAddress(int $address): bool
    {
        return $address >= $this->baseAddress && $address <= ($this->baseAddress + 3);
    }

    /**
     * Reads a byte from the UART register at the specified address.
     *
     * @param int $address The memory address to read
     * @return int The register value (0-255)
     */
    public function read(int $address): int
    {
        $registerOffset = $address - $this->baseAddress;

        switch ($registerOffset) {
            case self::DATA_REGISTER:
                return $this->readDataRegister();

            case self::STATUS_REGISTER:
                return $this->readStatusRegister();

            case self::COMMAND_REGISTER:
            case self::CONTROL_REGISTER:
                // These are write-only registers, return 0
                return 0x00;

            default:
                return 0x00;
        }
    }

    /**
     * Writes a byte to the UART register at the specified address.
     *
     * @param int $address The memory address to write
     * @param int $value The value to write (will be masked to 8-bit)
     */
    public function write(int $address, int $value): void
    {
        $registerOffset = $address - $this->baseAddress;
        $value &= 0xFF; // Ensure 8-bit value

        switch ($registerOffset) {
            case self::DATA_REGISTER:
                $this->writeDataRegister($value);
                break;

            case self::STATUS_REGISTER:
                break;

            case self::COMMAND_REGISTER:
                $this->writeCommandRegister($value);
                break;

            case self::CONTROL_REGISTER:
                $this->writeControlRegister($value);
                break;
        }
    }

    private function readDataRegister(): int
    {
        if (!empty($this->receiveBuffer)) {
            $char = substr($this->receiveBuffer, 0, 1);
            $this->receiveBuffer = substr($this->receiveBuffer, 1);
            $this->receiveData = ord($char);

            if (empty($this->receiveBuffer)) {
                $this->statusRegister &= ~self::STATUS_RECEIVER_DATA_READY;
            }

            return $this->receiveData;
        }

        return 0x00;
    }

    private function writeDataRegister(int $value): void
    {
        $this->transmitData = $value;

        if ($this->ctsbState) {
            $this->statusRegister |= self::STATUS_TRANSMITTER_DATA_EMPTY;
            return;
        }

        $this->transmitBuffer .= chr($value);
        $this->statusRegister &= ~self::STATUS_TRANSMITTER_DATA_EMPTY;
        $this->flushTransmitBuffer();
        $this->statusRegister |= self::STATUS_TRANSMITTER_DATA_EMPTY;
    }

    private function readStatusRegister(): int
    {
        $this->updateStatus();

        $statusValue = $this->statusRegister;

        if ($this->statusRegister & self::STATUS_IRQ) {
            $this->statusRegister &= ~self::STATUS_IRQ;  // Clear IRQ bit
            $this->irqPending = false;                   // Clear internal IRQ state
        }

        return $statusValue;
    }

    private function writeCommandRegister(int $value): void
    {
        $this->commandRegister = $value;

        if ($value & self::COMMAND_DTR) {
        }

        $receiveIrqDisabled = ($value & self::COMMAND_IRD) !== 0;

        $ticValue = $value & self::COMMAND_TIC_MASK;
        switch ($ticValue) {
            case self::COMMAND_TIC_RTS_HIGH_IRQ_OFF:
                break;
            case self::COMMAND_TIC_RTS_LOW_IRQ_OFF:
                break;
            case self::COMMAND_TIC_RTS_LOW_BREAK:
                break;
            default:
                break;
        }

        $this->irqEnabled = !$receiveIrqDisabled;

        if ($value & self::COMMAND_ECHO_MODE) {
        }
    }

    private function writeControlRegister(int $value): void
    {
        $this->controlRegister = $value;
        $this->selectedBaudRate = $value & self::CONTROL_BAUD_RATE_MASK;
        $this->useExternalReceiverClock = ($value & self::CONTROL_RECEIVER_CLOCK_SOURCE) === 0;
        $wlBits = ($value & self::CONTROL_WORD_LENGTH_MASK) >> 5;
        $this->wordLength = match ($wlBits) {
            0b00 => 8,  // 8 bits
            0b01 => 7,  // 7 bits
            0b10 => 6,  // 6 bits
            0b11 => 5,  // 5 bits
        };

        if ($value & self::CONTROL_STOP_BITS) {
            $this->stopBits = ($this->wordLength === 5) ? 1.5 : 2;
        } else {
            $this->stopBits = 1;
        }
    }

    private function updateStatus(): void
    {
        if (empty($this->transmitBuffer)) {
            $this->statusRegister |= self::STATUS_TRANSMITTER_DATA_EMPTY;
        }

        if (!empty($this->receiveBuffer)) {
            $this->statusRegister |= self::STATUS_RECEIVER_DATA_READY;
        }

        if ($this->terminalConnected) {
            $this->statusRegister |= self::STATUS_DCD | self::STATUS_DSR;
        }

        $this->updateIrqStatus();
    }

    private function updateIrqStatus(): void
    {
        $newIrqPending = false;

        $receiveIrqDisabled = ($this->commandRegister & self::COMMAND_IRD) !== 0;
        if (!$receiveIrqDisabled && ($this->statusRegister & self::STATUS_RECEIVER_DATA_READY)) {
            $newIrqPending = true;
        }

        if (!$receiveIrqDisabled) {
            if (($this->statusRegister & self::STATUS_DCD) || ($this->statusRegister & self::STATUS_DSR)) {
            }
        }

        $this->irqPending = $newIrqPending;

        if ($this->irqPending) {
            $this->statusRegister |= self::STATUS_IRQ;
        } else {
            $this->statusRegister &= ~self::STATUS_IRQ;
        }
    }

    /**
     * Performs one cycle of UART operation.
     *
     * Polls terminal input and updates status flags.
     */
    public function tick(): void
    {
        $this->pollTerminalInput();
        $this->updateStatus();
    }

    /**
     * Resets the UART to initial state.
     *
     * Clears all buffers and resets registers to default values.
     */
    public function reset(): void
    {
        $this->statusRegister = self::STATUS_TRANSMITTER_DATA_EMPTY;
        $this->commandRegister = 0x00;
        $this->controlRegister = 0x00;
        $this->transmitBuffer = '';
        $this->receiveBuffer = '';
        $this->transmitData = 0x00;
        $this->receiveData = 0x00;
        $this->useExternalReceiverClock = false; // Default: external clock
        $this->selectedBaudRate = 0x00;          // Default: 115.2K baud
        $this->wordLength = 8;                   // Default: 8 bits
        $this->stopBits = 1;                     // Default: 1 stop bit
        $this->ctsbState = false;                // Default: CTSB low (transmitter enabled)
    }

    private function connectToTerminal(): void
    {
        $this->inputStream = STDIN;
        $this->outputStream = STDOUT;
        $this->terminalConnected = true;

        if (function_exists('stream_set_blocking')) {
            stream_set_blocking($this->inputStream, false);
        }
    }

    private function pollTerminalInput(): void
    {
        if (!$this->terminalConnected || !$this->inputStream) {
            return;
        }

        $data = fread($this->inputStream, 1024);
        if ($data !== false && strlen($data) > 0) {
            $this->receiveBuffer .= $data;
            $this->statusRegister |= self::STATUS_RECEIVER_DATA_READY;
        }
    }

    private function flushTransmitBuffer(): void
    {
        if (!$this->terminalConnected || !$this->outputStream || empty($this->transmitBuffer)) {
            return;
        }

        if ($this->ctsbState) {
            return;
        }

        fwrite($this->outputStream, $this->transmitBuffer);
        fflush($this->outputStream);

        $this->transmitBuffer = '';
        $this->statusRegister |= self::STATUS_TRANSMITTER_DATA_EMPTY;
    }

    /** @return int The status register value */
    public function getStatusRegister(): int
    {
        return $this->statusRegister;
    }

    /** @return int The command register value */
    public function getCommandRegister(): int
    {
        return $this->commandRegister;
    }

    /** @return int The control register value */
    public function getControlRegister(): int
    {
        return $this->controlRegister;
    }

    /** @return int Number of bytes in receive buffer */
    public function getReceiveBufferLength(): int
    {
        return strlen($this->receiveBuffer);
    }

    /** @return int Number of bytes in transmit buffer */
    public function getTransmitBufferLength(): int
    {
        return strlen($this->transmitBuffer);
    }

    /** @return bool True if IRQ is pending */
    public function isIrqPending(): bool
    {
        return $this->irqPending;
    }

    /** Clears the pending IRQ state */
    public function clearIrq(): void
    {
        $this->irqPending = false;
        $this->statusRegister &= ~self::STATUS_IRQ;
    }

    /** @return bool True if using external receiver clock */
    public function isUsingExternalReceiverClock(): bool
    {
        return $this->useExternalReceiverClock;
    }

    /** @return int The selected baud rate (0-15) */
    public function getSelectedBaudRate(): int
    {
        return $this->selectedBaudRate;
    }

    /** @return int The word length in bits (5-8) */
    public function getWordLength(): int
    {
        return $this->wordLength;
    }

    /** @return float The number of stop bits (1, 1.5, or 2) */
    public function getStopBits(): float
    {
        return $this->stopBits;
    }

    /**
     * Sets the Clear To Send Bar signal state.
     *
     * @param bool $high True for high (transmitter disabled), false for low (enabled)
     */
    public function setCTSB(bool $high): void
    {
        $this->ctsbState = $high;
    }

    /** @return bool The CTSB signal state */
    public function getCTSB(): bool
    {
        return $this->ctsbState;
    }

    /** @return bool True if transmitter is enabled (CTSB low) */
    public function isTransmitterEnabled(): bool
    {
        return !$this->ctsbState;
    }

    /**
     * Checks if this peripheral has a pending interrupt request.
     *
     * @return bool True if an IRQ is pending
     */
    public function hasInterruptRequest(): bool
    {
        return $this->irqPending;
    }
}
