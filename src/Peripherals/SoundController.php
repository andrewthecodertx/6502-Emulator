<?php

declare(strict_types=1);

namespace Emulator\Peripherals;

use Emulator\Bus\PeripheralInterface;

class SoundController implements PeripheralInterface
{
    public const SOUND_BASE = 0xC400;
    public const SOUND_END = 0xC40F;
    public const CH0_FREQ_LO = 0xC400;
    public const CH0_FREQ_HI = 0xC401;
    public const CH0_VOLUME = 0xC402;
    public const CH0_CONTROL = 0xC403;
    public const CH1_FREQ_LO = 0xC404;
    public const CH1_FREQ_HI = 0xC405;
    public const CH1_VOLUME = 0xC406;
    public const CH1_CONTROL = 0xC407;
    public const CH2_FREQ_LO = 0xC408;
    public const CH2_FREQ_HI = 0xC409;
    public const CH2_VOLUME = 0xC40A;
    public const CH2_CONTROL = 0xC40B;
    public const CH3_FREQ_LO = 0xC40C;
    public const CH3_FREQ_HI = 0xC40D;
    public const CH3_VOLUME = 0xC40E;
    public const CH3_CONTROL = 0xC40F;
    public const CTRL_ENABLE = 0x01;
    public const CTRL_SQUARE = 0x00;
    public const CTRL_NOISE = 0x02;
    public const CTRL_TRIANGLE = 0x04;

    /** @var array<int, array<string, int>> */ private array $channels = [];

    private bool $irq_pending = false;

    public function __construct()
    {
        $this->reset();
    }

    public function handlesAddress(int $address): bool
    {
        return $address >= self::SOUND_BASE && $address <= self::SOUND_END;
    }

    public function read(int $address): int
    {
        $channel = ($address - self::SOUND_BASE) >> 2;
        $register = ($address - self::SOUND_BASE) & 3;

        if ($channel >= 4) {
            return 0;
        }

        switch ($register) {
            case 0:
                return $this->channels[$channel]['freq'] & 0xFF;
            case 1:
                return ($this->channels[$channel]['freq'] >> 8) & 0xFF;
            case 2:
                return $this->channels[$channel]['volume'];
            case 3:
                return $this->channels[$channel]['control'];
            default:
                return 0;
        }
    }

    public function write(int $address, int $value): void
    {
        $channel = ($address - self::SOUND_BASE) >> 2;
        $register = ($address - self::SOUND_BASE) & 3;

        if ($channel >= 4) {
            return;
        }

        switch ($register) {
            case 0:
                $this->channels[$channel]['freq'] =
                  ($this->channels[$channel]['freq'] & 0xFF00) | ($value & 0xFF);
                break;
            case 1:
                $this->channels[$channel]['freq'] =
                  ($this->channels[$channel]['freq'] & 0x00FF) | (($value & 0xFF) << 8);
                break;
            case 2:
                $this->channels[$channel]['volume'] = $value & 0x0F;
                break;
            case 3:
                $this->channels[$channel]['control'] = $value & 0xFF;
                if ($value & self::CTRL_ENABLE) {
                    $this->playTone($channel);
                }
                break;
        }
    }

    public function tick(): void
    {
    }

    public function reset(): void
    {
        for ($i = 0; $i < 4; $i++) {
            $this->channels[$i] = [
              'freq' => 0,
              'volume' => 0,
              'control' => 0,
              'phase' => 0
            ];
        }
    }

    private function playTone(int $channel): void
    {
        $freq = $this->channels[$channel]['freq'];
        $volume = $this->channels[$channel]['volume'];
        $control = $this->channels[$channel]['control'];

        if (!($control & self::CTRL_ENABLE) || $volume === 0 || $freq === 0) {
            return;
        }

        $hz = max(50, min(2000, $freq));

        if (php_sapi_name() === 'cli') {
            $this->generateBeep($hz, $volume);
        }
    }

    private function generateBeep(int $frequency, int $volume): void
    {
        $duration = $volume * 0.1;

        if (shell_exec('which speaker-test 2>/dev/null')) {
            shell_exec("timeout {$duration}s speaker-test -t sine -f $frequency -l 1 >/dev/null 2>&1 &");
        } elseif (shell_exec('which play 2>/dev/null')) {
            shell_exec("play -n synth {$duration} sine $frequency vol 0.1 >/dev/null 2>&1 &");
        } else {
            echo "\a";
        }
    }

    public function playNote(int $channel, int $note, int $volume = 10, int $duration = 500): void
    {
        $frequency = intval(440 * pow(2, ($note - 69) / 12));

        $this->channels[$channel]['freq'] = $frequency;
        $this->channels[$channel]['volume'] = $volume & 0x0F;
        $this->channels[$channel]['control'] = self::CTRL_ENABLE | self::CTRL_SQUARE;

        $this->playTone($channel);
    }

    public function silence(): void
    {
        for ($i = 0; $i < 4; $i++) {
            $this->channels[$i]['control'] &= ~self::CTRL_ENABLE;
        }
    }

    public function hasInterruptRequest(): bool
    {
        return $this->irq_pending;
    }
}
