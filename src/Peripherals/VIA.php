<?php

declare(strict_types=1);

namespace Emulator\Peripherals;

use Emulator\Bus\PeripheralInterface;

class VIA implements PeripheralInterface
{
    public const REG_ORB = 0x00; // Output Register B
    public const REG_ORA = 0x01; // Output Register A
    public const REG_DDRB = 0x02; // Data Direction Register B
    public const REG_DDRA = 0x03; // Data Direction Register A
    public const REG_T1C_L = 0x04; // Timer 1 Counter Low
    public const REG_T1C_H = 0x05; // Timer 1 Counter High
    public const REG_T1L_L = 0x06; // Timer 1 Latch Low
    public const REG_T1L_H = 0x07; // Timer 1 Latch High
    public const REG_T2C_L = 0x08; // Timer 2 Counter Low
    public const REG_T2C_H = 0x09; // Timer 2 Counter High
    public const REG_SR = 0x0A; // Shift Register
    public const REG_ACR = 0x0B; // Auxiliary Control Register
    public const REG_PCR = 0x0C; // Peripheral Control Register
    public const REG_IFR = 0x0D; // Interrupt Flag Register
    public const REG_IER = 0x0E; // Interrupt Enable Register
    public const REG_ORA_NO_HS = 0x0F; // Output Register A (No Handshake)

    public const IRQ_CA2 = 0b00000001;
    public const IRQ_CA1 = 0b00000010;
    public const IRQ_SR = 0b00000100;
    public const IRQ_CB2 = 0b00001000;
    public const IRQ_CB1 = 0b00010000;
    public const IRQ_T2 = 0b00100000;
    public const IRQ_T1 = 0b01000000;
    public const IRQ_ANY = 0b10000000;

    private int $baseAddress;

    private int $orb = 0x00;
    private int $ora = 0x00;
    private int $ddrb = 0x00;
    private int $ddra = 0x00;
    private int $t1c = 0x0000;
    private int $t1l = 0x0000;
    private int $t2c = 0x0000;
    private int $t2l = 0x0000; // T2 latch is only on T2C-L write
    private int $sr = 0x00;
    private int $acr = 0x00;
    private int $pcr = 0x00;
    private int $ifr = 0x00;
    private int $ier = 0x00;

    private bool $t1_active = false;
    private bool $t2_active = false;

    private bool $irq_pending = false;

    public function __construct(int $baseAddress)
    {
        $this->baseAddress = $baseAddress;
    }

    public function handlesAddress(int $address): bool
    {
        return $address >= $this->baseAddress && $address < $this->baseAddress + 16;
    }

    public function read(int $address): int
    {
        $offset = $address - $this->baseAddress;
        $value = 0x00;

        switch ($offset) {
            case self::REG_ORB:
                // Reading Port B clears CB1/CB2 interrupt flags
                $this->clearInterrupt(self::IRQ_CB1 | self::IRQ_CB2);
                // TODO: Implement handshake logic
                // For now, return a mix of output register and "inputs"
                // Assume inputs are all high for now
                $value = ($this->orb & $this->ddrb) | (~$this->ddrb & 0xFF);
                break;
            case self::REG_ORA:
            case self::REG_ORA_NO_HS:
                // Reading Port A clears CA1/CA2 interrupt flags
                $this->clearInterrupt(self::IRQ_CA1 | self::IRQ_CA2);
                // TODO: Implement handshake logic
                $value = ($this->ora & $this->ddra) | (~$this->ddra & 0xFF);
                break;
            case self::REG_DDRB:
                $value = $this->ddrb;
                break;
            case self::REG_DDRA:
                $value = $this->ddra;
                break;
            case self::REG_T1C_L:
                $this->clearInterrupt(self::IRQ_T1);
                $value = $this->t1c & 0xFF;
                break;
            case self::REG_T1C_H:
                $value = ($this->t1c >> 8) & 0xFF;
                break;
            case self::REG_T2C_L:
                $this->clearInterrupt(self::IRQ_T2);
                $value = $this->t2c & 0xFF;
                break;
            case self::REG_T2C_H:
                $value = ($this->t2c >> 8) & 0xFF;
                break;
            case self::REG_SR:
                $this->clearInterrupt(self::IRQ_SR);
                $value = $this->sr;
                break;
            case self::REG_ACR:
                $value = $this->acr;
                break;
            case self::REG_PCR:
                $value = $this->pcr;
                break;
            case self::REG_IFR:
                $value = $this->ifr;
                if ($this->ifr & ($this->ier & 0x7F)) {
                    $value |= self::IRQ_ANY;
                }
                break;
            case self::REG_IER:
                $value = $this->ier | 0x80;
                break;
        }
        return $value;
    }

    public function write(int $address, int $data): void
    {
        $offset = $address - $this->baseAddress;

        switch ($offset) {
            case self::REG_ORB:
                $this->orb = $data;
                $this->clearInterrupt(self::IRQ_CB1 | self::IRQ_CB2);
                // TODO: Handshake logic
                break;
            case self::REG_ORA:
            case self::REG_ORA_NO_HS:
                $this->ora = $data;
                $this->clearInterrupt(self::IRQ_CA1 | self::IRQ_CA2);
                // TODO: Handshake logic
                break;
            case self::REG_DDRB:
                $this->ddrb = $data;
                break;
            case self::REG_DDRA:
                $this->ddra = $data;
                break;
            case self::REG_T1C_L:
            case self::REG_T1L_L:
                $this->t1l = ($this->t1l & 0xFF00) | $data;
                break;
            case self::REG_T1C_H:
                $this->t1l = ($this->t1l & 0x00FF) | ($data << 8);
                $this->t1c = $this->t1l;
                $this->t1_active = true;
                $this->clearInterrupt(self::IRQ_T1);
                break;
            case self::REG_T1L_H:
                $this->t1l = ($this->t1l & 0x00FF) | ($data << 8);
                $this->clearInterrupt(self::IRQ_T1);
                break;
            case self::REG_T2C_L:
                $this->t2l = $data;
                break;
            case self::REG_T2C_H:
                $this->t2c = ($data << 8) | $this->t2l;
                $this->t2_active = true;
                $this->clearInterrupt(self::IRQ_T2);
                break;
            case self::REG_SR:
                $this->sr = $data;
                $this->clearInterrupt(self::IRQ_SR);
                break;
            case self::REG_ACR:
                $this->acr = $data;
                break;
            case self::REG_PCR:
                $this->pcr = $data;
                break;
            case self::REG_IFR:
                // Writing to IFR clears flags where a 1 is written
                $this->ifr &= ~$data;
                $this->updateIrqStatus();
                break;
            case self::REG_IER:
                if ($data & 0x80) { // Bit 7 is set
                    $this->ier |= ($data & 0x7F);
                } else { // Bit 7 is clear
                    $this->ier &= ~($data & 0x7F);
                }
                $this->updateIrqStatus();
                break;
        }
    }

    public function tick(): void
    {
        if ($this->t1_active) {
            $this->t1c--;
            if ($this->t1c < 0) {
                $this->setInterrupt(self::IRQ_T1);
                // Check ACR for T1 mode
                if ($this->acr & 0x40) { // One-shot mode
                    $this->t1_active = false;
                    $this->t1c = 0xFFFF;
                } else { // Continuous mode
                    $this->t1c = $this->t1l; // Reload from latch
                }
            }
        }

        if ($this->t2_active && !($this->acr & 0x20)) {
            $this->t2c--;
            if ($this->t2c < 0) {
                $this->setInterrupt(self::IRQ_T2);
                $this->t2_active = false; // T2 is always one-shot as interval timer
                $this->t2c = 0xFFFF;
            }
        }
    }

    public function hasInterruptRequest(): bool
    {
        return $this->irq_pending;
    }

    private function setInterrupt(int $flag): void
    {
        $this->ifr |= $flag;
        $this->updateIrqStatus();
    }

    private function clearInterrupt(int $flag): void
    {
        $this->ifr &= ~$flag;
        $this->updateIrqStatus();
    }

    private function updateIrqStatus(): void
    {
        // Is any enabled interrupt flag set?
        $this->irq_pending = ($this->ifr & $this->ier & 0x7F) !== 0;
    }
}
