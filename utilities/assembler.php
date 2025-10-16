<?php

// 1. Load opcode table from your JSON file (adjust path as needed)
$opcode_table = json_decode(file_get_contents('opcodes.json'), true);

// 2. Build lookup: [mnemonic][addressing mode] => opcode info
$lookup = [];
foreach ($opcode_table as $entry) {
    $mnemonic = strtoupper($entry['mnemonic']);
    $mode     = $entry['addressing mode'];
    $lookup[$mnemonic][$mode] = $entry;
}

// 3. Helper to parse an operand into addressing mode and numeric value(s)
function detect_addressing_mode(string $mnemonic, string $operand_raw): string|null
{
    $op = trim($operand_raw);
    // Check all common addressing modes
    if ($op === '') {
        return 'Implied';
    }

    if ($op[0] === '#') {
        return 'Immediate';
    } // #$44
    if (preg_match('/^\$\w{1,4}$/', $op)) {
        return 'Absolute';
    } // $4400 or $08

    if (preg_match('/^\$\w{1,2},X$/i', $op)) {
        return 'X-Indexed Zero Page';
    }
    if (preg_match('/^\$\w{1,4},X$/i', $op)) {
        return 'X-Indexed Absolute';
    }

    if (preg_match('/^\$\w{1,2},Y$/i', $op)) {
        return 'Y-Indexed Zero Page';
    }
    if (preg_match('/^\$\w{1,4},Y$/i', $op)) {
        return 'Y-Indexed Absolute';
    }

    if (preg_match('/^\(\$\w{1,2},X\)$/i', $op)) {
        return 'X-Indexed Zero Page Indirect';
    }
    if (preg_match('/^\(\$\w{1,2}\),Y$/i', $op)) {
        return 'Zero Page Indirect Y-Indexed';
    }
    if (preg_match('/^\(\$\w{1,4}\)$/i', $op)) {
        return 'Absolute Indirect';
    }

    if (preg_match('/^\$\w{1,2}$/', $op)) {
        return 'Zero Page';
    }

    if (strtoupper($mnemonic) === 'BRA' || strtoupper($mnemonic)[0] === 'B') {
        return 'Relative';
    }

    if (strtoupper($mnemonic) === 'ASL' && strtoupper($op) === 'A') {
        return 'Accumulator';
    }

    return null; // Unknown or unsupported
}

// 4. Parse assembly, lookup opcode, and output
$input = file('program.asm');
$output = [];
foreach ($input as $line) {
    $line = trim(preg_replace('/;.*$/', '', $line)); // drop comments
    if ($line === '') {
        continue;
    }

    if (!preg_match('/^([A-Za-z]{3}) ?(.*)$/', $line, $m)) {
        continue;
    }

    $mnemonic = strtoupper($m[1]);
    $operand = isset($m[2]) ? trim($m[2]) : '';
    $mode = detect_addressing_mode($mnemonic, $operand);

    if (!$mode || !isset($lookup[$mnemonic][$mode])) {
        echo "Unknown opcode or addressing mode on line: $line\n";
        continue;
    }

    $entry = $lookup[$mnemonic][$mode];
    $output[] = hexdec($entry['opcode']);

    // Encode operand(s) as bytes, little-endian where needed
    if ($mode === 'Immediate') {
        if (preg_match('/^\#\$(\w{1,2})$/', $operand, $val)) {
            $output[] = hexdec($val[1]);
        }
    } elseif ($mode === 'Absolute' || $mode === 'X-Indexed Absolute' || $mode === 'Y-Indexed Absolute' || $mode === 'Absolute Indirect') {
        // Address (16-bit, little-endian)
        if (preg_match('/^\$?(\w{4})/', $operand, $val)) {
            $v = hexdec($val[1]);
            $output[] = $v & 0xFF;
            $output[] = ($v >> 8) & 0xFF;
        }
    } elseif ($mode === 'Zero Page' || $mode === 'X-Indexed Zero Page' || $mode === 'Y-Indexed Zero Page') {
        if (preg_match('/^\$?(\w{2})/', $operand, $val)) {
            $output[] = hexdec($val[1]);
        }
    } elseif ($mode === 'X-Indexed Zero Page Indirect') {
        if (preg_match('/^\(\$(\w{2}),X\)/i', $operand, $val)) {
            $output[] = hexdec($val[1]);
        }
    } elseif ($mode === 'Zero Page Indirect Y-Indexed') {
        if (preg_match('/^\(\$(\w{2})\),Y/i', $operand, $val)) {
            $output[] = hexdec($val[1]);
        }
    } elseif ($mode === 'Relative') {
        // For now, placeholder zero offset (needs labels to be useful)
        $output[] = 0x00;
    }
    // Implied/Accumulator: no operand bytes
}

// 5. Output to .bin
file_put_contents('program.bin', pack('C*', ...$output));
echo "Assembly converted to program.bin\n";
