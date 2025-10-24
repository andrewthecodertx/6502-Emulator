<?php

declare(strict_types=1);

namespace andrewthecoder\MOS6502\Instructions;

use andrewthecoder\MOS6502\CPU;
use andrewthecoder\Core\Opcode;
use andrewthecoder\Core\StatusRegister;

class LoadStore
{
    public function __construct(
        private CPU $cpu
    ) {
    }

    public function lda(Opcode $opcode): int
    {
        $address = $this->cpu->getAddress($opcode->getAddressingMode());
        $value = $this->cpu->getBus()->read($address);
        $this->cpu->setAccumulator($value);

        $this->cpu->status->set(StatusRegister::ZERO, $value === 0);
        $this->cpu->status->set(StatusRegister::NEGATIVE, ($value & 0x80) !== 0);

        return $opcode->getCycles();
    }

    public function sta(Opcode $opcode): int
    {
        $address = $this->cpu->getAddress($opcode->getAddressingMode());
        $this->cpu->getBus()->write($address, $this->cpu->getAccumulator());

        return $opcode->getCycles();
    }

    public function ldx(Opcode $opcode): int
    {
        $address = $this->cpu->getAddress($opcode->getAddressingMode());
        $value = $this->cpu->getBus()->read($address);
        $this->cpu->setRegisterX($value);

        $this->cpu->status->set(StatusRegister::ZERO, $value === 0);
        $this->cpu->status->set(StatusRegister::NEGATIVE, ($value & 0x80) !== 0);

        return $opcode->getCycles();
    }

    public function ldy(Opcode $opcode): int
    {
        $address = $this->cpu->getAddress($opcode->getAddressingMode());
        $value = $this->cpu->getBus()->read($address);
        $this->cpu->setRegisterY($value);

        $this->cpu->status->set(StatusRegister::ZERO, $value === 0);
        $this->cpu->status->set(StatusRegister::NEGATIVE, ($value & 0x80) !== 0);

        return $opcode->getCycles();
    }

    public function stx(Opcode $opcode): int
    {
        $address = $this->cpu->getAddress($opcode->getAddressingMode());
        $this->cpu->getBus()->write($address, $this->cpu->getRegisterX());

        return $opcode->getCycles();
    }

    public function sty(Opcode $opcode): int
    {
        $address = $this->cpu->getAddress($opcode->getAddressingMode());
        $this->cpu->getBus()->write($address, $this->cpu->getRegisterY());

        return $opcode->getCycles();
    }

    // SAX: Store A AND X (undocumented)
    public function sax(Opcode $opcode): int
    {
        $address = $this->cpu->getAddress($opcode->getAddressingMode());
        $result = $this->cpu->getAccumulator() & $this->cpu->getRegisterX();
        $this->cpu->getBus()->write($address, $result);

        return $opcode->getCycles();
    }
}
