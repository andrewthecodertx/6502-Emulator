<?php

namespace Emulator\Systems\C64\Peripherals;

use Emulator\Systems\C64\Bus\PeripheralInterface;

/**
 * MOS 6581/8580 Sound Interface Device (SID)
 *
 * The SID is the C64's legendary sound chip:
 * - 3 independent voices
 * - 4 waveforms per voice (triangle, sawtooth, pulse, noise)
 * - ADSR envelope generator per voice
 * - Programmable filters (low-pass, high-pass, band-pass)
 * - Ring modulation and oscillator sync
 *
 * Register Map ($D400-$D7FF, repeated):
 * Voice 1 ($D400-$D406):
 *   $00-$01: Frequency (16-bit)
 *   $02-$03: Pulse width (12-bit)
 *   $04: Control register (waveform, gate, ring mod, sync, test)
 *   $05: Attack/Decay
 *   $06: Sustain/Release
 * Voice 2 ($D407-$D40D): Same as Voice 1
 * Voice 3 ($D40E-$D414): Same as Voice 1
 * Filter ($D415-$D418):
 *   $15-$16: Filter cutoff frequency (11-bit)
 *   $17: Resonance and filter routing
 *   $18: Volume and filter mode
 * Misc ($D419-$D41C):
 *   $19: Paddle X (potentiometer)
 *   $1A: Paddle Y (potentiometer)
 *   $1B: Voice 3 oscillator output (read-only)
 *   $1C: Voice 3 envelope output (read-only)
 *
 */
class SID6581 implements PeripheralInterface
{
    private const BASE_ADDRESS = 0xD400;

    /** @var array{freq: int, pw: int, ctrl: int, ad: int, sr: int} */
    private array $voice1 = ['freq' => 0, 'pw' => 0, 'ctrl' => 0, 'ad' => 0, 'sr' => 0];
    /** @var array{freq: int, pw: int, ctrl: int, ad: int, sr: int} */
    private array $voice2 = ['freq' => 0, 'pw' => 0, 'ctrl' => 0, 'ad' => 0, 'sr' => 0];
    /** @var array{freq: int, pw: int, ctrl: int, ad: int, sr: int} */
    private array $voice3 = ['freq' => 0, 'pw' => 0, 'ctrl' => 0, 'ad' => 0, 'sr' => 0];

    private int $filterCutoffLow = 0;
    private int $filterCutoffHigh = 0;
    private int $filterResonance = 0;
    private int $filterMode = 0;

    private int $volume = 0;
    private int $paddleX = 0xFF;
    private int $paddleY = 0xFF;

    private int $voice3Osc = 0;
    private int $voice3Env = 0;

    /** @var array{phase: int, envelope: int, adsrPhase: string} */
    private array $voice1State = ['phase' => 0, 'envelope' => 0, 'adsrPhase' => 'idle'];
    /** @var array{phase: int, envelope: int, adsrPhase: string} */
    private array $voice2State = ['phase' => 0, 'envelope' => 0, 'adsrPhase' => 'idle'];
    /** @var array{phase: int, envelope: int, adsrPhase: string} */
    private array $voice3State = ['phase' => 0, 'envelope' => 0, 'adsrPhase' => 'idle'];

    public function handlesAddress(int $address): bool
    {
        return $address >= 0xD400 && $address <= 0xD7FF;
    }

    public function read(int $address): int
    {
        $reg = ($address - self::BASE_ADDRESS) & 0x1F;

        return match ($reg) {
            // Most SID registers are write-only
            0x19 => $this->paddleX,        // Paddle X
            0x1A => $this->paddleY,        // Paddle Y
            0x1B => $this->voice3Osc,      // Voice 3 oscillator
            0x1C => $this->voice3Env,      // Voice 3 envelope
            default => 0x00,               // Write-only registers return 0
        };
    }

    public function write(int $address, int $value): void
    {
        $value &= 0xFF;
        $reg = ($address - self::BASE_ADDRESS) & 0x1F;

        match ($reg) {
            0x00 => $this->voice1['freq'] = ($this->voice1['freq'] & 0xFF00) | $value,
            0x01 => $this->voice1['freq'] = ($this->voice1['freq'] & 0x00FF) | ($value << 8),
            0x02 => $this->voice1['pw'] = ($this->voice1['pw'] & 0x0F00) | $value,
            0x03 => $this->voice1['pw'] = ($this->voice1['pw'] & 0x00FF) | (($value & 0x0F) << 8),
            0x04 => $this->writeVoiceControl(1, $value),
            0x05 => $this->voice1['ad'] = $value,
            0x06 => $this->voice1['sr'] = $value,

            0x07 => $this->voice2['freq'] = ($this->voice2['freq'] & 0xFF00) | $value,
            0x08 => $this->voice2['freq'] = ($this->voice2['freq'] & 0x00FF) | ($value << 8),
            0x09 => $this->voice2['pw'] = ($this->voice2['pw'] & 0x0F00) | $value,
            0x0A => $this->voice2['pw'] = ($this->voice2['pw'] & 0x00FF) | (($value & 0x0F) << 8),
            0x0B => $this->writeVoiceControl(2, $value),
            0x0C => $this->voice2['ad'] = $value,
            0x0D => $this->voice2['sr'] = $value,

            0x0E => $this->voice3['freq'] = ($this->voice3['freq'] & 0xFF00) | $value,
            0x0F => $this->voice3['freq'] = ($this->voice3['freq'] & 0x00FF) | ($value << 8),
            0x10 => $this->voice3['pw'] = ($this->voice3['pw'] & 0x0F00) | $value,
            0x11 => $this->voice3['pw'] = ($this->voice3['pw'] & 0x00FF) | (($value & 0x0F) << 8),
            0x12 => $this->writeVoiceControl(3, $value),
            0x13 => $this->voice3['ad'] = $value,
            0x14 => $this->voice3['sr'] = $value,

            0x15 => $this->filterCutoffLow = $value & 0x07,
            0x16 => $this->filterCutoffHigh = $value,
            0x17 => $this->filterResonance = $value,
            0x18 => $this->writeFilterMode($value),

            default => null,
        };
    }

    public function tick(): void
    {
        if (($this->voice1['ctrl'] & 0x01) !== 0) {  // Gate bit
            $this->voice1State['phase'] = ($this->voice1State['phase'] + $this->voice1['freq']) & 0xFFFFFF;
            $this->updateEnvelope($this->voice1State, $this->voice1);
        }

        if (($this->voice2['ctrl'] & 0x01) !== 0) {
            $this->voice2State['phase'] = ($this->voice2State['phase'] + $this->voice2['freq']) & 0xFFFFFF;
            $this->updateEnvelope($this->voice2State, $this->voice2);
        }

        if (($this->voice3['ctrl'] & 0x01) !== 0) {
            $this->voice3State['phase'] = ($this->voice3State['phase'] + $this->voice3['freq']) & 0xFFFFFF;
            $this->updateEnvelope($this->voice3State, $this->voice3);

            $this->voice3Osc = ($this->voice3State['phase'] >> 16) & 0xFF;
            $this->voice3Env = $this->voice3State['envelope'] & 0xFF;
        }
    }

    private function writeVoiceControl(int $voiceNum, int $value): void
    {
        $previousGate = match ($voiceNum) {
            1 => ($this->voice1['ctrl'] & 0x01) !== 0,
            2 => ($this->voice2['ctrl'] & 0x01) !== 0,
            3 => ($this->voice3['ctrl'] & 0x01) !== 0,
            default => throw new \InvalidArgumentException("Invalid voice: $voiceNum"),
        };

        $newGate = ($value & 0x01) !== 0;

        match ($voiceNum) {
            1 => $this->voice1['ctrl'] = $value,
            2 => $this->voice2['ctrl'] = $value,
            3 => $this->voice3['ctrl'] = $value,
            default => throw new \InvalidArgumentException("Invalid voice: $voiceNum"),
        };

        if ($newGate && !$previousGate) {
            match ($voiceNum) {
                1 => $this->voice1State['adsrPhase'] = 'attack',
                2 => $this->voice2State['adsrPhase'] = 'attack',
                3 => $this->voice3State['adsrPhase'] = 'attack',
                default => null,
            };
        } elseif (!$newGate && $previousGate) {
            match ($voiceNum) {
                1 => $this->voice1State['adsrPhase'] = 'release',
                2 => $this->voice2State['adsrPhase'] = 'release',
                3 => $this->voice3State['adsrPhase'] = 'release',

                default => null,
            };
        }

        if (($value & 0x08) !== 0) {
            match ($voiceNum) {
                1 => $this->voice1State['phase'] = 0,
                2 => $this->voice2State['phase'] = 0,
                3 => $this->voice3State['phase'] = 0,
                default => null,
            };
        }
    }

    private function writeFilterMode(int $value): void
    {
        $this->filterMode = $value;
        $this->volume = $value & 0x0F;
    }

    /**
     * @param array{phase: int, envelope: int, adsrPhase: string} $state
     * @param array{freq: int, pw: int, ctrl: int, ad: int, sr: int} $voice
     */
    private function updateEnvelope(array &$state, array $voice): void
    {
        $attack = ($voice['ad'] >> 4) & 0x0F;
        $decay = $voice['ad'] & 0x0F;
        $sustain = ($voice['sr'] >> 4) & 0x0F;
        $release = $voice['sr'] & 0x0F;

        match ($state['adsrPhase']) {
            'attack' => $this->doAttack($state, $attack, $sustain),
            'decay' => $this->doDecay($state, $decay, $sustain),
            'sustain' => null,  // Sustain holds at current level
            'release' => $this->doRelease($state, $release),
            default => null,
        };
    }

    /**
     * @param array{phase: int, envelope: int, adsrPhase: string} $state
     */
    private function doAttack(array &$state, int $attack, int $sustain): void
    {
        $state['envelope'] += (16 - $attack) * 4;
        if ($state['envelope'] >= 255) {
            $state['envelope'] = 255;
            $state['adsrPhase'] = 'decay';
        }
    }

    /**
     * @param array{phase: int, envelope: int, adsrPhase: string} $state
     */
    private function doDecay(array &$state, int $decay, int $sustain): void
    {
        $sustainLevel = $sustain * 17;  // Scale 0-15 to 0-255
        $state['envelope'] -= (16 - $decay) * 2;
        if ($state['envelope'] <= $sustainLevel) {
            $state['envelope'] = $sustainLevel;
            $state['adsrPhase'] = 'sustain';
        }
    }

    /**
     * @param array{phase: int, envelope: int, adsrPhase: string} $state
     */
    private function doRelease(array &$state, int $release): void
    {
        $state['envelope'] -= (16 - $release) * 2;
        if ($state['envelope'] <= 0) {
            $state['envelope'] = 0;
            $state['adsrPhase'] = 'idle';
        }
    }

    public function getVolume(): int
    {
        return $this->volume;
    }

    /**
     * @return array{frequency: int, pulse_width: int, control: int, gate: bool, waveform: int, envelope: int, adsr_phase: string}
     */
    public function getVoiceInfo(int $voiceNum): array
    {
        $voice = match ($voiceNum) {
            1 => $this->voice1,
            2 => $this->voice2,
            3 => $this->voice3,
            default => throw new \InvalidArgumentException("Invalid voice: $voiceNum"),
        };

        $state = match ($voiceNum) {
            1 => $this->voice1State,
            2 => $this->voice2State,
            3 => $this->voice3State,
            default => throw new \InvalidArgumentException("Invalid voice: $voiceNum"),
        };

        return [
            'frequency' => $voice['freq'],
            'pulse_width' => $voice['pw'],
            'control' => $voice['ctrl'],
            'gate' => ($voice['ctrl'] & 0x01) !== 0,
            'waveform' => ($voice['ctrl'] >> 4) & 0x0F,
            'envelope' => $state['envelope'],
            'adsr_phase' => $state['adsrPhase'],
        ];
    }
}
