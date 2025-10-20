# ROM API Simplification - Change Summary

## Overview

The ROM loading API has been simplified to focus on binary file loading only, removing the array-based loading method that was never actually used in production code.

## Changes Made

### 1. ROMInterface (src/ROMInterface.php)

**Removed:**
- `loadROM(array $romData): void` - Array-based loading (unused)
- `loadBinaryROM(string $binaryFile): void` - Old method name

**Added:**
- `loadFromFile(string $path, ?int $loadAddress = null): void` - Simplified, clearer name with optional load address

**Rationale:**
- ROM represents physical hardware that loads from binary files
- Tests don't need ROM - they use `SimpleBus` which is all RAM
- Array loading was only in documentation examples, never used in actual code
- Simpler API with single, clear loading method

### 2. ROM Implementation (src/Systems/Eater/ROM.php)

**Changed:**
- Now implements `ROMInterface` explicitly
- Renamed `loadBinaryROM()` → `loadFromFile()`
- Removed `loadROM(array)` method entirely
- Added `loadAddress` parameter to `loadFromFile()` for custom load addresses

**Added Interface Methods:**
- `getStartAddress(): int` - Returns 0x8000
- `getEndAddress(): int` - Returns 0xFFFF
- `getSize(): int` - Returns 0x8000 (32KB)
- `handlesAddress(int $address): bool` - Checks if address is in ROM range

### 3. Documentation Updates

**docs/INSTRUCTIONS.md:**
- Changed `$rom->loadBinaryROM('file.bin')` → `$rom->loadFromFile('file.bin')`
- Removed array loading example
- Added custom load address example: `$rom->loadFromFile('program.bin', 0xC000)`

**CLAUDE.md:**
- Updated ROM documentation to mention `ROMInterface` implementation
- Documented `loadFromFile()` and `loadFromDirectory()` methods

## Migration Guide

### Before:
```php
// Loading from binary file
$rom->loadBinaryROM('roms/wozmon.bin');

// Loading from array (for tests - NOT RECOMMENDED)
$rom->loadROM([
    0x8000 => 0xA9,
    0x8001 => 0x42
]);
```

### After:
```php
// Loading from binary file
$rom->loadFromFile('roms/wozmon.bin');

// Loading with custom address
$rom->loadFromFile('roms/program.bin', 0xC000);

// For tests - use SimpleBus instead
$bus = new SimpleBus();
$bus->loadProgram(0x8000, [0xA9, 0x42]);
```

## Why This Change?

1. **Clarity** - ROM models physical hardware, which loads from files
2. **Simplicity** - Single method instead of two
3. **Removes Dead Code** - Array loading was never used
4. **Better Testing Pattern** - Tests should use SimpleBus, not ROM
5. **Flexibility** - Optional load address parameter for advanced use cases

## Testing

All tests pass (same failures as before, unrelated to ROM):
```bash
./vendor/bin/phpunit
# Tests: 56, Assertions: 156, Failures: 6 (pre-existing interrupt tests)
```

Static analysis passes:
```bash
./vendor/bin/phpstan analyse src/ROMInterface.php src/Systems/Eater/ROM.php --level=5
# [OK] No errors
```

## Files Modified

1. `src/ROMInterface.php` - Simplified interface
2. `src/Systems/Eater/ROM.php` - Updated implementation
3. `docs/INSTRUCTIONS.md` - Updated examples
4. `CLAUDE.md` - Updated architecture notes
