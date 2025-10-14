<?php

declare(strict_types=1);

namespace Emulator\Systems\Eater;

/**
 * Read-only memory with multiple loading mechanisms.
 *
 * Supports loading ROM data from arrays, binary files, or directories with
 * metadata. ROM space is 32KB ($8000-$FFFF). Uninitialized addresses return 0.
 */
class ROM
{
    public const ROM_START = 0x8000;
    public const ROM_END = 0xFFFF;
    public const ROM_SIZE = 0x8000; // 32KB ROM space

    /** @var array<int, int> */ private array $rom = [];

    /**
     * Creates a new ROM instance.
     *
     * @param string|null $romDirectory Optional directory to load ROM files from
     * @throws \RuntimeException If directory loading fails
     */
    public function __construct(?string $romDirectory)
    {
        $this->reset();

        if ($romDirectory != null) {
            $this->loadFromDirectory($romDirectory);
        }
    }

    /**
     * Loads ROM data from an array indexed by address.
     *
     * Only addresses within ROM space ($8000-$FFFF) are loaded.
     *
     * @param array<int, int> $romData Array of address => byte mappings
     */
    public function loadROM(array $romData): void
    {
        $this->rom = [];
        foreach ($romData as $addr => $value) {
            if ($addr >= self::ROM_START && $addr <= self::ROM_END) {
                $this->rom[$addr] = $value & 0xFF;
            }
        }
    }

    /**
     * Loads ROM data from a binary file.
     *
     * File is loaded starting at $8000. Files larger than 32KB are truncated.
     *
     * @param string $binaryFile Path to the binary file
     * @throws \RuntimeException If file not found or cannot be read
     */
    public function loadBinaryROM(string $binaryFile): void
    {
        if (!file_exists($binaryFile)) {
            throw new \RuntimeException("ROM binary file not found: $binaryFile");
        }

        $binaryData = file_get_contents($binaryFile);
        if ($binaryData === false) {
            throw new \RuntimeException("Failed to read ROM binary file: $binaryFile");
        }

        $this->rom = [];
        $bytes = unpack('C*', $binaryData);
        if ($bytes === false) {
            throw new \RuntimeException("Failed to unpack ROM binary data");
        }

        $address = self::ROM_START;
        foreach ($bytes as $byte) {
            if ($address <= self::ROM_END) {
                $this->rom[$address] = $byte;
                $address++;
            }
        }
    }

    /**
     * Reads a byte from ROM at the specified address.
     *
     * Uninitialized addresses return 0.
     *
     * @param int $address The memory address
     * @return int The byte value (0-255)
     */
    public function readByte(int $address): int
    {
        return $this->rom[$address] ?? 0;
    }

    /**
     * Loads ROM files from a directory using JSON metadata.
     *
     * Scans for .json files, loads their metadata, and loads corresponding
     * .bin files in priority order. Each metadata file must specify: name,
     * load_address, size, and priority.
     *
     * @param string $directory Path to directory containing ROM files
     * @throws \RuntimeException If directory not found or cannot be scanned
     */
    public function loadFromDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            throw new \RuntimeException("ROM directory not found: $directory");
        }

        $metadataFiles = glob($directory . '/*.json');
        if ($metadataFiles === false) {
            throw new \RuntimeException("Failed to scan ROM directory: $directory");
        }

        $romFiles = [];

        foreach ($metadataFiles as $metadataFile) {
            $metadata = $this->loadMetadata($metadataFile);
            if ($metadata !== null) {
                $romFiles[] = $metadata;
            }
        }

        usort($romFiles, fn ($a, $b) => $a['priority'] <=> $b['priority']);

        foreach ($romFiles as $romInfo) {
            $this->loadROMFile($directory, $romInfo);
        }

        echo "Loaded " . count($romFiles) . " ROM file(s) from $directory\n";
    }

    /**
     * Loads and validates JSON metadata for a ROM file.
     *
     * @param string $metadataFile Path to the .json metadata file
     * @return array<string, mixed>|null Metadata array or null if invalid
     */
    private function loadMetadata(string $metadataFile): ?array
    {
        $content = file_get_contents($metadataFile);
        if ($content === false) {
            echo "Warning: Failed to read metadata file: $metadataFile\n";
            return null;
        }

        $metadata = json_decode($content, true);
        if ($metadata === null) {
            echo "Warning: Invalid JSON in metadata file: $metadataFile\n";
            return null;
        }

        $required = ['name', 'load_address', 'size', 'priority'];
        foreach ($required as $field) {
            if (!isset($metadata[$field])) {
                echo "Warning: Missing required field '$field' in metadata file: $metadataFile\n";
                return null;
            }
        }

        $baseName = basename($metadataFile, '.json');
        $directory = dirname($metadataFile);
        $metadata['binary_file'] = $directory . '/' . $baseName . '.bin';
        $metadata['metadata_file'] = $metadataFile;

        return $metadata;
    }

    /**
     * Loads a single ROM file using its metadata.
     *
     * @param string $directory Base directory path
     * @param array<string, mixed> $romInfo ROM metadata including binary_file, load_address, size
     */
    private function loadROMFile(string $directory, array $romInfo): void
    {
        $binaryFile = $romInfo['binary_file'];

        if (!file_exists($binaryFile)) {
            echo "Warning: ROM binary file not found: $binaryFile\n";
            return;
        }

        $binaryData = file_get_contents($binaryFile);
        if ($binaryData === false) {
            echo "Warning: Failed to read ROM binary file: $binaryFile\n";
            return;
        }

        $loadAddress = is_string($romInfo['load_address'])
            ? intval($romInfo['load_address'], 0)
            : $romInfo['load_address'];

        if ($loadAddress < self::ROM_START || $loadAddress > self::ROM_END) {
            echo "Warning: Load address 0x" . sprintf('%04X', $loadAddress) .
                 " outside ROM space for {$romInfo['name']}\n";
            return;
        }

        $bytesToLoad = min(strlen($binaryData), $romInfo['size']);

        for ($i = 0; $i < $bytesToLoad; $i++) {
            $address = $loadAddress + $i;
            if ($address <= self::ROM_END) {
                $this->rom[$address] = ord($binaryData[$i]);
            }
        }

        echo "Loaded {$romInfo['name']} at 0x" . sprintf('%04X', $loadAddress) .
             " (" . $bytesToLoad . " bytes, priority {$romInfo['priority']})\n";
    }

    /**
     * Resets the ROM.
     *
     * ROM contents persist through reset (no operation performed).
     */
    public function reset(): void
    {
        // ROM contents persist through reset
    }
}
