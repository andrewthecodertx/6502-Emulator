<?php

declare(strict_types=1);

namespace Emulator\Systems\BenEater;

class ROM
{
    public const ROM_START = 0x8000;
    public const ROM_END = 0xFFFF;
    public const ROM_SIZE = 0x8000; // 32KB ROM space

    /** @var array<int, int> */ private array $rom = [];

    public function __construct(?string $romDirectory)
    {
        $this->reset();

        if ($romDirectory != null) {
            $this->loadFromDirectory($romDirectory);
        }
    }

    /** @param array<int, int> $romData */
    public function loadROM(array $romData): void
    {
        $this->rom = [];
        foreach ($romData as $addr => $value) {
            if ($addr >= self::ROM_START && $addr <= self::ROM_END) {
                $this->rom[$addr] = $value & 0xFF;
            }
        }
    }

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

    public function readByte(int $address): int
    {
        return $this->rom[$address] ?? 0;
    }

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

    /** @return array<string, mixed>|null */
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

    /** @param array<string, mixed> $romInfo */
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

    public function reset(): void
    {
        // ROM contents persist through reset
    }
}
