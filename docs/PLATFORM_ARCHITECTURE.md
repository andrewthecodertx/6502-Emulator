# Platform Architecture Discussion

## Overview

This document discusses the evolution of the 6502 emulator from a simple CPU emulator to a **platform for building 6502-based systems**. It addresses memory layout requirements, interface contracts, and strategies for enforcing system-level constraints.

## Current State Analysis

### Interface Hierarchy

The platform currently defines three core interfaces in `andrewthecoder\MOS6502`:

1. **BusInterface** - System bus contract for memory routing
   - Methods: `read()`, `write()`, `readWord()`, `tick()`
   - No address validation or reserved region enforcement

2. **PeripheralInterface** - Memory-mapped peripheral contract
   - Methods: `handlesAddress()`, `read()`, `write()`, `tick()`, `hasInterruptRequest()`
   - Currently **MISSING**: `getStartAddress()`, `getEndAddress()` (mentioned as added)
   - No enforcement of address space constraints

3. **ROMInterface** - Read-only memory contract
   - Methods: `loadFromFile()`, `readByte()`, `reset()`, `getStartAddress()`, `getEndAddress()`, `getSize()`, `handlesAddress()`
   - Already includes address boundary methods

## Memory Layout Requirements

### CPU Reserved Memory Regions

The 6502 CPU has specific memory requirements that **must** be enforced by the platform:

#### 1. Zero Page ($0000-$00FF) - 256 bytes
- **Usage**: Direct page addressing, fast access
- **CPU Relationship**: CPU doesn't "own" this memory, but many instructions have special zero-page addressing modes
- **Platform Requirement**: Should peripherals be allowed here? Historically, this was RAM with occasional memory-mapped I/O
- **Recommendation**: Allow peripherals but warn/document that this may break software expectations

#### 2. Stack Memory ($0100-$01FF) - 256 bytes
- **Usage**: Hardware stack (SP register starts at $FF, grows downward)
- **CPU Relationship**: CPU directly uses SP to push/pull from $0100+SP
- **Platform Requirement**: **MUST be RAM** - peripherals here would break the CPU
- **Enforcement Level**: **CRITICAL** - system validation should fail if this isn't RAM

#### 3. Interrupt Vectors ($FFFA-$FFFF) - 6 bytes
- $FFFA-$FFFB: NMI vector
- $FFFC-$FFFD: RESET vector
- $FFFE-$FFFF: IRQ/BRK vector
- **Platform Requirement**: **MUST be readable** - typically ROM
- **Enforcement Level**: **CRITICAL** - CPU will fail on boot if these aren't accessible

### General Memory Map Constraints

- **Total Address Space**: $0000-$FFFF (64KB)
- **No Overlapping**: Peripherals must not overlap with each other
- **Peripheral Size**: Can be any size from 1 byte to 64KB
- **Peripheral Placement**: Can be at any address (subject to above constraints)

## Proposed Implementation Strategies

### Option 1: Validation in BusInterface Implementations

**Approach**: Make system builders responsible for validation

```php
interface BusInterface {
    public function read(int $address): int;
    public function write(int $address, int $value): void;
    public function readWord(int $address): int;
    public function tick(): void;

    // New validation method
    public function validate(): ValidationResult;
}

class ValidationResult {
    public bool $isValid;
    /** @var string[] */
    public array $errors;
    /** @var string[] */
    public array $warnings;
}
```

**Pros**:
- Flexibility - system builders control validation logic
- Doesn't enforce specific memory layouts
- Can have different validation for different systems (C64 vs Eater vs NES)

**Cons**:
- Not enforced - developers can skip validation
- Inconsistent validation across systems
- Errors discovered at runtime, not construction time

---

### Option 2: Memory Region Registry with Validation

**Approach**: Bus tracks all registered memory regions and validates on peripheral add

```php
interface BusInterface {
    // Existing methods...

    /**
     * Register a peripheral with validation
     * @throws MemoryConflictException if address space conflicts
     */
    public function addPeripheral(PeripheralInterface $peripheral): void;

    /**
     * Register RAM region
     * @throws MemoryConflictException if address space conflicts
     */
    public function addRAM(int $start, int $end): void;

    /**
     * Register ROM region
     * @throws MemoryConflictException if address space conflicts
     */
    public function addROM(ROMInterface $rom): void;
}

abstract class BaseBus implements BusInterface {
    private MemoryMap $memoryMap;

    public function addPeripheral(PeripheralInterface $peripheral): void {
        $this->memoryMap->register(
            $peripheral->getStartAddress(),
            $peripheral->getEndAddress(),
            'peripheral'
        );
        // Add to peripheral list...
    }
}

class MemoryMap {
    const RESERVED_STACK_START = 0x0100;
    const RESERVED_STACK_END = 0x01FF;
    const RESERVED_VECTORS_START = 0xFFFA;
    const RESERVED_VECTORS_END = 0xFFFF;

    private array $regions = [];

    public function register(int $start, int $end, string $type): void {
        // Validate stack region is RAM
        if ($type === 'peripheral' &&
            $this->overlaps($start, $end, self::RESERVED_STACK_START, self::RESERVED_STACK_END)) {
            throw new MemoryConflictException(
                "Stack region (\$0100-\$01FF) must be RAM, cannot add peripheral"
            );
        }

        // Check for overlaps with existing regions
        foreach ($this->regions as $region) {
            if ($this->overlaps($start, $end, $region['start'], $region['end'])) {
                throw new MemoryConflictException(
                    sprintf("Address space \$%04X-\$%04X conflicts with existing %s at \$%04X-\$%04X",
                        $start, $end, $region['type'], $region['start'], $region['end'])
                );
            }
        }

        $this->regions[] = ['start' => $start, 'end' => $end, 'type' => $type];
    }
}
```

**Pros**:
- Enforces constraints at construction time
- Prevents invalid configurations from being created
- Clear error messages when violations occur
- Self-documenting through exceptions

**Cons**:
- More complex bus implementation
- Requires all components to declare address ranges upfront
- May be too restrictive for exotic configurations

---

### Option 3: Builder Pattern with Validation

**Approach**: Use a builder to construct validated systems

```php
class SystemBuilder {
    private MemoryMap $memoryMap;
    private array $components = [];

    public function addRAM(int $start, int $end): self {
        $this->memoryMap->registerRAM($start, $end);
        $this->components[] = new RAM($start, $end);
        return $this;
    }

    public function addROM(ROMInterface $rom): self {
        $this->memoryMap->registerROM(
            $rom->getStartAddress(),
            $rom->getEndAddress()
        );
        $this->components[] = $rom;
        return $this;
    }

    public function addPeripheral(PeripheralInterface $peripheral): self {
        $this->memoryMap->registerPeripheral(
            $peripheral->getStartAddress(),
            $peripheral->getEndAddress()
        );
        $this->components[] = $peripheral;
        return $this;
    }

    public function build(): SystemBus {
        // Validate required regions
        if (!$this->memoryMap->hasRAMAt(0x0100, 0x01FF)) {
            throw new InvalidSystemException(
                "System must have RAM at stack region (\$0100-\$01FF)"
            );
        }

        if (!$this->memoryMap->hasReadableMemoryAt(0xFFFA, 0xFFFF)) {
            throw new InvalidSystemException(
                "System must have readable memory at vector region (\$FFFA-\$FFFF)"
            );
        }

        return new SystemBus($this->components, $this->memoryMap);
    }
}

// Usage:
$system = (new SystemBuilder())
    ->addRAM(0x0000, 0x7FFF)  // Includes stack region
    ->addROM($rom)             // Includes vectors
    ->addPeripheral($uart)
    ->addPeripheral($via)
    ->build();  // Validates and constructs
```

**Pros**:
- Fluent API for system construction
- Validation happens at build time
- Easy to extend with new component types
- Optional validation (can still construct manually)

**Cons**:
- Requires system builders to use the builder (not enforced)
- More boilerplate code
- May feel over-engineered for simple systems

---

### Option 4: Minimal Interface Extension with Recommended Validation

**Approach**: Extend PeripheralInterface with address methods, provide validation utilities

```php
interface PeripheralInterface {
    public function handlesAddress(int $address): bool;
    public function read(int $address): int;
    public function write(int $address, int $value): void;
    public function tick(): void;
    public function hasInterruptRequest(): bool;

    // NEW: Address boundary methods (for validation and documentation)
    /**
     * Gets the first address handled by this peripheral.
     *
     * @return int The starting address (inclusive)
     */
    public function getStartAddress(): int;

    /**
     * Gets the last address handled by this peripheral.
     *
     * @return int The ending address (inclusive)
     */
    public function getEndAddress(): int;
}

// Utility class for validation (optional to use)
class MemoryValidator {
    const STACK_START = 0x0100;
    const STACK_END = 0x01FF;
    const VECTORS_START = 0xFFFA;
    const VECTORS_END = 0xFFFF;

    public static function validateSystem(BusInterface $bus): ValidationResult {
        // Get all peripherals from bus (would need getter)
        // Check for overlaps
        // Check stack region
        // Check vector region
        // Return results
    }

    public static function validatePeripheral(
        PeripheralInterface $peripheral,
        array $existingRanges
    ): ValidationResult {
        $start = $peripheral->getStartAddress();
        $end = $peripheral->getEndAddress();

        $result = new ValidationResult();

        // Check stack region
        if (self::overlaps($start, $end, self::STACK_START, self::STACK_END)) {
            $result->addError(
                "Peripheral at \$%04X-\$%04X conflicts with stack region (\$0100-\$01FF)",
                $start, $end
            );
        }

        // Check overlaps with existing ranges
        foreach ($existingRanges as $range) {
            if (self::overlaps($start, $end, $range['start'], $range['end'])) {
                $result->addError(
                    "Peripheral at \$%04X-\$%04X overlaps with %s at \$%04X-\$%04X",
                    $start, $end, $range['name'], $range['start'], $range['end']
                );
            }
        }

        return $result;
    }
}
```

**Pros**:
- Minimal changes to interfaces
- Backward compatible (can add default implementations)
- Validation is available but not forced
- Flexible - developers can use or ignore

**Cons**:
- Validation is optional - can still create invalid systems
- Documentation-based enforcement
- Relies on developers reading docs

---

## Recommended Approach

I recommend **Option 2 (Memory Region Registry)** with elements from **Option 4 (Interface Extension)**:

### Implementation Plan

1. **Update PeripheralInterface** (already mentioned as done):
   ```php
   interface PeripheralInterface {
       // Existing methods...
       public function getStartAddress(): int;
       public function getEndAddress(): int;
   }
   ```

2. **Create MemoryMap class** in core package:
   ```php
   namespace andrewthecoder\MOS6502;

   class MemoryMap {
       // Track all registered regions
       // Validate on registration
       // Provide query methods
   }
   ```

3. **Create custom exceptions**:
   ```php
   class MemoryConflictException extends \RuntimeException {}
   class InvalidSystemException extends \RuntimeException {}
   ```

4. **Update BusInterface** (or create BaseBus abstract class):
   ```php
   abstract class BaseBus implements BusInterface {
       protected MemoryMap $memoryMap;

       abstract public function addPeripheral(PeripheralInterface $peripheral): void;
       abstract public function addRAM(int $start, int $end): void;
       abstract public function addROM(ROMInterface $rom): void;

       public function validate(): ValidationResult {
           return $this->memoryMap->validate();
       }
   }
   ```

### Why This Approach?

1. **Fail Fast**: Errors caught at construction time, not runtime
2. **Clear Contracts**: Interfaces explicitly define address boundaries
3. **Documentation**: Self-documenting through method names and exceptions
4. **Flexible**: Can be relaxed for testing (SimpleBus doesn't need validation)
5. **Backward Compatible**: Existing code works, new code gets validation
6. **Platform-Ready**: Foundation for enforcing system requirements

## Implementation Details

### PeripheralInterface Updates

The interface already has the basic methods but needs address boundary methods documented:

```php
/**
 * Peripheral interface that all memory-mapped peripherals must implement.
 *
 * Peripherals are memory-mapped devices that respond to specific address ranges.
 * They can perform I/O operations, run periodic updates, and generate interrupts.
 *
 * Address Space Rules:
 * - Peripherals must declare their address boundaries via getStartAddress/getEndAddress
 * - Address ranges must not overlap with other peripherals
 * - Peripherals should avoid the stack region ($0100-$01FF) unless absolutely necessary
 * - Address ranges are inclusive (both start and end addresses are handled)
 */
interface PeripheralInterface
{
    /**
     * Gets the first address handled by this peripheral.
     *
     * The peripheral will handle all addresses from this address through
     * getEndAddress() inclusive. This is used for validation and documentation.
     *
     * @return int The starting address (0x0000-0xFFFF)
     */
    public function getStartAddress(): int;

    /**
     * Gets the last address handled by this peripheral.
     *
     * The peripheral will handle all addresses from getStartAddress() through
     * this address inclusive. This is used for validation and documentation.
     *
     * @return int The ending address (0x0000-0xFFFF)
     */
    public function getEndAddress(): int;

    /**
     * Determines if this peripheral handles the specified address.
     *
     * This method is called for every memory access to determine routing.
     * Should return true for any address in [getStartAddress(), getEndAddress()].
     *
     * Implementation note: For performance, this is often implemented as:
     *   return $address >= $this->baseAddress && $address <= $this->baseAddress + $this->size - 1;
     *
     * @param int $address The memory address to check
     * @return bool True if this peripheral handles this address
     */
    public function handlesAddress(int $address): bool;

    // ... existing methods (read, write, tick, hasInterruptRequest)
}
```

### Updating Existing Peripherals

All existing peripherals need to implement the new methods:

```php
class VIA implements PeripheralInterface {
    private int $baseAddress;
    private const SIZE = 16;  // VIA occupies 16 bytes

    public function getStartAddress(): int {
        return $this->baseAddress;
    }

    public function getEndAddress(): int {
        return $this->baseAddress + self::SIZE - 1;
    }

    public function handlesAddress(int $address): bool {
        return $address >= $this->baseAddress &&
               $address <= $this->baseAddress + self::SIZE - 1;
    }
}
```

### Memory Map Example

```php
class MemoryMap {
    const STACK_START = 0x0100;
    const STACK_END = 0x01FF;
    const VECTORS_START = 0xFFFA;
    const VECTORS_END = 0xFFFF;

    /** @var array<array{start: int, end: int, type: string, name: string}> */
    private array $regions = [];

    /**
     * Register a RAM region
     */
    public function registerRAM(int $start, int $end, string $name = 'RAM'): void {
        $this->register($start, $end, 'ram', $name);
    }

    /**
     * Register a ROM region
     */
    public function registerROM(int $start, int $end, string $name = 'ROM'): void {
        $this->register($start, $end, 'rom', $name);
    }

    /**
     * Register a peripheral
     */
    public function registerPeripheral(int $start, int $end, string $name): void {
        // Check stack region conflict
        if ($this->overlaps($start, $end, self::STACK_START, self::STACK_END)) {
            throw new MemoryConflictException(
                sprintf(
                    "Peripheral '%s' (\$%04X-\$%04X) conflicts with CPU stack region (\$0100-\$01FF). " .
                    "The stack region must be RAM for proper CPU operation.",
                    $name, $start, $end
                )
            );
        }

        $this->register($start, $end, 'peripheral', $name);
    }

    /**
     * Register any memory region with overlap checking
     */
    private function register(int $start, int $end, string $type, string $name): void {
        // Validate range
        if ($start < 0x0000 || $start > 0xFFFF) {
            throw new \InvalidArgumentException("Start address must be 0x0000-0xFFFF");
        }
        if ($end < 0x0000 || $end > 0xFFFF) {
            throw new \InvalidArgumentException("End address must be 0x0000-0xFFFF");
        }
        if ($start > $end) {
            throw new \InvalidArgumentException("Start address must be <= end address");
        }

        // Check for overlaps
        foreach ($this->regions as $region) {
            if ($this->overlaps($start, $end, $region['start'], $region['end'])) {
                throw new MemoryConflictException(
                    sprintf(
                        "%s '%s' (\$%04X-\$%04X) overlaps with %s '%s' (\$%04X-\$%04X)",
                        ucfirst($type), $name, $start, $end,
                        $region['type'], $region['name'], $region['start'], $region['end']
                    )
                );
            }
        }

        $this->regions[] = [
            'start' => $start,
            'end' => $end,
            'type' => $type,
            'name' => $name
        ];
    }

    /**
     * Check if two address ranges overlap
     */
    private function overlaps(int $start1, int $end1, int $start2, int $end2): bool {
        return !($end1 < $start2 || $start1 > $end2);
    }

    /**
     * Validate critical regions
     */
    public function validate(): ValidationResult {
        $result = new ValidationResult();

        // Check for RAM in stack region
        $hasStackRAM = false;
        foreach ($this->regions as $region) {
            if ($region['type'] === 'ram' &&
                $this->covers($region['start'], $region['end'], self::STACK_START, self::STACK_END)) {
                $hasStackRAM = true;
                break;
            }
        }

        if (!$hasStackRAM) {
            $result->addError(
                "System must have RAM covering the stack region (\$0100-\$01FF)"
            );
        }

        // Check for readable memory at vectors
        $hasVectorMemory = false;
        foreach ($this->regions as $region) {
            if (($region['type'] === 'ram' || $region['type'] === 'rom') &&
                $this->covers($region['start'], $region['end'], self::VECTORS_START, self::VECTORS_END)) {
                $hasVectorMemory = true;
                break;
            }
        }

        if (!$hasVectorMemory) {
            $result->addError(
                "System must have readable memory at vector region (\$FFFA-\$FFFF)"
            );
        }

        return $result;
    }

    /**
     * Check if range1 completely covers range2
     */
    private function covers(int $start1, int $end1, int $start2, int $end2): bool {
        return $start1 <= $start2 && $end1 >= $end2;
    }
}
```

## Migration Path

### Phase 1: Interface Extension (Non-Breaking)
- Add `getStartAddress()` and `getEndAddress()` to `PeripheralInterface`
- Provide default implementations for backward compatibility (PHP 8.1+ trait)
- Update documentation

### Phase 2: Update Existing Peripherals
- Implement new methods in all existing peripherals
- VIA, UART, Serial, KeyboardController, SoundController
- Add tests for address boundary methods

### Phase 3: Create MemoryMap Core Class
- Implement `MemoryMap` in core package
- Create exception classes
- Add comprehensive tests

### Phase 4: Update SystemBus Implementations
- Integrate `MemoryMap` into `SystemBus`
- Add validation on `addPeripheral()`
- Update examples and documentation

### Phase 5: Documentation and Examples
- Update CLAUDE.md with memory layout requirements
- Create example showing validation
- Update CPU_CORE_ARCHITECTURE.md

## Testing Strategy

```php
class MemoryMapTest extends TestCase {
    public function test_prevents_peripheral_in_stack_region(): void {
        $map = new MemoryMap();
        $map->registerRAM(0x0000, 0x7FFF, 'Main RAM');

        $this->expectException(MemoryConflictException::class);
        $this->expectExceptionMessage('conflicts with CPU stack region');

        // Try to add peripheral in stack region
        $map->registerPeripheral(0x0100, 0x010F, 'BadPeripheral');
    }

    public function test_detects_overlapping_peripherals(): void {
        $map = new MemoryMap();
        $map->registerPeripheral(0xC000, 0xC0FF, 'Device1');

        $this->expectException(MemoryConflictException::class);

        // Try to add overlapping peripheral
        $map->registerPeripheral(0xC080, 0xC180, 'Device2');
    }

    public function test_validates_stack_region_presence(): void {
        $map = new MemoryMap();
        $map->registerRAM(0x0000, 0x00FF, 'Zero Page');
        $map->registerRAM(0x0200, 0x7FFF, 'Main RAM');
        // Missing $0100-$01FF!

        $result = $map->validate();

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('stack region', $result->getFirstError());
    }
}
```

## Questions for Discussion

1. **Zero Page Access**: Should peripherals be allowed in zero page ($0000-$00FF)?
   - **Allow**: Historically accurate (some systems had I/O in zero page)
   - **Warn**: Add warning but don't prevent
   - **Forbid**: Strict validation prevents it

2. **Validation Timing**: When should validation occur?
   - **Construction**: Validate immediately when adding peripherals (recommended)
   - **Explicit**: Require calling `validate()` before use
   - **Runtime**: Validate during first CPU operation

3. **SimpleBus Exemption**: Should test bus (SimpleBus) bypass validation?
   - **Yes**: Tests need flexibility
   - **No**: Tests should validate too
   - **Configurable**: Add constructor parameter for strict mode

4. **ROM Address Requirements**: Should ROM be required to include vectors?
   - **Yes**: Enforce ROM at $FFFA-$FFFF
   - **No**: Allow RAM at vectors (useful for testing)
   - **Warn**: Allow but warn if not ROM

## Conclusion

The platform needs enforcement of memory layout constraints to prevent invalid system configurations. The recommended approach is:

1. **Extend PeripheralInterface** with `getStartAddress()` and `getEndAddress()`
2. **Create MemoryMap** class for tracking and validation
3. **Integrate validation** into bus implementations
4. **Fail fast** - catch errors at construction time
5. **Document extensively** - make requirements clear

This provides a robust foundation for building 6502-based systems while maintaining flexibility for different system architectures.
